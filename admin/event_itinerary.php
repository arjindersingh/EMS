<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/event_itinerary_funcs.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    header('Location: ' . buildUrl('admin'));
    exit;
}

function formatItineraryDate(?string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', (string) $date);
    return $parsed ? $parsed->format('d, M, y') : (string) $date;
}

function formatItineraryTime(?string $time): string
{
    if (empty($time)) {
        return '—';
    }
    $parsed = DateTimeImmutable::createFromFormat('H:i:s', (string) $time)
        ?: DateTimeImmutable::createFromFormat('H:i', (string) $time);
    return $parsed ? $parsed->format('h:i A') : (string) $time;
}

$pdo = null;
$events = [];
$itineraries = [];
$adminError = '';
$selectedEventId = (int) ($_GET['event_id'] ?? 0);
$formData = [
    'itinerary_id' => '',
    'event_id' => $selectedEventId,
    'itinerary_date' => '',
    'start_time' => '',
    'end_time' => '',
    'activity' => '',
    'resource_person' => '',
    'itinerary_description' => '',
];

try {
    $pdo = createDbConnection();
    ensureEventsTable($pdo);
    ensureEventItinerariesTable($pdo);
    $events = getOpenEvents($pdo);
    if ($selectedEventId > 0) {
        $itineraries = getEventItineraries($pdo, $selectedEventId);
    }
} catch (PDOException $exception) {
    $adminError = 'Database connection failed: ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo !== null) {
    $action = $_POST['action'] ?? '';
    $selectedEventId = (int) ($_POST['event_id'] ?? 0);

    if ($action === 'save_itinerary') {
        $formData = [
            'itinerary_id' => (int) ($_POST['itinerary_id'] ?? 0),
            'event_id' => $selectedEventId,
            'itinerary_date' => trim((string) ($_POST['itinerary_date'] ?? '')),
            'start_time' => trim((string) ($_POST['start_time'] ?? '')),
            'end_time' => trim((string) ($_POST['end_time'] ?? '')),
            'activity' => trim((string) ($_POST['activity'] ?? '')),
            'resource_person' => trim((string) ($_POST['resource_person'] ?? '')),
            'itinerary_description' => trim((string) ($_POST['itinerary_description'] ?? '')),
        ];

        try {
            saveEventItinerary($pdo, $formData);
            $_SESSION['admin_success'] = !empty($formData['itinerary_id']) ? 'Itinerary item updated.' : 'Itinerary item created.';
            header('Location: ' . buildUrl('admin/event_itinerary.php') . '?event_id=' . $selectedEventId);
            exit;
        } catch (InvalidArgumentException $exception) {
            $adminError = $exception->getMessage();
        } catch (PDOException $exception) {
            $adminError = 'Unable to save itinerary: ' . $exception->getMessage();
        }
    } elseif ($action === 'delete_itinerary') {
        $itineraryId = (int) ($_POST['itinerary_id'] ?? 0);
        if ($itineraryId > 0) {
            deleteEventItinerary($pdo, $itineraryId);
            $_SESSION['admin_success'] = 'Itinerary item deleted.';
            header('Location: ' . buildUrl('admin/event_itinerary.php') . '?event_id=' . $selectedEventId);
            exit;
        }
    }
}

if (isset($_GET['edit']) && $pdo !== null) {
    $itinerary = getEventItineraryById($pdo, (int) $_GET['edit']);
    if ($itinerary) {
        $formData = $itinerary;
        $selectedEventId = (int) $itinerary['event_id'];
        $itineraries = getEventItineraries($pdo, $selectedEventId);
    } else {
        $adminError = 'Itinerary item not found.';
    }
}

$selectedEvent = null;
foreach ($events as $eventOption) {
    if ((int) $eventOption['event_id'] === $selectedEventId) {
        $selectedEvent = $eventOption;
        break;
    }
}
$minimumItineraryDate = (string) ($selectedEvent['start_date'] ?? '');
$maximumItineraryDate = (string) (
    !empty($selectedEvent['end_date'])
        ? $selectedEvent['end_date']
        : ($selectedEvent['start_date'] ?? '')
);

