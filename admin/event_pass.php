<?php

require_once __DIR__ . '/settings_funcs.php';
require_once __DIR__ . '/registration_approval_funcs.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    header('Location: ' . buildUrl('admin'));
    exit;
}

function ensureQrDirectory(): string
{
    $directory = __DIR__ . '/../assets/images/QR';
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    return $directory;
}

function isAjaxRequest(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function sendJsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

function ensureEventPassHistoryTable(PDO $pdo): void
{
    $oldTableExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'qr_history'"
    )->fetchColumn() > 0;
    $newTableExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'event_pass_history'"
    )->fetchColumn() > 0;

    if ($oldTableExists && !$newTableExists) {
        $pdo->exec('RENAME TABLE qr_history TO event_pass_history');
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS event_pass_history (
            event_pass_history_id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            registration_id INT NOT NULL,
            channel ENUM('email','whatsapp') NOT NULL,
            recipient VARCHAR(255) NOT NULL,
            status ENUM('sent','failed') NOT NULL,
            details TEXT NULL,
            message TEXT NULL,
            qr_path VARCHAR(500) NULL,
            pass_code CHAR(5) NULL,
            sent_by_user_id INT NULL,
            sent_by_username VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_pass_history_event (event_id),
            INDEX idx_event_pass_history_registration (registration_id),
            INDEX idx_event_pass_history_channel (channel)
        )
SQL
    );

    $oldIdColumnExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'event_pass_history' AND column_name = 'qr_history_id'"
    )->fetchColumn() > 0;
    if ($oldIdColumnExists) {
        $pdo->exec('ALTER TABLE event_pass_history CHANGE qr_history_id event_pass_history_id INT NOT NULL AUTO_INCREMENT');
    }

    $passCodeColumnExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'event_pass_history' AND column_name = 'pass_code'"
    )->fetchColumn() > 0;
    if (!$passCodeColumnExists) {
        $pdo->exec('ALTER TABLE event_pass_history ADD COLUMN pass_code CHAR(5) NULL AFTER qr_path');
    }
}

function recordEventPassHistory(PDO $pdo, array $data): int
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO event_pass_history (
            event_id,
            registration_id,
            channel,
            recipient,
            status,
            details,
            message,
            qr_path,
            pass_code,
            sent_by_user_id,
            sent_by_username
        ) VALUES (
            :event_id,
            :registration_id,
            :channel,
            :recipient,
            :status,
            :details,
            :message,
            :qr_path,
            :pass_code,
            :sent_by_user_id,
            :sent_by_username
        )
SQL
    );
    $statement->execute([
        ':event_id' => $data['event_id'],
        ':registration_id' => $data['registration_id'],
        ':channel' => $data['channel'],
        ':recipient' => $data['recipient'],
        ':status' => $data['status'],
        ':details' => $data['details'] ?? null,
        ':message' => $data['message'] ?? null,
        ':qr_path' => $data['qr_path'] ?? null,
        ':pass_code' => $data['pass_code'] ?? null,
        ':sent_by_user_id' => $data['sent_by_user_id'] ?? null,
        ':sent_by_username' => $data['sent_by_username'] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

function getEventPassHistorySummaries(PDO $pdo, int $eventId): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT qh.registration_id,
               qh.channel,
               qh.status,
               qh.details,
               qh.created_at,
               counts.attempts
        FROM event_pass_history qh
        INNER JOIN (
            SELECT registration_id, channel, MAX(created_at) AS last_sent_at, COUNT(*) AS attempts
            FROM event_pass_history
            WHERE event_id = :event_id_inner
            GROUP BY registration_id, channel
        ) counts ON qh.registration_id = counts.registration_id
            AND qh.channel = counts.channel
            AND qh.created_at = counts.last_sent_at
        WHERE qh.event_id = :event_id_outer
SQL
    );
    $statement->execute([
        ':event_id_inner' => $eventId,
        ':event_id_outer' => $eventId,
    ]);

    $summaries = [];
    foreach ($statement->fetchAll() as $row) {
        $registrationId = (int) $row['registration_id'];
        $channel = (string) $row['channel'];
        $summaries[$registrationId][$channel] = [
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'last_at' => (string) $row['created_at'],
            'details' => (string) ($row['details'] ?? ''),
        ];
    }

    return $summaries;
}

function formatEventPassHistorySummary(array $summary): string
{
    if ($summary === []) {
        return 'No history';
    }

    $parts = [];
    foreach (['email', 'whatsapp'] as $channel) {
        if (!isset($summary[$channel])) {
            $parts[] = ucfirst($channel) . ': none';
            continue;
        }

        $entry = $summary[$channel];
        $parts[] = ucfirst($channel)
            . ': ' . ($entry['status'] === 'sent' ? 'Sent' : 'Failed')
            . ' (' . $entry['attempts'] . ')'
            . ' @ ' . date('d M, y H:i', strtotime($entry['last_at']));
    }

    return implode(' | ', $parts);
}

