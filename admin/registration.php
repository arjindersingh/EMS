<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/registration_approval_funcs.php';
require_once __DIR__ . '/registration_admin_funcs.php';
require_once __DIR__ . '/feedback_funcs.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_authenticated'])) { header('Location: ' . buildUrl('admin')); exit; }
if (empty($_SESSION['admin_registration_csrf'])) $_SESSION['admin_registration_csrf'] = bin2hex(random_bytes(24));
$pdo = createDbConnection();
ensureRegistrationApprovalStorage($pdo);
ensureSettingsTable($pdo);
ensureAdminRegistrationTables($pdo);
$success = (string) ($_SESSION['admin_success'] ?? ''); $error = (string) ($_SESSION['admin_error'] ?? '');
unset($_SESSION['admin_success'], $_SESSION['admin_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_registration_csrf'], (string) ($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('Invalid request token.'); }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'direct_register') {
            $pdo->beginTransaction();
            $registrationId = saveAdministrativeRegistration($pdo, $_POST);
            saveAdministrativePhotograph($pdo, $_FILES['photograph'] ?? [], (int) $_POST['event_id'], $registrationId, (string) $_POST['name']);
            $pdo->commit();
            $_SESSION['admin_success'] = 'Registration saved successfully. Reference ID: ' . $registrationId;
        } elseif (in_array($action, ['send_email', 'send_whatsapp', 'create_link'], true)) {
            $eventId = (int) ($_POST['event_id'] ?? 0); $name = trim((string) ($_POST['candidate_name'] ?? '')); $email = trim((string) ($_POST['email'] ?? '')); $whatsapp = trim((string) ($_POST['whatsapp_number'] ?? ''));
            $invitation = createSpecialRegistrationInvitation($pdo, $eventId, $name, $email, $whatsapp);
            $link = buildSpecialRegistrationLink($invitation);
            $eventStatement = $pdo->prepare('SELECT event_title FROM events WHERE event_id = :id'); $eventStatement->execute([':id' => $eventId]); $eventTitle = (string) $eventStatement->fetchColumn();
            $message = "Dear " . ($name ?: 'Participant') . ",\n\nYou are invited to register for {$eventTitle}. This special link remains available even when normal registration is closed.\n\n{$link}\n\nThe link expires in 7 days.";
            $sent = false; $deliveryError = '';
            if ($action === 'send_email') $sent = sendFeedbackEmail($pdo, $email, 'Registration link for ' . $eventTitle, $message, $deliveryError);
            elseif ($action === 'send_whatsapp') $sent = sendFeedbackWhatsapp($pdo, $whatsapp, $message);
            if ($action === 'create_link') {
                $_SESSION['admin_success'] = 'Registration link created: ' . $link;
            } elseif ($sent) {
                $pdo->prepare("UPDATE special_registration_invitations SET status = 'sent', sent_at = NOW() WHERE invitation_id = :id")->execute([':id' => $invitation['invitation_id']]);
                $_SESSION['admin_success'] = ucfirst(str_replace('send_', '', $action)) . ' registration link sent successfully.';
            } else $_SESSION['admin_error'] = 'The registration link was created, but delivery failed. ' . $deliveryError . ' Link: ' . $link;
        } elseif ($action === 'add_option') {
            $group = (string) ($_POST['option_group'] ?? ''); $value = trim((string) ($_POST['option_value'] ?? ''));
            $allowed = ['salutation','designation','teacher_post','role','institution_level','state','country'];
            if (!in_array($group, $allowed, true) || $value === '') throw new InvalidArgumentException('Choose a valid option group and enter a value.');
            $pdo->prepare('INSERT INTO registration_form_options (option_group, option_value, sort_order) VALUES (:group_name, :value, :sort_order)')->execute([':group_name' => $group, ':value' => $value, ':sort_order' => (int) ($_POST['sort_order'] ?? 0)]);
            $_SESSION['admin_success'] = 'Dropdown option added.';
        } elseif ($action === 'toggle_option') {
            $pdo->prepare('UPDATE registration_form_options SET is_active = 1 - is_active WHERE option_id = :id')->execute([':id' => (int) ($_POST['option_id'] ?? 0)]);
            $_SESSION['admin_success'] = 'Dropdown option updated.';
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['admin_error'] = $exception->getMessage();
    }
    header('Location: ' . buildUrl('admin/registration.php')); exit;
}

