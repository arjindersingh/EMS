<?php

require_once __DIR__ . '/events_funcs.php';

function getAllEventSchedulesWithEvent(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT es.*, e.event_title, e.event_status
         FROM event_schedules es
         INNER JOIN events e ON e.event_id = es.event_id
         ORDER BY e.event_title ASC, es.schedule_start_date ASC, es.schedule_id ASC'
    );

    return $statement ? $statement->fetchAll() : [];
}

function getEventSchedulesForEvent(PDO $pdo, int $eventId): array
{
    $statement = $pdo->prepare(
        'SELECT es.*, e.event_title, e.event_status
         FROM event_schedules es
         INNER JOIN events e ON e.event_id = es.event_id
         WHERE es.event_id = :event_id
         ORDER BY es.schedule_start_date ASC, es.schedule_id ASC'
    );
    $statement->execute([':event_id' => $eventId]);
    return $statement->fetchAll() ?: [];
}
