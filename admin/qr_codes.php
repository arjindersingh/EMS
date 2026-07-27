<?php

require_once __DIR__ . '/settings_funcs.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    header('Location: /admin');
    exit;
}

function ensureQrDirectory(): string
{
    $directory = __DIR__ . '/../images/QR';
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    return $directory;
}

function ensureQrSettings(PDO $pdo): void
{
    $defaults = [
        ['whatsapp_api_url', 'text', ''],
        ['whatsapp_username', 'text', ''],
        ['whatsapp_password', 'text', ''],
        ['whatsapp_sender', 'text', ''],
        ['mail_from', 'text', 'no-reply@example.com'],
        ['mail_host', 'text', ''],
        ['mail_username', 'text', ''],
        ['mail_password', 'text', ''],
        ['mail_port', 'number', '587'],
        ['mail_encryption', 'text', 'tls'],
    ];

    foreach ($defaults as $default) {
        [$name, $type, $value] = $default;
        if (!getSettingByName($pdo, $name)) {
            saveSetting($pdo, [
                'setting_id' => 0,
                'setting_name' => $name,
                'setting_type' => $type,
                'setting_value' => $value,
                'setting_description' => 'Used by QR delivery features.',
            ]);
        }
    }
}

function buildQrFilePath(int $eventId, int $registrationId): string
{
    return ensureQrDirectory() . '/' . $eventId . '-' . $registrationId . '.png';
}

function generateQrCode(string $payload, string $outputPath): bool
{
    $encodedPayload = urlencode($payload);
    $urls = [
        'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . $encodedPayload,
        'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . $encodedPayload,
    ];

    foreach ($urls as $url) {
        $contents = @file_get_contents($url);
        if ($contents !== false && $contents !== '') {
            file_put_contents($outputPath, $contents);
            return true;
        }
    }

    return false;
}

function saveQrCodePath(PDO $pdo, int $registrationId, string $path): void
{
    $statement = $pdo->prepare('UPDATE event_registrations SET qr_code_path = :qr_code_path WHERE registration_id = :registration_id');
    $statement->execute([
        ':qr_code_path' => $path,
        ':registration_id' => $registrationId,
    ]);
}

function buildQrPayload(int $eventId, int $registrationId): string
{
    return 'event_id=' . $eventId . '&registration_id=' . $registrationId;
}

function createQrForRegistration(PDO $pdo, int $eventId, int $registrationId): ?string
{
    $outputPath = buildQrFilePath($eventId, $registrationId);
    $payload = buildQrPayload($eventId, $registrationId);

    if (!generateQrCode($payload, $outputPath)) {
        return null;
    }

    $relativePath = '/images/QR/' . basename($outputPath);
    saveQrCodePath($pdo, $registrationId, $relativePath);
    return $relativePath;
}

function sendEmailWithAttachment(PDO $pdo, string $to, string $subject, string $message, string $attachmentPath): bool
{
    $from = trim((string) getSettingValue($pdo, 'mail_from', 'no-reply@example.com'));
    $host = trim((string) getSettingValue($pdo, 'mail_host', ''));
    $username = trim((string) getSettingValue($pdo, 'mail_username', ''));
    $password = trim((string) getSettingValue($pdo, 'mail_password', ''));
    $port = (int) getSettingValue($pdo, 'mail_port', 587);
    $encryption = trim((string) getSettingValue($pdo, 'mail_encryption', 'tls'));

    $boundary = md5(uniqid((string) time(), true));
    $fileName = basename($attachmentPath);
    $fileContents = file_get_contents($attachmentPath);

    if ($fileContents === false) {
        return false;
    }

    $headers = [];
    $headers[] = 'From: ' . $from;
    $headers[] = 'Reply-To: ' . $from;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/mixed; boundary=' . $boundary;

    $body = [];
    $body[] = '--' . $boundary;
    $body[] = 'Content-Type: text/plain; charset=UTF-8';
    $body[] = '';
    $body[] = $message;
    $body[] = '';
    $body[] = '--' . $boundary;
    $body[] = 'Content-Type: application/png; name="' . $fileName . '"';
    $body[] = 'Content-Transfer-Encoding: base64';
    $body[] = 'Content-Disposition: attachment; filename="' . $fileName . '"';
    $body[] = '';
    $body[] = chunk_split(base64_encode($fileContents));
    $body[] = '--' . $boundary . '--';

    if ($host !== '') {
        $transport = 'smtp://' . $host . ':' . $port;
        ini_set('SMTP', $host);
        ini_set('smtp_port', (string) $port);
        ini_set('sendmail_from', $from);
        if ($username !== '') {
            ini_set('username', $username);
        }
        if ($password !== '') {
            ini_set('password', $password);
        }
        if ($encryption !== '') {
            ini_set('smtp_crypto', $encryption);
        }
    }

    return mail($to, $subject, implode("\r\n", $body), implode("\r\n", $headers));
}

