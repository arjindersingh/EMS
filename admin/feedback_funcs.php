<?php

require_once __DIR__ . '/settings_funcs.php';

function ensureFeedbackTables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS feedback_types (
            feedback_type_id INT AUTO_INCREMENT PRIMARY KEY,
            type_name VARCHAR(100) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS feedback_items (
            feedback_item_id INT AUTO_INCREMENT PRIMARY KEY,
            feedback_type_id INT NOT NULL,
            item_text VARCHAR(500) NOT NULL,
            response_type ENUM('rating','text') NOT NULL DEFAULT 'rating',
            rating_display ENUM('words','emojis','stars') NOT NULL DEFAULT 'words',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_feedback_items_type (feedback_type_id)
        )
SQL);
    if (!$pdo->query("SHOW COLUMNS FROM feedback_items LIKE 'rating_display'")->fetch()) {
        $pdo->exec("ALTER TABLE feedback_items ADD COLUMN rating_display ENUM('words','emojis','stars') NOT NULL DEFAULT 'words' AFTER response_type");
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS feedback_invitations (
            feedback_id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            registration_id INT NOT NULL,
            feedback_type_id INT NOT NULL,
            access_token CHAR(64) NOT NULL UNIQUE,
            status ENUM('created','sent','submitted') NOT NULL DEFAULT 'created',
            sent_at DATETIME NULL,
            submitted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_feedback_invitation (event_id, registration_id, feedback_type_id),
            INDEX idx_feedback_event (event_id)
        )
SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS feedback_responses (
            feedback_response_id INT AUTO_INCREMENT PRIMARY KEY,
            feedback_id INT NOT NULL,
            feedback_item_id INT NOT NULL,
            rating TINYINT NULL,
            response_text TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_feedback_response (feedback_id, feedback_item_id)
        )
SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS feedback_delivery_history (
            feedback_delivery_id INT AUTO_INCREMENT PRIMARY KEY,
            feedback_id INT NOT NULL,
            channel ENUM('email','whatsapp','copy') NOT NULL,
            recipient VARCHAR(255) NOT NULL,
            status ENUM('sent','failed') NOT NULL,
            details TEXT NULL,
            sent_by_username VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_feedback_delivery (feedback_id)
        )
SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS feedback_qualitative_responses (
            feedback_qualitative_response_id INT AUTO_INCREMENT PRIMARY KEY,
            feedback_id INT NOT NULL UNIQUE,
            subject_summary TEXT NOT NULL,
            impact_summary TEXT NOT NULL,
            suggestions TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_feedback_qualitative_feedback (feedback_id)
        )
SQL);

    $typeInsert = $pdo->prepare('INSERT IGNORE INTO feedback_types (type_name) VALUES (:name)');
    foreach (['Overall', 'Itinerary', 'Food', 'Academic'] as $name) $typeInsert->execute([':name' => $name]);
    $itemCount = (int) $pdo->query('SELECT COUNT(*) FROM feedback_items')->fetchColumn();
    if ($itemCount === 0) {
        $standardItems = [
            'How relevant was the theme?',
            'How effectively did the sessions integrate the theme?',
            'How satisfied were you with the venue, hospitality, and logistics?',
            'Do you envision applying this in your own classroom?',
        ];
        $defaults = [
            'Overall' => $standardItems,
            'Itinerary' => $standardItems,
            'Food' => $standardItems,
            'Academic' => $standardItems,
        ];
        $typeId = $pdo->prepare('SELECT feedback_type_id FROM feedback_types WHERE type_name = :name');
        $insert = $pdo->prepare('INSERT INTO feedback_items (feedback_type_id, item_text, sort_order) VALUES (:type_id, :text, :sort_order)');
        foreach ($defaults as $name => $items) {
            $typeId->execute([':name' => $name]);
            $id = (int) $typeId->fetchColumn();
            foreach ($items as $sort => $text) $insert->execute([':type_id' => $id, ':text' => $text, ':sort_order' => $sort + 1]);
        }
    }
}

