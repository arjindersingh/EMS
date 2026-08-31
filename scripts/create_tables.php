<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../admin/settings_funcs.php';

function getServerConnection(): PDO
{
    $config = getDbConfig();
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['port']);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    return new PDO($dsn, $config['username'], $config['password'], $options);
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
    $statement->execute([':table_name' => $tableName]);
    return (int) $statement->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name');
    $statement->execute([':table_name' => $tableName, ':column_name' => $columnName]);
    return (int) $statement->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $tableName, string $indexName): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name');
    $statement->execute([':table_name' => $tableName, ':index_name' => $indexName]);
    return (int) $statement->fetchColumn() > 0;
}

function addColumnIfMissing(PDO $pdo, string $tableName, string $columnName, string $definition): void
{
    if (columnExists($pdo, $tableName, $columnName)) {
        return;
    }

    $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $tableName, $columnName, $definition));
}

function addIndexIfMissing(PDO $pdo, string $tableName, string $indexName, string $definition): void
{
    if (indexExists($pdo, $tableName, $indexName)) {
        return;
    }

    $pdo->exec(sprintf('ALTER TABLE %s ADD INDEX %s %s', $tableName, $indexName, $definition));
}

function settingExists(PDO $pdo, string $settingName): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE setting_name = :setting_name');
    $statement->execute([':setting_name' => trim($settingName)]);
    return (int) $statement->fetchColumn() > 0;
}

function insertSetting(PDO $pdo, string $name, string $type, ?string $value, string $description): void
{
    $statement = $pdo->prepare(
        'INSERT INTO settings (setting_name, setting_type, setting_value, setting_description) VALUES (:setting_name, :setting_type, :setting_value, :setting_description)'
    );
    $statement->execute([
        ':setting_name' => $name,
        ':setting_type' => $type,
        ':setting_value' => $value,
        ':setting_description' => $description,
    ]);
}

function seedDefaultSettings(PDO $pdo): void
{
    if (!tableExists($pdo, 'settings')) {
        return;
    }

    $defaultSettings = [
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

    foreach ($defaultSettings as [$name, $type, $value, $description]) {
        if (!settingExists($pdo, $name)) {
            insertSetting($pdo, $name, $type, $value === '' ? null : $value, $description);
        }
    }
}

function ensureAppSchema(PDO $pdo): void
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
SQL
    );

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS events (
            event_id INT AUTO_INCREMENT PRIMARY KEY,
            event_title VARCHAR(255) NOT NULL,
            event_code VARCHAR(50) UNIQUE,
            event_tagline VARCHAR(255),
            event_theme VARCHAR(255),
            event_type ENUM('Academic','Cultural','Sports','Seminar','Workshop','Conference','Meeting','Celebration','Competition','Other') DEFAULT 'Other',
            event_status ENUM('Open','Suspended','Delayed','Postponed','Closed') NOT NULL DEFAULT 'Open',
            event_description TEXT,
            start_date DATE NOT NULL,
            end_date DATE,
            start_time TIME,
            end_time TIME,
            venue_name VARCHAR(255),
            venue_address TEXT,
            city VARCHAR(20),
            state VARCHAR(20),
            country VARCHAR(20),
            registration_required TINYINT(1) DEFAULT 0,
            registration_start_date DATE,
            registration_end_date DATE,
            registration_fee DECIMAL(10,2) DEFAULT 0.00,
            created_by INT,
            updated_by INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
SQL
    );

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS event_schedules (
            schedule_id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            schedule_start_date DATE NOT NULL,
            schedule_end_date DATE,
            schedule_description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_event_schedules_event FOREIGN KEY (event_id) REFERENCES events(event_id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        )
SQL
    );

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS event_itineraries (
            itinerary_id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            itinerary_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME,
            activity VARCHAR(255) NOT NULL,
            resource_person VARCHAR(255),
            itinerary_description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_itinerary_event_date (event_id, itinerary_date),
            CONSTRAINT fk_event_itineraries_event FOREIGN KEY (event_id) REFERENCES events(event_id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        )
SQL
    );

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS registration_form_options (
            option_id INT AUTO_INCREMENT PRIMARY KEY,
            option_group VARCHAR(50) NOT NULL,
            option_value VARCHAR(150) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_registration_option (option_group, option_value)
        )
SQL
    );

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS special_registration_invitations (
            invitation_id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            candidate_name VARCHAR(255) NULL,
            email VARCHAR(255) NULL,
            whatsapp_number VARCHAR(30) NULL,
            access_token CHAR(64) NOT NULL UNIQUE,
            status ENUM('created','sent','completed','expired') NOT NULL DEFAULT 'created',
            expires_at DATETIME NULL,
            registration_id INT NULL,
            sent_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_special_registration_event (event_id)
        )
SQL
    );

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
            country VARCHAR(100),
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
SQL
    );

    addColumnIfMissing($pdo, 'event_registrations', 'reviewed_by_user_id', 'INT NULL AFTER reviewed_by');
    addColumnIfMissing($pdo, 'event_registrations', 'qr_code_path', 'VARCHAR(500)');
    addColumnIfMissing($pdo, 'event_registrations', 'country', 'VARCHAR(100) AFTER state');
    addColumnIfMissing($pdo, 'event_registrations', 'pass_code', 'CHAR(5) NULL AFTER qr_code_path');
    addColumnIfMissing($pdo, 'event_registrations', 'approval_status', "ENUM('pending', 'approved', 'denied') NOT NULL DEFAULT 'pending' AFTER qr_code_path");
    addColumnIfMissing($pdo, 'event_registrations', 'reviewed_at', 'DATETIME NULL AFTER approval_status');
    addColumnIfMissing($pdo, 'event_registrations', 'reviewed_by', 'VARCHAR(255) NULL AFTER reviewed_at');
    addIndexIfMissing($pdo, 'event_registrations', 'idx_event_approval', '(event_id, approval_status)');
    addIndexIfMissing($pdo, 'event_registrations', 'uq_event_pass_code', '(event_id, pass_code)');

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
            INDEX idx_event_attendance_registration (registration_id),
            UNIQUE KEY uq_event_attendance_registration (event_id, registration_id)
        )
SQL
    );

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
SQL
    );

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

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS feedback_types (
            feedback_type_id INT AUTO_INCREMENT PRIMARY KEY,
            type_name VARCHAR(100) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
SQL
    );

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
SQL
    );

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
SQL
    );

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
SQL
    );

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
SQL
    );

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
SQL
    );

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
            created_by_user_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
SQL
    );

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
            CONSTRAINT fk_broadcast_logs_broadcast FOREIGN KEY (broadcast_id) REFERENCES broadcast_messages (broadcast_id)
                ON DELETE CASCADE
        )
SQL
    );
}

$config = getDbConfig();
$serverPdo = getServerConnection();
$serverPdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $config['database']));

$pdo = getServerConnection();
$pdo->exec(sprintf('USE `%s`', $config['database']));
ensureAppSchema($pdo);
seedDefaultSettings($pdo);

$createdTables = [];
foreach (['admin_users','settings','events','event_schedules','event_itineraries','registration_form_options','special_registration_invitations','event_registrations','event_attendance','api_tokens','event_pass_history','feedback_types','feedback_items','feedback_invitations','feedback_responses','feedback_delivery_history','feedback_qualitative_responses','broadcast_messages','broadcast_logs'] as $table) {
    if (tableExists($pdo, $table)) {
        $createdTables[] = $table;
    }
}

echo 'Database schema initialized successfully.' . PHP_EOL;
echo 'Tables created/confirmed: ' . implode(', ', $createdTables) . PHP_EOL;
