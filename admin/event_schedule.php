<?php

require_once __DIR__ . '/event_schedule_funcs.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    header('Location: ' . buildUrl('admin'));
    exit;
}

function requireAdminAuthentication(): void
{
    if (!($_SESSION['admin_authenticated'] ?? false)) {
        $_SESSION['admin_error'] = 'Please sign in to access the admin panel.';
        header('Location: ' . buildUrl('admin'));
        exit;
    }
}

function formatScheduleDate(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return 'Open ended';
    }

    $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $parsedDate ? $parsedDate->format('d, M, y') : $date;
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
$selectedEventId = (int) ($_GET['event_id'] ?? 0);
$formData['event_id'] = $selectedEventId;

try {
    $pdo = createDbConnection();
    ensureEventsTable($pdo);
    $events = getOpenEvents($pdo);
    if ($selectedEventId > 0) {
        $eventSchedules = getEventSchedulesForEvent($pdo, $selectedEventId);
    }
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
            header('Location: ' . buildUrl('admin/event_schedule.php') . '?event_id=' . $selectedEventId);
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
                $returnEventId = (int) ($_POST['event_id'] ?? 0);
                header('Location: ' . buildUrl('admin/event_schedule.php') . ($returnEventId > 0 ? '?event_id=' . $returnEventId : ''));
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
    $eventSchedules = getEventSchedulesForEvent($pdo, $selectedEventId);
}
ob_start();
?>

    <?php if (!empty($adminError)): ?><div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['admin_success'])): ?><div class="alert success"><?php echo htmlspecialchars((string) $_SESSION['admin_success'], ENT_QUOTES, 'UTF-8'); ?></div><?php unset($_SESSION['admin_success']); endif; ?>

    <form method="post" class="schedule-form schedule-form-two-column">
        <input type="hidden" name="action" value="save_schedule">
        <input type="hidden" name="schedule_id" value="<?php echo htmlspecialchars((string) ($formData['schedule_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

        <div class="form-grid schedule-creation-grid">
        <div class="form-field schedule-event-field">
            <label for="event_id">Select Event</label>
            <select id="event_id" name="event_id" required onchange="if (this.value) window.location.href='<?php echo htmlspecialchars(buildUrl('admin/event_schedule.php'), ENT_QUOTES, 'UTF-8'); ?>?event_id=' + encodeURIComponent(this.value)">
                <option value="">Choose an event</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?php echo (int) $event['event_id']; ?>" <?php echo ((int) ($formData['event_id'] ?? 0) === (int) $event['event_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($event['event_title'] ?? '') . ' — ' . ($event['event_status'] ?? 'Open'), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-field">
            <label for="schedule_start_date">Schedule Start Date</label>
            <input id="schedule_start_date" name="schedule_start_date" type="date" value="<?php echo htmlspecialchars((string) ($formData['schedule_start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-field">
            <label for="schedule_end_date">Schedule End Date</label>
            <input id="schedule_end_date" name="schedule_end_date" type="date" value="<?php echo htmlspecialchars((string) ($formData['schedule_end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="form-field schedule-description-field">
            <label for="schedule_description">Schedule Description</label>
            <textarea id="schedule_description" name="schedule_description"><?php echo htmlspecialchars((string) ($formData['schedule_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>
        </div>

        <div class="form-footer">
            <button type="submit" class="button-primary">Save Schedule</button>
            <?php if (!empty($formData['schedule_id'])): ?>
                <a href="<?php echo htmlspecialchars(buildUrl('admin/event_schedule.php'), ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary">Cancel Edit</a>
            <?php endif; ?>
            
        </div>
    </form>

    <div class="schedule-list-heading">
        <div><span class="schedule-kicker">Selected event</span><h2>Existing Schedules</h2></div>
        <span class="schedule-total"><?php echo count($eventSchedules); ?> total</span>
    </div>
    <?php if ($selectedEventId <= 0): ?>
        <div class="schedule-empty-state">Select an open event above to view its registration schedules.</div>
    <?php elseif (!empty($eventSchedules)): ?>
        <div class="schedule-responsive-table">
            <table>
                <thead>
                    <tr>
                        
                        <th>Event</th>
                        <th>Registration Period</th>
                        <th>Description</th>
                        <th class="schedule-actions-column">Actions</th>
                    </tr>
                </thead>
                <tbody>
            <?php foreach ($eventSchedules as $schedule): ?>
                <tr>
                    
                    <td data-label="Event">
                        <strong class="schedule-event-name"><?php echo htmlspecialchars((string) ($schedule['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                        
                    </td>
                    <td data-label="Registration Period">
                        <div class="schedule-period"><strong><?php echo htmlspecialchars(formatScheduleDate($schedule['schedule_start_date'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong><span> to </span><strong><?php echo htmlspecialchars(formatScheduleDate($schedule['schedule_end_date'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </td>
                    <td data-label="Description" class="schedule-description-cell"><?php echo nl2br(htmlspecialchars((string) ($schedule['schedule_description'] ?? 'No description provided.'), ENT_QUOTES, 'UTF-8')); ?></td>
                    <td data-label="Actions">
                        <div class="schedule-row-actions">
                        <form class="inline-form" method="get" action="<?php echo htmlspecialchars(buildUrl('admin/event_schedule.php'), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="edit" value="<?php echo (int) $schedule['schedule_id']; ?>">
                            <button class="schedule-action-button schedule-edit-button" type="submit">Edit</button>
                        </form>
                        <form class="inline-form" method="post" onsubmit="return confirm('Delete this schedule?');">
                            <input type="hidden" name="action" value="delete_schedule">
                            <input type="hidden" name="schedule_id" value="<?php echo (int) $schedule['schedule_id']; ?>">
                            <input type="hidden" name="event_id" value="<?php echo (int) $selectedEventId; ?>">
                            <button class="schedule-action-button schedule-delete-button" type="submit">Delete</button>
                        </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>No schedules found yet.</p>
    <?php endif; ?>

        <p><a href="<?php echo htmlspecialchars(buildUrl('admin'), ENT_QUOTES, 'UTF-8'); ?>">Back to admin</a></p>
</div>
<?php
$content = ob_get_clean();

renderAdminLayout('Event Registration Schedule Manager', $content, [
    'current_path' => 'event_schedule',
    'page_heading' => 'Event Registration Schedule Manager',
    'body_class' => 'event-schedule-page',
]);
