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

function ensureBroadcastTables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS broadcast_messages (
            broadcast_id INT AUTO_INCREMENT PRIMARY KEY,
            channel VARCHAR(20) NOT NULL DEFAULT 'email',
            subject VARCHAR(255) NULL,
            message_body TEXT NOT NULL,
            recipient_count INT NOT NULL DEFAULT 0,
            sent_count INT NOT NULL DEFAULT 0,
            failed_count INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            created_by VARCHAR(100) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS broadcast_logs (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            broadcast_id INT NOT NULL,
            registration_id INT NULL,
            recipient_name VARCHAR(255) NULL,
            recipient_address VARCHAR(255) NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'email',
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            error_message TEXT NULL,
            sent_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_broadcast_id (broadcast_id),
            INDEX idx_registration_id (registration_id),
            CONSTRAINT fk_broadcast_logs_broadcast FOREIGN KEY (broadcast_id)
                REFERENCES broadcast_messages (broadcast_id)
                ON DELETE CASCADE
        )
SQL);
}

function getAllBroadcastMessages(PDO $pdo): array
{
    $statement = $pdo->query('SELECT * FROM broadcast_messages ORDER BY broadcast_id DESC');
    return $statement ? $statement->fetchAll() : [];
}

function getBroadcastLogs(PDO $pdo, int $broadcastId): array
{
    $statement = $pdo->prepare('SELECT * FROM broadcast_logs WHERE broadcast_id = :broadcast_id ORDER BY log_id ASC');
    $statement->execute([':broadcast_id' => $broadcastId]);
    return $statement->fetchAll() ?: [];
}

function getRegisteredUsers(PDO $pdo): array
{
    $statement = $pdo->query('SELECT registration_id, name, official_email, whatsapp_number FROM event_registrations ORDER BY registration_id ASC');
    return $statement ? $statement->fetchAll() : [];
}

function sendEmailMessage(PDO $pdo, string $to, string $subject, string $message): bool
{
    $from = trim((string) getSettingValue($pdo, 'mail_from', 'no-reply@example.com'));
    $host = trim((string) getSettingValue($pdo, 'mail_host', ''));
    $username = trim((string) getSettingValue($pdo, 'mail_username', ''));
    $password = trim((string) getSettingValue($pdo, 'mail_password', ''));
    $port = (int) getSettingValue($pdo, 'mail_port', 587);
    $encryption = trim((string) getSettingValue($pdo, 'mail_encryption', 'tls'));

    $headers = [];
    $headers[] = 'From: ' . $from;
    $headers[] = 'Reply-To: ' . $from;
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    if ($host !== '') {
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

    return mail($to, $subject, $message, implode("\r\n", $headers));
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

function buildBroadcastMessageBody(string $messageTemplate, array $registration): string
{
    $name = trim((string) ($registration['name'] ?? ''));
    $body = $messageTemplate;
    if ($name !== '') {
        $body = 'Dear ' . $name . ",\n\n" . $body;
    }

    return $body;
}

function createBroadcast(PDO $pdo, string $channel, ?string $subject, string $messageBody, string $createdBy): int
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO broadcast_messages (channel, subject, message_body, recipient_count, sent_count, failed_count, status, created_by)
        VALUES (:channel, :subject, :message_body, 0, 0, 0, 'queued', :created_by)
SQL);
    $statement->execute([
        ':channel' => $channel,
        ':subject' => $subject === '' ? null : $subject,
        ':message_body' => $messageBody,
        ':created_by' => $createdBy === '' ? null : $createdBy,
    ]);
    return (int) $pdo->lastInsertId();
}

function logBroadcastRecipient(PDO $pdo, int $broadcastId, ?int $registrationId, ?string $recipientName, ?string $recipientAddress, string $channel, string $status, ?string $errorMessage = null): void
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO broadcast_logs (broadcast_id, registration_id, recipient_name, recipient_address, channel, status, error_message, sent_at)
        VALUES (:broadcast_id, :registration_id, :recipient_name, :recipient_address, :channel, :status, :error_message, :sent_at)