function postJson(string $url, array $payload): bool
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_exec($ch);
        $error = curl_errno($ch);
        curl_close($ch);
        return $error === 0;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($payload),
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    return $result !== false;
}

function sendWhatsappMessage(PDO $pdo, string $to, string $message): bool
{
    $apiUrl = trim((string) getSettingValue($pdo, 'whatsapp_api_url', ''));
    if ($apiUrl === '') {
        return false;
    }

    $payload = [
        'username' => trim((string) getSettingValue($pdo, 'whatsapp_username', '')),
        'password' => trim((string) getSettingValue($pdo, 'whatsapp_password', '')),
        'sender' => trim((string) getSettingValue($pdo, 'whatsapp_sender', '')),
        'to' => $to,
        'message' => $message,
    ];

    return postJson($apiUrl, $payload);
}

$pdo = null;
$events = [];
$registrations = [];
$selectedEventId = 0;
$messageBody = 'Hello! Please find your QR code attached for the event.';
$adminError = '';
$adminSuccess = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_success']);

try {
    $pdo = createDbConnection();
    ensureSettingsTable($pdo);
    ensureQrSettings($pdo);
    $events = getAllEvents($pdo);
} catch (PDOException $exception) {
    $adminError = 'Database connection failed: ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $selectedEventId = (int) ($_POST['event_id'] ?? 0);
    $messageBody = trim((string) ($_POST['message_body'] ?? $messageBody));

    if ($action === 'load_event') {
        $selectedEventId = (int) ($_POST['event_id'] ?? 0);
    } elseif ($action === 'generate_qr' || $action === 'generate_qr_all') {
        $registrationIds = array_map('intval', (array) ($_POST['registration_ids'] ?? []));
        $registrationIds = array_values(array_filter($registrationIds));
        if ($selectedEventId > 0 && $pdo !== null) {
            $generated = 0;
            if ($action === 'generate_qr_all') {
                $registrations = getRegistrationsForEvent($pdo, $selectedEventId);
                foreach ($registrations as $registration) {
                    $result = createQrForRegistration($pdo, $selectedEventId, (int) $registration['registration_id']);
                    if ($result !== null) {
                        $generated++;
                    }
                }
            } elseif ($registrationIds !== []) {
                foreach ($registrationIds as $registrationId) {
                    $registration = getRegistrationById($pdo, $registrationId);
                    if ($registration && (int) $registration['event_id'] === $selectedEventId) {
                        $result = createQrForRegistration($pdo, $selectedEventId, $registrationId);
                        if ($result !== null) {
                            $generated++;
                        }
                    }
                }
            }
            $_SESSION['admin_success'] = $generated > 0 ? 'Generated ' . $generated . ' QR code(s).' : 'No QR codes were generated.';
            header('Location: /admin/qr_codes.php?event=' . $selectedEventId);
            exit;
        }
    } elseif ($action === 'send_email' || $action === 'send_email_all') {
        $registrationIds = array_map('intval', (array) ($_POST['registration_ids'] ?? []));
        $registrationIds = array_values(array_filter($registrationIds));
        $registrationsToProcess = [];
        if ($action === 'send_email_all') {
            $registrationsToProcess = getRegistrationsForEvent($pdo, $selectedEventId);
        } elseif ($registrationIds !== []) {
            foreach ($registrationIds as $registrationId) {
                $registration = getRegistrationById($pdo, $registrationId);
                if ($registration && (int) $registration['event_id'] === $selectedEventId) {
                    $registrationsToProcess[] = $registration;
                }
            }
        }

        $sent = 0;
        foreach ($registrationsToProcess as $registration) {
            $eventId = (int) $registration['event_id'];
            $registrationId = (int) $registration['registration_id'];
            $qrPath = buildQrFilePath($eventId, $registrationId);
            if (!is_file($qrPath)) {
                $created = createQrForRegistration($pdo, $eventId, $registrationId);
                if ($created === null) {
                    continue;
                }
            }
            $to = trim((string) ($registration['official_email'] ?? ''));
            if ($to === '') {
                continue;
            }
            $subject = 'Your QR code for event #' . $eventId;
            $body = $messageBody . "\n\nEvent ID: " . $eventId . "\nRegistration ID: " . $registrationId;
            if (sendEmailWithAttachment($pdo, $to, $subject, $body, $qrPath)) {
                $sent++;
            }
        }
        $_SESSION['admin_success'] = $sent > 0 ? 'Email sent to ' . $sent . ' recipient(s).' : 'No emails were sent.';
        header('Location: /admin/qr_codes.php?event=' . $selectedEventId);
        exit;
    } elseif ($action === 'send_whatsapp' || $action === 'send_whatsapp_all') {
        $registrationIds = array_map('intval', (array) ($_POST['registration_ids'] ?? []));
        $registrationIds = array_values(array_filter($registrationIds));
        $registrationsToProcess = [];
        if ($action === 'send_whatsapp_all') {
            $registrationsToProcess = getRegistrationsForEvent($pdo, $selectedEventId);
        } elseif ($registrationIds !== []) {
            foreach ($registrationIds as $registrationId) {
                $registration = getRegistrationById($pdo, $registrationId);
                if ($registration && (int) $registration['event_id'] === $selectedEventId) {
                    $registrationsToProcess[] = $registration;
                }
            }
        }

        $sent = 0;
        foreach ($registrationsToProcess as $registration) {
            $eventId = (int) $registration['event_id'];
            $registrationId = (int) $registration['registration_id'];
            $qrPath = buildQrFilePath($eventId, $registrationId);
            if (!is_file($qrPath)) {
                $created = createQrForRegistration($pdo, $eventId, $registrationId);
                if ($created === null) {
                    continue;
                }
            }
            $to = trim((string) ($registration['whatsapp_number'] ?? ''));
            if ($to === '') {
                continue;
            }
            $body = $messageBody . "\n\nEvent ID: " . $eventId . "\nRegistration ID: " . $registrationId;
            if (sendWhatsappMessage($pdo, $to, $body)) {
                $sent++;
            }
        }
        $_SESSION['admin_success'] = $sent > 0 ? 'WhatsApp messages sent to ' . $sent . ' recipient(s).' : 'No WhatsApp messages were sent.';
        header('Location: /admin/qr_codes.php?event=' . $selectedEventId);
        exit;
    }
}

