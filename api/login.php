<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(405, [
        'success' => false,
        'message' => 'Only POST requests are accepted.',
    ]);
}

$body = readJsonOrFormBody();
$login = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');
$deviceLabel = trim((string) ($body['device_label'] ?? ''));

if ($login === '' || $password === '') {
    sendJson(422, [
        'success' => false,
        'message' => 'Username and password are required.',
    ]);
}

$pdo = connectDatabaseOrFail();

$admin = getAdminUserByLogin($pdo, $login);
if (!$admin || !password_verify($password, (string) $admin['password_hash'])) {
    sendJson(401, [
        'success' => false,
        'message' => 'Invalid username or password.',
    ]);
}

$token = createApiToken($pdo, (int) $admin['admin_user_id'], $deviceLabel);

sendJson(200, [
    'success' => true,
    'message' => 'Signed in successfully.',
    'token' => $token,
    'admin' => [
        'admin_user_id' => (int) $admin['admin_user_id'],
        'name' => $admin['name'],
        'username' => $admin['username'],
        'role' => $admin['role'],
    ],
]);
