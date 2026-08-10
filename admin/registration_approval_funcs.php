<?php

declare(strict_types=1);

function ensureRegistrationApprovalStorage(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS event_registrations (
            registration_id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            salutation VARCHAR(20) NOT NULL,
            name VARCHAR(255) NOT NULL,
            designation VARCHAR(100),
            teacher_post VARCHAR(100),
            role VARCHAR(100),
            photograph_name VARCHAR(255),
            photograph_path VARCHAR(500),
            institution_name VARCHAR(255),
            affiliation_number VARCHAR(100),
            institution_level VARCHAR(100),
            experience VARCHAR(100),
            mobile VARCHAR(20),
            whatsapp_number VARCHAR(20),
            official_email VARCHAR(255),
            institution_address TEXT,
            city VARCHAR(100),
            district VARCHAR(100),
            state VARCHAR(100),
            declaration_accepted TINYINT(1) DEFAULT 0,
            qr_code_path VARCHAR(500),
            pass_code CHAR(5) NULL,
            approval_status ENUM('pending', 'approved', 'denied') NOT NULL DEFAULT 'pending',
            reviewed_at DATETIME NULL,
            reviewed_by VARCHAR(255) NULL,
            reviewed_by_user_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_id (event_id),
            INDEX idx_event_approval (event_id, approval_status)
        )
SQL);

    $columns = [
        'approval_status' => "ALTER TABLE event_registrations ADD COLUMN approval_status ENUM('pending', 'approved', 'denied') NOT NULL DEFAULT 'pending' AFTER qr_code_path",
        'pass_code' => 'ALTER TABLE event_registrations ADD COLUMN pass_code CHAR(5) NULL AFTER qr_code_path',
        'reviewed_at' => 'ALTER TABLE event_registrations ADD COLUMN reviewed_at DATETIME NULL AFTER approval_status',
        'reviewed_by' => 'ALTER TABLE event_registrations ADD COLUMN reviewed_by VARCHAR(255) NULL AFTER reviewed_at',
        'reviewed_by_user_id' => 'ALTER TABLE event_registrations ADD COLUMN reviewed_by_user_id INT NULL AFTER reviewed_by',
    ];

    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    foreach ($columns as $column => $sql) {
        $check->execute([':table_name' => 'event_registrations', ':column_name' => $column]);
        if ((int) $check->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }

    $indexCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
    );
    $indexCheck->execute([':table_name' => 'event_registrations', ':index_name' => 'idx_event_approval']);
    if ((int) $indexCheck->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE event_registrations ADD INDEX idx_event_approval (event_id, approval_status)');
    }

    $indexCheck->execute([':table_name' => 'event_registrations', ':index_name' => 'uq_event_pass_code']);
    if ((int) $indexCheck->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE event_registrations ADD UNIQUE INDEX uq_event_pass_code (event_id, pass_code)');
    }
}

function getOpenEventsForApproval(PDO $pdo): array
{
    $statement = $pdo->query(<<<'SQL'
        SELECT event_id, event_title, event_code, start_date
        FROM events
        WHERE event_status = 'Open'
          AND registration_required = 1
          AND (end_date IS NULL OR end_date >= CURDATE())
        ORDER BY start_date ASC, event_title ASC
SQL);
    return $statement->fetchAll();
}

function getEventRegistrationsForApproval(PDO $pdo, int $eventId): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT registration_id, name, designation, institution_name, approval_status, reviewed_at, reviewed_by, reviewed_by_user_id
        FROM event_registrations
        WHERE event_id = :event_id
        ORDER BY created_at ASC, registration_id ASC
SQL);
    $statement->execute([':event_id' => $eventId]);
    return $statement->fetchAll();
}

function openEventExists(PDO $pdo, int $eventId): bool
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT COUNT(*)
        FROM events
        WHERE event_id = :event_id
          AND event_status = 'Open'
          AND registration_required = 1
          AND (end_date IS NULL OR end_date >= CURDATE())
SQL);
    $statement->execute([':event_id' => $eventId]);
    return (int) $statement->fetchColumn() > 0;
}

function setRegistrationApproval(PDO $pdo, int $eventId, int $registrationId, string $status, ?int $reviewedByUserId, string $reviewedBy): bool
{
    if (!in_array($status, ['approved', 'denied'], true)) {
        throw new InvalidArgumentException('Invalid approval status.');
    }

    $statement = $pdo->prepare(<<<'SQL'
        UPDATE event_registrations
        SET approval_status = :status, reviewed_at = NOW(), reviewed_by = :reviewed_by, reviewed_by_user_id = :reviewed_by_user_id
        WHERE registration_id = :registration_id AND event_id = :event_id
SQL);
    $statement->execute([
        ':status' => $status,
        ':reviewed_by' => $reviewedBy,
        ':reviewed_by_user_id' => $reviewedByUserId,
        ':registration_id' => $registrationId,
        ':event_id' => $eventId,
    ]);
    return $statement->rowCount() > 0;
}

function setAllRegistrationApprovals(PDO $pdo, int $eventId, string $status, ?int $reviewedByUserId, string $reviewedBy): int
{
    if (!in_array($status, ['approved', 'denied'], true)) {
        throw new InvalidArgumentException('Invalid approval status.');
    }

    $statement = $pdo->prepare(<<<'SQL'
        UPDATE event_registrations
        SET approval_status = :status, reviewed_at = NOW(), reviewed_by = :reviewed_by, reviewed_by_user_id = :reviewed_by_user_id
        WHERE event_id = :event_id
SQL);
    $statement->execute([
        ':status' => $status,
        ':reviewed_by' => $reviewedBy,
        ':reviewed_by_user_id' => $reviewedByUserId,
        ':event_id' => $eventId,
    ]);
    return $statement->rowCount();
}
