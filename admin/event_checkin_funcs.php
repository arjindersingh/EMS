<?php

require_once __DIR__ . '/settings_funcs.php';

function ensureEventAttendanceTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS event_attendance (
            attendance_id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            registration_id INT NOT NULL,
            checked_in_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            checked_in_by VARCHAR(100) NULL,
            checked_in_by_user_id INT NULL,
            mode VARCHAR(20) NOT NULL DEFAULT 'qr',
            welcome_message TEXT NULL,
            whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_attendance_event (event_id),
            INDEX idx_event_attendance_registration (registration_id)
        )
SQL);
}

function ensureEventAttendanceColumns(PDO $pdo): void
{
    // Keep older attendance tables compatible with the current check-in fields.
    $statement = $pdo->prepare(<<<'SQL'
        SELECT COUNT(*) AS cnt
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'event_attendance'
          AND column_name = :column
SQL
    );

    $columns = [
        'checked_in_by_user_id' => 'INT NULL AFTER checked_in_by',
        'mode' => "VARCHAR(20) NOT NULL DEFAULT 'qr' AFTER checked_in_by_user_id",
    ];
    foreach ($columns as $col => $definition) {
        $statement->execute([':column' => $col]);
        $row = $statement->fetch();
        $exists = (int) ($row['cnt'] ?? 0) > 0;
        if (!$exists) {
            $pdo->exec("ALTER TABLE event_attendance ADD COLUMN {$col} {$definition}");
        }
    }

    $indexStatement = $pdo->prepare(<<<'SQL'
        SELECT COUNT(*) AS cnt
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'event_attendance'
          AND index_name = 'uq_event_attendance_registration'
SQL
    );
    $indexStatement->execute();
    $indexRow = $indexStatement->fetch();
    if ((int) ($indexRow['cnt'] ?? 0) === 0) {
        // De-duplicate any pre-existing double check-ins before the unique index can be added.
        $pdo->exec(<<<'SQL'
            DELETE ea1 FROM event_attendance ea1
            INNER JOIN event_attendance ea2
                ON ea1.event_id = ea2.event_id
               AND ea1.registration_id = ea2.registration_id
               AND ea1.attendance_id > ea2.attendance_id
SQL
        );
        $pdo->exec('ALTER TABLE event_attendance ADD UNIQUE KEY uq_event_attendance_registration (event_id, registration_id)');
    }
}

function isAjaxRequest(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function parseQrPayload(string $payload): array
{
    $parts = [];
    if ($payload === '') {
        return $parts;
    }

    if (str_contains($payload, '?')) {
        [$payload] = explode('?', $payload, 2);
    }

    foreach (explode('&', $payload) as $segment) {
        if ($segment === '') {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $segment, 2), 2, '');
        $parts[urldecode($key)] = urldecode($value);
    }

    return $parts;
}

function getAllEvents(PDO $pdo): array
{
    $statement = $pdo->query('SELECT event_id, event_title, start_date FROM events ORDER BY start_date DESC, event_title ASC');
    return $statement ? $statement->fetchAll() : [];
}

function getEventById(PDO $pdo, int $eventId): ?array
{
    $statement = $pdo->prepare('SELECT event_id, event_title, start_date FROM events WHERE event_id = :event_id LIMIT 1');
    $statement->execute([':event_id' => $eventId]);
    $event = $statement->fetch();
    return $event ?: null;
}

function getRegistrationById(PDO $pdo, int $registrationId): ?array
{
    $statement = $pdo->prepare('SELECT registration_id, event_id, name, designation, institution_name, mobile, official_email, whatsapp_number, approval_status FROM event_registrations WHERE registration_id = :registration_id LIMIT 1');
    $statement->execute([':registration_id' => $registrationId]);
    $registration = $statement->fetch();
    return $registration ?: null;
}

function getApprovedRegistrationByPassCode(PDO $pdo, int $eventId, string $passCode): ?array
{
    $passCode = trim($passCode);
    if (!preg_match('/^\d{5}$/', $passCode)) {
        return null;
    }

    $statement = $pdo->prepare(<<<'SQL'
        SELECT registration_id, event_id, name, designation, institution_name, mobile,
               official_email, whatsapp_number, approval_status, pass_code
        FROM event_registrations
        WHERE event_id = :event_id
          AND pass_code = :pass_code
          AND approval_status = 'approved'
        LIMIT 1
SQL);
    $statement->execute([':event_id' => $eventId, ':pass_code' => $passCode]);
    $registration = $statement->fetch();
    return $registration ?: null;
}

