<?php

require_once __DIR__ . '/settings_funcs.php';
require_once __DIR__ . '/registration_approval_funcs.php';
require_once __DIR__ . '/playback_funcs.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    header('Location: ' . buildUrl('admin'));
    exit;
}

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

    $statement->execute([
        ':event_id' => $eventId,
        ':registration_id' => $registrationId,
        ':checked_in_by' => $checkedInBy === '' ? null : $checkedInBy,
        ':checked_in_by_user_id' => $checkedInByUserId,
        ':mode' => $mode,
        ':welcome_message' => $welcomeMessage,
        ':whatsapp_sent' => $whatsappSent,
    ]);

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

$pdo = null;
$events = [];
$selectedEventId = 0;
$searchTerm = '';
$searchResults = [];
$recentAttendance = [];
$approvedRegistrations = [];
$adminError = '';
$adminSuccess = '';
$announcement = '';
$checkedInName = '';
$checkinMode = '';
$checkedInRegistrationId = 0;
$lookupCandidate = null;
$checkedInAttendance = null;
$announcementConfig = [
    'enabled' => true,
    'style' => 'warm',
    'language' => 'en-IN',
    'gender' => 'female',
    'rate' => 0.9,
    'pitch' => 1,
];
$playbackConfig = [
    'enabled' => false,
    'volume' => 0.35,
    'shuffle' => false,
    'repeat' => true,
    'fadeSeconds' => 1.5,
    'tracks' => [],
];

try {
    $pdo = createDbConnection();
    ensureSettingsTable($pdo);
    ensureCheckinAnnouncementSettings($pdo);
    ensurePlaybackSettings($pdo);
    ensureRegistrationApprovalStorage($pdo);
    ensureEventAttendanceTable($pdo);
    ensureEventAttendanceColumns($pdo);
    $announcementConfig = getCheckinAnnouncementConfig($pdo);
    $playbackConfig = getPlaybackConfig($pdo);
    $events = getAllEvents($pdo);
} catch (PDOException $exception) {
    $adminError = 'Database connection failed: ' . $exception->getMessage();
}

if (!empty($events) && $selectedEventId === 0) {
    $selectedEventId = (int) ($events[0]['event_id'] ?? 0);
}