function ensureQrSettings(PDO $pdo): void
{
    $defaults = [
        ['whatsapp_api_url', 'text', ''],
        ['whatsapp_username', 'text', ''],
        ['whatsapp_password', 'text', ''],
        ['whatsapp_sender', 'text', ''],
        ['whatsapp_template_name', 'text', '', 'WhatsApp template text used for the provider API text field.'],
        ['whatsapp_priority', 'text', 'wa', 'WhatsApp message priority parameter.'],
        ['whatsapp_stype', 'text', 'normal', 'WhatsApp message type parameter.'],
        ['whatsapp_params', 'text', '', 'Additional WhatsApp Params field.'],
        ['whatsapp_htype', 'text', 'image', 'WhatsApp htype parameter.'],
        ['whatsapp_image_url', 'text', '', 'URL of the image to include with WhatsApp messages.'],
        ['mail_from', 'text', ''],
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
                'setting_description' => 'Used by Event Pass delivery features.',
            ]);
        }
    }
}

function getOrCreatePassCode(PDO $pdo, int $eventId, int $registrationId): string
{
    $statement = $pdo->prepare(
        'SELECT pass_code FROM event_registrations WHERE registration_id = :registration_id AND event_id = :event_id LIMIT 1'
    );
    $statement->execute([':registration_id' => $registrationId, ':event_id' => $eventId]);
    $existingCode = trim((string) $statement->fetchColumn());
    if (preg_match('/^\d{5}$/', $existingCode)) {
        return $existingCode;
    }

    $update = $pdo->prepare(
        'UPDATE event_registrations SET pass_code = :pass_code WHERE registration_id = :registration_id AND event_id = :event_id'
    );
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $passCode = (string) random_int(10000, 99999);
        try {
            $update->execute([
                ':pass_code' => $passCode,
                ':registration_id' => $registrationId,
                ':event_id' => $eventId,
            ]);
            return $passCode;
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }
    }

    throw new RuntimeException('Unable to generate a unique five-digit Pass Code.');
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

    $relativePath = '/assets/images/QR/' . basename($outputPath);
    saveQrCodePath($pdo, $registrationId, $relativePath);
    return $relativePath;
}

function readSmtpResponse($socket): array
{
    $response = '';
    while (($line = fgets($socket, 4096)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return [(int) substr($response, 0, 3), trim($response)];
}

function runSmtpCommand($socket, string $command, array $expectedCodes, string &$errorMessage): bool
{
    if (fwrite($socket, $command . "\r\n") === false) {
        $errorMessage = 'Unable to write to the SMTP server.';
        return false;
    }

    [$code, $response] = readSmtpResponse($socket);
    if (!in_array($code, $expectedCodes, true)) {
        if (
            $code === 534
            || stripos($response, 'InvalidSecondFactor') !== false
            || stripos($response, 'Application-specific password required') !== false
        ) {
            $errorMessage = 'Gmail requires an App Password for this SMTP connection. Enable 2-Step Verification, create a new App Password, and save it as mail_password in Admin Settings.';
        } elseif ($code === 535) {
            $errorMessage = 'SMTP authentication failed. Verify mail_username and replace mail_password with a valid App Password.';
        } else {
            $errorMessage = 'SMTP command failed: ' . ($response !== '' ? $response : 'no response from server');
        }
        return false;
    }
    return true;
}

function sendUsingSmtp(
    string $host,
    int $port,
    string $encryption,
    string $username,
    string $password,
    string $from,
    string $to,
    string $message,
    string &$errorMessage
): bool {
    $encryption = strtolower(trim($encryption));
    $useImplicitTls = in_array($encryption, ['ssl', 'smtps'], true);
    $remote = ($useImplicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socketErrorNumber = 0;
    $socketError = '';
    $socket = @stream_socket_client($remote, $socketErrorNumber, $socketError, 20, STREAM_CLIENT_CONNECT);
    if ($socket === false) {
        $errorMessage = 'Could not connect to SMTP server ' . $host . ':' . $port . '. ' . $socketError;
        return false;
    }

    stream_set_timeout($socket, 20);
    [$code, $response] = readSmtpResponse($socket);
    if ($code !== 220) {
        fclose($socket);
        $errorMessage = 'SMTP server rejected the connection: ' . $response;
        return false;
    }

    $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
    if (!runSmtpCommand($socket, 'EHLO ' . preg_replace('/[^a-z0-9.-]/i', '', (string) $serverName), [250], $errorMessage)) {
        fclose($socket);
        return false;
    }

    if (in_array($encryption, ['tls', 'starttls'], true)) {
        if (!runSmtpCommand($socket, 'STARTTLS', [220], $errorMessage)) {
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            $errorMessage = 'Could not establish an encrypted TLS connection to the SMTP server.';
            return false;
        }
        if (!runSmtpCommand($socket, 'EHLO ' . preg_replace('/[^a-z0-9.-]/i', '', (string) $serverName), [250], $errorMessage)) {
            fclose($socket);
            return false;
        }
    }

    if ($username !== '') {
        if ($password === '') {
            fclose($socket);
            $errorMessage = 'SMTP username is configured but the SMTP password is empty.';
            return false;
        }
        if (
            !runSmtpCommand($socket, 'AUTH LOGIN', [334], $errorMessage)
            || !runSmtpCommand($socket, base64_encode($username), [334], $errorMessage)
            || !runSmtpCommand($socket, base64_encode($password), [235], $errorMessage)
        ) {
            fclose($socket);
            return false;
        }
    }

    if (
        !runSmtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250], $errorMessage)
        || !runSmtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251], $errorMessage)
        || !runSmtpCommand($socket, 'DATA', [354], $errorMessage)
    ) {
        fclose($socket);
        return false;
    }

    // SMTP dot-stuffing prevents message lines beginning with a dot from ending DATA early.
    $message = preg_replace('/(?m)^\./', '..', $message);
    if (fwrite($socket, $message . "\r\n.\r\n") === false) {
        fclose($socket);
        $errorMessage = 'Unable to transmit the email body to the SMTP server.';
        return false;
    }
    [$code, $response] = readSmtpResponse($socket);
    if ($code !== 250) {
        fclose($socket);
        $errorMessage = 'SMTP server rejected the email: ' . $response;
        return false;
    }

    $ignoredError = '';
    runSmtpCommand($socket, 'QUIT', [221], $ignoredError);
    fclose($socket);
    return true;
}