function countFeedbackWords(?string $value): int
{
    $cleanValue = trim((string) $value);
    if ($cleanValue === '') {
        return 0;
    }

    $words = preg_split('/\s+/', $cleanValue, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($words) ? count($words) : 0;
}

function saveFeedbackQualitativeResponses(PDO $pdo, int $feedbackId, array $data): void
{
    $subjectSummary = trim((string) ($data['subject_summary'] ?? ''));
    $impactSummary = trim((string) ($data['impact_summary'] ?? ''));
    $suggestions = trim((string) ($data['suggestions'] ?? ''));

    foreach (['subject_summary' => $subjectSummary, 'impact_summary' => $impactSummary, 'suggestions' => $suggestions] as $field => $value) {
        if ($value === '') {
            throw new InvalidArgumentException('Please answer all qualitative questions.');
        }

        if (countFeedbackWords($value) > 255) {
            throw new InvalidArgumentException('Each qualitative response must be 255 words or fewer.');
        }
    }

    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO feedback_qualitative_responses (feedback_id, subject_summary, impact_summary, suggestions)
        VALUES (:feedback_id, :subject_summary, :impact_summary, :suggestions)
        ON DUPLICATE KEY UPDATE
            subject_summary = VALUES(subject_summary),
            impact_summary = VALUES(impact_summary),
            suggestions = VALUES(suggestions)
SQL);
    $statement->execute([
        ':feedback_id' => $feedbackId,
        ':subject_summary' => $subjectSummary,
        ':impact_summary' => $impactSummary,
        ':suggestions' => $suggestions,
    ]);
}

function getFeedbackQualitativeResponses(PDO $pdo, int $eventId, int $typeId): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT fi.feedback_id, fi.submitted_at, er.name, fqr.subject_summary, fqr.impact_summary, fqr.suggestions
        FROM feedback_invitations fi
        JOIN event_registrations er ON er.registration_id = fi.registration_id
        LEFT JOIN feedback_qualitative_responses fqr ON fqr.feedback_id = fi.feedback_id
        WHERE fi.event_id = :event_id
          AND fi.feedback_type_id = :type_id
          AND fi.status = 'submitted'
        ORDER BY fi.submitted_at DESC, fi.feedback_id DESC
SQL);
    $statement->execute([':event_id' => $eventId, ':type_id' => $typeId]);
    return $statement->fetchAll() ?: [];
}

/**
 * Produce a small, deterministic text analysis without sending personal feedback
 * to a third-party service. Categories are event-focused and unmatched language
 * is represented by its most frequent meaningful terms.
 */
function analyseQualitativeFeedback(array $responses): array
{
    $fields = [
        'subject_summary' => [
            'Learning & knowledge' => ['learn', 'learning', 'knowledge', 'academic', 'education', 'educational', 'content', 'session', 'workshop', 'training'],
            'Organisation & delivery' => ['organised', 'organized', 'organisation', 'organization', 'coordination', 'management', 'schedule', 'planning', 'arrangement'],
            'Speakers & interaction' => ['speaker', 'faculty', 'resource person', 'presentation', 'discussion', 'interactive', 'interaction', 'question'],
            'Networking & community' => ['network', 'networking', 'people', 'community', 'colleague', 'collaboration', 'connect'],
            'Food & hospitality' => ['food', 'meal', 'lunch', 'dinner', 'breakfast', 'refreshment', 'hospitality', 'accommodation'],
            'Venue & facilities' => ['venue', 'facility', 'facilities', 'hall', 'seating', 'sound', 'audio', 'projector', 'location'],
        ],
        'impact_summary' => [
            'New knowledge & insight' => ['learn', 'knowledge', 'insight', 'understand', 'awareness', 'perspective', 'informative'],
            'Motivation & inspiration' => ['inspire', 'inspired', 'inspiration', 'motivate', 'motivated', 'encourage', 'confidence', 'energised', 'energized'],
            'Practical application' => ['apply', 'practical', 'practice', 'implement', 'useful', 'skill', 'work', 'career', 'professional'],
            'Connection & collaboration' => ['network', 'connect', 'collaborate', 'relationship', 'community', 'team', 'share'],
            'Personal growth' => ['growth', 'personal', 'self', 'mindset', 'creativity', 'leadership', 'confidence'],
        ],
        'suggestions' => [
            'More time / better pacing' => ['more time', 'duration', 'longer', 'short', 'rushed', 'pace', 'timing', 'break', 'schedule'],
            'More interactive activities' => ['interactive', 'activity', 'activities', 'hands-on', 'practical', 'workshop', 'discussion', 'question', 'participation'],
            'Content & speaker improvements' => ['content', 'topic', 'speaker', 'faculty', 'presentation', 'session', 'material', 'resource'],
            'Food & hospitality improvements' => ['food', 'meal', 'refreshment', 'lunch', 'dinner', 'variety', 'hospitality', 'accommodation'],
            'Venue & technical improvements' => ['venue', 'facility', 'seating', 'sound', 'audio', 'projector', 'internet', 'wifi', 'parking', 'transport'],
            'Communication & coordination' => ['communication', 'information', 'notice', 'registration', 'coordination', 'organise', 'organize', 'management'],
        ],
    ];

    $result = ['response_count' => count($responses), 'columns' => []];
    foreach ($fields as $field => $categories) {
        $texts = [];
        foreach ($responses as $response) {
            $text = trim((string) ($response[$field] ?? ''));
            if ($text !== '') $texts[] = $text;
        }
        $result['columns'][$field] = analyseFeedbackTextColumn($texts, $categories);
    }
    return $result;
}

