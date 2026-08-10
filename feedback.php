<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/public_layout.php';
require_once __DIR__ . '/admin/feedback_funcs.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$pdo = createDbConnection();
ensureFeedbackTables($pdo);
$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$registrationId = (int) ($_GET['registration_id'] ?? $_POST['registration_id'] ?? 0);
$feedbackId = (int) ($_GET['feedback_id'] ?? $_POST['feedback_id'] ?? 0);
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = false;

$statement = $pdo->prepare(<<<'SQL'
    SELECT fi.*, e.event_title, e.start_date, ft.type_name
    FROM feedback_invitations fi
    JOIN events e ON e.event_id = fi.event_id
    JOIN feedback_types ft ON ft.feedback_type_id = fi.feedback_type_id
    WHERE fi.feedback_id = :feedback_id AND fi.event_id = :event_id
      AND fi.registration_id = :registration_id AND fi.access_token = :token
    LIMIT 1
SQL);
$statement->execute([':feedback_id' => $feedbackId, ':event_id' => $eventId, ':registration_id' => $registrationId, ':token' => $token]);
$invitation = $statement->fetch() ?: null;
$items = $invitation ? getFeedbackItems($pdo, (int) $invitation['feedback_type_id']) : [];
$items = array_merge(
    array_values(array_filter($items, static fn(array $item): bool => $item['response_type'] === 'rating')),
    array_values(array_filter($items, static fn(array $item): bool => $item['response_type'] === 'text'))
);
$ratingLabels = [
    1 => ['label' => 'Poor', 'emoji' => '&#128542;'],
    2 => ['label' => 'Fair', 'emoji' => '&#128528;'],
    3 => ['label' => 'Good', 'emoji' => '&#128578;'],
    4 => ['label' => 'Very Good', 'emoji' => '&#128522;'],
    5 => ['label' => 'Excellent', 'emoji' => '&#129321;'],
];

