<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/events_funcs.php';
require_once __DIR__ . '/registration_approval_funcs.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAjaxRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && (
        ($_POST['ajax'] ?? '') === '1'
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    );

if (!($_SESSION['admin_authenticated'] ?? false)) {
    if ($isAjaxRequest) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'Your admin session has expired. Please sign in again.',
        ]);
        exit;
    }
    $_SESSION['admin_error'] = 'Please sign in to access the admin panel.';
    header('Location: ' . buildUrl('admin'));
    exit;
}

$pdo = null;
$events = [];
$registrations = [];
$selectedEventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$includeEventsWithoutRegistration = (string) ($_GET['include_non_registration_events'] ?? $_POST['include_non_registration_events'] ?? '') === '1';
$errorMessage = '';
$successMessage = (string) ($_SESSION['approval_success'] ?? '');
unset($_SESSION['approval_success']);

try {
    $pdo = createDbConnection();
    ensureEventsTable($pdo);
    ensureRegistrationApprovalStorage($pdo);
    $events = getOpenEventsForApproval($pdo, $includeEventsWithoutRegistration);

    if ($selectedEventId === 0 && !empty($events)) {
        $selectedEventId = (int) $events[0]['event_id'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!openEventExists($pdo, $selectedEventId, $includeEventsWithoutRegistration)) {
            throw new InvalidArgumentException('Please select a valid open event.');
        }

        $action = (string) ($_POST['action'] ?? '');
        $reviewedByUserId = isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
        $reviewedBy = (string) ($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'admin');

        if ($action === 'set_registration') {
            $registrationId = (int) ($_POST['registration_id'] ?? 0);
            $status = (string) ($_POST['status'] ?? '');
            if ($registrationId <= 0 || !setRegistrationApproval($pdo, $selectedEventId, $registrationId, $status, $reviewedByUserId, $reviewedBy)) {
                throw new InvalidArgumentException('The selected registration could not be updated.');
            }
            $_SESSION['approval_success'] = 'Registration status updated.';
            $responseMessage = 'Registration status updated.';
        } elseif ($action === 'set_all') {
            $status = (string) ($_POST['status'] ?? '');
            $updated = setAllRegistrationApprovals($pdo, $selectedEventId, $status, $reviewedByUserId, $reviewedBy);
            $_SESSION['approval_success'] = $updated . ' registration(s) updated.';
            $responseMessage = $updated . ' registration(s) updated.';
        } else {
            throw new InvalidArgumentException('Invalid approval action.');
        }

        if ($isAjaxRequest) {
            unset($_SESSION['approval_success']);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'message' => $responseMessage,
                'status' => $status,
                'action' => $action,
                'registration_id' => $registrationId ?? null,
            ]);
            exit;
        }

        $redirectQuery = ['event_id' => $selectedEventId];
        if ($includeEventsWithoutRegistration) $redirectQuery['include_non_registration_events'] = '1';
        header('Location: ' . buildUrl('admin/registration_approvals.php') . '?' . http_build_query($redirectQuery));
        exit;
    }

    if ($selectedEventId > 0 && openEventExists($pdo, $selectedEventId, $includeEventsWithoutRegistration)) {
        $registrations = getEventRegistrationsForApproval($pdo, $selectedEventId);
    } elseif ($selectedEventId > 0) {
        $errorMessage = 'The selected event is not available for approval.';
        $selectedEventId = 0;
    }
} catch (InvalidArgumentException $exception) {
    if ($isAjaxRequest) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
        exit;
    }
    $errorMessage = $exception->getMessage();
} catch (PDOException $exception) {
    if ($isAjaxRequest) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Unable to update the registration status.']);
        exit;
    }
    $errorMessage = 'Unable to load registration approvals: ' . $exception->getMessage();
} catch (Throwable $exception) {
    if ($isAjaxRequest) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'An unexpected error occurred while updating the registration.']);
        exit;
    }
    $errorMessage = 'Unable to load registration approvals: ' . $exception->getMessage();
}