function sendEmailWithAttachment(PDO $pdo, string $to, string $subject, string $message, string $attachmentPath, string &$errorMessage = ''): bool
{
    $errorMessage = '';
    $from = trim((string) getSettingValue($pdo, 'mail_from', ''));
    $host = trim((string) getSettingValue($pdo, 'mail_host', 'smtp.gmail.com'));
    $username = trim((string) getSettingValue($pdo, 'mail_username', ''));
    $password = trim((string) getSettingValue($pdo, 'mail_password', ''));
    $port = (int) getSettingValue($pdo, 'mail_port', 587);
    $encryption = trim((string) getSettingValue($pdo, 'mail_encryption', 'tls'));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'The recipient email address is missing or invalid.';
        return false;
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'The Mail From setting is missing or invalid.';
        return false;
    }
    if (!is_file($attachmentPath) || !is_readable($attachmentPath)) {
        $errorMessage = 'The QR image attachment is missing or unreadable.';
        return false;
    }

    // Prevent user-controlled values from adding extra mail headers.
    $to = str_replace(["\r", "\n"], '', $to);
    $from = str_replace(["\r", "\n"], '', $from);
    $subject = str_replace(["\r", "\n"], ' ', $subject);

    $mixedBoundary = 'mixed_' . bin2hex(random_bytes(12));
    $relatedBoundary = 'related_' . bin2hex(random_bytes(12));
    $alternativeBoundary = 'alternative_' . bin2hex(random_bytes(12));
    $contentId = 'qr_' . bin2hex(random_bytes(10)) . '@ems';
    $fileName = basename($attachmentPath);
    $fileContents = file_get_contents($attachmentPath);

    if ($fileContents === false) {
        $errorMessage = 'The QR image could not be read.';
        return false;
    }

    $headers = [];
    $headers[] = 'From: ' . $from;
    $headers[] = 'Reply-To: ' . $from;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"';

    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $safeFileName = str_replace(['"', "\r", "\n"], '', $fileName);
    $htmlBody = '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#1f2937;">'
        . '<div style="max-width:600px;margin:24px auto;background:#ffffff;padding:28px;border-radius:12px;">'
        . '<div style="font-size:16px;line-height:1.6;">' . $safeMessage . '</div>'
        . '<div style="margin-top:24px;text-align:center;"><p style="font-weight:bold;">Your Event Pass</p>'
        . '<img src="cid:' . $contentId . '" width="300" height="300" alt="Event Pass QR code" style="display:block;width:300px;max-width:100%;height:auto;margin:0 auto;border:8px solid #fff;">'
        . '<p style="color:#6b7280;font-size:13px;">Your Event Pass is also attached to this email.</p></div>'
        . '</div></body></html>';

    $encodedImage = chunk_split(base64_encode($fileContents));
    $body = [];
    $body[] = 'This is a multipart message in MIME format.';
    $body[] = '';
    $body[] = '--' . $mixedBoundary;
    $body[] = 'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"';
    $body[] = '';
    $body[] = '--' . $relatedBoundary;
    $body[] = 'Content-Type: multipart/alternative; boundary="' . $alternativeBoundary . '"';
    $body[] = '';
    $body[] = '--' . $alternativeBoundary;
    $body[] = 'Content-Type: text/plain; charset=UTF-8';
    $body[] = 'Content-Transfer-Encoding: base64';
    $body[] = '';
    $body[] = chunk_split(base64_encode($message));
    $body[] = '--' . $alternativeBoundary;
    $body[] = 'Content-Type: text/html; charset=UTF-8';
    $body[] = 'Content-Transfer-Encoding: base64';
    $body[] = '';
    $body[] = chunk_split(base64_encode($htmlBody));
    $body[] = '--' . $alternativeBoundary . '--';
    $body[] = '';
    $body[] = '--' . $relatedBoundary;
    $body[] = 'Content-Type: image/png; name="' . $safeFileName . '"';
    $body[] = 'Content-Transfer-Encoding: base64';
    $body[] = 'Content-ID: <' . $contentId . '>';
    $body[] = 'Content-Disposition: inline; filename="' . $safeFileName . '"';
    $body[] = '';
    $body[] = $encodedImage;
    $body[] = '--' . $relatedBoundary . '--';
    $body[] = '';
    $body[] = '--' . $mixedBoundary;
    $body[] = 'Content-Type: image/png; name="' . $safeFileName . '"';
    $body[] = 'Content-Transfer-Encoding: base64';
    $body[] = 'Content-Disposition: attachment; filename="' . $safeFileName . '"';
    $body[] = '';
    $body[] = $encodedImage;
    $body[] = '--' . $mixedBoundary . '--';

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if ($host !== '') {
        $smtpMessage = 'Date: ' . date(DATE_RFC2822) . "\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Subject: ' . $encodedSubject . "\r\n"
            . implode("\r\n", $headers) . "\r\n\r\n"
            . implode("\r\n", $body);
        return sendUsingSmtp($host, $port, $encryption, $username, $password, $from, $to, $smtpMessage, $errorMessage);
    }

    $sent = mail($to, $encodedSubject, implode("\r\n", $body), implode("\r\n", $headers));
    if (!$sent) {
        $lastError = error_get_last();
        $detail = trim((string) ($lastError['message'] ?? ''));
        $errorMessage = 'No SMTP host is configured, and the local PHP mail transport rejected the message.'
            . ($detail !== '' ? ' Server detail: ' . $detail : ' Configure mail_host, mail_port, mail_encryption, mail_username, and mail_password in Settings.');
    }
    return $sent;
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

