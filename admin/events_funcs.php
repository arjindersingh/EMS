<?php

// Event helper functions (no side effects)
function ensureEventsTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS events (
            event_id INT AUTO_INCREMENT PRIMARY KEY,
            event_title VARCHAR(255) NOT NULL,
            event_code VARCHAR(50) UNIQUE,
            event_tagline VARCHAR(255),
            event_theme VARCHAR(255),
            event_type ENUM('Academic','Cultural','Sports','Seminar','Workshop','Conference','Meeting','Celebration','Competition','Other') DEFAULT 'Other',
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
}

function getAllEvents(PDO $pdo): array
{
    $statement = $pdo->query('SELECT * FROM events ORDER BY start_date DESC, event_title ASC');
    return $statement ? $statement->fetchAll() : [];
}

function getEventById(PDO $pdo, int $eventId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM events WHERE event_id = :event_id LIMIT 1');
    $statement->execute([':event_id' => $eventId]);
    $event = $statement->fetch();
    return $event ?: null;
}

function saveEvent(PDO $pdo, array $data): int
{
    $eventId = (int) ($data['event_id'] ?? 0);
    $eventTitle = trim((string) ($data['event_title'] ?? ''));
    $eventCode = trim((string) ($data['event_code'] ?? ''));
    $eventTagline = trim((string) ($data['event_tagline'] ?? ''));
    $eventTheme = trim((string) ($data['event_theme'] ?? ''));
    $eventType = trim((string) ($data['event_type'] ?? 'Other'));
    $eventDescription = trim((string) ($data['event_description'] ?? ''));
    $startDate = trim((string) ($data['start_date'] ?? ''));
    $endDate = trim((string) ($data['end_date'] ?? ''));
    $startTime = trim((string) ($data['start_time'] ?? ''));
    $endTime = trim((string) ($data['end_time'] ?? ''));
    $venueName = trim((string) ($data['venue_name'] ?? ''));
    $venueAddress = trim((string) ($data['venue_address'] ?? ''));
    $city = trim((string) ($data['city'] ?? ''));
    $state = trim((string) ($data['state'] ?? ''));
    $country = trim((string) ($data['country'] ?? ''));
    $registrationRequired = !empty($data['registration_required']) ? 1 : 0;
    $registrationStartDate = trim((string) ($data['registration_start_date'] ?? ''));
    $registrationEndDate = trim((string) ($data['registration_end_date'] ?? ''));
    $registrationFee = trim((string) ($data['registration_fee'] ?? '0.00'));

    if ($eventTitle === '' || $startDate === '') {
        throw new InvalidArgumentException('Event title and start date are required.');
    }

    $payload = [
        ':event_title' => $eventTitle,
        ':event_code' => $eventCode === '' ? null : $eventCode,
        ':event_tagline' => $eventTagline === '' ? null : $eventTagline,
        ':event_theme' => $eventTheme === '' ? null : $eventTheme,
        ':event_type' => $eventType,
        ':event_description' => $eventDescription === '' ? null : $eventDescription,
        ':start_date' => $startDate,
        ':end_date' => $endDate === '' ? null : $endDate,
        ':start_time' => $startTime === '' ? null : $startTime,
        ':end_time' => $endTime === '' ? null : $endTime,
        ':venue_name' => $venueName === '' ? null : $venueName,
        ':venue_address' => $venueAddress === '' ? null : $venueAddress,
        ':city' => $city === '' ? null : $city,
        ':state' => $state === '' ? null : $state,
        ':country' => $country === '' ? null : $country,
        ':registration_required' => $registrationRequired,
        ':registration_start_date' => $registrationStartDate === '' ? null : $registrationStartDate,
        ':registration_end_date' => $registrationEndDate === '' ? null : $registrationEndDate,
        ':registration_fee' => $registrationFee === '' ? '0.00' : $registrationFee,
    ];

    if ($eventId > 0) {
        $payload[':event_id'] = $eventId;
        $pdo->prepare(
            'UPDATE events SET event_title = :event_title, event_code = :event_code, event_tagline = :event_tagline, event_theme = :event_theme, event_type = :event_type, event_description = :event_description, start_date = :start_date, end_date = :end_date, start_time = :start_time, end_time = :end_time, venue_name = :venue_name, venue_address = :venue_address, city = :city, state = :state, country = :country, registration_required = :registration_required, registration_start_date = :registration_start_date, registration_end_date = :registration_end_date, registration_fee = :registration_fee WHERE event_id = :event_id'
        )->execute($payload);
        return $eventId;
    }

    $statement = $pdo->prepare(
        'INSERT INTO events (event_title, event_code, event_tagline, event_theme, event_type, event_description, start_date, end_date, start_time, end_time, venue_name, venue_address, city, state, country, registration_required, registration_start_date, registration_end_date, registration_fee) VALUES (:event_title, :event_code, :event_tagline, :event_theme, :event_type, :event_description, :start_date, :end_date, :start_time, :end_time, :venue_name, :venue_address, :city, :state, :country, :registration_required, :registration_start_date, :registration_end_date, :registration_fee)'
    );
    $statement->execute($payload);
    return (int) $pdo->lastInsertId();
}

function deleteEvent(PDO $pdo, int $eventId): void
{
    $statement = $pdo->prepare('DELETE FROM events WHERE event_id = :event_id');
    $statement->execute([':event_id' => $eventId]);
}
