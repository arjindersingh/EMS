<?php

// Event helpers are provided by events_funcs.php
require_once __DIR__ . '/events_funcs.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize local variables used by the UI
$pdo = null;
$events = [];
$selectedEvent = null;
$selectedEventId = 0;
$eventFormData = [
    'event_id' => '',
    'event_title' => '',
    'event_code' => '',
    'event_tagline' => '',
    'event_theme' => '',
    'event_type' => 'Other',
    'event_status' => 'Open',
    'event_description' => '',
    'start_date' => '',
    'end_date' => '',
    'start_time' => '',
    'end_time' => '',
    'venue_name' => '',
    'venue_address' => '',
    'city' => '',
    'state' => '',
    'country' => '',
    'registration_required' => 0,
    'registration_start_date' => '',
    'registration_end_date' => '',
    'registration_fee' => '0.00',
];
try {
    $pdo = createDbConnection();
    ensureEventsTable($pdo);
    $events = getAllEvents($pdo);
} catch (PDOException $exception) {
    $adminError = ($adminError ?? '') !== '' ? $adminError : 'Database connection failed: ' . $exception->getMessage();
}

// Handle event-specific POST actions here so this file is self-contained
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_event') {
        requireAdminAuthentication();

        $eventFormData = [
            'event_id' => (int) ($_POST['event_id'] ?? 0),
            'event_title' => trim((string) ($_POST['event_title'] ?? '')),
            'event_code' => trim((string) ($_POST['event_code'] ?? '')),
            'event_tagline' => trim((string) ($_POST['event_tagline'] ?? '')),
            'event_theme' => trim((string) ($_POST['event_theme'] ?? '')),
            'event_type' => trim((string) ($_POST['event_type'] ?? 'Other')),
            'event_status' => trim((string) ($_POST['event_status'] ?? 'Open')),
            'event_description' => trim((string) ($_POST['event_description'] ?? '')),
            'start_date' => trim((string) ($_POST['start_date'] ?? '')),
            'end_date' => trim((string) ($_POST['end_date'] ?? '')),
            'start_time' => trim((string) ($_POST['start_time'] ?? '')),
            'end_time' => trim((string) ($_POST['end_time'] ?? '')),
            'venue_name' => trim((string) ($_POST['venue_name'] ?? '')),
            'venue_address' => trim((string) ($_POST['venue_address'] ?? '')),
            'city' => trim((string) ($_POST['city'] ?? '')),
            'state' => trim((string) ($_POST['state'] ?? '')),
            'country' => trim((string) ($_POST['country'] ?? '')),
            'registration_required' => !empty($_POST['registration_required']) ? 1 : 0,
            'registration_start_date' => trim((string) ($_POST['registration_start_date'] ?? '')),
            'registration_end_date' => trim((string) ($_POST['registration_end_date'] ?? '')),
            'registration_fee' => trim((string) ($_POST['registration_fee'] ?? '0.00')),
        ];

        try {
            $savedEventId = saveEvent($pdo, $eventFormData);
            $_SESSION['admin_success'] = $eventFormData['event_id'] > 0 ? 'Event updated successfully.' : 'Event created successfully.';
            header('Location: ' . buildUrl('admin'));
            exit;
        } catch (InvalidArgumentException $exception) {
            $adminError = $exception->getMessage();
        } catch (PDOException $exception) {
            $adminError = 'Unable to save event: ' . $exception->getMessage();
        }
    } elseif ($action === 'delete_event') {
        requireAdminAuthentication();
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($eventId > 0 && $pdo !== null) {
            try {
                deleteEvent($pdo, $eventId);
                $_SESSION['admin_success'] = 'Event deleted successfully.';
                header('Location: ' . buildUrl('admin'));
                exit;
            } catch (PDOException $exception) {
                $adminError = 'Unable to delete event: ' . $exception->getMessage();
            }
        }
    }
}

// If editing, load the selected event
if (isset($_GET['edit']) && $pdo !== null) {
    $editEventId = (int) $_GET['edit'];
    $selectedEvent = getEventById($pdo, $editEventId);
    if ($selectedEvent) {
        $eventFormData = $selectedEvent;
        $selectedEventId = $editEventId;
    } else {
        $adminError = 'Event not found.';
    }
}

$eventTypeOptions = ['Academic','Cultural','Sports','Seminar','Workshop','Conference','Meeting','Celebration','Competition','Other'];
$eventStatusOptions = ['Open','Suspended','Delayed','Postponed','Closed'];

