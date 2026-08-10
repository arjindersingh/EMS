<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function ensureAdminUsersTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS admin_users (
            admin_user_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            username VARCHAR(100) NOT NULL UNIQUE,
            email VARCHAR(191) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'admin',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_admin_users_email (email),
            INDEX idx_admin_users_username (username)
        )
SQL
    );
}

function requireAdminAuthentication(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!($_SESSION['admin_authenticated'] ?? false)) {
        $_SESSION['admin_error'] = 'Please sign in to access the admin panel.';
        header('Location: ' . buildUrl('admin'));
        exit;
    }
}

function getAdminUsersCount(PDO $pdo): int
{
    $statement = $pdo->query('SELECT COUNT(*) FROM admin_users');
    return $statement ? (int) $statement->fetchColumn() : 0;
}

function getAdminUserByLogin(PDO $pdo, string $login): ?array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT admin_user_id, name, username, email, password_hash, role
        FROM admin_users
        WHERE username = :login_username OR email = :login_email
        LIMIT 1
SQL
    );
    $statement->execute([
        ':login_username' => $login,
        ':login_email' => $login,
    ]);
    $user = $statement->fetch();
    return $user ?: null;
}

function adminUserExists(PDO $pdo, string $username, string $email, ?int $excludeAdminUserId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM admin_users WHERE (username = :username OR email = :email)';
    if ($excludeAdminUserId !== null) {
        $sql .= ' AND admin_user_id != :exclude_admin_user_id';
    }

    $statement = $pdo->prepare($sql);
    $params = [
        ':username' => $username,
        ':email' => $email,
    ];

    if ($excludeAdminUserId !== null) {
        $params[':exclude_admin_user_id'] = $excludeAdminUserId;
    }

    $statement->execute($params);
    return (int) $statement->fetchColumn() > 0;
}

function getAllAdminUsers(PDO $pdo): array
{
    $statement = $pdo->query('SELECT admin_user_id, name, username, email, role, created_at, updated_at FROM admin_users ORDER BY created_at DESC');
    return $statement ? $statement->fetchAll() : [];
}

function createAdminUser(PDO $pdo, string $name, string $email, string $username, string $password, string $role = 'admin'): int
{
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO admin_users (name, username, email, password_hash, role)
        VALUES (:name, :username, :email, :password_hash, :role)
SQL
    );
    $statement->execute([
        ':name' => $name,
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $passwordHash,
        ':role' => $role,
    ]);
    return (int) $pdo->lastInsertId();
}

function updateAdminUser(PDO $pdo, int $adminUserId, string $name, string $email, string $username, ?string $password = null, string $role = 'admin'): bool
{
    $params = [
        ':admin_user_id' => $adminUserId,
        ':name' => $name,
        ':username' => $username,
        ':email' => $email,
        ':role' => $role,
    ];

    if ($password !== null && trim($password) !== '') {
        $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $sql = <<< 'SQL'
            UPDATE admin_users
            SET name = :name,
                username = :username,
                email = :email,
                password_hash = :password_hash,
                role = :role,
                updated_at = CURRENT_TIMESTAMP
            WHERE admin_user_id = :admin_user_id
SQL;
    } else {
        $sql = <<< 'SQL'
            UPDATE admin_users
            SET name = :name,
                username = :username,
                email = :email,
                role = :role,
                updated_at = CURRENT_TIMESTAMP
            WHERE admin_user_id = :admin_user_id
SQL;
    }

    $statement = $pdo->prepare($sql);
    return $statement->execute($params);
}

function deleteAdminUser(PDO $pdo, int $adminUserId): bool
{
    $statement = $pdo->prepare('DELETE FROM admin_users WHERE admin_user_id = :admin_user_id');
    $statement->execute([':admin_user_id' => $adminUserId]);
    return $statement->rowCount() > 0;
}

function getAdminUserById(PDO $pdo, int $adminUserId): ?array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT admin_user_id, name, username, email, role, created_at, updated_at
        FROM admin_users
        WHERE admin_user_id = :admin_user_id
        LIMIT 1
SQL
    );
    $statement->execute([':admin_user_id' => $adminUserId]);
    $user = $statement->fetch();
    return $user ?: null;
}

function parseAdminUsersEnv(): array
{
    $configuredUsers = trim((string) getenv('ADMIN_USERS'));
    if ($configuredUsers === '') {
        return [];
    }

    $accounts = [];
    $entries = preg_split('/[\r\n,;]+/', $configuredUsers);
    foreach ($entries as $entry) {
        $entry = trim((string) $entry);
        if ($entry === '') {
            continue;
        }

        $parts = explode(':', $entry);
        if (count($parts) < 2) {
            continue;
        }

        $username = trim((string) ($parts[0] ?? ''));
        $password = trim((string) ($parts[1] ?? ''));
        $email = trim((string) ($parts[2] ?? ''));
        $name = trim((string) ($parts[3] ?? ''));

        if ($username === '' || $password === '') {
            continue;
        }

        if ($email === '') {
            $email = str_contains($username, '@') ? $username : $username . '@example.com';
        }

        if ($name === '') {
            $name = ucfirst($username);
        }

        $accounts[] = [
            'username' => $username,
            'password' => $password,
            'email' => $email,
            'name' => $name,
        ];
    }

    return $accounts;
}

function migrateEnvAdminUsers(PDO $pdo): void
{
    if (getAdminUsersCount($pdo) > 0) {
        return;
    }

    $users = parseAdminUsersEnv();
    foreach ($users as $user) {
        if (!adminUserExists($pdo, $user['username'], $user['email'])) {
            try {
                createAdminUser($pdo, $user['name'], $user['email'], $user['username'], $user['password']);
            } catch (PDOException $exception) {
                // ignore duplicates or other issues during migration
            }
        }
    }
}

function createDefaultAdminUserIfNoneExists(PDO $pdo): void
{
    migrateEnvAdminUsers($pdo);

    if (getAdminUsersCount($pdo) > 0) {
        return;
    }

    $email = trim((string) getenv('ADMIN_EMAIL')) ?: 'admin@example.com';
    $username = trim((string) getenv('ADMIN_USERNAME')) ?: 'admin';
    $password = trim((string) getenv('ADMIN_PASSWORD')) ?: 'admin123';
    $name = trim((string) getenv('ADMIN_NAME')) ?: 'Administrator';

    try {
        createAdminUser($pdo, $name, $email, $username, $password);
    } catch (PDOException $exception) {
        // If creation fails because of duplicate or other issues, ignore it here.
    }
}
