<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(405, [
        'success' => false,
        'message' => 'Only GET requests are accepted.',
    ]);
}

$pdo = connectDatabaseOrFail();
requireApiAuth($pdo);

$eventId = (int) ($_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    sendJson(422, [
        'success' => false,
        'message' => 'A valid event_id is required.',
    ]);
}

sendJson(200, [
    'success' => true,
    'registrations' => getApprovedRegistrationsForCheckin($pdo, $eventId),
]);