// Render the events UI fragment
ob_start();
?>
            <hr>
            <h2>Create and Edit Event</h2>
            <p>Use this section to add, edit, delete, and list events.</p>

            <form method="post">
                <input type="hidden" name="action" value="save_event">
                <input type="hidden" name="event_id" value="<?php echo htmlspecialchars((string) ($eventFormData['event_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="event_title">Event Title</label>
                <input id="event_title" name="event_title" type="text" value="<?php echo htmlspecialchars((string) ($eventFormData['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>

                <label for="event_code">Event Code</label>
                <input id="event_code" name="event_code" type="text" value="<?php echo htmlspecialchars((string) ($eventFormData['event_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="event_tagline">Event Tagline</label>
                <input id="event_tagline" name="event_tagline" type="text" value="<?php echo htmlspecialchars((string) ($eventFormData['event_tagline'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="event_theme">Event Theme</label>
                <input id="event_theme" name="event_theme" type="text" value="<?php echo htmlspecialchars((string) ($eventFormData['event_theme'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="event_type">Event Type</label>
                <select id="event_type" name="event_type">
                    <?php foreach ($eventTypeOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($eventFormData['event_type'] ?? 'Other') === $option) ? 'selected' : ''; ?>><?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="event_status">Event Status</label>
                <select id="event_status" name="event_status" required>
                    <?php foreach ($eventStatusOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($eventFormData['event_status'] ?? 'Open') === $option) ? 'selected' : ''; ?>><?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="event_description">Event Description</label>
                <textarea id="event_description" name="event_description"><?php echo htmlspecialchars((string) ($eventFormData['event_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>

                <label for="start_date">Start Date</label>
                <input id="start_date" name="start_date" type="date" value="<?php echo htmlspecialchars((string) ($eventFormData['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>

                <label for="end_date">End Date</label>
                <input id="end_date" name="end_date" type="date" value="<?php echo htmlspecialchars((string) ($eventFormData['end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="start_time">Start Time</label>
                <input id="start_time" name="start_time" type="time" value="<?php echo htmlspecialchars((string) ($eventFormData['start_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="end_time">End Time</label>
                <input id="end_time" name="end_time" type="time" value="<?php echo htmlspecialchars((string) ($eventFormData['end_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="venue_name">Venue Name</label>
                <input id="venue_name" name="venue_name" type="text" value="<?php echo htmlspecialchars((string) ($eventFormData['venue_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="venue_address">Venue Address</label>
                <textarea id="venue_address" name="venue_address"><?php echo htmlspecialchars((string) ($eventFormData['venue_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>

                <label for="city">City</label>
                <input id="city" name="city" type="text" value="<?php echo htmlspecialchars((string) ($eventFormData['city'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="state">State</label>
                <input id="state" name="state" type="text" value="<?php echo htmlspecialchars((string) ($eventFormData['state'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="country">Country</label>
                <input id="country" name="country" type="text" value="<?php echo htmlspecialchars((string) ($eventFormData['country'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label>
                    <input type="checkbox" name="registration_required" value="1" <?php echo !empty($eventFormData['registration_required']) ? 'checked' : ''; ?>>
                    Registration Required
                </label>

                <label for="registration_start_date">Registration Start Date</label>
                <input id="registration_start_date" name="registration_start_date" type="date" value="<?php echo htmlspecialchars((string) ($eventFormData['registration_start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="registration_end_date">Registration End Date</label>
                <input id="registration_end_date" name="registration_end_date" type="date" value="<?php echo htmlspecialchars((string) ($eventFormData['registration_end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="registration_fee">Registration Fee</label>
                <input id="registration_fee" name="registration_fee" type="number" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($eventFormData['registration_fee'] ?? '0.00'), ENT_QUOTES, 'UTF-8'); ?>">

                <div class="actions">
                    <button type="submit">Save Event</button>
                    <?php if (!empty($eventFormData['event_id'])): ?>
                        <a href="<?php echo htmlspecialchars(buildUrl('admin'), ENT_QUOTES, 'UTF-8'); ?>"><button type="button">Cancel Edit</button></a>
                    <?php endif; ?>
                </div>
            </form>

            <hr>
            <p><a href="<?php echo htmlspecialchars(buildUrl('admin/event_schedule.php'), ENT_QUOTES, 'UTF-8'); ?>">Manage event registration schedules from a dedicated page</a></p>

            <h3>Events List</h3>
            <?php if (!empty($events)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Start Date</th>
                            <th>Venue</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) ($event['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($event['event_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($event['event_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="event-status event-status-<?php echo strtolower(htmlspecialchars((string) ($event['event_status'] ?? 'Open'), ENT_QUOTES, 'UTF-8')); ?>"><?php echo htmlspecialchars((string) ($event['event_status'] ?? 'Open'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($event['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($event['venue_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <a href="<?php echo htmlspecialchars(buildUrl('admin') . '?edit=' . (int) $event['event_id'], ENT_QUOTES, 'UTF-8'); ?>">Edit</a>
                                    |
                                    <form class="inline-form" method="post" onsubmit="return confirm('Delete this event?');">
                                        <input type="hidden" name="action" value="delete_event">
                                        <input type="hidden" name="event_id" value="<?php echo (int) $event['event_id']; ?>">
                                        <button type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No events found yet.</p>
            <?php endif; ?>

            <p><a href="<?php echo htmlspecialchars(buildUrl(''), ENT_QUOTES, 'UTF-8'); ?>">Back to home</a></p>

<?php
$content = ob_get_clean();
renderAdminLayout('Events', $content, ['current_path' => 'events', 'page_heading' => 'Manage Events']);