if (isset($_GET['event']) && $pdo !== null) {
    $selectedEventId = (int) $_GET['event'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedEventId = (int) ($_POST['event_id'] ?? $selectedEventId);
    $searchTerm = trim((string) ($_POST['search_term'] ?? ''));
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_announcement_settings' && $pdo !== null) {
        $style = (string) ($_POST['announcement_style'] ?? 'warm');
        $language = (string) ($_POST['announcement_language'] ?? 'en-IN');
        $gender = (string) ($_POST['announcement_gender'] ?? 'female');
        $rate = max(0.5, min(2, (float) ($_POST['announcement_rate'] ?? 0.9)));
        $pitch = max(0, min(2, (float) ($_POST['announcement_pitch'] ?? 1)));
        $allowedStyles = ['warm', 'formal', 'concise', 'celebratory'];
        $allowedLanguages = ['en-IN', 'hi-IN', 'en-GB', 'en-US'];
        $allowedGenders = ['female', 'male', 'any'];

        foreach ([
            ['checkin_announcement_enabled', 'boolean', isset($_POST['announcement_enabled']) ? '1' : '0'],
            ['checkin_announcement_style', 'text', in_array($style, $allowedStyles, true) ? $style : 'warm'],
            ['checkin_announcement_language', 'text', in_array($language, $allowedLanguages, true) ? $language : 'en-IN'],
            ['checkin_announcement_gender', 'text', in_array($gender, $allowedGenders, true) ? $gender : 'female'],
            ['checkin_announcement_rate', 'number', (string) $rate],
            ['checkin_announcement_pitch', 'number', (string) $pitch],
        ] as [$name, $type, $value]) {
            $existing = getSettingByName($pdo, $name);
            saveSetting($pdo, [
                'setting_id' => (int) ($existing['setting_id'] ?? 0),
                'setting_name' => $name,
                'setting_type' => $type,
                'setting_value' => $value,
                'setting_description' => (string) ($existing['setting_description'] ?? ''),
            ]);
        }
        $announcementConfig = getCheckinAnnouncementConfig($pdo);
        $adminSuccess = 'Announcement preferences saved.';
    } elseif ($action === 'search_candidates' && $selectedEventId > 0 && $pdo !== null) {
        $searchResults = findRegistrationsByName($pdo, $selectedEventId, $searchTerm);
        if ($searchResults === []) {
            $adminError = 'No candidate matched your search.';
        } else {
            $adminSuccess = 'Found ' . count($searchResults) . ' matching candidate(s).';
        }
    } elseif ($action === 'lookup_checkin_code' && $selectedEventId > 0 && $pdo !== null) {
        $registration = getApprovedRegistrationByPassCode(
            $pdo,
            $selectedEventId,
            (string) ($_POST['checkin_code'] ?? '')
        );
        if ($registration) {
            $lookupCandidate = $registration;
            $adminSuccess = 'Registered candidate found.';
        } else {
            $adminError = 'No approved candidate matched this check-in code for the selected event.';
        }
    } elseif ($action === 'mark_manual' && $selectedEventId > 0 && $pdo !== null) {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        if ($registrationId > 0) {
            $registration = getRegistrationById($pdo, $registrationId);
            $event = getEventById($pdo, $selectedEventId);
            if ($registration
                && $event
                && (int) $registration['event_id'] === $selectedEventId
                && (string) ($registration['approval_status'] ?? '') === 'approved') {
                $announcement = buildWelcomeAnnouncement($pdo, $registration, $event);
                $adminUserId = isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
                $adminUserName = (string) ($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'admin');
                $result = recordAttendance($pdo, $selectedEventId, $registrationId, 'manual', $adminUserId, $adminUserName, $announcement);
                $checkedInName = trim((string) ($registration['name'] ?? ''));
                $checkinMode = 'manual';
                $checkedInRegistrationId = $registrationId;
                $checkedInAttendance = $result['attendance'] ?? null;
                if ($result['success']) {
                    $adminSuccess = 'Checked in ' . $checkedInName . ' manually.';
                } else {
                    $adminError = $result['message'] ?? 'Unable to mark attendance.';
                }
            } else {
                $adminError = 'Only an approved registration for the selected event can be checked in.';
            }
        } else {
            $adminError = 'Please choose a candidate first.';
        }
    } elseif ($action === 'mark_checkin_code' && $selectedEventId > 0 && $pdo !== null) {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $registration = $registrationId > 0 ? getRegistrationById($pdo, $registrationId) : null;
        $event = getEventById($pdo, $selectedEventId);
        if ($registration
            && $event
            && (int) $registration['event_id'] === $selectedEventId
            && (string) ($registration['approval_status'] ?? '') === 'approved') {
            $announcement = buildWelcomeAnnouncement($pdo, $registration, $event);
            $adminUserId = isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
            $adminUserName = (string) ($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'admin');
            $result = recordAttendance($pdo, $selectedEventId, $registrationId, 'Check in Code', $adminUserId, $adminUserName, $announcement);
            $checkedInName = trim((string) ($registration['name'] ?? ''));
            $checkinMode = 'Check in Code';
            $checkedInRegistrationId = $registrationId;
            $checkedInAttendance = $result['attendance'] ?? null;
            if ($result['success']) {
                $adminSuccess = 'Checked in ' . $checkedInName . ' using the check-in code.';
            } else {
                $adminError = $result['message'] ?? 'Unable to mark attendance.';
            }
        } else {
            $adminError = 'Only an approved registration for the selected event can be checked in.';
        }
    } elseif ($action === 'mark_qr' && $selectedEventId > 0 && $pdo !== null) {
        $payload = trim((string) ($_POST['qr_payload'] ?? ''));
        $scanData = parseQrPayload($payload);
        $registrationId = (int) ($scanData['registration_id'] ?? 0);
        $eventIdFromScan = (int) ($scanData['event_id'] ?? 0);
        $eventIdToUse = $eventIdFromScan > 0 ? $eventIdFromScan : $selectedEventId;

        if ($registrationId > 0 && $eventIdToUse > 0) {
            $registration = getRegistrationById($pdo, $registrationId);
            $event = getEventById($pdo, $eventIdToUse);
            if ($registration
                && $event
                && (int) $registration['event_id'] === $eventIdToUse
                && (string) ($registration['approval_status'] ?? '') === 'approved') {
                $announcement = buildWelcomeAnnouncement($pdo, $registration, $event);
                $adminUserId = isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
                $adminUserName = (string) ($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'admin');
                $result = recordAttendance($pdo, $eventIdToUse, $registrationId, 'qr', $adminUserId, $adminUserName, $announcement);
                $checkedInName = trim((string) ($registration['name'] ?? ''));
                $checkinMode = 'qr';
                $checkedInRegistrationId = $registrationId;
                $checkedInAttendance = $result['attendance'] ?? null;
                if ($result['success']) {
                    $adminSuccess = 'Checked in ' . $checkedInName . ' via QR.';
                    $announcement = $result['announcement'];
                } else {
                    $adminError = $result['message'] ?? 'Unable to mark attendance.';
                }
            } else {
                $adminError = 'The QR code does not belong to an approved registration for this event.';
            }
        } else {
            $adminError = 'QR payload was empty or invalid.';
        }
    }

    if ($selectedEventId > 0 && $pdo !== null) {
        $recentAttendance = getRecentAttendance($pdo, $selectedEventId);
    }

    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $adminError === '' && $adminSuccess !== '',
            'message' => $adminSuccess !== '' ? $adminSuccess : $adminError,
            'announcement' => $announcement,
            'name' => $checkedInName,
            'mode' => $checkinMode,
            'registration_id' => $checkedInRegistrationId,
            'candidate' => $lookupCandidate,
            'attendance' => $checkedInAttendance,
        ]);
        exit;
    }
}

