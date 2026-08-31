<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../admin/auth_funcs.php';
require_once __DIR__ . '/../admin/settings_funcs.php';
require_once __DIR__ . '/../admin/registration_approval_funcs.php';
require_once __DIR__ . '/../admin/event_checkin_funcs.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * @return never
 */
function sendJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function ensureApiTokensTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS api_tokens (
            token_id INT AUTO_INCREMENT PRIMARY KEY,
            admin_user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            device_label VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            INDEX idx_api_tokens_admin_user (admin_user_id)
        )
SQL);
}

function getBearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', trim((string) $header), $matches)) {
        return null;
    }

    return trim($matches[1]);
}

function createApiToken(PDO $pdo, int $adminUserId, string $deviceLabel): string
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO api_tokens (admin_user_id, token_hash, device_label)
        VALUES (:admin_user_id, :token_hash, :device_label)
SQL);
    $statement->execute([
        ':admin_user_id' => $adminUserId,
        ':token_hash' => $tokenHash,
        ':device_label' => $deviceLabel === '' ? null : $deviceLabel,
    ]);

    return $token;
}

function getApiTokenRowByHash(PDO $pdo, string $tokenHash): ?array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT token_id, admin_user_id
        FROM api_tokens
        WHERE token_hash = :token_hash
          AND revoked_at IS NULL
        LIMIT 1
SQL);
    $statement->execute([':token_hash' => $tokenHash]);
    $row = $statement->fetch();
    return $row ?: null;
}

function revokeApiTokenByHash(PDO $pdo, string $tokenHash): void
{
    $statement = $pdo->prepare('UPDATE api_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE token_hash = :token_hash');
    $statement->execute([':token_hash' => $tokenHash]);
}

/**
 * Authenticates the current request via its bearer token and returns the admin user row.
 * Sends a 401 JSON response and exits if authentication fails.
 */
function requireApiAuth(PDO $pdo): array
{
    $token = getBearerToken();
    if ($token === null || $token === '') {
        sendJson(401, [
            'success' => false,
            'message' => 'Missing or malformed Authorization header.',
        ]);
    }

    $tokenHash = hash('sha256', $token);
    $tokenRow = getApiTokenRowByHash($pdo, $tokenHash);
    if ($tokenRow === null) {
        sendJson(401, [
            'success' => false,
            'message' => 'Your session has expired. Please sign in again.',
        ]);
    }

    $admin = getAdminUserById($pdo, (int) $tokenRow['admin_user_id']);
    if ($admin === null) {
        sendJson(401, [
            'success' => false,
            'message' => 'Your session has expired. Please sign in again.',
        ]);
    }

    $updateStatement = $pdo->prepare('UPDATE api_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE token_hash = :token_hash');
    $updateStatement->execute([':token_hash' => $tokenHash]);

    $admin['_token_hash'] = $tokenHash;

    return $admin;
}

function readJsonOrFormBody(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return [];
    }

    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw !== false ? $raw : '', true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function connectDatabaseOrFail(): PDO
{
    try {
        $pdo = createDbConnection();
        ensureSettingsTable($pdo);
        ensureAdminUsersTable($pdo);
        ensureApiTokensTable($pdo);
        ensureRegistrationApprovalStorage($pdo);
        ensureEventAttendanceTable($pdo);
        ensureEventAttendanceColumns($pdo);
        ensureCheckinAnnouncementSettings($pdo);

        return $pdo;
    } catch (PDOException $exception) {
        sendJson(500, [
            'success' => false,
            'message' => 'Database connection failed: ' . $exception->getMessage(),
        ]);
    }
}