function analyseFeedbackTextColumn(array $texts, array $categories): array
{
    $groups = [];
    foreach ($categories as $label => $terms) $groups[$label] = ['label' => $label, 'mentions' => 0, 'examples' => []];
    foreach ($texts as $text) {
        $normalized = strtolower($text);
        foreach ($categories as $label => $terms) {
            $matched = false;
            foreach ($terms as $term) {
                if (preg_match('/(?<![a-z])' . preg_quote($term, '/') . '(?![a-z])/i', $normalized)) { $matched = true; break; }
            }
            if ($matched) {
                $groups[$label]['mentions']++;
                if (count($groups[$label]['examples']) < 2) $groups[$label]['examples'][] = feedbackAnalysisExcerpt($text);
            }
        }
    }
    $groups = array_values(array_filter($groups, static fn(array $group): bool => $group['mentions'] > 0));
    usort($groups, static fn(array $a, array $b): int => $b['mentions'] <=> $a['mentions']);
    $keywords = feedbackAnalysisKeywords($texts);
    return ['responses' => count($texts), 'themes' => array_slice($groups, 0, 5), 'keywords' => array_slice($keywords, 0, 8)];
}

function feedbackAnalysisKeywords(array $texts): array
{
    $stopWords = array_flip(explode(' ', 'about after again also and are because been before being but can could did does event for from had has have how into its just may more most much not our out over really should some than that the their them then there these they this those through too very was were what when where which while who will with would your good great very event events please')); 
    $counts = [];
    $content = strtolower(implode(' ', $texts));
    preg_match_all('/[a-z][a-z\-]{2,}/', $content, $matches);
    foreach ($matches[0] ?? [] as $word) {
        $word = rtrim($word, '.,');
        if (!isset($stopWords[$word])) $counts[$word] = ($counts[$word] ?? 0) + 1;
    }
    arsort($counts);
    return $counts;
}

function feedbackAnalysisExcerpt(string $text, int $limit = 150): string
{
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
    if (function_exists('mb_strlen') && mb_strlen($text) > $limit) return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    return strlen($text) > $limit ? rtrim(substr($text, 0, $limit - 1)) . '…' : $text;
}

