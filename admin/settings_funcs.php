<?php

function ensureSettingsTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS settings (
            setting_id INT AUTO_INCREMENT PRIMARY KEY,
            setting_name VARCHAR(100) NOT NULL UNIQUE,
            setting_type VARCHAR(20) NOT NULL DEFAULT 'text',
            setting_value TEXT,
            setting_description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
SQL);
}

function getAllSettings(PDO $pdo): array
{
    $statement = $pdo->query('SELECT * FROM settings ORDER BY setting_name ASC, setting_id ASC');
    return $statement ? $statement->fetchAll() : [];
}

function getSettingById(PDO $pdo, int $settingId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM settings WHERE setting_id = :setting_id LIMIT 1');
    $statement->execute([':setting_id' => $settingId]);
    $setting = $statement->fetch();
    return $setting ?: null;
}

function getSettingByName(PDO $pdo, string $settingName): ?array
{
    $statement = $pdo->prepare('SELECT * FROM settings WHERE setting_name = :setting_name LIMIT 1');
    $statement->execute([':setting_name' => trim($settingName)]);
    $setting = $statement->fetch();
    return $setting ?: null;
}

function getSettingValue(PDO $pdo, string $settingName, mixed $default = null): mixed
{
    $setting = getSettingByName($pdo, $settingName);
    if (!$setting) {
        return $default;
    }

    $value = (string) ($setting['setting_value'] ?? '');
    $type = (string) ($setting['setting_type'] ?? 'text');

    return match ($type) {
        'number' => is_numeric($value) ? (float) $value : $default,
        'boolean' => in_array($value, ['1', 'true', 'yes', 'on'], true) || filter_var($value, FILTER_VALIDATE_BOOLEAN),
        default => $value,
    };
}

function seedDefaultSettings(PDO $pdo): void
{
    $defaults = [
        ['attendance_view_enabled', 'boolean', '1', 'Controls whether the live attendance display is enabled.'],
        ['attendance_view_duration_seconds', 'number', '8', 'How long each attendance slide remains visible.'],
        ['attendance_view_idle_seconds', 'number', '12', 'Seconds to wait before cycling to the next slide.'],
        ['attendance_view_title', 'text', 'Welcome Attendee', 'Title shown on the attendance display.'],
        ['attendance_view_message', 'text', 'Welcome to the event', 'Message shown on the attendance display.'],
        ['portal_base_url', 'text', '', 'Public HTTPS base URL for attendee links and media, including the application path when applicable.'],
        ['whatsapp_api_url', 'text', 'http://bhashsms.com/api/sendmsg.php', 'WhatsApp API endpoint used for sending messages.'],
        ['whatsapp_username', 'text', 'InnocentWTS', 'Username for WhatsApp API authentication.'],
        ['whatsapp_password', 'text', '123456', 'Password for WhatsApp API authentication.'],
        ['whatsapp_sender', 'text', 'BUZWAP', 'Whom the WhatsApp message is sent from.'],
        ['whatsapp_template_name', 'text', '', 'Approved WhatsApp template name used in the provider API text field.'],
        ['whatsapp_priority', 'text', 'wa', 'WhatsApp message priority parameter.'],
        ['whatsapp_stype', 'text', 'normal', 'WhatsApp message type parameter.'],
        ['whatsapp_params', 'text', '', 'Optional comma-separated WhatsApp template values in approved order. Event Pass supports {{name}}, {{event_title}}, {{pass_code}}, and {{message}}.'],
        ['whatsapp_htype', 'text', 'image', 'WhatsApp htype parameter.'],
        ['whatsapp_image_url', 'text', '', 'URL of the image to include with WhatsApp messages.'],
        ['mail_from', 'text', 'arjindermatahru@gmail.com', 'Email sender address used for event pass emails.'],
        ['mail_host', 'text', 'smtp.gmail.com', 'SMTP host used for sending email.'],
        ['mail_username', 'text', 'arjindermatahru@gmail.com', 'SMTP user name for Gmail.'],
        ['mail_password', 'text', '', 'SMTP password or app password for Gmail.'],
        ['mail_port', 'number', '587', 'SMTP port for sending email.'],
        ['mail_encryption', 'text', 'tls', 'Encryption method for SMTP.'],
    ];

    foreach ($defaults as $default) {
        [$name, $type, $value] = $default;
        if (!getSettingByName($pdo, $name)) {
            saveSetting($pdo, [
                'setting_id' => 0,
                'setting_name' => $name,
                'setting_type' => $type,
                'setting_value' => $value,
                'setting_description' => $default[3] ?? 'Default application setting.',
            ]);
        }
    }
}

function saveSetting(PDO $pdo, array $data): int
{
    $settingId = (int) ($data['setting_id'] ?? 0);
    $settingName = trim((string) ($data['setting_name'] ?? ''));
    $settingType = in_array((string) ($data['setting_type'] ?? 'text'), ['text', 'number', 'boolean'], true)
        ? (string) $data['setting_type']
        : 'text';
    $settingValue = trim((string) ($data['setting_value'] ?? ''));
    $settingDescription = trim((string) ($data['setting_description'] ?? ''));

    if ($settingName === '') {
        throw new InvalidArgumentException('Setting name is required.');
    }

    if ($settingType === 'boolean') {
        $settingValue = filter_var($settingValue, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }

    $existing = getSettingByName($pdo, $settingName);
    if ($existing && (int) ($existing['setting_id'] ?? 0) !== $settingId) {
        throw new InvalidArgumentException('A setting with this name already exists.');
    }

    $payload = [
        ':setting_name' => $settingName,
        ':setting_type' => $settingType,
        ':setting_value' => $settingValue === '' ? null : $settingValue,
        ':setting_description' => $settingDescription === '' ? null : $settingDescription,
    ];

    if ($settingId > 0) {
        $payload[':setting_id'] = $settingId;
        $pdo->prepare(
            'UPDATE settings SET setting_name = :setting_name, setting_type = :setting_type, setting_value = :setting_value, setting_description = :setting_description WHERE setting_id = :setting_id'
        )->execute($payload);
        return $settingId;
    }

    $statement = $pdo->prepare(
        'INSERT INTO settings (setting_name, setting_type, setting_value, setting_description) VALUES (:setting_name, :setting_type, :setting_value, :setting_description)'
    );
    $statement->execute($payload);
    return (int) $pdo->lastInsertId();
}

function deleteSetting(PDO $pdo, int $settingId): void
{
    $statement = $pdo->prepare('DELETE FROM settings WHERE setting_id = :setting_id');
    $statement->execute([':setting_id' => $settingId]);
}