SQL);
    $statement->execute([
        ':broadcast_id' => $broadcastId,
        ':registration_id' => $registrationId,
        ':recipient_name' => $recipientName === '' ? null : $recipientName,
        ':recipient_address' => $recipientAddress === '' ? null : $recipientAddress,
        ':channel' => $channel,
        ':status' => $status,
        ':error_message' => $errorMessage === '' ? null : $errorMessage,
        ':sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
    ]);
}

function updateBroadcastSummary(PDO $pdo, int $broadcastId, int $recipientCount, int $sentCount, int $failedCount, string $status): void
{
    $statement = $pdo->prepare(<<<'SQL'
        UPDATE broadcast_messages
        SET recipient_count = :recipient_count,
            sent_count = :sent_count,
            failed_count = :failed_count,
            status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE broadcast_id = :broadcast_id
SQL);
    $statement->execute([
        ':recipient_count' => $recipientCount,
        ':sent_count' => $sentCount,
        ':failed_count' => $failedCount,
        ':status' => $status,
        ':broadcast_id' => $broadcastId,
    ]);
}

function sendBroadcastToAllUsers(PDO $pdo, string $channel, string $subject, string $messageBody, string $createdBy): array
{
    $broadcastId = createBroadcast($pdo, $channel, $subject, $messageBody, $createdBy);
    $users = getRegisteredUsers($pdo);
    $recipientCount = 0;
    $sentCount = 0;
    $failedCount = 0;

    foreach ($users as $user) {
        $recipientAddress = '';
        if ($channel === 'whatsapp') {
            $recipientAddress = trim((string) ($user['whatsapp_number'] ?? ''));
        } else {
            $recipientAddress = trim((string) ($user['official_email'] ?? ''));
        }

        if ($recipientAddress === '') {
            logBroadcastRecipient($pdo, $broadcastId, (int) ($user['registration_id'] ?? 0), (string) ($user['name'] ?? ''), '', $channel, 'skipped', 'Missing recipient address');
            continue;
        }

        $recipientCount++;
        $personalizedBody = buildBroadcastMessageBody($messageBody, $user);
        $success = false;
        $errorMessage = null;

        try {
            if ($channel === 'whatsapp') {
                $success = sendWhatsappMessage($pdo, $recipientAddress, $personalizedBody);
            } else {
                $success = sendEmailMessage($pdo, $recipientAddress, $subject, $personalizedBody);
            }
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }

        if ($success) {
            $sentCount++;
            logBroadcastRecipient($pdo, $broadcastId, (int) ($user['registration_id'] ?? 0), (string) ($user['name'] ?? ''), $recipientAddress, $channel, 'sent');
        } else {
            $failedCount++;
            logBroadcastRecipient($pdo, $broadcastId, (int) ($user['registration_id'] ?? 0), (string) ($user['name'] ?? ''), $recipientAddress, $channel, 'failed', $errorMessage ?? 'Delivery failed');
        }
    }

    $status = 'completed';
    if ($sentCount === 0 && $failedCount > 0) {
        $status = 'failed';
    } elseif ($sentCount > 0 && $failedCount > 0) {
        $status = 'partial';
    }

    updateBroadcastSummary($pdo, $broadcastId, $recipientCount, $sentCount, $failedCount, $status);

    return [
        'broadcast_id' => $broadcastId,
        'recipient_count' => $recipientCount,
        'sent_count' => $sentCount,
        'failed_count' => $failedCount,
        'status' => $status,
    ];
}

$pdo = null;
$adminError = '';
$adminSuccess = '';
$broadcasts = [];
$channel = 'email';
$subject = '';
$messageBody = 'Hello! This is a common update for all registered users.';