function getFeedbackTypes(PDO $pdo, bool $activeOnly = true): array
{
    return $pdo->query('SELECT * FROM feedback_types' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY type_name')->fetchAll() ?: [];
}

function getFeedbackItems(PDO $pdo, int $typeId, bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM feedback_items WHERE feedback_type_id = :type_id' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, feedback_item_id';
    $statement = $pdo->prepare($sql);
    $statement->execute([':type_id' => $typeId]);
    return $statement->fetchAll() ?: [];
}

function getOrCreateFeedbackInvitation(PDO $pdo, int $eventId, int $registrationId, int $typeId): array
{
    $find = $pdo->prepare('SELECT * FROM feedback_invitations WHERE event_id = :event_id AND registration_id = :registration_id AND feedback_type_id = :type_id');
    $params = [':event_id' => $eventId, ':registration_id' => $registrationId, ':type_id' => $typeId];
    $find->execute($params);
    $invitation = $find->fetch();
    if ($invitation) return $invitation;
    $params[':token'] = bin2hex(random_bytes(32));
    $insert = $pdo->prepare('INSERT INTO feedback_invitations (event_id, registration_id, feedback_type_id, access_token) VALUES (:event_id, :registration_id, :type_id, :token)');
    $insert->execute($params);
    $find->execute(array_diff_key($params, [':token' => true]));
    return $find->fetch() ?: [];
}

function buildFeedbackLink(array $invitation): string
{
    return buildUrl('feedback.php') . '?' . http_build_query([
        'event_id' => (int) $invitation['event_id'],
        'registration_id' => (int) $invitation['registration_id'],
        'feedback_id' => (int) $invitation['feedback_id'],
        'token' => (string) $invitation['access_token'],
    ]);
}

function buildAbsoluteFeedbackLink(PDO $pdo, array $invitation): string
{
    $feedbackPath = buildFeedbackLink($invitation);
    $portalBaseUrl = trim((string) getSettingValue($pdo, 'portal_base_url', getenv('APP_BASE_URL') ?: ''));

    if ($portalBaseUrl === '') {
        $forwardedProto = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]);
        $scheme = $forwardedProto !== '' ? $forwardedProto : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        $portalBaseUrl = $host !== '' ? $scheme . '://' . $host : '';
    }

    if ($portalBaseUrl === '') return $feedbackPath;

    $parts = parse_url($portalBaseUrl);
    if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $configuredPath = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($configuredPath !== '' && str_starts_with($feedbackPath . '/', $configuredPath . '/')) {
            return $origin . $feedbackPath;
        }
    }

    return rtrim($portalBaseUrl, '/') . '/' . ltrim($feedbackPath, '/');
}

