<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../events_funcs.php';
require_once __DIR__ . '/../registration_approval_funcs.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function sendApprovalJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendApprovalJson(405, [
        'success' => false,
        'message' => 'Only POST requests are accepted.',
    ]);
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    sendApprovalJson(401, [
        'success' => false,
        'message' => 'Your admin session has expired. Please sign in again.',
    ]);
}

try {
    $pdo = createDbConnection();
    ensureEventsTable($pdo);
    ensureRegistrationApprovalStorage($pdo);

    $eventId = (int) ($_POST['event_id'] ?? 0);
    if ($eventId <= 0 || !openEventExists($pdo, $eventId)) {
        throw new InvalidArgumentException('Please select a valid open event.');
    }

    $action = (string) ($_POST['action'] ?? '');
    $status = (string) ($_POST['status'] ?? '');
    $reviewedByUserId = isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
    $reviewedBy = (string) ($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'admin');
    $registrationId = null;

    if ($action === 'set_registration') {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        if ($registrationId <= 0 || !setRegistrationApproval($pdo, $eventId, $registrationId, $status, $reviewedByUserId, $reviewedBy)) {
            throw new InvalidArgumentException('The selected registration could not be updated.');
        }
        $message = 'Registration status updated.';
    } elseif ($action === 'set_all') {
        $updated = setAllRegistrationApprovals($pdo, $eventId, $status, $reviewedByUserId, $reviewedBy);
        $message = $updated . ' registration(s) updated.';
    } else {
        throw new InvalidArgumentException('Invalid approval action.');
    }

    sendApprovalJson(200, [
        'success' => true,
        'message' => $message,
        'status' => $status,
        'action' => $action,
        'registration_id' => $registrationId,
    ]);
} catch (InvalidArgumentException $exception) {
    sendApprovalJson(422, [
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    sendApprovalJson(500, [
        'success' => false,
        'message' => 'Unable to update the registration status.',
    ]);
}