function postJson(string $url, array $payload, ?string &$responseBody = null, ?int &$statusCode = null, ?string &$errorMessage = null): bool
{
    $encodedPayload = json_encode($payload);
    if ($encodedPayload === false) {
        $errorMessage = 'Unable to encode WhatsApp payload.';
        return false;
    }

    return postHttpRequest($url, $encodedPayload, 'application/json', $responseBody, $statusCode, $errorMessage);
}

function postForm(string $url, array $payload, ?string &$responseBody = null, ?int &$statusCode = null, ?string &$errorMessage = null): bool
{
    $encodedPayload = http_build_query($payload);
    return postHttpRequest($url, $encodedPayload, 'application/x-www-form-urlencoded', $responseBody, $statusCode, $errorMessage);
}

function postHttpRequest(string $url, string $payload, string $contentType, ?string &$responseBody = null, ?int &$statusCode = null, ?string &$errorMessage = null): bool
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: ' . $contentType]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $responseBody = (string) curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorMessage = curl_error($ch);
        curl_close($ch);
        return $errorMessage === '' && $statusCode >= 200 && $statusCode < 300;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: ' . $contentType,
            'content' => $payload,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $statusCode = 0;
    if (isset($http_response_header[0])) {
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches);
        if (isset($matches[1])) {
            $statusCode = (int) $matches[1];
        }
    }

    if ($responseBody === false) {
        $errorMessage = 'HTTP request failed';
        return false;
    }

    return $statusCode >= 200 && $statusCode < 300;
}

function sendWhatsappMessage(PDO $pdo, string $to, string $message): bool
{
    $apiUrl = trim((string) getSettingValue($pdo, 'whatsapp_api_url', ''));
    $recipient = normalizeWhatsappNumber($to);
    if ($apiUrl === '' || $recipient === '') {
        return false;
    }

    $payload = [
        'user' => trim((string) getSettingValue($pdo, 'whatsapp_username', '')),
        'pass' => trim((string) getSettingValue($pdo, 'whatsapp_password', '')),
        'sender' => trim((string) getSettingValue($pdo, 'whatsapp_sender', '')),
        'phone' => $recipient,
        'text' => trim((string) getSettingValue($pdo, 'whatsapp_template_name', '')) ?: $message,
        'priority' => trim((string) getSettingValue($pdo, 'whatsapp_priority', 'wa')),
        'stype' => trim((string) getSettingValue($pdo, 'whatsapp_stype', 'normal')),
        'Params' => trim((string) getSettingValue($pdo, 'whatsapp_params', '')),
        'htype' => trim((string) getSettingValue($pdo, 'whatsapp_htype', 'image')),
        'url' => trim((string) getSettingValue($pdo, 'whatsapp_image_url', '')),
    ];

    $responseBody = null;
    $statusCode = null;
    $errorMessage = null;
    $sent = postForm($apiUrl, $payload, $responseBody, $statusCode, $errorMessage);

    if (!$sent) {
        error_log('WhatsApp send failure: status=' . ($statusCode ?? 'unknown') . ' error=' . ($errorMessage ?? 'none') . ' response=' . substr((string) $responseBody, 0, 1000));
    }

    return $sent;
}

