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

sendJson(200, [
    'success' => true,
    'events' => getAllEvents($pdo),
]);