if (!$invitation) {
    http_response_code(404);
    $error = 'This feedback link is invalid or unavailable.';
} elseif ($invitation['status'] === 'submitted') {
    $success = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ratings = (array) ($_POST['rating'] ?? []);
    $texts = (array) ($_POST['response_text'] ?? []);
    $qualitative = [
        'subject_summary' => trim((string) ($_POST['subject_summary'] ?? '')),
        'impact_summary' => trim((string) ($_POST['impact_summary'] ?? '')),
        'suggestions' => trim((string) ($_POST['suggestions'] ?? '')),
    ];
    try {
        if (!$items) throw new InvalidArgumentException('No feedback items are currently available.');
        foreach ($items as $item) {
            $itemId = (int) $item['feedback_item_id'];
            if ($item['response_type'] === 'rating') {
                $rating = (int) ($ratings[$itemId] ?? 0);
                if ($rating < 1 || $rating > 5) throw new InvalidArgumentException('Please rate every item from 1 to 5.');
            } elseif (trim((string) ($texts[$itemId] ?? '')) === '') {
                throw new InvalidArgumentException('Please answer every feedback item.');
            }
        }
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO feedback_responses (feedback_id, feedback_item_id, rating, response_text) VALUES (:feedback_id, :item_id, :rating, :response_text)');
        foreach ($items as $item) {
            $itemId = (int) $item['feedback_item_id'];
            $insert->execute([
                ':feedback_id' => $feedbackId,
                ':item_id' => $itemId,
                ':rating' => $item['response_type'] === 'rating' ? (int) $ratings[$itemId] : null,
                ':response_text' => $item['response_type'] === 'text' ? trim((string) $texts[$itemId]) : null,
            ]);
        }
        saveFeedbackQualitativeResponses($pdo, $feedbackId, $qualitative);
        $pdo->prepare("UPDATE feedback_invitations SET status = 'submitted', submitted_at = NOW() WHERE feedback_id = :id AND status <> 'submitted'")->execute([':id' => $feedbackId]);
        $pdo->commit();
        $success = true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception instanceof PDOException ? 'Your feedback could not be saved. Please try again.' : $exception->getMessage();
    }
}
$feedbackStylesPath = __DIR__ . '/assets/css/styles.css';
$feedbackStylesVersion = is_file($feedbackStylesPath) ? (string) filemtime($feedbackStylesPath) : '1';
?><!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Event Feedback</title><link rel="stylesheet" href="<?php echo htmlspecialchars(buildUrl('assets/css/styles.css') . '?v=' . $feedbackStylesVersion); ?>"></head>
<body class="public-feedback-page">
<?php renderPublicHeader(); ?>
<main class="public-feedback-main">
    <article class="public-feedback-card">
        <?php if ($success): ?>
            <div class="feedback-thank-you"><span aria-hidden="true">✓</span><h1>Thank you</h1><p>Your feedback has been submitted successfully.</p></div>
        <?php elseif (!$invitation): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php else: ?>
            <header><span>Blind feedback form</span><h1><?php echo htmlspecialchars($invitation['event_title']); ?></h1><p><?php echo htmlspecialchars($invitation['type_name']); ?> Feedback</p></header>
            <div class="feedback-privacy-note">Your name and contact details are not displayed on this form. Your invitation is recorded internally only to validate the response and prevent duplicate submissions.</div>
            <?php if ($error): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="post" class="public-feedback-form">
                <input type="hidden" name="event_id" value="<?php echo $eventId; ?>"><input type="hidden" name="registration_id" value="<?php echo $registrationId; ?>"><input type="hidden" name="feedback_id" value="<?php echo $feedbackId; ?>"><input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <?php foreach ($items as $number => $item): ?><fieldset><legend><span>Q<?php echo $number + 1; ?></span><?php echo htmlspecialchars($item['item_text']); ?></legend>
                    <?php if ($item['response_type'] === 'rating'): $ratingDisplay = $item['rating_display'] ?? 'words'; ?><div class="feedback-rating rating-display-<?php echo htmlspecialchars($ratingDisplay); ?>"><?php foreach ($ratingLabels as $rating => $ratingOption): ?><label><input type="radio" name="rating[<?php echo (int) $item['feedback_item_id']; ?>]" value="<?php echo $rating; ?>" aria-label="<?php echo htmlspecialchars($ratingOption['label'] . ', ' . $rating . ' out of 5'); ?>" required><span class="rating-option"><?php if ($ratingDisplay === 'emojis'): ?><span class="rating-emoji" aria-hidden="true"><?php echo $ratingOption['emoji']; ?></span><?php elseif ($ratingDisplay === 'stars'): ?><span class="rating-stars" aria-hidden="true">&#9733;</span><?php else: ?><strong><?php echo htmlspecialchars($ratingOption['label']); ?></strong><?php endif; ?></span></label><?php endforeach; ?></div>
                    <?php else: ?><textarea name="response_text[<?php echo (int) $item['feedback_item_id']; ?>]" required placeholder="Write your feedback"></textarea><?php endif; ?>
                </fieldset><?php endforeach; ?>
                <?php $standardQuestionNumber = count($items) + 1; ?>
                <fieldset><legend><span>Q<?php echo $standardQuestionNumber; ?></span>What was the main subject or theme of your feedback?</legend><textarea name="subject_summary" required placeholder="Describe the subject of your feedback in up to 255 words"></textarea><p class="feedback-privacy-note">Maximum 255 words.</p></fieldset>
                <fieldset><legend><span>Q<?php echo $standardQuestionNumber + 1; ?></span>How did the event inspire or impact you?</legend><textarea name="impact_summary" required placeholder="Share the inspiration or impact you experienced during the event"></textarea><p class="feedback-privacy-note">Maximum 255 words.</p></fieldset>
                <fieldset><legend><span>Q<?php echo $standardQuestionNumber + 2; ?></span>What suggestions do you have for improving future events?</legend><textarea name="suggestions" required placeholder="Share your suggestions in up to 255 words"></textarea><p class="feedback-privacy-note">Maximum 255 words.</p></fieldset>
                <button type="submit">Submit Feedback</button>
            </form>
        <?php endif; ?>
    </article>
</main>
<?php renderPublicFooter(); ?>
</body></html>
