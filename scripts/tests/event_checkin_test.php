<?php

require_once __DIR__ . '/../../admin/event_checkin_funcs.php';

foreach (['0' => 0, '3' => 3, '2147483647' => 2147483647] as $input => $expected) {
    if (parseCheckinAccompanistCount((string) $input) !== $expected) {
        throw new RuntimeException('Valid accompanist count was rejected.');
    }
}
foreach ([null, [], '', '-1', '1.5', '2147483648'] as $input) {
    if (parseCheckinAccompanistCount($input) !== null) {
        throw new RuntimeException('Invalid accompanist count was accepted.');
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE event_registrations (
    registration_id INTEGER PRIMARY KEY, event_id INTEGER, name TEXT, designation TEXT,
    institution_name TEXT, mobile TEXT, official_email TEXT, whatsapp_number TEXT, approval_status TEXT
)');
$pdo->exec('CREATE TABLE event_attendance (
    attendance_id INTEGER PRIMARY KEY AUTOINCREMENT, event_id INTEGER, registration_id INTEGER,
    checked_in_at TEXT DEFAULT CURRENT_TIMESTAMP, checked_in_by TEXT, checked_in_by_user_id INTEGER,
    mode TEXT, accompanist_count INTEGER, welcome_message TEXT, whatsapp_sent INTEGER,
    UNIQUE (event_id, registration_id)
)');
$pdo->exec("INSERT INTO event_registrations (registration_id, event_id, name, approval_status)
    VALUES (1, 10, 'Guest One', 'approved'), (2, 10, 'Guest Two', 'approved'),
           (3, 10, 'Guest Three', 'approved')");

$result = recordAttendance($pdo, 10, 1, 'qr', null, 'admin', 'Welcome', 3);
if (!$result['success'] || (int) $result['attendance']['accompanist_count'] !== 3) {
    throw new RuntimeException('Accompanist count was not saved with attendance.');
}

$duplicate = recordAttendance($pdo, 10, 1, 'manual', null, 'admin', 'Welcome', 0);
if ($duplicate['success'] || !$duplicate['already_checked_in']
    || (int) $duplicate['attendance']['accompanist_count'] !== 3) {
    throw new RuntimeException('Duplicate check-in changed the existing count.');
}

$legacy = recordAttendance($pdo, 10, 2, 'manual', null, 'admin', 'Welcome');
if (!$legacy['success'] || $legacy['attendance']['accompanist_count'] !== null) {
    throw new RuntimeException('A check-in without a captured count must retain NULL.');
}

$zero = recordAttendance($pdo, 10, 3, 'Check in Code', null, 'admin', 'Welcome', 0);
if (!$zero['success'] || $zero['attendance']['accompanist_count'] === null
    || (int) $zero['attendance']['accompanist_count'] !== 0) {
    throw new RuntimeException('Zero accompanists must be recorded as zero.');
}

echo "Event check-in attendance tests passed.\n";