$pdo = null;
$events = [];
$registrations = [];
$historySummaries = [];
$selectedEventId = 0;
$messageBody = "Dear Educator,\nGreetings!\nPlease find attached your unique Event Pass. Kindly download it and present it at the Registration Desk on the day of the event to mark your attendance.\nWe look forward to welcoming you to the event.\nThanks";
$adminError = (string) ($_SESSION['admin_error'] ?? '');
$adminSuccess = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_error'], $_SESSION['admin_success']);

try {
    $pdo = createDbConnection();
    ensureSettingsTable($pdo);
    ensureQrSettings($pdo);
    ensureRegistrationApprovalStorage($pdo);
    ensureEventPassHistoryTable($pdo);
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
            $_SESSION['admin_success'] = $generated > 0 ? 'Generated ' . $generated . ' Event Pass(es).' : 'No Event Passes were generated.';
            header('Location: ' . buildUrl('admin/event_pass.php') . '?event=' . $selectedEventId);
            exit;
        }
    } elseif ($action === 'send_email' || $action === 'send_email_all' || $action === 'ajax_send_email') {
        if ($action === 'ajax_send_email' && !isAjaxRequest()) {
            sendJsonResponse(['success' => false, 'message' => 'AJAX requests only.'], 405);
        }

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
        $emailErrors = [];
        if ($registrationsToProcess === []) {
            $emailErrors[] = $action === 'send_email' && $registrationIds === []
                ? 'No candidates were selected.'
                : 'No approved candidates were available for email delivery.';
        }
        foreach ($registrationsToProcess as $registration) {
            $eventId = (int) $registration['event_id'];
            $registrationId = (int) $registration['registration_id'];
            $passCode = getOrCreatePassCode($pdo, $eventId, $registrationId);
            $candidateName = trim((string) ($registration['name'] ?? ''));
            $candidateLabel = $candidateName !== ''
                ? $candidateName . ' (#' . $registrationId . ')'
                : 'Registration #' . $registrationId;
            $qrPath = buildQrFilePath($eventId, $registrationId);
            if (!is_file($qrPath)) {
                $created = createQrForRegistration($pdo, $eventId, $registrationId);
                if ($created === null) {
                    $emailErrors[] = $candidateLabel . ': Event Pass generation failed. Check internet access and QR service availability.';
                    continue;
                }
            }
            $to = trim((string) ($registration['official_email'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $emailErrors[] = $candidateLabel . ': official email address is missing or invalid.';
                recordEventPassHistory($pdo, [
                    'event_id' => $eventId,
                    'registration_id' => $registrationId,
                    'channel' => 'email',
                    'recipient' => $to,
                    'status' => 'failed',
                    'details' => 'Invalid recipient email address.',
                    'message' => $messageBody,
                    'qr_path' => $qrPath,
                    'pass_code' => $passCode,
                    'sent_by_user_id' => $_SESSION['admin_user_id'] ?? null,
                    'sent_by_username' => $_SESSION['admin_username'] ?? null,
                ]);
                continue;
            }
            $subject = 'Your Event Pass for ' . (string) ($registration['event_title'] ?? ('event #' . $eventId));
            $body = $messageBody . "\n\nPass Code: " . $passCode;
            $deliveryError = '';
            if (sendEmailWithAttachment($pdo, $to, $subject, $body, $qrPath, $deliveryError)) {
                $sent++;
                recordEventPassHistory($pdo, [
                    'event_id' => $eventId,
                    'registration_id' => $registrationId,
                    'channel' => 'email',
                    'recipient' => $to,
                    'status' => 'sent',
                    'details' => 'Email delivered successfully.',
                    'message' => $body,
                    'qr_path' => $qrPath,
                    'pass_code' => $passCode,
                    'sent_by_user_id' => $_SESSION['admin_user_id'] ?? null,
                    'sent_by_username' => $_SESSION['admin_username'] ?? null,
                ]);
            } else {
                $emailErrors[] = $candidateLabel . ' (' . $to . '): '
                    . ($deliveryError !== '' ? $deliveryError : 'Unknown email delivery error.');
                recordEventPassHistory($pdo, [
                    'event_id' => $eventId,
                    'registration_id' => $registrationId,
                    'channel' => 'email',
                    'recipient' => $to,
                    'status' => 'failed',
                    'details' => $deliveryError,
                    'message' => $body,
                    'qr_path' => $qrPath,
                    'pass_code' => $passCode,
                    'sent_by_user_id' => $_SESSION['admin_user_id'] ?? null,
                    'sent_by_username' => $_SESSION['admin_username'] ?? null,
                ]);
            }
        }
        if ($action === 'ajax_send_email') {
            sendJsonResponse([
                'success' => $sent > 0,
                'sent' => $sent,
                'errors' => $emailErrors,
            ], $sent > 0 ? 200 : 422);
        }
        if ($sent > 0) {
            $_SESSION['admin_success'] = 'Email sent to ' . $sent . ' recipient(s).';
        }
        if ($emailErrors !== []) {
            $_SESSION['admin_error'] = ($sent === 0 ? 'No emails were sent. ' : 'Some emails could not be sent. ')
                . implode(' ', $emailErrors);
        }
        header('Location: ' . buildUrl('admin/event_pass.php') . '?event=' . $selectedEventId);
        exit;
    } elseif ($action === 'send_whatsapp' || $action === 'send_whatsapp_all' || $action === 'ajax_send_whatsapp') {
        if ($action === 'ajax_send_whatsapp' && !isAjaxRequest()) {
            sendJsonResponse(['success' => false, 'message' => 'AJAX requests only.'], 405);
        }

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
        $whatsappErrors = [];
        if ($registrationsToProcess === []) {
            $whatsappErrors[] = $action === 'send_whatsapp' && $registrationIds === []
                ? 'No candidates were selected.'
                : 'No approved candidates were available for WhatsApp delivery.';
        }
        foreach ($registrationsToProcess as $registration) {
            $eventId = (int) $registration['event_id'];
            $registrationId = (int) $registration['registration_id'];
            $passCode = getOrCreatePassCode($pdo, $eventId, $registrationId);
            $qrPath = buildQrFilePath($eventId, $registrationId);
            if (!is_file($qrPath)) {
                $created = createQrForRegistration($pdo, $eventId, $registrationId);
                if ($created === null) {
                    $whatsappErrors[] = 'Registration #' . $registrationId . ': Event Pass generation failed.';
                    recordEventPassHistory($pdo, [
                        'event_id' => $eventId,
                        'registration_id' => $registrationId,
                        'channel' => 'whatsapp',
                        'recipient' => trim((string) ($registration['whatsapp_number'] ?? '')),
                        'status' => 'failed',
                        'details' => 'Event Pass generation failed',
                        'message' => $messageBody,
                        'qr_path' => null,
                        'pass_code' => $passCode,
                        'sent_by_user_id' => $_SESSION['admin_user_id'] ?? null,
                        'sent_by_username' => $_SESSION['admin_username'] ?? null,
                    ]);
                    continue;
                }
            }
            $to = normalizeWhatsappNumber(trim((string) ($registration['whatsapp_number'] ?? $registration['mobile'] ?? '')));
            if ($to === '') {
                $whatsappErrors[] = 'Registration #' . $registrationId . ': WhatsApp number is missing.';
                recordEventPassHistory($pdo, [
                    'event_id' => $eventId,
                    'registration_id' => $registrationId,
                    'channel' => 'whatsapp',
                    'recipient' => $to,
                    'status' => 'failed',
                    'details' => 'Missing WhatsApp number',
                    'message' => $messageBody,
                    'qr_path' => $qrPath,
                    'pass_code' => $passCode,
                    'sent_by_user_id' => $_SESSION['admin_user_id'] ?? null,
                    'sent_by_username' => $_SESSION['admin_username'] ?? null,
                ]);
                continue;
            }
            $body = $messageBody . "\n\nPass Code: " . $passCode;
            if (sendWhatsappMessage($pdo, $to, $body)) {
                $sent++;
                recordEventPassHistory($pdo, [
                    'event_id' => $eventId,
                    'registration_id' => $registrationId,
                    'channel' => 'whatsapp',
                    'recipient' => $to,
                    'status' => 'sent',
                    'details' => 'WhatsApp delivered successfully.',
                    'message' => $body,
                    'qr_path' => $qrPath,
                    'pass_code' => $passCode,
                    'sent_by_user_id' => $_SESSION['admin_user_id'] ?? null,
                    'sent_by_username' => $_SESSION['admin_username'] ?? null,
                ]);
            } else {
                $whatsappErrors[] = 'Registration #' . $registrationId . ': WhatsApp send failed. Check WhatsApp settings and internet access.';
                recordEventPassHistory($pdo, [
                    'event_id' => $eventId,
                    'registration_id' => $registrationId,
                    'channel' => 'whatsapp',
                    'recipient' => $to,
                    'status' => 'failed',
                    'details' => 'WhatsApp API send failed',
                    'message' => $body,
                    'qr_path' => $qrPath,
                    'pass_code' => $passCode,
                    'sent_by_user_id' => $_SESSION['admin_user_id'] ?? null,
                    'sent_by_username' => $_SESSION['admin_username'] ?? null,
                ]);
            }
        }
        if ($action === 'ajax_send_whatsapp') {
            sendJsonResponse([
                'success' => $sent > 0,
                'sent' => $sent,
                'errors' => $whatsappErrors,
            ], $sent > 0 ? 200 : 422);
        }
        $_SESSION['admin_success'] = $sent > 0 ? 'WhatsApp messages sent to ' . $sent . ' recipient(s).' : 'No WhatsApp messages were sent.';
        if ($whatsappErrors !== []) {
            $_SESSION['admin_error'] = ($sent === 0 ? 'No WhatsApp messages were sent. ' : 'Some messages could not be sent. ')
                . implode(' ', $whatsappErrors);
        }
        header('Location: ' . buildUrl('admin/event_pass.php') . '?event=' . $selectedEventId);
        exit;
    }
}

if (isset($_GET['event']) && $pdo !== null) {
    $selectedEventId = (int) $_GET['event'];
}

if ($selectedEventId > 0 && $pdo !== null) {
    $registrations = getRegistrationsForEvent($pdo, $selectedEventId);
    $historySummaries = getEventPassHistorySummaries($pdo, $selectedEventId);
}

function getRegistrationById(PDO $pdo, int $registrationId): ?array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT er.*, e.event_title, e.start_date AS event_date
        FROM event_registrations er
        INNER JOIN events e ON e.event_id = er.event_id
        WHERE er.registration_id = :registration_id
          AND er.approval_status = 'approved'
        LIMIT 1
SQL);
    $statement->execute([':registration_id' => $registrationId]);
    $registration = $statement->fetch();
    return $registration ?: null;
}

