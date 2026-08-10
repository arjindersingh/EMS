<?php

require_once __DIR__ . '/events_funcs.php';

function ensureEventItinerariesTable(PDO $pdo): void
{
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
            CONSTRAINT fk_event_itineraries_event
                FOREIGN KEY (event_id) REFERENCES events(event_id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        )
SQL);
}

function getEventItineraries(PDO $pdo, int $eventId): array
{
    $statement = $pdo->prepare(
        'SELECT ei.*, e.event_title, e.event_status
         FROM event_itineraries ei
         INNER JOIN events e ON e.event_id = ei.event_id
         WHERE ei.event_id = :event_id
         ORDER BY ei.itinerary_date ASC, ei.start_time ASC, ei.itinerary_id ASC'
    );
    $statement->execute([':event_id' => $eventId]);
    return $statement->fetchAll() ?: [];
}

function getEventItineraryById(PDO $pdo, int $itineraryId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM event_itineraries WHERE itinerary_id = :itinerary_id LIMIT 1');
    $statement->execute([':itinerary_id' => $itineraryId]);
    $itinerary = $statement->fetch();
    return $itinerary ?: null;
}

function saveEventItinerary(PDO $pdo, array $data): int
{
    $itineraryId = (int) ($data['itinerary_id'] ?? 0);
    $eventId = (int) ($data['event_id'] ?? 0);
    $itineraryDate = trim((string) ($data['itinerary_date'] ?? ''));
    $startTime = trim((string) ($data['start_time'] ?? ''));
    $endTime = trim((string) ($data['end_time'] ?? ''));
    $activity = trim((string) ($data['activity'] ?? ''));
    $resourcePerson = trim((string) ($data['resource_person'] ?? ''));
    $description = trim((string) ($data['itinerary_description'] ?? ''));

    if ($eventId <= 0 || $itineraryDate === '' || $startTime === '' || $activity === '') {
        throw new InvalidArgumentException('Event, date, start time, and activity are required.');
    }

    if ($endTime !== '' && $endTime <= $startTime) {
        throw new InvalidArgumentException('End time must be later than start time.');
    }

    $event = getEventById($pdo, $eventId);
    if (!$event) {
        throw new InvalidArgumentException('The selected event does not exist.');
    }

    $eventEndDate = !empty($event['end_date']) ? (string) $event['end_date'] : (string) $event['start_date'];
    if ($itineraryDate < (string) $event['start_date'] || $itineraryDate > $eventEndDate) {
        throw new InvalidArgumentException('Itinerary date must fall within the event dates.');
    }

    $payload = [
        ':event_id' => $eventId,
        ':itinerary_date' => $itineraryDate,
        ':start_time' => $startTime,
        ':end_time' => $endTime === '' ? null : $endTime,
        ':activity' => $activity,
        ':resource_person' => $resourcePerson === '' ? null : $resourcePerson,
        ':itinerary_description' => $description === '' ? null : $description,
    ];

    if ($itineraryId > 0) {
        $payload[':itinerary_id'] = $itineraryId;
        $pdo->prepare(
            'UPDATE event_itineraries SET event_id = :event_id, itinerary_date = :itinerary_date, start_time = :start_time, end_time = :end_time, activity = :activity, resource_person = :resource_person, itinerary_description = :itinerary_description WHERE itinerary_id = :itinerary_id'
        )->execute($payload);
        return $itineraryId;
    }

    $statement = $pdo->prepare(
        'INSERT INTO event_itineraries (event_id, itinerary_date, start_time, end_time, activity, resource_person, itinerary_description) VALUES (:event_id, :itinerary_date, :start_time, :end_time, :activity, :resource_person, :itinerary_description)'
    );
    $statement->execute($payload);
    return (int) $pdo->lastInsertId();
}

function deleteEventItinerary(PDO $pdo, int $itineraryId): void
{
    $statement = $pdo->prepare('DELETE FROM event_itineraries WHERE itinerary_id = :itinerary_id');
    $statement->execute([':itinerary_id' => $itineraryId]);
}
