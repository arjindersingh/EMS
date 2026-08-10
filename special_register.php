<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/public_layout.php';
require_once __DIR__ . '/admin/registration_approval_funcs.php';
require_once __DIR__ . '/admin/registration_admin_funcs.php';

$pdo = createDbConnection();
ensureRegistrationApprovalStorage($pdo);
ensureAdminRegistrationTables($pdo);
$eventId = (int) ($_REQUEST['event_id'] ?? 0); $invitationId = (int) ($_REQUEST['invitation_id'] ?? 0); $token = trim((string) ($_REQUEST['token'] ?? ''));
$statement = $pdo->prepare(<<<'SQL'
    SELECT sri.*, e.event_title, e.event_status, e.start_date, e.end_date, e.venue_name
    FROM special_registration_invitations sri JOIN events e ON e.event_id = sri.event_id
    WHERE sri.invitation_id = :invitation_id AND sri.event_id = :event_id AND sri.access_token = :token
      AND sri.status NOT IN ('expired') AND (sri.expires_at IS NULL OR sri.expires_at >= NOW()) LIMIT 1
SQL);
$statement->execute([':invitation_id' => $invitationId, ':event_id' => $eventId, ':token' => $token]);
$invitation = $statement->fetch() ?: null;
$error = ''; $success = false;
$options = getRegistrationOptions($pdo, true);

if (!$invitation) { http_response_code(404); $error = 'This registration link is invalid or has expired.'; }
elseif ($invitation['status'] === 'completed') $success = true;
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        $_POST['event_id'] = $eventId;
        $registrationId = saveAdministrativeRegistration($pdo, $_POST);
        saveAdministrativePhotograph($pdo, $_FILES['photograph'] ?? [], $eventId, $registrationId, (string) $_POST['name']);
        $update = $pdo->prepare("UPDATE special_registration_invitations SET status = 'completed', registration_id = :registration_id, completed_at = NOW() WHERE invitation_id = :id AND status <> 'completed'");
        $update->execute([':registration_id' => $registrationId, ':id' => $invitationId]);
        if ($update->rowCount() !== 1) throw new RuntimeException('This invitation has already been used.');
        $pdo->commit(); $success = true;
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); $error = $exception->getMessage(); }
}
$defaults = $invitation ? ['name' => $invitation['candidate_name'], 'official_email' => $invitation['email'], 'whatsapp_number' => $invitation['whatsapp_number']] : [];
?><!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Special Event Registration</title><link rel="stylesheet" href="<?php echo htmlspecialchars(buildUrl('assets/css/styles.css')); ?>"></head><body class="public-page special-register-page">
<?php renderPublicHeader('register'); ?><main class="public-feedback-main"><article class="public-feedback-card special-registration-card">
<?php if ($success): ?><div class="feedback-thank-you"><span>✓</span><h1>Registration complete</h1><p>Your registration was saved successfully.</p></div>
<?php elseif (!$invitation): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php else: ?><header><span>Special registration</span><h1><?php echo htmlspecialchars($invitation['event_title']); ?></h1><p><?php echo !empty($invitation['start_date']) ? htmlspecialchars(date('d M, y', strtotime($invitation['start_date']))) : ''; ?> · <?php echo htmlspecialchars($invitation['venue_name']); ?></p></header>
<div class="feedback-privacy-note">This invitation was issued by the event administrator and remains valid regardless of the normal event registration schedule or event status.</div>
<?php if ($error): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"><input type="hidden" name="invitation_id" value="<?php echo $invitationId; ?>"><input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>"><?php renderAdministrativeRegistrationFields($options, $defaults); ?><label class="declaration-field"><input type="checkbox" name="declaration_accepted" value="1" required> I confirm that the provided information is correct.</label><button type="submit">Submit Registration</button></form>
<?php endif; ?></article></main><?php renderPublicFooter(); ?></body></html>
