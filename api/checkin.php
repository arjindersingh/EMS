<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(405, [
        'success' => false,
        'message' => 'Only POST requests are accepted.',
    ]);
}

$pdo = connectDatabaseOrFail();
$admin = requireApiAuth($pdo);
$adminUserId = (int) $admin['admin_user_id'];
$adminUserName = (string) ($admin['name'] ?? $admin['username'] ?? 'admin');

$body = readJsonOrFormBody();
$action = (string) ($body['action'] ?? '');
$eventId = (int) ($body['event_id'] ?? 0);

if ($eventId <= 0) {
    sendJson(422, [
        'success' => false,
        'message' => 'A valid event_id is required.',
    ]);
}

$event = getEventById($pdo, $eventId);
if ($event === null) {
    sendJson(404, [
        'success' => false,
        'message' => 'Event not found.',
    ]);
}

switch ($action) {
    case 'mark_qr':
        $payload = trim((string) ($body['qr_payload'] ?? ''));
        $scanData = parseQrPayload($payload);
        $registrationId = (int) ($scanData['registration_id'] ?? 0);
        $eventIdFromScan = (int) ($scanData['event_id'] ?? 0);
        $eventIdToUse = $eventIdFromScan > 0 ? $eventIdFromScan : $eventId;

        if ($registrationId <= 0 || $eventIdToUse <= 0) {
            sendJson(422, [
                'success' => false,
                'message' => 'QR payload was empty or invalid.',
            ]);
        }

        $eventForScan = $eventIdToUse === $eventId ? $event : getEventById($pdo, $eventIdToUse);
        $registration = getRegistrationById($pdo, $registrationId);

        if (!$registration
            || !$eventForScan
            || (int) $registration['event_id'] !== $eventIdToUse
            || (string) ($registration['approval_status'] ?? '') !== 'approved') {
            sendJson(422, [
                'success' => false,
                'message' => 'The QR code does not belong to an approved registration for this event.',
            ]);
        }

        $announcement = buildWelcomeAnnouncement($pdo, $registration, $eventForScan);
        $result = recordAttendance($pdo, $eventIdToUse, $registrationId, 'qr', $adminUserId, $adminUserName, $announcement);

        sendJson($result['success'] ? 200 : 409, [
            'success' => $result['success'],
            'message' => $result['success'] ? ('Checked in ' . trim((string) $registration['name']) . ' via QR.') : $result['message'],
            'announcement' => $result['success'] ? $announcement : '',
            'name' => trim((string) $registration['name']),
            'mode' => 'qr',
            'registration_id' => $registrationId,
            'candidate' => null,
            'attendance' => $result['attendance'] ?? null,
        ]);
        break;

    case 'mark_manual':
        $registrationId = (int) ($body['registration_id'] ?? 0);
        if ($registrationId <= 0) {
            sendJson(422, [
                'success' => false,
                'message' => 'Please choose a candidate first.',
            ]);
        }

        $registration = getRegistrationById($pdo, $registrationId);
        if (!$registration
            || (int) $registration['event_id'] !== $eventId
            || (string) ($registration['approval_status'] ?? '') !== 'approved') {
            sendJson(422, [
                'success' => false,
                'message' => 'Only an approved registration for the selected event can be checked in.',
            ]);
        }

        $announcement = buildWelcomeAnnouncement($pdo, $registration, $event);
        $result = recordAttendance($pdo, $eventId, $registrationId, 'manual', $adminUserId, $adminUserName, $announcement);

        sendJson($result['success'] ? 200 : 409, [
            'success' => $result['success'],
            'message' => $result['success'] ? ('Checked in ' . trim((string) $registration['name']) . ' manually.') : $result['message'],
            'announcement' => $result['success'] ? $announcement : '',
            'name' => trim((string) $registration['name']),
            'mode' => 'manual',
            'registration_id' => $registrationId,
            'candidate' => null,
            'attendance' => $result['attendance'] ?? null,
        ]);
        break;

    case 'lookup_checkin_code':
        $registration = getApprovedRegistrationByPassCode($pdo, $eventId, (string) ($body['checkin_code'] ?? ''));
        if ($registration === null) {
            sendJson(404, [
                'success' => false,
                'message' => 'No approved candidate matched this check-in code for the selected event.',
            ]);
        }

        sendJson(200, [
            'success' => true,
            'message' => 'Registered candidate found.',
            'announcement' => '',
            'name' => trim((string) $registration['name']),
            'mode' => '',
            'registration_id' => (int) $registration['registration_id'],
            'candidate' => $registration,
            'attendance' => null,
        ]);
        break;

    case 'mark_checkin_code':
        $registrationId = (int) ($body['registration_id'] ?? 0);
        $registration = $registrationId > 0 ? getRegistrationById($pdo, $registrationId) : null;

        if (!$registration
            || (int) $registration['event_id'] !== $eventId
            || (string) ($registration['approval_status'] ?? '') !== 'approved') {
            sendJson(422, [
                'success' => false,
                'message' => 'Only an approved registration for the selected event can be checked in.',
            ]);
        }

        $announcement = buildWelcomeAnnouncement($pdo, $registration, $event);
        $result = recordAttendance($pdo, $eventId, $registrationId, 'Check in Code', $adminUserId, $adminUserName, $announcement);

        sendJson($result['success'] ? 200 : 409, [
            'success' => $result['success'],
            'message' => $result['success'] ? ('Checked in ' . trim((string) $registration['name']) . ' using the check-in code.') : $result['message'],
            'announcement' => $result['success'] ? $announcement : '',
            'name' => trim((string) $registration['name']),
            'mode' => 'Check in Code',
            'registration_id' => $registrationId,
            'candidate' => null,
            'attendance' => $result['attendance'] ?? null,
        ]);
        break;

    case 'recent':
        sendJson(200, [
            'success' => true,
            'message' => '',
            'recent_attendance' => getRecentAttendance($pdo, $eventId),
        ]);
        break;

    default:
        sendJson(422, [
            'success' => false,
            'message' => 'Invalid check-in action.',
        ]);
}