function buildFeedbackEmailMessage(string $eventTitle, string $feedbackType, string $link): string
{
    $safeEvent = htmlspecialchars($eventTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeType = htmlspecialchars($feedbackType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<!doctype html><html><body style="margin:0;background:#f1f5f9;font-family:Arial,sans-serif;color:#1e293b">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px"><tr><td style="padding:32px">'
        . '<p style="margin:0 0 18px">Dear Participant,</p>'
        . '<h2 style="margin:0 0 12px;color:#0f766e">Your feedback is requested</h2>'
        . '<p style="margin:0 0 24px;line-height:1.6">Please share your ' . $safeType . ' feedback for <strong>' . $safeEvent . '</strong>. Your responses are presented as blind feedback to reviewers.</p>'
        . '<table role="presentation" cellspacing="0" cellpadding="0"><tr><td style="border-radius:7px;background:#0f766e"><a href="' . $safeLink . '" target="_blank" style="display:inline-block;padding:13px 22px;color:#ffffff;text-decoration:none;font-weight:bold">Open Feedback Form</a></td></tr></table>'
        . '<p style="margin:24px 0 6px;color:#64748b;font-size:13px;line-height:1.5">If the button does not work, copy and paste this link into your browser:</p>'
        . '<p style="margin:0 0 24px;font-size:13px;word-break:break-all"><a href="' . $safeLink . '" style="color:#0f766e">' . $safeLink . '</a></p>'
        . '<p style="margin:0">Thank you.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function recordFeedbackDelivery(PDO $pdo, int $feedbackId, string $channel, string $recipient, string $status, string $details): void
{
    $statement = $pdo->prepare('INSERT INTO feedback_delivery_history (feedback_id, channel, recipient, status, details, sent_by_username) VALUES (:feedback_id, :channel, :recipient, :status, :details, :username)');
    $statement->execute([':feedback_id' => $feedbackId, ':channel' => $channel, ':recipient' => $recipient, ':status' => $status, ':details' => $details, ':username' => $_SESSION['admin_username'] ?? null]);
    if ($status === 'sent') {
        $pdo->prepare("UPDATE feedback_invitations SET status = IF(status = 'submitted', status, 'sent'), sent_at = COALESCE(sent_at, NOW()) WHERE feedback_id = :id")->execute([':id' => $feedbackId]);
    }
}

function feedbackSmtpRead($socket): array
{
    $response = '';
    while (($line = fgets($socket, 4096)) !== false) { $response .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
    return [(int) substr($response, 0, 3), trim($response)];
}

function feedbackSmtpCommand($socket, string $command, array $codes, string &$error): bool
{
    if (fwrite($socket, $command . "\r\n") === false) { $error = 'Unable to write to SMTP server.'; return false; }
    [$code, $message] = feedbackSmtpRead($socket);
    if (!in_array($code, $codes, true)) { $error = 'SMTP error: ' . $message; return false; }
    return true;
}

function sendFeedbackEmail(PDO $pdo, string $to, string $subject, string $message, string &$error, bool $isHtml = false): bool
{
    $error = '';
    $from = trim((string) getSettingValue($pdo, 'mail_from', ''));
    $host = trim((string) getSettingValue($pdo, 'mail_host', ''));
    $user = trim((string) getSettingValue($pdo, 'mail_username', ''));
    $password = trim((string) getSettingValue($pdo, 'mail_password', ''));
    $port = (int) getSettingValue($pdo, 'mail_port', 587);
    $encryption = strtolower(trim((string) getSettingValue($pdo, 'mail_encryption', 'tls')));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) { $error = 'Invalid recipient or sender email.'; return false; }
    $to = str_replace(["\r", "\n"], '', $to); $subject = str_replace(["\r", "\n"], ' ', $subject);
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $contentType = $isHtml ? 'text/html' : 'text/plain';
    $headers = "From: {$from}\r\nReply-To: {$from}\r\nMIME-Version: 1.0\r\nContent-Type: {$contentType}; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    if ($host === '') return mail($to, $encodedSubject, chunk_split(base64_encode($message)), $headers);
    $implicit = in_array($encryption, ['ssl', 'smtps'], true);
    $socket = @stream_socket_client(($implicit ? 'ssl://' : 'tcp://') . $host . ':' . $port, $number, $socketError, 20);
    if (!$socket) { $error = 'SMTP connection failed: ' . $socketError; return false; }
    [$code, $greeting] = feedbackSmtpRead($socket);
    if ($code !== 220 || !feedbackSmtpCommand($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250], $error)) { fclose($socket); return false; }
    if (in_array($encryption, ['tls', 'starttls'], true)) {
        if (!feedbackSmtpCommand($socket, 'STARTTLS', [220], $error) || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) || !feedbackSmtpCommand($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250], $error)) { fclose($socket); return false; }
    }
    if ($user !== '' && (!feedbackSmtpCommand($socket, 'AUTH LOGIN', [334], $error) || !feedbackSmtpCommand($socket, base64_encode($user), [334], $error) || !feedbackSmtpCommand($socket, base64_encode($password), [235], $error))) { fclose($socket); return false; }
    if (!feedbackSmtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250], $error) || !feedbackSmtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251], $error) || !feedbackSmtpCommand($socket, 'DATA', [354], $error)) { fclose($socket); return false; }
    $mail = 'To: <' . $to . ">\r\nSubject: " . $encodedSubject . "\r\n" . $headers . "\r\n\r\n" . chunk_split(base64_encode($message));
    fwrite($socket, preg_replace('/(?m)^\./', '..', $mail) . "\r\n.\r\n");
    [$code, $reply] = feedbackSmtpRead($socket); fclose($socket);
    if ($code !== 250) { $error = 'SMTP rejected email: ' . $reply; return false; }
    return true;
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

function sendFeedbackWhatsapp(PDO $pdo, string $to, string $message): bool
{
    $url = trim((string) getSettingValue($pdo, 'whatsapp_api_url', ''));
    $recipient = normalizeWhatsappNumber($to);
    if ($url === '' || $recipient === '') return false;
    $payload = [
        'user' => getSettingValue($pdo, 'whatsapp_username', ''),
        'pass' => getSettingValue($pdo, 'whatsapp_password', ''),
        'sender' => getSettingValue($pdo, 'whatsapp_sender', ''),
        'phone' => $recipient,
        'text' => trim((string) getSettingValue($pdo, 'whatsapp_template_name', '')) ?: $message,
        'priority' => trim((string) getSettingValue($pdo, 'whatsapp_priority', 'wa')),
        'stype' => trim((string) getSettingValue($pdo, 'whatsapp_stype', 'normal')),
        'Params' => trim((string) getSettingValue($pdo, 'whatsapp_params', '')),
        'htype' => trim((string) getSettingValue($pdo, 'whatsapp_htype', 'image')),
        'url' => trim((string) getSettingValue($pdo, 'whatsapp_image_url', '')),
    ];

    $payload = http_build_query($payload);
    if ($payload === false) return false;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        $response = (string) curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        return $error === '' && $statusCode >= 200 && $statusCode < 300;
    }
    return @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => $payload]])) !== false;
}