$counts = ['pending' => 0, 'approved' => 0, 'denied' => 0];
foreach ($registrations as $registration) {
    $status = (string) ($registration['approval_status'] ?? 'pending');
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}

ob_start();
?>
<p>Select an open event, then approve or deny its registrations.</p>

<div id="approval-message" aria-live="polite">
<?php if ($errorMessage !== ''): ?>
    <div class="alert error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<?php if ($successMessage !== ''): ?>
    <div class="alert success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
</div>

<form method="get" class="approval-event-picker">
    <label for="event_id">Open event</label>
    <select id="event_id" name="event_id" onchange="this.form.submit()">
        <option value="">Select an event</option>
        <?php foreach ($events as $event): ?>
            <option value="<?php echo (int) $event['event_id']; ?>" <?php echo $selectedEventId === (int) $event['event_id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string) $event['event_title'] . ((string) ($event['event_code'] ?? '') !== '' ? ' (' . $event['event_code'] . ')' : ''), ENT_QUOTES, 'UTF-8'); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <label class="approval-include-events"><input type="checkbox" name="include_non_registration_events" value="1" <?php echo $includeEventsWithoutRegistration ? 'checked' : ''; ?> onchange="this.form.submit()"> Include open events that do not require registration</label>
</form>

<?php if ($selectedEventId > 0): ?>
    <div class="approval-summary">
        <span><strong id="approval-total-count"><?php echo count($registrations); ?></strong> Total</span>
        <span><strong id="approval-pending-count"><?php echo $counts['pending']; ?></strong> Pending</span>
        <span class="approved"><strong id="approval-approved-count"><?php echo $counts['approved']; ?></strong> Approved</span>
        <span class="denied"><strong id="approval-denied-count"><?php echo $counts['denied']; ?></strong> Denied</span>
    </div>

    <div class="approval-bulk-actions">
        <form method="post" action="<?php echo htmlspecialchars(buildUrl('admin/registration_approvals.php'), ENT_QUOTES, 'UTF-8'); ?>" class="approval-ajax-form" data-confirm="Approve all registrations for this event?">
            <input type="hidden" name="action" value="set_all">
            <input type="hidden" name="event_id" value="<?php echo $selectedEventId; ?>">
            <?php if ($includeEventsWithoutRegistration): ?><input type="hidden" name="include_non_registration_events" value="1"><?php endif; ?>
            <input type="hidden" name="status" value="approved">
            <button type="submit" class="approval-button approve">Approve All</button>
        </form>
        <form method="post" action="<?php echo htmlspecialchars(buildUrl('admin/registration_approvals.php'), ENT_QUOTES, 'UTF-8'); ?>" class="approval-ajax-form" data-confirm="Deny all registrations for this event?">
            <input type="hidden" name="action" value="set_all">
            <input type="hidden" name="event_id" value="<?php echo $selectedEventId; ?>">
            <?php if ($includeEventsWithoutRegistration): ?><input type="hidden" name="include_non_registration_events" value="1"><?php endif; ?>
            <input type="hidden" name="status" value="denied">
            <button type="submit" class="approval-button deny">Deny All</button>
        </form>
    </div>

    <?php if (empty($registrations)): ?>
        <div class="approval-empty">No registrations have been received for this event.</div>
    <?php else: ?>
        <div class="schedule-responsive-table approval-table">
            <table>
                <thead>
                    <tr><th>Name</th><th>Designation</th><th>Institution</th><th>Decision</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $registration): ?>
                        <?php $status = (string) ($registration['approval_status'] ?? 'pending'); ?>
                        <tr data-approval-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                            <td data-label="Name"><?php echo htmlspecialchars((string) $registration['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Designation"><?php echo htmlspecialchars((string) ($registration['designation'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Institution"><?php echo htmlspecialchars((string) ($registration['institution_name'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Decision">
                                <form method="post" action="<?php echo htmlspecialchars(buildUrl('admin/registration_approvals.php'), ENT_QUOTES, 'UTF-8'); ?>" class="approval-toggle approval-ajax-form">
                                    <input type="hidden" name="action" value="set_registration">
                                    <input type="hidden" name="event_id" value="<?php echo $selectedEventId; ?>">
                                    <?php if ($includeEventsWithoutRegistration): ?><input type="hidden" name="include_non_registration_events" value="1"><?php endif; ?>
                                    <input type="hidden" name="registration_id" value="<?php echo (int) $registration['registration_id']; ?>">
                                    <button type="submit" name="status" value="approved" class="toggle-option approve <?php echo $status === 'approved' ? 'active' : ''; ?>">Approve</button>
                                    <button type="submit" name="status" value="denied" class="toggle-option deny <?php echo $status === 'denied' ? 'active' : ''; ?>">Deny</button>
                                </form>
                                <?php if ($status === 'pending'): ?><span class="pending-label">Pending</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php elseif (empty($events)): ?>
    <div class="approval-empty">There are no open events available.</div>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var forms = document.querySelectorAll('.approval-ajax-form');
    var messageBox = document.getElementById('approval-message');
    var approvalAjaxUrl = new URL('ajax/registration_approvals_ajax.php', window.location.href).href;

    function showMessage(message, type) {
        if (!messageBox) return;
        messageBox.innerHTML = '';
        var alert = document.createElement('div');
        alert.className = 'alert ' + type;
        alert.textContent = message;
        messageBox.appendChild(alert);
    }

    function refreshCounts() {
        var rows = document.querySelectorAll('[data-approval-status]');
        var counts = { pending: 0, approved: 0, denied: 0 };
        rows.forEach(function (row) {
            var status = row.dataset.approvalStatus || 'pending';
            if (Object.prototype.hasOwnProperty.call(counts, status)) counts[status]++;
        });

        var total = document.getElementById('approval-total-count');
        var pending = document.getElementById('approval-pending-count');
        var approved = document.getElementById('approval-approved-count');
        var denied = document.getElementById('approval-denied-count');
        if (total) total.textContent = rows.length;
        if (pending) pending.textContent = counts.pending;
        if (approved) approved.textContent = counts.approved;
        if (denied) denied.textContent = counts.denied;
    }

    function updateRow(row, status) {
        if (!row) return;
        row.dataset.approvalStatus = status;
        row.querySelectorAll('.toggle-option').forEach(function (button) {
            button.classList.toggle('active', button.value === status);
        });
        var pendingLabel = row.querySelector('.pending-label');
        if (pendingLabel) pendingLabel.remove();
    }

    forms.forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            var confirmation = form.dataset.confirm;
            if (confirmation && !window.confirm(confirmation)) return;

            var submitter = event.submitter;
            var data = new FormData(form);
            data.set('ajax', '1');
            if (submitter && submitter.name) data.set(submitter.name, submitter.value);

            var buttons = form.querySelectorAll('button');
            buttons.forEach(function (button) { button.disabled = true; });

            try {
                var response = await fetch(approvalAjaxUrl, {
                    method: 'POST',
                    body: data,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                });
                var responseText = await response.text();
                var result;
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    if (response.redirected || responseText.trim().startsWith('<')) {
                        throw new Error('The server returned a web page instead of an AJAX response. Your session may have expired; please refresh and sign in again.');
                    }
                    throw new Error('The server returned an invalid response. Please try again.');
                }
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to update the registration status.');
                }

                if (result.action === 'set_all') {
                    document.querySelectorAll('[data-approval-status]').forEach(function (row) {
                        updateRow(row, result.status);
                    });
                } else {
                    updateRow(form.closest('tr'), result.status);
                }
                refreshCounts();
                showMessage(result.message, 'success');
            } catch (error) {
                showMessage(error.message || 'Unable to update the registration status.', 'error');
            } finally {
                buttons.forEach(function (button) { button.disabled = false; });
            }
        });
    });
});
</script>
<?php
$content = ob_get_clean();

renderAdminLayout('Registration Approvals', $content, [
    'current_path' => 'registration_approvals',
    'page_heading' => 'Registration Approvals',
]);
