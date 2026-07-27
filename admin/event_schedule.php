<?php

require_once __DIR__ . '/event_schedule_funcs.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    header('Location: /admin');
    exit;
}

function requireAdminAuthentication(): void
{
    if (!($_SESSION['admin_authenticated'] ?? false)) {
        $_SESSION['admin_error'] = 'Please sign in to access the admin panel.';
        header('Location: /admin');
        exit;
    }
}

$pdo = null;
$adminError = '';
$eventSchedules = [];
$events = [];
$selectedEventId = 0;
$selectedScheduleId = 0;
$formData = [
    'schedule_id' => '',
    'event_id' => '',
    'schedule_start_date' => '',
    'schedule_end_date' => '',
    'schedule_description' => '',
];

try {
    $pdo = createDbConnection();
    ensureEventsTable($pdo);
    $events = getAllEvents($pdo);
    $eventSchedules = getAllEventSchedulesWithEvent($pdo);
} catch (PDOException $exception) {
    $adminError = 'Database connection failed: ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_schedule') {
        requireAdminAuthentication();
        $selectedEventId = (int) ($_POST['event_id'] ?? 0);
        $selectedScheduleId = (int) ($_POST['schedule_id'] ?? 0);

        $formData = [
            'schedule_id' => $selectedScheduleId,
            'event_id' => $selectedEventId,
            'schedule_start_date' => trim((string) ($_POST['schedule_start_date'] ?? '')),
            'schedule_end_date' => trim((string) ($_POST['schedule_end_date'] ?? '')),
            'schedule_description' => trim((string) ($_POST['schedule_description'] ?? '')),
        ];

        try {
            saveEventSchedule($pdo, $formData);
            $_SESSION['admin_success'] = $selectedScheduleId > 0 ? 'Schedule updated successfully.' : 'Schedule created successfully.';
            header('Location: /admin/event_schedule.php');
            exit;
        } catch (InvalidArgumentException $exception) {
            $adminError = $exception->getMessage();
        } catch (PDOException $exception) {
            $adminError = 'Unable to save schedule: ' . $exception->getMessage();
        }
    } elseif ($action === 'delete_schedule') {
        requireAdminAuthentication();
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
        if ($scheduleId > 0 && $pdo !== null) {
            try {
                deleteEventSchedule($pdo, $scheduleId);
                $_SESSION['admin_success'] = 'Schedule deleted successfully.';
                header('Location: /admin/event_schedule.php');
                exit;
            } catch (PDOException $exception) {
                $adminError = 'Unable to delete schedule: ' . $exception->getMessage();
            }
        }
    }
}

if (isset($_GET['edit']) && $pdo !== null) {
    $scheduleId = (int) $_GET['edit'];
    $schedule = getEventScheduleById($pdo, $scheduleId);
    if ($schedule) {
        $selectedScheduleId = (int) $schedule['schedule_id'];
        $selectedEventId = (int) $schedule['event_id'];
        $formData = [
            'schedule_id' => $selectedScheduleId,
            'event_id' => $selectedEventId,
            'schedule_start_date' => $schedule['schedule_start_date'] ?? '',
            'schedule_end_date' => $schedule['schedule_end_date'] ?? '',
            'schedule_description' => $schedule['schedule_description'] ?? '',
        ];
    } else {
        $adminError = 'Schedule not found.';
    }
}

if ($selectedEventId > 0 && $pdo !== null) {
    $eventSchedules = getAllEventSchedulesWithEvent($pdo);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Schedule Manager</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; line-height: 1.6; color: #222; }
        .card { max-width: 1100px; margin: 0 auto; padding: 1.5rem 2rem; border: 1px solid #d0d7de; border-radius: 8px; background: #f8f9fa; }
        .alert { padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: 6px; }
        .alert.error { background: #ffe8e8; color: #9c1c1c; }
        .alert.success { background: #e8f7eb; color: #20653d; }
        form { margin-top: 1rem; }
        label { display: block; margin-top: 0.75rem; font-weight: bold; }
        input, textarea, select { width: 100%; padding: 0.65rem; margin: 0.35rem 0 0.8rem; box-sizing: border-box; }
        textarea { min-height: 110px; }
        button { padding: 0.6rem 1rem; cursor: pointer; }
        .actions { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #d0d7de; padding: 0.6rem; text-align: left; vertical-align: top; }
        th { background: #eef2f7; }
        .inline-form { display: inline; }
    </style>
</head>
<body>
<div class="card">
    <h1>Event Schedule Manager</h1>
    <p>Add, edit, delete, and review schedules for each event from a single page.</p>

    <?php if (!empty($adminError)): ?><div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['admin_success'])): ?><div class="alert success"><?php echo htmlspecialchars((string) $_SESSION['admin_success'], ENT_QUOTES, 'UTF-8'); ?></div><?php unset($_SESSION['admin_success']); endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="save_schedule">
        <input type="hidden" name="schedule_id" value="<?php echo htmlspecialchars((string) ($formData['schedule_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

        <label for="event_id">Select Event</label>
        <select id="event_id" name="event_id" required>
            <option value="">Choose an event</option>
            <?php foreach ($events as $event): ?>
                <option value="<?php echo (int) $event['event_id']; ?>" <?php echo ((int) ($formData['event_id'] ?? 0) === (int) $event['event_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($event['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="schedule_start_date">Schedule Start Date</label>
        <input id="schedule_start_date" name="schedule_start_date" type="date" value="<?php echo htmlspecialchars((string) ($formData['schedule_start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>

        <label for="schedule_end_date">Schedule End Date</label>
        <input id="schedule_end_date" name="schedule_end_date" type="date" value="<?php echo htmlspecialchars((string) ($formData['schedule_end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

        <label for="schedule_description">Schedule Description</label>
        <textarea id="schedule_description" name="schedule_description"><?php echo htmlspecialchars((string) ($formData['schedule_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>

        <div class="actions">
            <button type="submit">Save Schedule</button>
            <?php if (!empty($formData['schedule_id'])): ?>
                <a href="/admin/event_schedule.php"><button type="button">Cancel Edit</button></a>
            <?php endif; ?>
        </div>
    </form>

    <h2>Existing Schedules</h2>
    <?php if (!empty($eventSchedules)): ?>
        <table>
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Description</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventSchedules as $schedule): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($schedule['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($schedule['schedule_start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($schedule['schedule_end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo nl2br(htmlspecialchars((string) ($schedule['schedule_description'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></td>
                        <td>
                            <a href="/admin/event_schedule.php?edit=<?php echo (int) $schedule['schedule_id']; ?>">Edit</a>
                            |
                            <form class="inline-form" method="post" onsubmit="return confirm('Delete this schedule?');">
                                <input type="hidden" name="action" value="delete_schedule">
                                <input type="hidden" name="schedule_id" value="<?php echo (int) $schedule['schedule_id']; ?>">
                                <button type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No schedules found yet.</p>
    <?php endif; ?>

    <p><a href="/admin">Back to admin</a></p>
</div>
</body>
</html>