$events = $pdo->query('SELECT event_id, event_title, event_status, start_date FROM events ORDER BY start_date DESC, event_title')->fetchAll() ?: [];
$options = getRegistrationOptions($pdo, false); $activeOptions = getRegistrationOptions($pdo, true);
$invitations = $pdo->query('SELECT sri.*, e.event_title FROM special_registration_invitations sri JOIN events e ON e.event_id = sri.event_id ORDER BY sri.invitation_id DESC LIMIT 50')->fetchAll() ?: [];
$csrf = htmlspecialchars($_SESSION['admin_registration_csrf']);
$eventSelect = static function (array $events, string $name = 'event_id'): void { ?><select name="<?php echo $name; ?>" required><option value="">Select event</option><?php foreach ($events as $event): ?><option value="<?php echo (int) $event['event_id']; ?>"><?php echo htmlspecialchars($event['event_title'] . ' [' . $event['event_status'] . ']'); ?></option><?php endforeach; ?></select><?php };

ob_start(); ?>
<div class="admin-registration-page">
<?php if ($success): ?><div class="alert success admin-registration-message"><?php echo htmlspecialchars($success); ?></div><?php endif; ?><?php if ($error): ?><div class="alert error admin-registration-message"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<section class="feedback-panel"><h2>Direct Registration</h2><p class="small">Administrators can register a participant for any event. Event status and registration schedule are intentionally not checked.</p>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="direct_register"><label>Event<?php $eventSelect($events); ?></label><?php renderAdministrativeRegistrationFields($activeOptions); ?><button type="submit">Save Registration</button></form></section>

<section class="feedback-panel"><h2>Send Open Registration Link</h2><form method="post" class="special-registration-link-form"><input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>"><label>Event<?php $eventSelect($events); ?></label><label>Candidate name<input name="candidate_name"></label><label>Email<input type="email" name="email"></label><label>WhatsApp<input name="whatsapp_number"></label><div class="wide feedback-send-actions"><button name="action" value="send_email">Send Email</button><button name="action" value="send_whatsapp">Send WhatsApp</button><button name="action" value="create_link" class="button-secondary">Create Link Only</button></div></form>
<div class="event-report-table-wrap"><table><thead><tr><th>Event</th><th>Candidate</th><th>Contact</th><th>Status</th><th>Expires</th><th>Link</th></tr></thead><tbody><?php foreach ($invitations as $invitation): $link = buildSpecialRegistrationLink($invitation); ?><tr><td><?php echo htmlspecialchars($invitation['event_title']); ?></td><td><?php echo htmlspecialchars($invitation['candidate_name']); ?></td><td><?php echo htmlspecialchars($invitation['email'] ?: $invitation['whatsapp_number']); ?></td><td><?php echo htmlspecialchars($invitation['status']); ?></td><td><?php echo htmlspecialchars($invitation['expires_at']); ?></td><td><button type="button" class="button-secondary copy-registration-link" data-link="<?php echo htmlspecialchars($link); ?>">Copy</button></td></tr><?php endforeach; ?></tbody></table></div></section>

<section class="feedback-panel"><h2>Registration Dropdown Options</h2><form method="post" class="registration-option-form"><input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="add_option"><label>Dropdown<select name="option_group"><option value="salutation">Salutation</option><option value="designation">Designation</option><option value="teacher_post">Post</option><option value="role">Role</option><option value="institution_level">Institution Level</option><option value="state">State</option><option value="country">Country</option></select></label><label>New value<input name="option_value" required></label><label>Order<input type="number" name="sort_order" value="0"></label><button type="submit">Add Option</button></form>
<?php foreach ($options as $group => $values): ?><h3><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $group))); ?></h3><div class="registration-option-list"><?php foreach ($values as $option): ?><form method="post"><input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="toggle_option"><input type="hidden" name="option_id" value="<?php echo (int) $option['option_id']; ?>"><span><?php echo htmlspecialchars($option['option_value']); ?></span><button class="button-secondary"><?php echo $option['is_active'] ? 'Disable' : 'Enable'; ?></button></form><?php endforeach; ?></div><?php endforeach; ?></section>
</div><script>document.querySelectorAll('.copy-registration-link').forEach(button=>button.addEventListener('click',async function(){await navigator.clipboard.writeText(this.dataset.link);this.textContent='Copied';}));</script>
<?php $content = ob_get_clean(); renderAdminLayout('Admin Registration', $content, ['current_path' => 'admin_registration', 'page_heading' => 'Admin Registration']);
