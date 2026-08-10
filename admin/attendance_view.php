<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getLatestAttendanceForDisplay(PDO $pdo): ?array
{
    $statement = $pdo->query(<<<'SQL'
        SELECT
            ea.attendance_id,
            ea.checked_in_at,
            ea.mode,
            er.registration_id,
            er.salutation,
            er.name,
            er.designation,
            er.institution_name,
            er.city,
            er.district,
            er.photograph_path,
            e.event_title
        FROM event_attendance ea
        INNER JOIN event_registrations er
            ON er.registration_id = ea.registration_id
           AND er.event_id = ea.event_id
        INNER JOIN events e ON e.event_id = ea.event_id
        ORDER BY ea.attendance_id DESC
        LIMIT 1
SQL);
    $record = $statement ? $statement->fetch() : false;
    if (!$record) {
        return null;
    }

    $photoPath = trim((string) ($record['photograph_path'] ?? ''));
    if (str_starts_with(ltrim($photoPath, '/'), 'images/')) {
        $photoPath = 'assets/' . ltrim($photoPath, '/');
    }
    $record['photo_url'] = $photoPath !== '' ? buildUrl(ltrim($photoPath, '/')) : '';
    unset($record['photograph_path']);
    return $record;
}

$record = null;
$loadError = '';

try {
    $pdo = createDbConnection();
    $record = getLatestAttendanceForDisplay($pdo);
} catch (PDOException $exception) {
    $loadError = 'Waiting for the attendance service…';
}

