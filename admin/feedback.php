<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/feedback_funcs.php';
require_once __DIR__ . '/settings_funcs.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_authenticated'])) { header('Location: ' . buildUrl('admin')); exit; }
if (empty($_SESSION['feedback_csrf'])) $_SESSION['feedback_csrf'] = bin2hex(random_bytes(24));

$pdo = createDbConnection();
ensureSettingsTable($pdo);
ensureFeedbackTables($pdo);
$success = (string) ($_SESSION['admin_success'] ?? '');
$error = (string) ($_SESSION['admin_error'] ?? '');
unset($_SESSION['admin_success'], $_SESSION['admin_error']);
$eventId = (int) ($_REQUEST['event_id'] ?? 0);
$typeId = (int) ($_REQUEST['feedback_type_id'] ?? 0);

function feedbackAdminRedirect(int $eventId, int $typeId): never
{
    header('Location: ' . buildUrl('admin/feedback.php') . '?' . http_build_query(['event_id' => $eventId, 'feedback_type_id' => $typeId]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['feedback_csrf'], (string) ($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('Invalid request token.'); }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add_type') {
            $name = trim((string) ($_POST['type_name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('Enter a feedback type name.');
            $pdo->prepare('INSERT INTO feedback_types (type_name) VALUES (:name)')->execute([':name' => $name]);
            $_SESSION['admin_success'] = 'Feedback type added.';
        } elseif ($action === 'add_item') {
            $itemText = trim((string) ($_POST['item_text'] ?? ''));
            $responseType = (string) ($_POST['response_type'] ?? 'rating');
            $ratingDisplay = (string) ($_POST['rating_display'] ?? 'words');
            if ($typeId <= 0 || $itemText === '') throw new InvalidArgumentException('Select a feedback type and enter an item.');
            if (!in_array($responseType, ['rating', 'text'], true)) $responseType = 'rating';
            if (!in_array($ratingDisplay, ['words', 'emojis', 'stars'], true)) $ratingDisplay = 'words';
            $pdo->prepare('INSERT INTO feedback_items (feedback_type_id, item_text, response_type, rating_display, sort_order) VALUES (:type_id, :text, :response_type, :rating_display, :sort_order)')->execute([':type_id' => $typeId, ':text' => $itemText, ':response_type' => $responseType, ':rating_display' => $ratingDisplay, ':sort_order' => (int) ($_POST['sort_order'] ?? 0)]);
            $_SESSION['admin_success'] = 'Feedback item added.';
        } elseif ($action === 'update_item_display') {
            $itemId = (int) ($_POST['feedback_item_id'] ?? 0);
            $ratingDisplay = (string) ($_POST['rating_display'] ?? 'words');
            if (!in_array($ratingDisplay, ['words', 'emojis', 'stars'], true)) throw new InvalidArgumentException('Select a valid rating display.');
            $statement = $pdo->prepare("UPDATE feedback_items SET rating_display = :rating_display WHERE feedback_item_id = :id AND feedback_type_id = :type_id AND response_type = 'rating'");
            $statement->execute([':rating_display' => $ratingDisplay, ':id' => $itemId, ':type_id' => $typeId]);
            $_SESSION['admin_success'] = 'Rating display updated.';
        } elseif ($action === 'toggle_item') {
            $itemId = (int) ($_POST['feedback_item_id'] ?? 0);
            $pdo->prepare('UPDATE feedback_items SET is_active = 1 - is_active WHERE feedback_item_id = :id')->execute([':id' => $itemId]);
            $_SESSION['admin_success'] = 'Feedback item status updated.';
        } elseif ($action === 'delete_item') {
            $itemId = (int) ($_POST['feedback_item_id'] ?? 0);
            $itemStatement = $pdo->prepare('SELECT item_text FROM feedback_items WHERE feedback_item_id = :id AND feedback_type_id = :type_id');
            $itemStatement->execute([':id' => $itemId, ':type_id' => $typeId]);
            $itemText = $itemStatement->fetchColumn();
            if ($itemText === false) throw new InvalidArgumentException('Feedback item not found.');
            $responseStatement = $pdo->prepare('SELECT COUNT(*) FROM feedback_responses WHERE feedback_item_id = :id');
            $responseStatement->execute([':id' => $itemId]);
            if ((int) $responseStatement->fetchColumn() > 0) {
                throw new InvalidArgumentException('This item has submitted responses and cannot be deleted. Disable it to preserve feedback history.');
            }
            $pdo->prepare('DELETE FROM feedback_items WHERE feedback_item_id = :id AND feedback_type_id = :type_id')->execute([':id' => $itemId, ':type_id' => $typeId]);
            $_SESSION['admin_success'] = 'Feedback item deleted.';
        } elseif (in_array($action, ['send_email', 'send_email_all', 'send_whatsapp', 'send_whatsapp_all'], true)) {
            if ($eventId <= 0 || $typeId <= 0) throw new InvalidArgumentException('Select an event and feedback type.');
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['registration_ids'] ?? []))));
            $all = str_ends_with($action, '_all');
            $channel = str_contains($action, 'email') ? 'email' : 'whatsapp';
            $sql = "SELECT er.*, e.event_title, ft.type_name FROM event_registrations er JOIN events e ON e.event_id = er.event_id JOIN feedback_types ft ON ft.feedback_type_id = :type_id WHERE er.event_id = :event_id AND er.approval_status = 'approved'";
            $params = [':event_id' => $eventId, ':type_id' => $typeId];
            if (!$all) {
                if (!$ids) throw new InvalidArgumentException('Select at least one recipient.');
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $statement = $pdo->prepare(str_replace(':type_id', '?', str_replace(':event_id', '?', $sql)) . " AND er.registration_id IN ({$marks})");
                $statement->execute(array_merge([$typeId, $eventId], $ids));
            } else { $statement = $pdo->prepare($sql); $statement->execute($params); }
            $recipients = $statement->fetchAll() ?: [];
            $sent = 0; $failures = [];
            foreach ($recipients as $recipient) {
                $invitation = getOrCreateFeedbackInvitation($pdo, $eventId, (int) $recipient['registration_id'], $typeId);
                $link = buildAbsoluteFeedbackLink($pdo, $invitation);
                $plainMessage = "Dear Participant,\n\nPlease share your " . $recipient['type_name'] . ' feedback for ' . $recipient['event_title'] . ". Your responses are presented as blind feedback to reviewers.\n\nOpen feedback form: " . $link . "\n\nThank you.";
                $destination = trim((string) ($channel === 'email' ? $recipient['official_email'] : ($recipient['whatsapp_number'] ?: $recipient['mobile'])));
                $deliveryError = '';
                $ok = $channel === 'email'
                    ? sendFeedbackEmail($pdo, $destination, 'Feedback requested for ' . $recipient['event_title'], buildFeedbackEmailMessage((string) $recipient['event_title'], (string) $recipient['type_name'], $link), $deliveryError, true)
                    : sendFeedbackWhatsapp($pdo, $destination, $plainMessage);
                recordFeedbackDelivery($pdo, (int) $invitation['feedback_id'], $channel, $destination, $ok ? 'sent' : 'failed', $ok ? 'Feedback link delivered.' : ($deliveryError ?: 'Delivery failed.'));
                if ($ok) $sent++; else $failures[] = (string) ($recipient['name'] ?? ('Registration #' . $recipient['registration_id']));
            }
            $_SESSION['admin_success'] = "Feedback link sent to {$sent} recipient(s).";
            if ($failures) $_SESSION['admin_error'] = 'Delivery failed for: ' . implode(', ', $failures);
        }
    } catch (Throwable $exception) { $_SESSION['admin_error'] = $exception->getMessage(); }
    feedbackAdminRedirect($eventId, $typeId);
}

