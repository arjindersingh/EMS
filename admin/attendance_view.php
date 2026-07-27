<?php

require_once __DIR__ . '/settings_funcs.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function ensureAttendanceViewSettings(PDO $pdo): void
{
    $defaults = [
        ['attendance_view_enabled', 'boolean', '1'],
        ['attendance_view_duration_seconds', 'number', '8'],
        ['attendance_view_idle_seconds', 'number', '12'],
        ['attendance_view_title', 'text', 'Welcome Attendee'],
        ['attendance_view_message', 'text', 'Welcome to the event'],
    ];

    foreach ($defaults as $default) {
        [$name, $type, $value] = $default;
        if (!getSettingByName($pdo, $name)) {
            saveSetting($pdo, [
                'setting_id' => 0,
                'setting_name' => $name,
                'setting_type' => $type,
                'setting_value' => $value,
                'setting_description' => 'Controls the live attendance display view.',
            ]);
        }
    }
}

function getLatestAttendance(PDO $pdo): array
{
    $statement = $pdo->query(<<<'SQL'
        SELECT ea.*, er.name, er.designation, er.institution_name, er.event_id, e.event_title
        FROM event_attendance ea
        INNER JOIN event_registrations er ON er.registration_id = ea.registration_id
        INNER JOIN events e ON e.event_id = ea.event_id
        ORDER BY ea.checked_in_at DESC, ea.attendance_id DESC
        LIMIT 50
SQL);
    return $statement ? $statement->fetchAll() : [];
}

function getAttendanceViewConfig(PDO $pdo): array
{
    return [
        'enabled' => (bool) getSettingValue($pdo, 'attendance_view_enabled', true),
        'durationSeconds' => max(3, (int) getSettingValue($pdo, 'attendance_view_duration_seconds', 8)),
        'idleSeconds' => max(3, (int) getSettingValue($pdo, 'attendance_view_idle_seconds', 12)),
        'title' => trim((string) getSettingValue($pdo, 'attendance_view_title', 'Welcome Attendee')),
        'message' => trim((string) getSettingValue($pdo, 'attendance_view_message', 'Welcome to the event')),
    ];
}

$pdo = null;
$records = [];
$config = [];

try {
    $pdo = createDbConnection();
    ensureSettingsTable($pdo);
    ensureAttendanceViewSettings($pdo);
    $records = getLatestAttendance($pdo);
    $config = getAttendanceViewConfig($pdo);
} catch (PDOException $exception) {
    $config = [
        'enabled' => true,
        'durationSeconds' => 8,
        'idleSeconds' => 12,
        'title' => 'Welcome Attendee',
        'message' => 'Welcome to the event',
    ];
}

if ($pdo === null) {
    $records = [];
}

$refreshRequested = isset($_GET['refresh']) && $_GET['refresh'] === '1';
if ($refreshRequested) {
    header('Content-Type: application/json');
    echo json_encode([
        'records' => $records,
        'config' => $config,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance View</title>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: linear-gradient(135deg, #0f172a, #1d4ed8); color: #fff; overflow: hidden; }
        .screen { width: 100vw; height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; box-sizing: border-box; }
        .card { width: 100%; max-width: 1000px; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.2); border-radius: 24px; padding: 2rem; box-shadow: 0 20px 60px rgba(0,0,0,0.35); }
        h1 { font-size: 3rem; margin: 0 0 1rem; }
        .title { font-size: 2rem; font-weight: bold; margin-bottom: 0.75rem; }
        .name { font-size: 4rem; font-weight: 800; margin: 0.5rem 0 1rem; }
        .meta { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .badge { display: inline-block; padding: 0.4rem 0.8rem; border-radius: 999px; background: rgba(255,255,255,0.18); margin-top: 0.8rem; }
    </style>
</head>
<body>
<div class="screen">
    <div class="card">
        <h1><?php echo htmlspecialchars((string) ($config['title'] ?? 'Welcome Attendee'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <div id="displayArea">
            <div class="title"><?php echo htmlspecialchars((string) ($config['message'] ?? 'Welcome to the event'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="name">Waiting for the next check-in…</div>
            <div class="meta">No attendee has checked in yet.</div>
        </div>
    </div>
</div>
<script>
    const records = <?php echo json_encode($records, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const config = {
        durationSeconds: <?php echo (int) ($config['durationSeconds'] ?? 8); ?>,
        idleSeconds: <?php echo (int) ($config['idleSeconds'] ?? 12); ?>
    };
    const displayArea = document.getElementById('displayArea');
    let index = 0;
    let currentTimer = null;
    let lastRefreshAt = Date.now();

    function renderRecord(record) {
        if (!record) {
            displayArea.innerHTML = '<div class="title">' + (<?php echo json_encode((string) ($config['message'] ?? 'Welcome to the event')); ?>) + '</div><div class="name">Waiting for the next check-in…</div><div class="meta">No attendee has checked in yet.</div>';
            return;
        }

        const dateText = record.checked_in_at ? new Date(record.checked_in_at).toLocaleString() : '';
        displayArea.innerHTML = '<div class="title">' + (record.event_title || 'Event') + '</div>' +
            '<div class="name">' + (record.name || 'Attendee') + '</div>' +
            '<div class="meta">' + (record.designation || '') + '</div>' +
            '<div class="meta">' + (record.institution_name || '') + '</div>' +
            '<div class="meta">Checked in: ' + dateText + '</div>' +
            '<div class="badge">Welcome to the event</div>';
    }

    function cycle() {
        if (!records.length) {
            renderRecord(null);
            return;
        }

        const now = Date.now();
        const idleMs = config.idleSeconds * 1000;
        let record;
        if (now - lastRefreshAt >= idleMs) {
            record = records[Math.floor(Math.random() * records.length)];
        } else {
            record = records[index % records.length];
            index = (index + 1) % records.length;
        }

        renderRecord(record);
        clearTimeout(currentTimer);
        currentTimer = setTimeout(cycle, config.durationSeconds * 1000);
    }

    function refreshFromServer() {
        fetch(window.location.href + '?refresh=1', { cache: 'no-store' })
            .then(response => response.json())
            .then(data => {
                const nextRecords = Array.isArray(data.records) ? data.records : [];
                if (nextRecords.length) {
                    records.splice(0, records.length, ...nextRecords);
                    index = 0;
                    lastRefreshAt = Date.now();
                    cycle();
                } else if (!records.length) {
                    renderRecord(null);
                }
            })
            .catch(() => {});
    }

    setInterval(refreshFromServer, config.idleSeconds * 1000);
    cycle();
</script>
</body>
</html>