if (isset($_GET['live']) && $_GET['live'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'success' => $loadError === '',
        'record' => $record,
        'message' => $loadError,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

ob_start();
?>
<div class="attendance-display" id="attendanceDisplay">
    <div class="attendance-ambient attendance-ambient-one"></div>
    <div class="attendance-ambient attendance-ambient-two"></div>

    <button type="button" class="attendance-refresh" id="attendanceRefresh" title="Refresh attendance" aria-label="Refresh attendance">
        <span aria-hidden="true">↻</span>
    </button>

    <main class="attendance-person" id="attendancePerson" <?php echo $record ? '' : 'hidden'; ?>>
        <section class="attendance-photo-panel">
            <div class="attendance-photo-frame">
                <img id="attendeePhoto" alt="">
                <div class="attendance-photo-fallback" id="attendeePhotoFallback"></div>
                <div class="attendance-photo-glow"></div>
            </div>
        </section>

        <section class="attendance-info-panel">
            <div class="attendance-welcome">Welcome to</div>
            <div class="attendance-event" id="attendeeEvent"></div>
            <div class="attendance-divider"></div>
            <h1 class="attendance-name" id="attendeeName"></h1>
            <div class="attendance-designation" id="attendeeDesignation"></div>
            <div class="attendance-institution" id="attendeeInstitution"></div>
            <div class="attendance-location" id="attendeeLocation"></div>
            <div class="attendance-confirmation">
                <span class="attendance-checkmark" aria-hidden="true">✓</span>
                <div>
                    <strong>Check-in confirmed</strong>
                    <span id="attendeeCheckin"></span>
                </div>
            </div>
        </section>
    </main>

    <div class="attendance-waiting" id="attendanceWaiting" <?php echo $record ? 'hidden' : ''; ?>>
        <div class="attendance-waiting-mark"><span></span></div>
        <strong>Waiting for the next check-in</strong>
    </div>
</div>

<script>
    const initialAttendance = <?php echo json_encode($record, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
    const attendanceEndpoint = <?php echo json_encode(buildUrl('admin/attendance_view.php') . '?live=1'); ?>;
    const attendancePerson = document.getElementById('attendancePerson');
    const attendanceWaiting = document.getElementById('attendanceWaiting');
    const attendanceRefresh = document.getElementById('attendanceRefresh');
    const attendeePhoto = document.getElementById('attendeePhoto');
    const attendeePhotoFallback = document.getElementById('attendeePhotoFallback');
    const attendeeEvent = document.getElementById('attendeeEvent');
    const attendeeName = document.getElementById('attendeeName');
    const attendeeDesignation = document.getElementById('attendeeDesignation');
    const attendeeInstitution = document.getElementById('attendeeInstitution');
    const attendeeLocation = document.getElementById('attendeeLocation');
    const attendeeCheckin = document.getElementById('attendeeCheckin');
    let currentAttendanceId = 0;
    let requestInProgress = false;

    function initials(name) {
        return String(name || 'Guest')
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map(part => part.charAt(0).toUpperCase())
            .join('');
    }

    function formatCheckinTime(value) {
        if (!value) return 'Just now';
        const parsed = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(parsed.getTime())
            ? value
            : parsed.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    }

    function renderAttendance(record, animate = true) {
        if (!record) {
            attendancePerson.hidden = true;
            attendanceWaiting.hidden = false;
            return;
        }

        currentAttendanceId = Number(record.attendance_id || 0);
        attendeeEvent.textContent = record.event_title || 'The Event';
        const displayName = [record.salutation, record.name].filter(Boolean).join(' ').trim();
        attendeeName.textContent = displayName || 'Guest';
        attendeeDesignation.textContent = record.designation || '';
        attendeeDesignation.hidden = !record.designation;
        attendeeInstitution.textContent = record.institution_name || '';
        attendeeInstitution.hidden = !record.institution_name;
        const location = [record.city, record.district].filter(Boolean).join(', ');
        attendeeLocation.textContent = location;
        attendeeLocation.hidden = !location;
        attendeeCheckin.textContent = formatCheckinTime(record.checked_in_at);
        attendeePhoto.alt = record.name ? 'Photograph of ' + record.name : 'Attendee photograph';
        attendeePhotoFallback.textContent = initials(record.name);

        if (record.photo_url) {
            attendeePhoto.src = record.photo_url;
            attendeePhoto.hidden = false;
            attendeePhotoFallback.hidden = true;
            attendeePhoto.onerror = () => {
                attendeePhoto.hidden = true;
                attendeePhotoFallback.hidden = false;
            };
        } else {
            attendeePhoto.removeAttribute('src');
            attendeePhoto.hidden = true;
            attendeePhotoFallback.hidden = false;
        }

        attendanceWaiting.hidden = true;
        attendancePerson.hidden = false;
        if (animate) {
            attendancePerson.classList.remove('attendance-enter');
            void attendancePerson.offsetWidth;
            attendancePerson.classList.add('attendance-enter');
        }
    }

    async function refreshAttendance(force = false) {
        if (requestInProgress) return;
        requestInProgress = true;
        attendanceRefresh.classList.add('refreshing');
        try {
            const response = await fetch(attendanceEndpoint + '&_=' + Date.now(), { cache: 'no-store' });
            const data = await response.json();
            const nextRecord = data && data.record ? data.record : null;
            const nextId = Number(nextRecord?.attendance_id || 0);
            if (nextRecord && (force || nextId !== currentAttendanceId)) {
                renderAttendance(nextRecord, true);
            } else if (!nextRecord && !currentAttendanceId) {
                renderAttendance(null);
            }
        } catch (error) {
            // Keep the current attendee visible during a temporary network interruption.
        } finally {
            requestInProgress = false;
            attendanceRefresh.classList.remove('refreshing');
        }
    }

    attendanceRefresh.addEventListener('click', () => refreshAttendance(true));
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshAttendance();
    });

    if (initialAttendance) renderAttendance(initialAttendance, false);
    window.setInterval(refreshAttendance, 1200);
</script>
<?php
$content = ob_get_clean();

renderAdminLayout('Attendance View', $content, [
    'current_path' => 'attendance_view',
    'page_heading' => '',
    'show_sidebar' => false,
    'show_footer' => false,
    'body_class' => 'attendance-view',
]);