$events = $pdo->query("SELECT event_id, event_title, start_date FROM events WHERE event_status = 'Open' ORDER BY start_date, event_title")->fetchAll() ?: [];
if ($eventId <= 0 && $events) $eventId = (int) $events[0]['event_id'];
$types = getFeedbackTypes($pdo, false);
if ($typeId <= 0 && $types) $typeId = (int) $types[0]['feedback_type_id'];
$items = $typeId > 0 ? getFeedbackItems($pdo, $typeId, false) : [];
$registrations = [];
$submissions = [];
if ($eventId > 0) {
    $statement = $pdo->prepare("SELECT er.*, fi.feedback_id, fi.status AS feedback_status, fi.access_token FROM event_registrations er LEFT JOIN feedback_invitations fi ON fi.registration_id = er.registration_id AND fi.event_id = er.event_id AND fi.feedback_type_id = :type_id WHERE er.event_id = :event_id AND er.approval_status = 'approved' ORDER BY er.name");
    $statement->execute([':event_id' => $eventId, ':type_id' => $typeId]);
    $registrations = $statement->fetchAll() ?: [];
    $statement = $pdo->prepare("SELECT fi.feedback_id, fi.submitted_at, er.name, er.designation, fitem.item_text, fr.rating, fr.response_text FROM feedback_invitations fi JOIN event_registrations er ON er.registration_id = fi.registration_id LEFT JOIN feedback_responses fr ON fr.feedback_id = fi.feedback_id LEFT JOIN feedback_items fitem ON fitem.feedback_item_id = fr.feedback_item_id WHERE fi.event_id = :event_id AND fi.feedback_type_id = :type_id AND fi.status = 'submitted' ORDER BY fi.submitted_at DESC, fitem.sort_order, fitem.feedback_item_id");
    $statement->execute([':event_id' => $eventId, ':type_id' => $typeId]);
    foreach ($statement->fetchAll() ?: [] as $response) {
        $feedbackKey = (int) $response['feedback_id'];
        if (!isset($submissions[$feedbackKey])) $submissions[$feedbackKey] = ['name' => $response['name'], 'designation' => $response['designation'], 'submitted_at' => $response['submitted_at'], 'answers' => []];
        if ($response['item_text'] !== null) $submissions[$feedbackKey]['answers'][] = ['item' => $response['item_text'], 'answer' => $response['rating'] !== null ? $response['rating'] . '/5' : $response['response_text']];
    }
}