function getApprovedRegistrationsForCheckin(PDO $pdo, int $eventId): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT
            er.registration_id,
            er.name,
            er.designation,
            er.institution_name,
            er.mobile,
            er.official_email,
            ea.checked_in_at,
            ea.mode AS checkin_mode
        FROM event_registrations er
        LEFT JOIN event_attendance ea
          ON ea.registration_id = er.registration_id
         AND ea.event_id = er.event_id
        WHERE er.event_id = :event_id
          AND er.approval_status = 'approved'
        ORDER BY er.name ASC, er.registration_id ASC
SQL);
    $statement->execute([':event_id' => $eventId]);
    return $statement->fetchAll() ?: [];
}

function findRegistrationsByName(PDO $pdo, int $eventId, string $searchTerm): array
{
    $term = '%' . trim($searchTerm) . '%';
    $statement = $pdo->prepare(<<<'SQL'
        SELECT registration_id, event_id, name, official_email, whatsapp_number
        FROM event_registrations
        WHERE event_id = :event_id
          AND name LIKE :name
        ORDER BY name ASC, registration_id ASC
        LIMIT 20
SQL);
    $statement->execute([':event_id' => $eventId, ':name' => $term]);
    return $statement->fetchAll() ?: [];
}

function getAttendanceRecord(PDO $pdo, int $eventId, int $registrationId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM event_attendance WHERE event_id = :event_id AND registration_id = :registration_id ORDER BY attendance_id DESC LIMIT 1');
    $statement->execute([':event_id' => $eventId, ':registration_id' => $registrationId]);
    $attendance = $statement->fetch();
    return $attendance ?: null;
}

function getRecentAttendance(PDO $pdo, int $eventId): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT ea.*, er.name
        FROM event_attendance ea
        INNER JOIN event_registrations er ON er.registration_id = ea.registration_id
        WHERE ea.event_id = :event_id
        ORDER BY ea.checked_in_at DESC, ea.attendance_id DESC
        LIMIT 20
SQL);
    $statement->execute([':event_id' => $eventId]);
    return $statement->fetchAll() ?: [];
}

function ensureCheckinAnnouncementSettings(PDO $pdo): void
{
    $defaults = [
        ['checkin_announcement_enabled', 'boolean', '1', 'Speak an announcement after a successful check-in.'],
        ['checkin_announcement_style', 'text', 'warm', 'Announcement variation: warm, formal, concise, or celebratory.'],
        ['checkin_announcement_language', 'text', 'en-IN', 'Preferred browser speech language/accent.'],
        ['checkin_announcement_gender', 'text', 'female', 'Preferred voice gender when a matching browser voice is available.'],
        ['checkin_announcement_rate', 'number', '0.9', 'Speech rate from 0.5 to 2.'],
        ['checkin_announcement_pitch', 'number', '1', 'Speech pitch from 0 to 2.'],
    ];

    foreach ($defaults as [$name, $type, $value, $description]) {
        if (!getSettingByName($pdo, $name)) {
            saveSetting($pdo, [
                'setting_name' => $name,
                'setting_type' => $type,
                'setting_value' => $value,
                'setting_description' => $description,
            ]);
        }
    }
}

function getCheckinAnnouncementConfig(PDO $pdo): array
{
    return [
        'enabled' => (bool) getSettingValue($pdo, 'checkin_announcement_enabled', true),
        'style' => (string) getSettingValue($pdo, 'checkin_announcement_style', 'warm'),
        'language' => (string) getSettingValue($pdo, 'checkin_announcement_language', 'en-IN'),
        'gender' => (string) getSettingValue($pdo, 'checkin_announcement_gender', 'female'),
        'rate' => max(0.5, min(2, (float) getSettingValue($pdo, 'checkin_announcement_rate', 0.9))),
        'pitch' => max(0, min(2, (float) getSettingValue($pdo, 'checkin_announcement_pitch', 1))),
    ];
}

function buildWelcomeAnnouncement(PDO $pdo, array $registration, array $event): string
{
    $name = trim((string) ($registration['name'] ?? 'guest'));
    $eventTitle = trim((string) ($event['event_title'] ?? 'the event'));
    $style = (string) getSettingValue($pdo, 'checkin_announcement_style', 'warm');

    return match ($style) {
        'formal' => 'Welcome, ' . $name . '. Your attendance for ' . $eventTitle . ' has been confirmed. We wish you a pleasant experience.',
        'concise' => 'Welcome ' . $name . '. You are checked in for ' . $eventTitle . '.',
        'celebratory' => 'A very warm welcome to ' . $name . '! We are delighted to have you at ' . $eventTitle . '. Let us make this a wonderful event!',
        default => 'Welcome ' . $name . '! We are delighted to have you with us at ' . $eventTitle . '. Please enjoy the event and make the most of your visit.',
    };
}

