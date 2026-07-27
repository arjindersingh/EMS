<?php

require_once __DIR__ . '/events_funcs.php';

function getAllEventSchedulesWithEvent(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT es.*, e.event_title
         FROM event_schedules es
         INNER JOIN events e ON e.event_id = es.event_id
         ORDER BY e.event_title ASC, es.schedule_start_date ASC, es.schedule_id ASC'
    );

    return $statement ? $statement->fetchAll() : [];
}