ob_start(); ?>
<div class="feedback-admin-page">
    <?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <section class="feedback-panel">
        <h2>Feedback Types and Items</h2>
        <div class="feedback-config-grid">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['feedback_csrf']); ?>">
                <input type="hidden" name="action" value="add_type">
                <label>New feedback type<input name="type_name" placeholder="e.g. Transport" required></label>
                <button type="submit">Add Type</button>
            </form>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['feedback_csrf']); ?>">
                <input type="hidden" name="action" value="add_item">
                <label>Feedback type<select name="feedback_type_id" required><?php foreach ($types as $type): ?><option value="<?php echo (int) $type['feedback_type_id']; ?>" <?php echo $typeId === (int) $type['feedback_type_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($type['type_name']); ?></option><?php endforeach; ?></select></label>
                <label>Question / item<input name="item_text" required></label>
                <label>Answer type<select name="response_type"><option value="rating">Rating (1–5)</option><option value="text">Written answer</option></select></label>
                <label>Rating display<select name="rating_display"><option value="words">Words (Poor to Excellent)</option><option value="emojis">Emojis</option><option value="stars">Stars</option></select></label>
                <label>Order<input name="sort_order" type="number" value="0"></label>
                <button type="submit">Add Item</button>
            </form>
        </div>
        <form method="get" class="feedback-inline-filter"><label>View items for<select name="feedback_type_id" onchange="this.form.submit()"><?php foreach ($types as $type): ?><option value="<?php echo (int) $type['feedback_type_id']; ?>" <?php echo $typeId === (int) $type['feedback_type_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($type['type_name']); ?></option><?php endforeach; ?></select></label><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"></form>
        <table class="feedback-items-table">
            <thead><tr><th>Item</th><th>Answer</th><th>Rating display</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody><?php foreach ($items as $item): ?><tr>
                <td><?php echo htmlspecialchars($item['item_text']); ?></td>
                <td><?php echo htmlspecialchars(ucfirst($item['response_type'])); ?></td>
                <td><?php if ($item['response_type'] === 'rating'): ?><form method="post" class="rating-display-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['feedback_csrf']); ?>"><input type="hidden" name="action" value="update_item_display"><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"><input type="hidden" name="feedback_type_id" value="<?php echo $typeId; ?>"><input type="hidden" name="feedback_item_id" value="<?php echo (int) $item['feedback_item_id']; ?>"><select name="rating_display" aria-label="Rating display for <?php echo htmlspecialchars($item['item_text']); ?>"><option value="words" <?php echo $item['rating_display'] === 'words' ? 'selected' : ''; ?>>Words</option><option value="emojis" <?php echo $item['rating_display'] === 'emojis' ? 'selected' : ''; ?>>Emojis</option><option value="stars" <?php echo $item['rating_display'] === 'stars' ? 'selected' : ''; ?>>Stars</option></select><button type="submit" class="button-secondary">Save</button></form><?php else: ?>—<?php endif; ?></td>
                <td><?php echo (int) $item['sort_order']; ?></td>
                <td><?php echo $item['is_active'] ? 'Active' : 'Disabled'; ?></td>
                <td><div class="feedback-item-actions">
                    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['feedback_csrf']); ?>"><input type="hidden" name="action" value="toggle_item"><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"><input type="hidden" name="feedback_type_id" value="<?php echo $typeId; ?>"><input type="hidden" name="feedback_item_id" value="<?php echo (int) $item['feedback_item_id']; ?>"><button type="submit" class="button-secondary"><?php echo $item['is_active'] ? 'Disable' : 'Enable'; ?></button></form>
                    <form method="post" onsubmit="return confirm('Permanently delete this feedback item? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['feedback_csrf']); ?>"><input type="hidden" name="action" value="delete_item"><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"><input type="hidden" name="feedback_type_id" value="<?php echo $typeId; ?>"><input type="hidden" name="feedback_item_id" value="<?php echo (int) $item['feedback_item_id']; ?>"><button type="submit" class="button-danger">Delete</button></form>
                </div></td>
            </tr><?php endforeach; ?></tbody>
        </table>
    </section>

    <section class="feedback-panel" id="send-feedback">
        <h2>Send Feedback</h2>
        <form method="get" class="feedback-selector">
            <label>Open event<select name="event_id"><?php foreach ($events as $event): ?><option value="<?php echo (int) $event['event_id']; ?>" <?php echo $eventId === (int) $event['event_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($event['event_title']); ?></option><?php endforeach; ?></select></label>
            <label>Feedback type<select name="feedback_type_id"><?php foreach ($types as $type): ?><option value="<?php echo (int) $type['feedback_type_id']; ?>" <?php echo $typeId === (int) $type['feedback_type_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($type['type_name']); ?></option><?php endforeach; ?></select></label>
            <button type="submit">Load</button>
        </form>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['feedback_csrf']); ?>"><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"><input type="hidden" name="feedback_type_id" value="<?php echo $typeId; ?>">
            <div class="feedback-send-actions"><button name="action" value="send_email">Email Selected</button><button name="action" value="send_email_all" class="button-secondary">Email All</button><button name="action" value="send_whatsapp">WhatsApp Selected</button><button name="action" value="send_whatsapp_all" class="button-secondary">WhatsApp All</button></div>
            <div class="event-report-table-wrap"><table><thead><tr><th><input type="checkbox" id="feedback_select_all"></th><th>Candidate</th><th>Email</th><th>WhatsApp</th><th>Feedback status</th><th>Link</th></tr></thead><tbody>
            <?php foreach ($registrations as $registration): ?><tr><td><input class="feedback-recipient" type="checkbox" name="registration_ids[]" value="<?php echo (int) $registration['registration_id']; ?>"></td><td><?php echo htmlspecialchars($registration['name']); ?></td><td><?php echo htmlspecialchars($registration['official_email']); ?></td><td><?php echo htmlspecialchars($registration['whatsapp_number'] ?: $registration['mobile']); ?></td><td><?php echo htmlspecialchars($registration['feedback_status'] ?: 'Not sent'); ?></td><td><?php if ($registration['feedback_id']): $link = buildFeedbackLink(['event_id' => $eventId, 'registration_id' => $registration['registration_id'], 'feedback_id' => $registration['feedback_id'], 'access_token' => $registration['access_token']]); ?><button type="button" class="button-secondary copy-feedback-link" data-link="<?php echo htmlspecialchars($link); ?>">Copy</button><?php else: ?>Created when sent<?php endif; ?></td></tr><?php endforeach; ?>
            <?php if (!$registrations): ?><tr><td colspan="6">No approved registrations are available for this event.</td></tr><?php endif; ?></tbody></table></div>
        </form>
    </section>

    <section class="feedback-panel">
        <h2>Received Feedback <small>(internal view)</small></h2>
        <p class="small">Participant identity is available only to administrators and is never shown on the public feedback form.</p>
        <div class="event-report-table-wrap"><table><thead><tr><th>Participant</th><th>Designation</th><th>Responses</th><th>Submitted</th></tr></thead><tbody>
        <?php foreach ($submissions as $submission): ?><tr><td><?php echo htmlspecialchars($submission['name']); ?></td><td><?php echo htmlspecialchars($submission['designation']); ?></td><td><dl class="feedback-answer-list"><?php foreach ($submission['answers'] as $answer): ?><div><dt><?php echo htmlspecialchars($answer['item']); ?></dt><dd><?php echo nl2br(htmlspecialchars($answer['answer'])); ?></dd></div><?php endforeach; ?></dl></td><td><?php echo htmlspecialchars($submission['submitted_at']); ?></td></tr><?php endforeach; ?>
        <?php if (!$submissions): ?><tr><td colspan="4">No feedback has been submitted for this selection.</td></tr><?php endif; ?></tbody></table></div>
    </section>
</div>
<script>
document.getElementById('feedback_select_all')?.addEventListener('change', function(){ document.querySelectorAll('.feedback-recipient').forEach(box => box.checked = this.checked); });
document.querySelectorAll('.copy-feedback-link').forEach(button => button.addEventListener('click', async function(){ await navigator.clipboard.writeText(this.dataset.link); this.textContent = 'Copied'; }));
</script>
<?php $content = ob_get_clean();
renderAdminLayout('Event Feedback', $content, ['current_path' => 'feedback', 'page_heading' => 'Event Feedback']);