if ($selectedEventId > 0 && $pdo !== null) {
    $recentAttendance = getRecentAttendance($pdo, $selectedEventId);
    $approvedRegistrations = getApprovedRegistrationsForCheckin($pdo, $selectedEventId);
}
ob_start();
?>
<div class="card">
        <?php if ($adminError !== ''): ?><div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($adminSuccess !== ''): ?><div class="alert success"><?php echo htmlspecialchars($adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <form method="get" class="actions checkin-event-form">
        <table class="checkin-event-table">
            <tr>
                <td class="checkin-instructions">
                    Scan QR code, search by name, mark attendance, and send a check-in message.
                </td>
                <th>
                    <label for="event_id">Select event</label>
                </th>
                <td class="checkin-event-select">
                    <select id="event_id" name="event" onchange="this.form.submit()">
                        <?php foreach ($events as $event): ?>
                            <option value="<?php echo (int) $event['event_id']; ?>" <?php echo (int) $event['event_id'] === $selectedEventId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($event['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
    </form>

    <details class="checkin-announcement-settings">
        <summary>Announcement voice and message settings</summary>
        <form method="post" class="announcement-settings-form">
            <input type="hidden" name="action" value="save_announcement_settings">
            <input type="hidden" name="event_id" value="<?php echo (int) $selectedEventId; ?>">
            <label class="announcement-toggle">
                <input type="checkbox" name="announcement_enabled" value="1" <?php echo !empty($announcementConfig['enabled']) ? 'checked' : ''; ?>>
                Speak after check-in
            </label>
            <label>Message
                <select name="announcement_style" id="announcementStyle">
                    <?php foreach (['warm' => 'Warm welcome', 'formal' => 'Formal confirmation', 'concise' => 'Short and concise', 'celebratory' => 'Celebratory welcome'] as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $announcementConfig['style'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Accent / language
                <select name="announcement_language" id="announcementLanguage">
                    <option value="en-IN" <?php echo $announcementConfig['language'] === 'en-IN' ? 'selected' : ''; ?>>Indian English</option>
                    <option value="hi-IN" <?php echo $announcementConfig['language'] === 'hi-IN' ? 'selected' : ''; ?>>Hindi (India)</option>
                    <option value="en-GB" <?php echo $announcementConfig['language'] === 'en-GB' ? 'selected' : ''; ?>>British English</option>
                    <option value="en-US" <?php echo $announcementConfig['language'] === 'en-US' ? 'selected' : ''; ?>>American English</option>
                </select>
            </label>
            <label>Voice preference
                <select name="announcement_gender" id="announcementGender">
                    <option value="female" <?php echo $announcementConfig['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                    <option value="male" <?php echo $announcementConfig['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                    <option value="any" <?php echo $announcementConfig['gender'] === 'any' ? 'selected' : ''; ?>>Any available</option>
                </select>
            </label>
            <label>Speed
                <input name="announcement_rate" id="announcementRate" type="number" min="0.5" max="2" step="0.1" value="<?php echo htmlspecialchars((string) $announcementConfig['rate'], ENT_QUOTES, 'UTF-8'); ?>">
            </label>
            <label>Pitch
                <input name="announcement_pitch" id="announcementPitch" type="number" min="0" max="2" step="0.1" value="<?php echo htmlspecialchars((string) $announcementConfig['pitch'], ENT_QUOTES, 'UTF-8'); ?>">
            </label>
            <div class="announcement-actions">
                <button type="button" id="previewAnnouncementBtn" class="button-secondary">Preview voice</button>
                <button type="submit">Save settings</button>
            </div>
        </form>
        <p class="muted announcement-note">Voice availability depends on the browser and device. The closest matching installed voice is used.</p>
    </details>

    <div class="actions checkin-playback-actions">
        <button type="button" id="startPlaybackBtn" class="button-secondary" <?php echo (!$playbackConfig['enabled'] || empty($playbackConfig['tracks'])) ? 'disabled title="Enable playback and select at least one track in dPlayback first"' : ''; ?>>Start background music</button>
        <button type="button" id="stopPlaybackBtn" class="button-secondary" disabled>Stop background music</button>
    </div>

    <div class="checkin-tabs" role="tablist" aria-label="Check-in methods">
        <button type="button" class="checkin-tab active" id="qr-tab" role="tab" aria-selected="true" aria-controls="qr-checkin-panel" data-checkin-tab="qr-checkin-panel">1. QR Check-in</button>
        <button type="button" class="checkin-tab" id="manual-tab" role="tab" aria-selected="false" aria-controls="manual-checkin-panel" data-checkin-tab="manual-checkin-panel">2. Manual Check-in</button>
        <button type="button" class="checkin-tab" id="code-tab" role="tab" aria-selected="false" aria-controls="code-checkin-panel" data-checkin-tab="code-checkin-panel">3. Check-in Code</button>
    </div>

    <div class="checkin-tab-panel" id="code-checkin-panel" role="tabpanel" aria-labelledby="code-tab" hidden>
        <div class="panel">
            <div class="manual-checkin-heading">
                <div>
                    <h2>Check in with registration code</h2>
                    <p class="muted">Enter the candidate's unique five-digit Pass Code from their Event Pass email.</p>
                </div>
                <form id="checkinCodeLookupForm" class="manual-checkin-search">
                    <input type="hidden" name="action" value="lookup_checkin_code">
                    <input type="hidden" name="event_id" value="<?php echo (int) $selectedEventId; ?>">
                    <label for="checkinCodeInput"><span>Check-in code</span></label>
                    <div class="checkin-code-entry">
                        <input id="checkinCodeInput" name="checkin_code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{5}" maxlength="5" placeholder="Enter 5-digit Pass Code" required>
                        <button type="submit">Find candidate</button>
                    </div>
                </form>
            </div>

            <div id="checkinCodeResult" class="checkin-code-result" hidden>
                <h3>Registered candidate</h3>
                <div class="checkin-code-details">
                    <div><span>Name</span><strong data-candidate-field="name"></strong></div>
                    <div><span>Designation</span><strong data-candidate-field="designation"></strong></div>
                    <div><span>Institution</span><strong data-candidate-field="institution_name"></strong></div>
                    <div><span>Mobile</span><strong data-candidate-field="mobile"></strong></div>
                    <div><span>Email</span><strong data-candidate-field="official_email"></strong></div>
                    <div><span>Registration ID</span><strong data-candidate-field="registration_id"></strong></div>
                </div>
                <form id="checkinCodeConfirmForm" method="post">
                    <input type="hidden" name="action" value="mark_checkin_code">
                    <input type="hidden" name="event_id" value="<?php echo (int) $selectedEventId; ?>">
                    <input type="hidden" id="checkinCodeRegistrationId" name="registration_id" value="">
                    <button type="submit">Check in candidate</button>
                </form>
            </div>
        </div>
    </div>

    <div class="checkin-tab-panel active" id="qr-checkin-panel" role="tabpanel" aria-labelledby="qr-tab">
        <div class="panel">
            <div class="checkin-camera-heading">
                <h2>Scan registration QR code</h2>
                <div class="actions">
                    <button type="button" id="startCameraBtn">Start camera</button>
                    <button type="button" id="stopCameraBtn">Stop camera</button>
                </div>
            </div>
            <video id="video" class="video-frame" width="360" height="203" autoplay playsinline muted></video>
            <canvas id="canvas" hidden></canvas>
            <form id="qrForm" method="post">
                <input type="hidden" name="action" value="mark_qr">
                <input type="hidden" name="event_id" value="<?php echo (int) $selectedEventId; ?>">
                <input type="hidden" id="qr_payload" name="qr_payload" value="">
            </form>
        </div>
    </div>

    <div class="checkin-tab-panel" id="manual-checkin-panel" role="tabpanel" aria-labelledby="manual-tab" hidden>
        <div class="panel">
            <div class="manual-checkin-heading">
                <div>
                    <h2>Approved registrations</h2>
                    <p class="muted">Search the list and check in an approved participant.</p>
                </div>
                <label class="manual-checkin-search" for="manualCheckinSearch">
                    <span>Search registrations</span>
                    <input id="manualCheckinSearch" type="search" placeholder="Type a name, institution, mobile, or email">
                </label>
            </div>

            <div class="table-responsive">
                <table class="manual-checkin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Designation</th>
                            <th>Institution</th>
                            <th>Mobile</th>
                            <th>Email</th>
                            <th>Status / Action</th>
                        </tr>
                    </thead>
                    <tbody id="manualCheckinRows">
                        <?php foreach ($approvedRegistrations as $candidate): ?>
                            <?php
                            $isCheckedIn = !empty($candidate['checked_in_at']);
                            $searchText = strtolower(implode(' ', [
                                (string) ($candidate['name'] ?? ''),
                                (string) ($candidate['designation'] ?? ''),
                                (string) ($candidate['institution_name'] ?? ''),
                                (string) ($candidate['mobile'] ?? ''),
                                (string) ($candidate['official_email'] ?? ''),
                            ]));
                            ?>
                            <tr data-registration-id="<?php echo (int) $candidate['registration_id']; ?>" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>">
                                <td><?php echo htmlspecialchars((string) ($candidate['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($candidate['designation'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($candidate['institution_name'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($candidate['mobile'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($candidate['official_email'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="manual-checkin-action">
                                    <?php if ($isCheckedIn): ?>
                                        <?php $savedMode = (string) ($candidate['checkin_mode'] ?? 'manual'); ?>
                                        <span class="checkin-complete">Checked in via <?php echo $savedMode === 'qr' ? 'QR' : ($savedMode === 'Check in Code' ? 'Check-in Code' : 'Manual'); ?></span>
                                    <?php else: ?>
                                        <form method="post" class="manual-checkin-form">
                                    <input type="hidden" name="action" value="mark_manual">
                                    <input type="hidden" name="event_id" value="<?php echo (int) $selectedEventId; ?>">
                                    <input type="hidden" name="registration_id" value="<?php echo (int) $candidate['registration_id']; ?>">
                                            <button type="submit">Check in</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (empty($approvedRegistrations)): ?>
                    <p class="muted">No approved registrations are available for this event.</p>
                <?php endif; ?>
                <p id="manualCheckinEmpty" class="muted" hidden>No registrations match your search.</p>
            </div>
        </div>
    </div>

    <div class="panel checkin-status-panel">
        <h2>Check-in status</h2>
        <div id="statusBox" class="result info" role="status" aria-live="polite" aria-atomic="true">
            <span class="status-icon" aria-hidden="true">●</span>
            <span class="status-message">Waiting for a QR scan, manual check-in, or check-in code.</span>
        </div>
    </div>

    <div class="panel" style="margin-top: 1rem;">
        <h2>Recent check-ins</h2>
            <table id="recentCheckinsTable" <?php echo empty($recentAttendance) ? 'hidden' : ''; ?>>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Mode</th>
                        <th>Checked in</th>
                        <th>WhatsApp</th>
                    </tr>
                </thead>
                <tbody id="recentCheckinsBody">
                    <?php foreach ($recentAttendance as $attendance): ?>
                        <tr data-attendance-id="<?php echo (int) ($attendance['attendance_id'] ?? 0); ?>">
                            <td><?php echo htmlspecialchars((string) ($attendance['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($attendance['mode'] ?? 'qr'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($attendance['checked_in_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo (int) ($attendance['whatsapp_sent'] ?? 0) === 1 ? 'Sent' : 'Not sent'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p id="recentCheckinsEmpty" class="muted" <?php echo !empty($recentAttendance) ? 'hidden' : ''; ?>>No check-ins yet for this event.</p>
    </div>
</div>
<audio id="checkinBackgroundAudio" preload="auto" aria-hidden="true"></audio>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
    const playbackConfig = <?php echo json_encode($playbackConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const ctx = canvas.getContext('2d');
    const qrPayloadInput = document.getElementById('qr_payload');
    const qrForm = document.getElementById('qrForm');
    const statusBox = document.getElementById('statusBox');
    const startCameraBtn = document.getElementById('startCameraBtn');
    const stopCameraBtn = document.getElementById('stopCameraBtn');
    const checkinTabs = document.querySelectorAll('[data-checkin-tab]');
    const checkinPanels = document.querySelectorAll('.checkin-tab-panel');
    const manualSearch = document.getElementById('manualCheckinSearch');
    const manualRows = document.querySelectorAll('#manualCheckinRows tr');
    const manualEmpty = document.getElementById('manualCheckinEmpty');
    const checkinCodeInput = document.getElementById('checkinCodeInput');
    const checkinCodeLookupForm = document.getElementById('checkinCodeLookupForm');
    const checkinCodeConfirmForm = document.getElementById('checkinCodeConfirmForm');
    const checkinCodeResult = document.getElementById('checkinCodeResult');
    const checkinCodeRegistrationId = document.getElementById('checkinCodeRegistrationId');
    const announcementEnabled = document.querySelector('[name="announcement_enabled"]');
    const announcementLanguage = document.getElementById('announcementLanguage');
    const announcementGender = document.getElementById('announcementGender');
    const announcementRate = document.getElementById('announcementRate');
    const announcementPitch = document.getElementById('announcementPitch');
    const previewAnnouncementBtn = document.getElementById('previewAnnouncementBtn');
    const recentCheckinsTable = document.getElementById('recentCheckinsTable');
    const recentCheckinsBody = document.getElementById('recentCheckinsBody');
    const recentCheckinsEmpty = document.getElementById('recentCheckinsEmpty');
    const backgroundAudio = document.getElementById('checkinBackgroundAudio');
    const startPlaybackBtn = document.getElementById('startPlaybackBtn');
    const stopPlaybackBtn = document.getElementById('stopPlaybackBtn');
    let stream = null;
    let scanning = false;
    let lastScanAt = 0;
    let statusTypingTimer = null;
    let playbackIndex = 0;
    let playbackFadeTimer = null;
    let announcementSequence = 0;
    let playbackPausedForAnnouncement = false;

    function playbackTracks() {
        const tracks = Array.isArray(playbackConfig.tracks) ? [...playbackConfig.tracks] : [];
        if (playbackConfig.shuffle) {
            for (let index = tracks.length - 1; index > 0; index--) {
                const swapIndex = Math.floor(Math.random() * (index + 1));
                [tracks[index], tracks[swapIndex]] = [tracks[swapIndex], tracks[index]];
            }
        }
        return tracks;
    }

    const activePlaybackTracks = playbackTracks();

    function fadePlaybackTo(targetVolume) {
        window.clearInterval(playbackFadeTimer);
        const target = Math.max(0, Math.min(1, targetVolume));
        const seconds = Math.max(0, Number(playbackConfig.fadeSeconds || 0));
        if (!seconds) {
            backgroundAudio.volume = target;
            return;
        }
        const start = backgroundAudio.volume;
        const steps = Math.max(1, Math.round(seconds * 20));
        let step = 0;
        playbackFadeTimer = window.setInterval(() => {
            step++;
            backgroundAudio.volume = start + ((target - start) * step / steps);
            if (step >= steps) window.clearInterval(playbackFadeTimer);
        }, 50);
    }

    async function startBackgroundPlayback() {
        if (!playbackConfig.enabled || !activePlaybackTracks.length || playbackPausedForAnnouncement) return;
        if (!backgroundAudio.src) {
            backgroundAudio.src = activePlaybackTracks[playbackIndex].url;
            backgroundAudio.load();
        }
        backgroundAudio.volume = 0;
        try {
            await backgroundAudio.play();
            fadePlaybackTo(Number(playbackConfig.volume ?? 0.35));
            if (startPlaybackBtn) startPlaybackBtn.disabled = true;
            if (stopPlaybackBtn) stopPlaybackBtn.disabled = false;
        } catch (error) {
            const message = error && error.message ? error.message : 'The browser could not start this audio file.';
            setStatus('Background music could not start: ' + message, 'error');
        }
    }

    function stopBackgroundPlayback() {
        window.clearInterval(playbackFadeTimer);
        backgroundAudio.pause();
        backgroundAudio.currentTime = 0;
        if (startPlaybackBtn) startPlaybackBtn.disabled = false;
        if (stopPlaybackBtn) stopPlaybackBtn.disabled = true;
    }

    backgroundAudio.addEventListener('ended', () => {
        if (!activePlaybackTracks.length) return;
        playbackIndex++;
        if (playbackIndex >= activePlaybackTracks.length) {
            if (!playbackConfig.repeat) return;
            playbackIndex = 0;
        }
        backgroundAudio.src = activePlaybackTracks[playbackIndex].url;
        startBackgroundPlayback();
    });

    backgroundAudio.addEventListener('error', () => {
        const mediaError = backgroundAudio.error;
        const reason = mediaError ? {
            1: 'the request was aborted',
            2: 'a network error occurred',
            3: 'the audio file could not be decoded',
            4: 'this audio format is not supported',
        }[mediaError.code] : 'an unknown media error occurred';
        setStatus('Background music failed: ' + reason + '.', 'error');
        if (startPlaybackBtn) startPlaybackBtn.disabled = false;
        if (stopPlaybackBtn) stopPlaybackBtn.disabled = true;
    });

    function pausePlaybackForAnnouncement() {
        window.clearInterval(playbackFadeTimer);
        playbackPausedForAnnouncement = playbackPausedForAnnouncement || !backgroundAudio.paused;
        backgroundAudio.pause();
    }

    function resumePlaybackAfterAnnouncement(sequence) {
        if (sequence !== announcementSequence) return;
        if (playbackPausedForAnnouncement) {
            playbackPausedForAnnouncement = false;
            startBackgroundPlayback();
        }
    }

    function setStatus(message, type = 'info') {
        window.clearInterval(statusTypingTimer);
        const normalizedType = type === 'error' || type === 'success' ? type : 'info';
        const icon = statusBox.querySelector('.status-icon');
        const messageElement = statusBox.querySelector('.status-message');
        statusBox.classList.remove('info', 'error', 'success', 'status-pop');
        statusBox.classList.add(normalizedType);
        icon.textContent = normalizedType === 'success' ? '✓' : (normalizedType === 'error' ? '!' : '●');
        messageElement.textContent = '';
        statusBox.classList.add('typing');
        void statusBox.offsetWidth;
        statusBox.classList.add('status-pop');

        const text = String(message || '');
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            messageElement.textContent = text;
            statusBox.classList.remove('typing');
            return;
        }
        let position = 0;
        const chunkSize = text.length > 140 ? 4 : (text.length > 70 ? 2 : 1);
        statusTypingTimer = window.setInterval(() => {
            position = Math.min(text.length, position + chunkSize);
            messageElement.textContent = text.slice(0, position);
            if (position >= text.length) {
                window.clearInterval(statusTypingTimer);
                statusBox.classList.remove('typing');
            }
        }, 18);
    }

    function markRegistrationCheckedIn(registrationId, mode) {
        const row = document.querySelector('#manualCheckinRows tr[data-registration-id="' + registrationId + '"]');
        const actionCell = row?.querySelector('.manual-checkin-action');
        if (actionCell) {
            const displayMode = mode === 'qr' ? 'QR' : (mode === 'Check in Code' ? 'Check-in Code' : 'Manual');
            actionCell.innerHTML = '<span class="checkin-complete">Checked in via ' + displayMode + '</span>';
        }
    }

    function addRecentCheckin(data) {
        const attendance = data.attendance;
        if (!attendance || !recentCheckinsBody) return;
        const attendanceId = String(attendance.attendance_id || '');
        if (attendanceId && recentCheckinsBody.querySelector('[data-attendance-id="' + attendanceId + '"]')) return;

        const row = document.createElement('tr');
        row.dataset.attendanceId = attendanceId;
        [
            data.name || '—',
            attendance.mode || data.mode || '—',
            attendance.checked_in_at || 'Just now',
            Number(attendance.whatsapp_sent || 0) === 1 ? 'Sent' : 'Not sent'
        ].forEach(value => {
            const cell = document.createElement('td');
            cell.textContent = value;
            row.appendChild(cell);
        });
        recentCheckinsBody.prepend(row);
        while (recentCheckinsBody.rows.length > 20) {
            recentCheckinsBody.deleteRow(-1);
        }
        if (recentCheckinsTable) recentCheckinsTable.hidden = false;
        if (recentCheckinsEmpty) recentCheckinsEmpty.hidden = true;
    }

    function selectAnnouncementVoice(language, gender) {
        const voices = window.speechSynthesis?.getVoices() || [];
        if (!voices.length) return null;
        const languagePrefix = language.toLowerCase().split('-')[0];
        const genderHints = gender === 'female'
            ? ['female', 'woman', 'neerja', 'heera', 'kalpana', 'veena']
            : ['male', 'man', 'prabhat', 'ravi', 'madhur'];
        const exactLanguage = voices.filter(voice => voice.lang.toLowerCase() === language.toLowerCase());
        const relatedLanguage = voices.filter(voice => voice.lang.toLowerCase().startsWith(languagePrefix));
        const candidates = exactLanguage.length ? exactLanguage : (relatedLanguage.length ? relatedLanguage : voices);
        if (gender !== 'any') {
            const genderMatch = candidates.find(voice =>
                genderHints.some(hint => voice.name.toLowerCase().includes(hint))
            );
            if (genderMatch) return genderMatch;
        }
        return candidates[0] || null;
    }

    function speakAnnouncement(message, force = false) {
        if (!message || !('speechSynthesis' in window) || (!force && !announcementEnabled?.checked)) return;
        const sequence = ++announcementSequence;
        pausePlaybackForAnnouncement();
        const language = announcementLanguage?.value || 'en-IN';
        const utterance = new SpeechSynthesisUtterance(message);
        utterance.lang = language;
        utterance.rate = Math.max(0.5, Math.min(2, Number(announcementRate?.value || 0.9)));
        utterance.pitch = Math.max(0, Math.min(2, Number(announcementPitch?.value || 1)));
        const voice = selectAnnouncementVoice(language, announcementGender?.value || 'female');
        if (voice) utterance.voice = voice;
        utterance.onend = () => resumePlaybackAfterAnnouncement(sequence);
        utterance.onerror = () => resumePlaybackAfterAnnouncement(sequence);
        window.speechSynthesis.cancel();
        window.speechSynthesis.speak(utterance);
    }

    previewAnnouncementBtn?.addEventListener('click', () => {
        speakAnnouncement('Welcome! Your attendance has been confirmed. We are delighted to have you with us.', true);
    });

    startPlaybackBtn?.addEventListener('click', startBackgroundPlayback);
    stopPlaybackBtn?.addEventListener('click', stopBackgroundPlayback);

    ['pointerdown', 'keydown', 'touchstart'].forEach(eventName => {
        document.addEventListener(eventName, startBackgroundPlayback, { once: true, passive: true });
    });
    startBackgroundPlayback();

    async function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setStatus('Camera access is not available in this browser.');
            return;
        }

        if (stream) {
            stream.getTracks().forEach(track => track.stop());
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                }
            });
        } catch (error) {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    }
                });
            } catch (fallbackError) {
                const message = fallbackError && fallbackError.message ? fallbackError.message : 'Camera access was denied or unavailable.';
                const secureMessage = window.isSecureContext ? '' : ' Note: Camera access requires HTTPS or localhost in many browsers.';
                setStatus('Camera failed to start: ' + message + secureMessage);
                return;
            }
        }

        try {
            video.srcObject = stream;
            video.setAttribute('playsinline', 'true');
            await video.play();
            scanning = true;
            setStatus('Camera is ready. Point it at a QR code.');
            if (typeof jsQR !== 'function') {
                setStatus('QR decode library is not loaded.');
                return;
            }
            scanLoop();
        } catch (error) {
            const message = error && error.message ? error.message : 'Camera playback failed.';
            setStatus('Camera playback failed: ' + message);
        }
    }

    function stopCamera() {
        scanning = false;
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
        if (video.srcObject) {
            video.srcObject = null;
        }
        setStatus('Camera stopped.');
    }

    function scanLoop() {
        if (!scanning) {
            return;
        }

        if (video.readyState >= video.HAVE_ENOUGH_DATA && video.videoWidth > 0 && video.videoHeight > 0) {
            const maxWidth = 640;
            const maxHeight = 480;
            const ratio = Math.min(maxWidth / video.videoWidth, maxHeight / video.videoHeight, 1);
            const scanWidth = Math.floor(video.videoWidth * ratio);
            const scanHeight = Math.floor(video.videoHeight * ratio);
            canvas.width = scanWidth;
            canvas.height = scanHeight;
            ctx.drawImage(video, 0, 0, scanWidth, scanHeight);
            const imageData = ctx.getImageData(0, 0, scanWidth, scanHeight);
            const code = jsQR(imageData.data, imageData.width, imageData.height);
            if (code && Date.now() - lastScanAt > 1500) {
                lastScanAt = Date.now();
                qrPayloadInput.value = code.data;
                setStatus('QR scanned. Marking attendance...');
                submitQrForm();
                setTimeout(() => {
                    if (scanning) {
                        scanLoop();
                    }
                }, 1200);
                return;
            }
        }

        requestAnimationFrame(scanLoop);
    }

    function submitQrForm() {
        const formData = new FormData(qrForm);
        (async () => {
            try {
                setStatus('Submitting check-in...');
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams(formData)
                });

                const text = await response.text();
                let data = null;
                try {
                    data = JSON.parse(text);
                } catch (err) {
                    // non-JSON response
                }

                if (!response.ok) {
                    const msg = data && data.message ? data.message : text || ('Status ' + response.status);
                    setStatus('Server error: ' + msg, 'error');
                    return;
                }

                if (!data) {
                    setStatus('Unexpected server response: ' + (text ? text.substring(0, 300) : 'empty'), 'error');
                    return;
                }

                if (data.success) {
                    setStatus(data.message + ' ' + (data.announcement || ''), 'success');
                    markRegistrationCheckedIn(data.registration_id, data.mode);
                    addRecentCheckin(data);
                    speakAnnouncement(data.announcement || 'Welcome!');
                } else {
                    setStatus(data.message || 'Unable to mark attendance.', 'error');
                }
            } catch (err) {
                console.error('Check-in request failed:', err);
                setStatus('The check-in request could not be completed. ' + (err && err.message ? err.message : ''), 'error');
            }
        })();
    }

    checkinTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            checkinTabs.forEach(item => {
                const active = item === tab;
                item.classList.toggle('active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            checkinPanels.forEach(panel => {
                const active = panel.id === tab.dataset.checkinTab;
                panel.classList.toggle('active', active);
                panel.hidden = !active;
            });
            if (tab.dataset.checkinTab === 'manual-checkin-panel') {
                stopCamera();
                manualSearch?.focus();
            } else if (tab.dataset.checkinTab === 'code-checkin-panel') {
                stopCamera();
                checkinCodeInput?.focus();
            }
        });
    });

    manualSearch?.addEventListener('input', () => {
        const term = manualSearch.value.toLowerCase().trim();
        let visibleCount = 0;
        manualRows.forEach(row => {
            const visible = !term || (row.dataset.search || '').includes(term);
            row.hidden = !visible;
            if (visible) visibleCount++;
        });
        if (manualEmpty) manualEmpty.hidden = visibleCount !== 0;
    });

    document.querySelectorAll('.manual-checkin-form').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            const row = form.closest('tr');
            if (button) {
                button.disabled = true;
                button.textContent = 'Checking in...';
            }

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new URLSearchParams(new FormData(form))
                });
                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (error) {
                    throw new Error('The server returned an invalid response.');
                }

                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to mark attendance.');
                }

                setStatus(data.message + ' ' + (data.announcement || ''), 'success');
                markRegistrationCheckedIn(data.registration_id, data.mode);
                addRecentCheckin(data);
                speakAnnouncement(data.announcement);
            } catch (error) {
                setStatus(error.message || 'Unable to mark attendance.', 'error');
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Check in';
                }
            }
        });
    });

    checkinCodeLookupForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const button = checkinCodeLookupForm.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = 'Finding...';
        }
        checkinCodeResult.hidden = true;

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams(new FormData(checkinCodeLookupForm))
            });
            const data = await response.json();
            if (!response.ok || !data.success || !data.candidate) {
                throw new Error(data.message || 'No registered candidate was found.');
            }

            Object.entries(data.candidate).forEach(([field, value]) => {
                const target = checkinCodeResult.querySelector('[data-candidate-field="' + field + '"]');
                if (target) target.textContent = value || '—';
            });
            checkinCodeRegistrationId.value = data.candidate.registration_id;
            checkinCodeResult.hidden = false;
            setStatus(data.message, 'success');
        } catch (error) {
            setStatus(error.message || 'Unable to look up the check-in code.', 'error');
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = 'Find candidate';
            }
        }
    });

    checkinCodeConfirmForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const button = checkinCodeConfirmForm.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = 'Checking in...';
        }

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams(new FormData(checkinCodeConfirmForm))
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to mark attendance.');
            }
            setStatus(data.message + ' ' + (data.announcement || ''), 'success');
            markRegistrationCheckedIn(data.registration_id, data.mode);
            addRecentCheckin(data);
            checkinCodeResult.hidden = true;
            checkinCodeLookupForm.reset();
            speakAnnouncement(data.announcement);
        } catch (error) {
            setStatus(error.message || 'Unable to mark attendance.', 'error');
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = 'Check in candidate';
            }
        }
    });

    startCameraBtn.addEventListener('click', startCamera);
    stopCameraBtn.addEventListener('click', stopCamera);
</script>
<?php
$content = ob_get_clean();

renderAdminLayout('Event Check-in', $content, [
    'current_path' => 'event_checkin',
    'page_heading' => 'Event Check-in',
    'body_class' => 'event-checkin-page',
]);