function normalizeWhatsappNumber(string $number): string
{
    $normalized = trim($number);
    if ($normalized === '') {
        return '';
    }

    $normalized = preg_replace('/[^0-9+]/', '', $normalized) ?? '';
    if ($normalized === '') {
        return '';
    }

    if (str_starts_with($normalized, '00')) {
        $normalized = '+' . substr($normalized, 2);
    }

    if (!str_starts_with($normalized, '+')) {
        $normalized = '+' . ltrim($normalized, '+');
    }

    // The configured WhatsApp gateway expects the local Indian mobile number.
    $normalized = ltrim($normalized, '+');
    return str_starts_with($normalized, '91') && strlen($normalized) === 12
        ? substr($normalized, 2)
        : $normalized;
}

function sendWhatsappCheckinMessage(PDO $pdo, array $registration, string $message): bool
{
    $to = normalizeWhatsappNumber(trim((string) ($registration['whatsapp_number'] ?? $registration['mobile'] ?? '')));
    if ($to === '') {
        return false;
    }

    $apiUrl = trim((string) getSettingValue($pdo, 'whatsapp_api_url', ''));
    if ($apiUrl === '') {
        return false;
    }

    $payload = [
        'user' => trim((string) getSettingValue($pdo, 'whatsapp_username', '')),
        'pass' => trim((string) getSettingValue($pdo, 'whatsapp_password', '')),
        'sender' => trim((string) getSettingValue($pdo, 'whatsapp_sender', '')),
        'phone' => $to,
        'text' => trim((string) getSettingValue($pdo, 'whatsapp_template_name', '')) ?: $message,
        'priority' => trim((string) getSettingValue($pdo, 'whatsapp_priority', 'wa')),
        'stype' => trim((string) getSettingValue($pdo, 'whatsapp_stype', 'normal')),
        'Params' => trim((string) getSettingValue($pdo, 'whatsapp_params', '')),
        'htype' => trim((string) getSettingValue($pdo, 'whatsapp_htype', 'image')),
        'url' => trim((string) getSettingValue($pdo, 'whatsapp_image_url', '')),
    ];

    $encodedPayload = http_build_query($payload);
    if ($encodedPayload === false) {
        return false;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = (string) curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return $error === '' && $statusCode >= 200 && $statusCode < 300;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $encodedPayload,
        ],
    ]);

    $result = @file_get_contents($apiUrl, false, $context);
    return $result !== false;
}

function recordAttendance(PDO $pdo, int $eventId, int $registrationId, string $mode, ?int $checkedInByUserId, string $checkedInBy, string $welcomeMessage): array
{
    $existing = getAttendanceRecord($pdo, $eventId, $registrationId);
    if ($existing) {
        return [
            'success' => false,
            'already_checked_in' => true,
            'message' => 'This candidate is already checked in.',
            'attendance' => $existing,
        ];
    }

    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO event_attendance (
            event_id,
            registration_id,
            checked_in_by,
            checked_in_by_user_id,
            mode,
            welcome_message,
            whatsapp_sent
        ) VALUES (
            :event_id,
            :registration_id,
            :checked_in_by,
            :checked_in_by_user_id,
            :mode,
            :welcome_message,
            :whatsapp_sent
        )
SQL);
    $whatsappSent = 0;
    $registration = getRegistrationById($pdo, $registrationId);
    if ($registration && !empty($registration['whatsapp_number'])) {
        $whatsappSent = sendWhatsappCheckinMessage($pdo, $registration, $welcomeMessage) ? 1 : 0;
    }

    try {
        $statement->execute([
            ':event_id' => $eventId,
            ':registration_id' => $registrationId,
            ':checked_in_by' => $checkedInBy === '' ? null : $checkedInBy,
            ':checked_in_by_user_id' => $checkedInByUserId,
            ':mode' => $mode,
            ':welcome_message' => $welcomeMessage,
            ':whatsapp_sent' => $whatsappSent,
        ]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            // Another device won the race and checked this registration in first.
            return [
                'success' => false,
                'already_checked_in' => true,
                'message' => 'This candidate is already checked in.',
                'attendance' => getAttendanceRecord($pdo, $eventId, $registrationId),
            ];
        }

        throw $exception;
    }

    $attendanceId = (int) $pdo->lastInsertId();
    $attendance = getAttendanceRecord($pdo, $eventId, $registrationId);

    return [
        'success' => true,
        'already_checked_in' => false,
        'message' => 'Attendance marked successfully.',
        'attendance' => $attendance,
        'announcement' => $welcomeMessage,
        'whatsapp_sent' => $whatsappSent,
        'attendance_id' => $attendanceId,
    ];
}