function getRegistrationsForEvent(PDO $pdo, int $eventId): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT er.*, e.event_title, e.start_date AS event_date
        FROM event_registrations er
        INNER JOIN events e ON e.event_id = er.event_id
        WHERE er.event_id = :event_id
          AND er.approval_status = 'approved'
        ORDER BY er.registration_id ASC
SQL);
    $statement->execute([':event_id' => $eventId]);
    return $statement->fetchAll() ?: [];
}

function getAllEvents(PDO $pdo): array
{
    $statement = $pdo->query('SELECT event_id, event_title, start_date, event_status FROM events ORDER BY start_date DESC, event_title ASC');
    return $statement ? $statement->fetchAll() : [];
}
ob_start();
?>
<div class="qr-page">
    <div class="qr-page-intro">
        <div>
            <span class="qr-kicker">Attendee access</span>
            <h2>Event Pass Manager</h2>
            <p>Generate secure passes and deliver them to approved registered candidates.</p>
        </div>
        <div class="qr-intro-icon" aria-hidden="true">
            <span></span><span></span><span></span><span></span>
        </div>
    </div>

    <?php if ($adminError !== ''): ?><div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($adminSuccess !== ''): ?><div class="alert success"><?php echo htmlspecialchars((string) $adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <form method="post" class="qr-event-picker">
        <input type="hidden" name="action" value="load_event">
        <div class="qr-field">
            <label for="event_id">Event</label>
            <select id="event_id" name="event_id" required>
                <option value="">Choose an event</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?php echo (int) $event['event_id']; ?>" <?php echo ((int) $selectedEventId === (int) $event['event_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($event['event_title'] ?? '') . ' — ' . ($event['event_status'] ?? 'Open'), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="qr-button qr-button-primary">Load event</button>
    </form>

    <?php if ($selectedEventId > 0): ?>
        <form method="post" class="qr-workspace">
            <input type="hidden" name="action" value="generate_qr">
            <input type="hidden" name="event_id" value="<?php echo (int) $selectedEventId; ?>">

            <div class="qr-compose">
                <div class="qr-field">
                    <label for="message_body">Delivery message</label>
                    <textarea id="message_body" name="message_body" rows = 10><?php echo htmlspecialchars((string) $messageBody, ENT_QUOTES, 'UTF-8'); ?></textarea>

                </div>
                <div class="qr-stats">
                    <span>Approved registrations</span>
                    <strong><?php echo count($registrations); ?></strong>
                    <small>for the selected event</small>
                </div>
            </div>

            <div class="qr-action-bar">
                <div class="qr-action-group">
                    <span>Generate</span>
                    <button type="submit" class="qr-button qr-button-primary" name="action" value="generate_qr">Selected</button>
                    <button type="submit" class="qr-button qr-button-light" name="action" value="generate_qr_all">All</button>
                </div>
                <div class="qr-action-group">
                    <span>Email</span>
                    <button type="submit" class="qr-button qr-button-light" name="action" value="send_email">Selected</button>
                    <button type="submit" class="qr-button qr-button-light" name="action" value="send_email_all">All</button>
                </div>
                <div class="qr-action-group">
                    <span>WhatsApp</span>
                    <button type="submit" class="qr-button qr-button-light" name="action" value="send_whatsapp">Selected</button>
                    <button type="submit" class="qr-button qr-button-light" name="action" value="send_whatsapp_all">All</button>
                </div>
            </div>

            <div class="qr-table-heading">
                <div>
                    <span class="qr-kicker">Event roster</span>
                    <h3>Approved Registered Candidates</h3>
                </div>
                <span class="qr-count"><?php echo count($registrations); ?> total</span>
            </div>
            <?php if (!empty($registrations)): ?>
                <div class="qr-table-wrap">
                    <table class="qr-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all" aria-label="Select all candidates" onchange="document.querySelectorAll('.candidate-checkbox').forEach(cb => cb.checked = this.checked)"></th>
                                <th>ID</th><th>Pass Code</th><th>Candidate</th><th>Email</th><th>WhatsApp</th><th>Status</th><th>History</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registrations as $registration): ?>
                                <?php $registrationId = (int) $registration['registration_id']; ?>
                                <tr>
                                    <td><input class="candidate-checkbox" type="checkbox" name="registration_ids[]" aria-label="Select <?php echo htmlspecialchars((string) ($registration['name'] ?? 'candidate'), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo $registrationId; ?>"></td>
                                    <td><span class="qr-id">#<?php echo $registrationId; ?></span></td>
                                    <td><strong><?php echo htmlspecialchars((string) (($registration['pass_code'] ?? '') ?: '—'), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars((string) ($registration['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td><?php echo htmlspecialchars((string) ($registration['official_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($registration['whatsapp_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php $qrPath = buildQrFilePath((int) $registration['event_id'], $registrationId); ?>
                                        <?php if (is_file($qrPath)): ?>
                                            <a class="qr-status ready" href="<?php echo htmlspecialchars(buildUrl('assets/images/QR/' . basename($qrPath)), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">View Pass</a>
                                        <?php else: ?>
                                            <span class="qr-status pending">Not generated</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(formatEventPassHistorySummary($historySummaries[$registrationId] ?? []), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <button type="button" class="qr-button qr-button-secondary ajax-qr-send" data-action="ajax_send_email" data-registration-id="<?php echo $registrationId; ?>">Email</button>
                                        <button type="button" class="qr-button qr-button-secondary ajax-qr-send" data-action="ajax_send_whatsapp" data-registration-id="<?php echo $registrationId; ?>">WhatsApp</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <script>
                    (function () {
                        const workspaceForm = document.querySelector('.qr-workspace');
                        if (!workspaceForm) {
                            return;
                        }

                        const buttons = workspaceForm.querySelectorAll('.ajax-qr-send');
                        buttons.forEach(button => {
                            button.addEventListener('click', async function () {
                                const registrationId = this.dataset.registrationId;
                                const action = this.dataset.action;
                                const eventId = workspaceForm.querySelector('input[name="event_id"]').value;
                                const messageBody = workspaceForm.querySelector('textarea[name="message_body"]').value;

                                if (!registrationId || !action || !eventId) {
                                    alert('Unable to send. Missing event or registration information.');
                                    return;
                                }

                                this.disabled = true;
                                const previousLabel = this.textContent;
                                this.textContent = 'Sending...';

                                const formData = new FormData();
                                formData.append('action', action);
                                formData.append('event_id', eventId);
                                formData.append('message_body', messageBody);
                                formData.append('registration_ids[]', registrationId);

                                try {
                                    const response = await fetch(window.location.href, {
                                        method: 'POST',
                                        body: formData,
                                        headers: {
                                            'X-Requested-With': 'XMLHttpRequest',
                                        },
                                    });
                                    const data = await response.json();
                                    if (response.ok && data.success) {
                                        window.location.reload();
                                    } else {
                                        alert('Send failed: ' + (data.message || (data.errors ? data.errors.join(' ') : 'Unknown error.')));
                                        this.disabled = false;
                                        this.textContent = previousLabel;
                                    }
                                } catch (error) {
                                    alert('Network error sending message.');
                                    this.disabled = false;
                                    this.textContent = previousLabel;
                                }
                            });
                        });
                    })();
                </script>
            <?php else: ?>
                <div class="qr-empty"><strong>No approved registrations</strong><span>Candidates will appear here after their event registration is approved.</span></div>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <div class="qr-empty">
            <div class="qr-empty-code" aria-hidden="true">▦</div>
            <strong>Select an event to get started</strong>
            <span>You’ll be able to generate and deliver attendee Event Passes here.</span>
        </div>
    <?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
renderAdminLayout('Event Pass', $content, [
    'current_path' => 'event_pass',
    'page_heading' => 'Event Pass',
    'body_class' => 'event-pass-page',
]);