if (isset($_GET['event']) && $pdo !== null) {
    $selectedEventId = (int) $_GET['event'];
}

if ($selectedEventId > 0 && $pdo !== null) {
    $registrations = getRegistrationsForEvent($pdo, $selectedEventId);
}

function getRegistrationById(PDO $pdo, int $registrationId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM event_registrations WHERE registration_id = :registration_id LIMIT 1');
    $statement->execute([':registration_id' => $registrationId]);
    $registration = $statement->fetch();
    return $registration ?: null;
}

function getRegistrationsForEvent(PDO $pdo, int $eventId): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT er.*, e.event_title
        FROM event_registrations er
        INNER JOIN events e ON e.event_id = er.event_id
        WHERE er.event_id = :event_id
        ORDER BY er.registration_id ASC
SQL);
    $statement->execute([':event_id' => $eventId]);
    return $statement->fetchAll() ?: [];
}

function getAllEvents(PDO $pdo): array
{
    $statement = $pdo->query('SELECT event_id, event_title, start_date FROM events ORDER BY start_date DESC, event_title ASC');
    return $statement ? $statement->fetchAll() : [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code Manager</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; line-height: 1.6; color: #222; }
        .card { max-width: 1300px; margin: 0 auto; padding: 1.5rem 2rem; border: 1px solid #d0d7de; border-radius: 8px; background: #f8f9fa; }
        .alert { padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: 6px; }
        .alert.error { background: #ffe8e8; color: #9c1c1c; }
        .alert.success { background: #e8f7eb; color: #20653d; }
        label { display: block; margin-top: 0.75rem; font-weight: bold; }
        input, textarea, select { width: 100%; padding: 0.65rem; margin: 0.35rem 0 0.8rem; box-sizing: border-box; }
        button { padding: 0.6rem 1rem; cursor: pointer; }
        .actions { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #d0d7de; padding: 0.6rem; text-align: left; vertical-align: top; }
        th { background: #eef2f7; }
        .muted { color: #666; }
        .small { font-size: 0.95rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>QR Code Manager</h1>
    <p>Generate and send QR codes to registered candidates for a selected event.</p>

    <?php if ($adminError !== ''): ?><div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($adminSuccess !== ''): ?><div class="alert success"><?php echo htmlspecialchars((string) $adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="load_event">
        <label for="event_id">Select Event</label>
        <select id="event_id" name="event_id" required>
            <option value="">Choose an event</option>
            <?php foreach ($events as $event): ?>
                <option value="<?php echo (int) $event['event_id']; ?>" <?php echo ((int) $selectedEventId === (int) $event['event_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($event['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="actions">
            <button type="submit">Load Event</button>
        </div>
    </form>

    <?php if ($selectedEventId > 0): ?>
        <form method="post">
            <input type="hidden" name="action" value="generate_qr">
            <input type="hidden" name="event_id" value="<?php echo (int) $selectedEventId; ?>">

            <label for="message_body">Common Message</label>
            <textarea id="message_body" name="message_body"><?php echo htmlspecialchars((string) $messageBody, ENT_QUOTES, 'UTF-8'); ?></textarea>

            <div class="actions">
                <button type="submit" name="action" value="generate_qr">Generate QR for selected</button>
                <button type="submit" name="action" value="generate_qr_all">Generate QR for all</button>
                <button type="submit" name="action" value="send_email">Send selected by email</button>
                <button type="submit" name="action" value="send_email_all">Send all by email</button>
                <button type="submit" name="action" value="send_whatsapp">Send selected by WhatsApp</button>
                <button type="submit" name="action" value="send_whatsapp_all">Send all by WhatsApp</button>
            </div>

            <h2>Registered Candidates</h2>
            <?php if (!empty($registrations)): ?>
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all" onchange="document.querySelectorAll('.candidate-checkbox').forEach(cb => cb.checked = this.checked)"></th>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>WhatsApp</th>
                            <th>QR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registrations as $registration): ?>
                            <tr>
                                <td>
                                    <input class="candidate-checkbox" type="checkbox" name="registration_ids[]" value="<?php echo (int) $registration['registration_id']; ?>">
                                </td>
                                <td><?php echo (int) $registration['registration_id']; ?></td>
                                <td><?php echo htmlspecialchars((string) ($registration['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($registration['official_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($registration['whatsapp_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php $qrPath = buildQrFilePath((int) $registration['event_id'], (int) $registration['registration_id']); ?>
                                    <?php if (is_file($qrPath)): ?>
                                        <a href="/images/QR/<?php echo rawurlencode(basename($qrPath)); ?>" target="_blank">View QR</a>
                                    <?php else: ?>
                                        <span class="muted">Not generated</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No registrations found for the selected event.</p>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <p><a href="/admin">Back to admin</a></p>
</div>
</body>
</html>