ob_start();
?>
<div class="itinerary-page">
    <?php if ($adminError !== ''): ?><div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['admin_success'])): ?><div class="alert success"><?php echo htmlspecialchars((string) $_SESSION['admin_success'], ENT_QUOTES, 'UTF-8'); ?></div><?php unset($_SESSION['admin_success']); endif; ?>

    <form method="post" class="event-form itinerary-form">
        <input type="hidden" name="action" value="save_itinerary">
        <input type="hidden" name="itinerary_id" value="<?php echo (int) ($formData['itinerary_id'] ?? 0); ?>">
        <div class="form-grid">
            <div class="form-group itinerary-event-field">
                <label for="itinerary_event_id">Event</label>
                <select id="itinerary_event_id" name="event_id" required onchange="if (this.value) window.location.href='<?php echo htmlspecialchars(buildUrl('admin/event_itinerary.php'), ENT_QUOTES, 'UTF-8'); ?>?event_id=' + encodeURIComponent(this.value)">
                    <option value="">Choose an open event</option>
                    <?php foreach ($events as $event): ?>
                        <option value="<?php echo (int) $event['event_id']; ?>" <?php echo (int) ($formData['event_id'] ?? 0) === (int) $event['event_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $event['event_title'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group itinerary-day-field">
                <label for="itinerary_date">Event Day</label>
                <input id="itinerary_date" name="itinerary_date" type="date"
                       min="<?php echo htmlspecialchars($minimumItineraryDate, ENT_QUOTES, 'UTF-8'); ?>"
                       max="<?php echo htmlspecialchars($maximumItineraryDate, ENT_QUOTES, 'UTF-8'); ?>"
                       value="<?php echo htmlspecialchars((string) ($formData['itinerary_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                       <?php echo $selectedEventId > 0 ? '' : 'disabled'; ?> required>
                <?php if ($selectedEvent): ?>
                    <small>Allowed dates: <?php echo htmlspecialchars(formatItineraryDate($minimumItineraryDate), ENT_QUOTES, 'UTF-8'); ?> to <?php echo htmlspecialchars(formatItineraryDate($maximumItineraryDate), ENT_QUOTES, 'UTF-8'); ?></small>
                <?php else: ?>
                    <small>Select an event before choosing the event day.</small>
                <?php endif; ?>
            </div>
            <div class="form-group itinerary-start-field">
                <label for="itinerary_start_time">Start Time</label>
                <input id="itinerary_start_time" name="start_time" type="time" value="<?php echo htmlspecialchars(substr((string) ($formData['start_time'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="form-group itinerary-end-field">
                <label for="itinerary_end_time">End Time</label>
                <input id="itinerary_end_time" name="end_time" type="time" value="<?php echo htmlspecialchars(substr((string) ($formData['end_time'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group itinerary-activity-field">
                <label for="activity">Activity</label>
                <input id="activity" name="activity" type="text" value="<?php echo htmlspecialchars((string) ($formData['activity'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            
            <div class="form-group itinerary-resource-field">
                <label for="resource_person">Resource Person <span class="optional-label">(optional)</span></label>
                <input id="resource_person" name="resource_person" type="text" value="<?php echo htmlspecialchars((string) ($formData['resource_person'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group full-width">
                <label for="itinerary_description">Description</label>
                <textarea id="itinerary_description" name="itinerary_description"><?php echo htmlspecialchars((string) ($formData['itinerary_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>
        <div class="form-footer">
            <button type="submit" class="button-primary">Save Itinerary</button>
            <?php if (!empty($formData['itinerary_id'])): ?><a class="button-secondary" href="<?php echo htmlspecialchars(buildUrl('admin/event_itinerary.php') . '?event_id=' . $selectedEventId, ENT_QUOTES, 'UTF-8'); ?>">Cancel Edit</a><?php endif; ?>
        </div>
    </form>

    <div class="schedule-list-heading">
        <div><span class="schedule-kicker">Daily programme</span><h2>Event Itinerary</h2></div>
        <span class="schedule-total"><?php echo count($itineraries); ?> items</span>
    </div>

    <?php if ($selectedEventId <= 0): ?>
        <div class="schedule-empty-state">Select an open event to view its itinerary.</div>
    <?php elseif (empty($itineraries)): ?>
        <div class="schedule-empty-state">No itinerary items have been added for this event.</div>
    <?php else: ?>
        <div class="schedule-responsive-table itinerary-table">
            <table>
                <thead><tr><th>Date</th><th>Time</th><th>Activity</th><th>Resource Person</th><th>Description</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($itineraries as $item): ?>
                    <tr>
                        <td data-label="Date"><strong><?php echo htmlspecialchars(formatItineraryDate($item['itinerary_date'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                        <td data-label="Time"><span class="itinerary-time"><?php echo htmlspecialchars(formatItineraryTime($item['start_time'] ?? null), ENT_QUOTES, 'UTF-8'); ?> – <?php echo htmlspecialchars(formatItineraryTime($item['end_time'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td data-label="Activity"><strong><?php echo htmlspecialchars((string) ($item['activity'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                        <td data-label="Resource Person"><?php echo htmlspecialchars((string) ($item['resource_person'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="Description"><?php echo nl2br(htmlspecialchars((string) ($item['itinerary_description'] ?? '—'), ENT_QUOTES, 'UTF-8')); ?></td>
                        <td data-label="Actions"><div class="schedule-row-actions">
                            <form class="inline-form" method="get"><input type="hidden" name="edit" value="<?php echo (int) $item['itinerary_id']; ?>"><button class="schedule-action-button schedule-edit-button" type="submit">Edit</button></form>
                            <form class="inline-form" method="post" onsubmit="return confirm('Delete this itinerary item?');">
                                <input type="hidden" name="action" value="delete_itinerary">
                                <input type="hidden" name="itinerary_id" value="<?php echo (int) $item['itinerary_id']; ?>">
                                <input type="hidden" name="event_id" value="<?php echo $selectedEventId; ?>">
                                <button class="schedule-action-button schedule-delete-button" type="submit">Delete</button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
renderAdminLayout('Event Itinerary', $content, [
    'current_path' => 'event_itinerary',
    'page_heading' => 'Event Itinerary',
    'body_class' => 'event-itinerary-page',
]);
