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