try {
    $pdo = createDbConnection();
    ensureSettingsTable($pdo);
    ensureBroadcastTables($pdo);
    $broadcasts = getAllBroadcastMessages($pdo);
} catch (PDOException $exception) {
    $adminError = 'Database connection failed: ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $channel = in_array($_POST['channel'] ?? 'email', ['email', 'whatsapp'], true) ? (string) ($_POST['channel'] ?? 'email') : 'email';
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $messageBody = trim((string) ($_POST['message_body'] ?? ''));
    $createdBy = trim((string) ($_SESSION['admin_username'] ?? 'admin'));

    if ($messageBody === '') {
        $adminError = 'Please enter a message to send.';
    } elseif ($pdo !== null) {
        try {
            $result = sendBroadcastToAllUsers($pdo, $channel, $subject, $messageBody, $createdBy);
            $adminSuccess = 'Broadcast queued for ' . $result['recipient_count'] . ' recipient(s). Sent: ' . $result['sent_count'] . ', Failed: ' . $result['failed_count'] . '.';
            $broadcasts = getAllBroadcastMessages($pdo);
        } catch (Throwable $exception) {
            $adminError = 'Unable to send broadcast: ' . $exception->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Broadcast Messages</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; line-height: 1.6; color: #222; }
        .card { max-width: 1100px; margin: 0 auto; padding: 1.5rem 2rem; border: 1px solid #d0d7de; border-radius: 8px; background: #f8f9fa; }
        .alert { padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: 6px; }
        .alert.error { background: #ffe8e8; color: #9c1c1c; }
        .alert.success { background: #e8f7eb; color: #20653d; }
        label { display: block; margin-top: 0.75rem; font-weight: bold; }
        input, textarea, select { width: 100%; padding: 0.65rem; margin: 0.35rem 0 0.8rem; box-sizing: border-box; }
        textarea { min-height: 140px; }
        button { padding: 0.6rem 1rem; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #d0d7de; padding: 0.6rem; text-align: left; vertical-align: top; }
        th { background: #eef2f7; }
        .muted { color: #666; }
        .actions { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>Broadcast Messages</h1>
    <p>Send a common message to all registered users by email or WhatsApp and keep a delivery log.</p>

    <?php if ($adminError !== ''): ?><div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($adminSuccess !== ''): ?><div class="alert success"><?php echo htmlspecialchars($adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <form method="post">
        <label for="channel">Delivery channel</label>
        <select id="channel" name="channel">
            <option value="email" <?php echo $channel === 'email' ? 'selected' : ''; ?>>Email</option>
            <option value="whatsapp" <?php echo $channel === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp</option>
        </select>

        <label for="subject">Subject (email only)</label>
        <input id="subject" name="subject" type="text" value="<?php echo htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'); ?>">

        <label for="message_body">Message</label>
        <textarea id="message_body" name="message_body" required><?php echo htmlspecialchars($messageBody, ENT_QUOTES, 'UTF-8'); ?></textarea>

        <div class="actions">
            <button type="submit">Send to all registered users</button>
            <a href="/admin"><button type="button">Back to admin</button></a>
        </div>
    </form>

    <h2>Previous broadcasts</h2>
    <?php if (!empty($broadcasts)): ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Channel</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Recipients</th>
                    <th>Sent</th>
                    <th>Failed</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($broadcasts as $broadcast): ?>
                    <tr>
                        <td><?php echo (int) $broadcast['broadcast_id']; ?></td>
                        <td><?php echo htmlspecialchars((string) ($broadcast['channel'] ?? 'email'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($broadcast['subject'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($broadcast['status'] ?? 'queued'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int) ($broadcast['recipient_count'] ?? 0); ?></td>
                        <td><?php echo (int) ($broadcast['sent_count'] ?? 0); ?></td>
                        <td><?php echo (int) ($broadcast['failed_count'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars((string) ($broadcast['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="muted">No broadcasts have been sent yet.</p>
    <?php endif; ?>
</div>
</body>
</html>
