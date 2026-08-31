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

revokeApiTokenByHash($pdo, (string) $admin['_token_hash']);

sendJson(200, [
    'success' => true,
    'message' => 'Signed out successfully.',
]);
