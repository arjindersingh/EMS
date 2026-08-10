<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/events_funcs.php';
require_once __DIR__ . '/registration_approval_funcs.php';
require_once __DIR__ . '/event_itinerary_funcs.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!($_SESSION['admin_authenticated'] ?? false)) {
    header('Location: ' . buildUrl('admin'));
    exit;
}

const EVENT_REPORTS = [
    'registered' => 'Registered Candidates',
    'approved' => 'Approved Candidates',
    'denied' => 'Denied Candidates',
    'attendees' => 'Attendees',
    'pass_history' => 'Event Pass History',
    'itinerary' => 'Event Itinerary',
    'schedules' => 'Registration Schedules',
    'summary' => 'Event Summary',
];

function reportTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $statement->execute([':table' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

function reportQuery(PDO $pdo, string $sql, int $eventId): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute([':event_id' => $eventId]);
    return $statement->fetchAll() ?: [];
}

function getEventReportData(PDO $pdo, int $eventId, string $type): array
{
    if (in_array($type, ['registered', 'approved', 'denied'], true)) {
        $where = $type === 'registered' ? '' : ' AND approval_status = ' . $pdo->quote($type);
        return reportQuery($pdo, <<<SQL
            SELECT registration_id AS `Registration ID`, CONCAT_WS(' ', salutation, name) AS `Candidate`,
                   designation AS `Designation`, institution_name AS `Institution`, mobile AS `Mobile`,
                   official_email AS `Email`, district AS `District`, approval_status AS `Status`,
                   pass_code AS `Pass Code`, created_at AS `Registered On`
            FROM event_registrations
            WHERE event_id = :event_id {$where}
            ORDER BY name, registration_id
SQL, $eventId);
    }

    if ($type === 'attendees') {
        if (!reportTableExists($pdo, 'event_attendance')) return [];
        return reportQuery($pdo, <<<'SQL'
            SELECT er.registration_id AS `Registration ID`, CONCAT_WS(' ', er.salutation, er.name) AS `Candidate`,
                   er.designation AS `Designation`, er.institution_name AS `Institution`,
                   er.district AS `District`, er.approval_status AS `Status`, ea.checked_in_at AS `Checked In`,
                   ea.mode AS `Mode`, ea.checked_in_by AS `Checked In By`
            FROM event_attendance ea
            INNER JOIN event_registrations er ON er.registration_id = ea.registration_id
            WHERE ea.event_id = :event_id
            ORDER BY ea.checked_in_at, ea.attendance_id
SQL, $eventId);
    }

    if ($type === 'pass_history') {
        if (!reportTableExists($pdo, 'event_pass_history')) return [];
        return reportQuery($pdo, <<<'SQL'
            SELECT CONCAT_WS(' ', er.salutation, er.name) AS `Candidate`,
                   eph.pass_code AS `Pass Code`, eph.channel AS `Channel`, eph.recipient AS `Recipient`,
                   eph.status AS `Status`, eph.details AS `Details`, eph.created_at AS `Sent On`,
                   eph.sent_by_username AS `Sent By`
            FROM event_pass_history eph
            INNER JOIN event_registrations er ON er.registration_id = eph.registration_id
            WHERE eph.event_id = :event_id
            ORDER BY eph.created_at DESC
SQL, $eventId);
    }

    if ($type === 'itinerary') {
        return reportQuery($pdo, <<<'SQL'
            SELECT itinerary_date AS `Date`, start_time AS `Starts`, end_time AS `Ends`,
                   activity AS `Activity`, resource_person AS `Resource Person`,
                   itinerary_description AS `Description`
            FROM event_itineraries
            WHERE event_id = :event_id
            ORDER BY itinerary_date, start_time, itinerary_id
SQL, $eventId);
    }

    if ($type === 'schedules') {
        return reportQuery($pdo, <<<'SQL'
            SELECT schedule_start_date AS `Registration Starts`, schedule_end_date AS `Registration Ends`,
                   schedule_description AS `Description`, created_at AS `Created On`
            FROM event_schedules
            WHERE event_id = :event_id
            ORDER BY schedule_start_date, schedule_id
SQL, $eventId);
    }

    return [];
}

function getEventReportCounts(PDO $pdo, int $eventId): array
{
    $registration = $pdo->prepare(<<<'SQL'
        SELECT COUNT(*) AS registered,
               SUM(approval_status = 'approved') AS approved,
               SUM(approval_status = 'denied') AS denied,
               SUM(approval_status = 'pending') AS pending
        FROM event_registrations WHERE event_id = :event_id
SQL);
    $registration->execute([':event_id' => $eventId]);
    $counts = $registration->fetch() ?: [];
    foreach (['event_attendance' => 'attendees', 'event_pass_history' => 'pass_history', 'event_itineraries' => 'itinerary', 'event_schedules' => 'schedules'] as $table => $key) {
        if (!reportTableExists($pdo, $table)) {
            $counts[$key] = 0;
            continue;
        }
        $statement = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE event_id = :event_id");
        $statement->execute([':event_id' => $eventId]);
        $counts[$key] = (int) $statement->fetchColumn();
    }
    return array_map('intval', $counts);
}

function reportValue(mixed $value): string
{
    $value = trim((string) $value);
    return $value !== '' ? $value : '—';
}

function reportEventTitle(array $event): string
{
    $title = trim((string) ($event['event_title'] ?? 'Event'));
    $startDate = trim((string) ($event['start_date'] ?? ''));
    if ($startDate === '') return $title;
    $timestamp = strtotime($startDate);
    return $timestamp === false ? $title : $title . ' (' . date('d M, y', $timestamp) . ')';
}

function filterReportRows(array $rows, string $district, string $status, string $designation): array
{
    return array_values(array_filter($rows, static function (array $row) use ($district, $status, $designation): bool {
        if ($district !== '' && array_key_exists('District', $row) && strcasecmp(trim((string) $row['District']), $district) !== 0) return false;
        if ($status !== '' && array_key_exists('Status', $row) && strcasecmp(trim((string) $row['Status']), $status) !== 0) return false;
        if ($designation !== '' && array_key_exists('Designation', $row) && strcasecmp(trim((string) $row['Designation']), $designation) !== 0) return false;
        return true;
    }));
}

function getRegistrationFilterOptions(PDO $pdo, int $eventId): array
{
    $statement = $pdo->prepare('SELECT DISTINCT district, approval_status, designation FROM event_registrations WHERE event_id = :event_id ORDER BY district, designation');
    $statement->execute([':event_id' => $eventId]);
    $rows = $statement->fetchAll() ?: [];
    $options = ['districts' => [], 'statuses' => [], 'designations' => []];
    foreach ($rows as $row) {
        foreach (['district' => 'districts', 'approval_status' => 'statuses', 'designation' => 'designations'] as $column => $key) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') $options[$key][$value] = $value;
        }
    }
    foreach ($options as &$values) {
        natcasesort($values);
        $values = array_values($values);
    }
    unset($values);
    return $options;
}

function renderReportTable(array $rows): string
{
    if (!$rows) return '<div class="event-report-empty">No records are available for this report.</div>';
    $removedColumns = ['Registration ID', 'ID', 'Mobile', 'Phone Number', 'Email', 'District', 'Status', 'Pass Code', 'Registered On'];
    $columns = array_values(array_diff(array_keys($rows[0]), $removedColumns));
    ob_start(); ?>
    <div class="event-report-table-wrap"><table class="event-report-table"><thead><tr>
        <th>SN</th>
        <?php foreach ($columns as $column): ?><th><?php echo htmlspecialchars($column, ENT_QUOTES, 'UTF-8'); ?></th><?php endforeach; ?>
    </tr></thead><tbody>
        <?php foreach ($rows as $index => $row): ?><tr>
            <td><?php echo $index + 1; ?></td>
            <?php foreach ($columns as $column): ?><td><?php echo nl2br(htmlspecialchars(reportValue($row[$column] ?? ''), ENT_QUOTES, 'UTF-8')); ?></td><?php endforeach; ?>
        </tr><?php endforeach; ?>
    </tbody></table></div>
    <?php return (string) ob_get_clean();
}

function buildReportBody(string $type, array $rows, array $counts, array $event, string $detail): string
{
    if ($type !== 'summary') return renderReportTable($rows);
    ob_start(); ?>
    <div class="event-report-metrics">
        <?php foreach ([
            'registered' => 'Registered', 'approved' => 'Approved', 'denied' => 'Denied',
            'pending' => 'Pending', 'attendees' => 'Attendees', 'pass_history' => 'Pass Records',
            'itinerary' => 'Itinerary Items', 'schedules' => 'Schedules'
        ] as $key => $label): ?>
            <div><span><?php echo $label; ?></span><strong><?php echo (int) ($counts[$key] ?? 0); ?></strong></div>
        <?php endforeach; ?>
    </div>
    <?php if ($detail === 'full'): ?>
        <div class="event-full-summary">
            <h3>Full event information</h3>
            <dl>
                <div><dt>Event code</dt><dd><?php echo htmlspecialchars(reportValue($event['event_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>Status</dt><dd><?php echo htmlspecialchars(reportValue($event['event_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>Type</dt><dd><?php echo htmlspecialchars(reportValue($event['event_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>Dates</dt><dd><?php echo htmlspecialchars(reportValue(($event['start_date'] ?? '') . (!empty($event['end_date']) ? ' to ' . $event['end_date'] : '')), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>Time</dt><dd><?php echo htmlspecialchars(reportValue(($event['start_time'] ?? '') . (!empty($event['end_time']) ? ' to ' . $event['end_time'] : '')), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>Venue</dt><dd><?php echo htmlspecialchars(reportValue($event['venue_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>Location</dt><dd><?php echo htmlspecialchars(reportValue(implode(', ', array_filter([$event['city'] ?? '', $event['state'] ?? '', $event['country'] ?? '']))), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>Registration fee</dt><dd><?php echo htmlspecialchars(reportValue($event['registration_fee'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd></div>
            </dl>
            <?php if (!empty($event['event_description'])): ?><h3>Description</h3><p><?php echo nl2br(htmlspecialchars((string) $event['event_description'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
        </div>
    <?php endif;
    return (string) ob_get_clean();
}

function exportWordReport(string $title, string $eventTitle, string $body, string $template): void
{
    $filename = preg_replace('/[^a-z0-9]+/i', '-', strtolower($title . '-' . $eventTitle)) . '.doc';
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . trim($filename, '-') . '"');
    echo '<html><head><meta charset="UTF-8"><style>body{font-family:Arial;color:#1f2937}h1{color:#0f766e}table{border-collapse:collapse;width:100%;font-size:10pt}th,td{border:1px solid #cbd5e1;padding:6px;text-align:left}th{background:#e6fffb}.event-report-metrics{display:table;width:100%}.event-report-metrics div{display:table-cell;padding:12px;border:1px solid #ddd}.event-report-metrics span,.event-report-metrics strong{display:block}</style></head><body><h1>' . htmlspecialchars($eventTitle) . '</h1><h2>' . htmlspecialchars($title) . '</h2>' . $body . '</body></html>';
    exit;
}

$pdo = null;
$events = [];
$event = null;
$rows = [];
$counts = [];
$filterOptions = ['districts' => [], 'statuses' => [], 'designations' => []];
$error = '';
$type = (string) ($_GET['type'] ?? 'registered');
$type = isset(EVENT_REPORTS[$type]) ? $type : 'registered';
$template = in_array((string) ($_GET['template'] ?? 'modern'), ['modern', 'classic', 'minimal'], true) ? (string) $_GET['template'] : 'modern';
$detail = (string) ($_GET['detail'] ?? 'compact') === 'full' ? 'full' : 'compact';
$eventId = (int) ($_GET['event'] ?? 0);
$districtFilter = trim((string) ($_GET['district'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$designationFilter = trim((string) ($_GET['designation'] ?? ''));

try {
    $pdo = createDbConnection();
    ensureEventsTable($pdo);
    ensureRegistrationApprovalStorage($pdo);
    ensureEventItinerariesTable($pdo);
    $events = getAllEvents($pdo);
    if ($eventId <= 0 && $events) $eventId = (int) $events[0]['event_id'];
    if ($eventId > 0) {
        $event = getEventById($pdo, $eventId);
        $counts = getEventReportCounts($pdo, $eventId);
        $rows = getEventReportData($pdo, $eventId, $type);
        $filterOptions = getRegistrationFilterOptions($pdo, $eventId);
        if (in_array($type, ['registered', 'approved', 'denied', 'attendees'], true)) {
            $rows = filterReportRows($rows, $districtFilter, $statusFilter, $designationFilter);
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$title = EVENT_REPORTS[$type];
$eventDisplayTitle = $event ? reportEventTitle($event) : '';
$reportBody = $event ? buildReportBody($type, $rows, $counts, $event, $detail) : '';
$export = (string) ($_GET['export'] ?? '');
if ($event && $export === 'doc') exportWordReport($title, $eventDisplayTitle, $reportBody, $template);

if ($event && $export === 'pdf') {
    ?><!doctype html><html><head><meta charset="UTF-8"><title><?php echo htmlspecialchars($title); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(buildUrl('assets/css/styles.css')); ?>"><style>body{padding:28px;background:#fff}.admin-print-report{max-width:1100px;margin:auto}.print-actions{position:fixed;right:20px;top:20px}@media print{.print-actions{display:none}}</style></head>
    <body><button class="print-actions" onclick="window.print()">Save as PDF</button><article class="admin-print-report event-report-template-<?php echo $template; ?>"><h1><?php echo htmlspecialchars($eventDisplayTitle); ?></h1><h2><?php echo htmlspecialchars($title); ?></h2><p>Generated <?php echo date('d M Y, h:i A'); ?></p><?php echo $reportBody; ?></article><script>window.addEventListener('load',()=>window.print());</script></body></html><?php exit;
}

ob_start();
?>
<div class="event-reports-page">
    <?php if ($error): ?><div class="alert error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <form method="get" class="event-report-toolbar">
        <label>Event<select name="event"><?php foreach ($events as $option): ?><option value="<?php echo (int) $option['event_id']; ?>" <?php echo (int) $option['event_id'] === $eventId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $option['event_title'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
        <label>Report<select name="type"><?php foreach (EVENT_REPORTS as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $type === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
        <?php if (in_array($type, ['registered', 'approved', 'denied', 'attendees'], true)): ?>
            <label>District<select name="district"><option value="">All districts</option><?php foreach ($filterOptions['districts'] as $value): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $districtFilter === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
            <label>Status<select name="status"><option value="">All statuses</option><?php foreach ($filterOptions['statuses'] as $value): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($value), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
            <label>Designation<select name="designation"><option value="">All designations</option><?php foreach ($filterOptions['designations'] as $value): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $designationFilter === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <label>Template<select name="template"><option value="modern" <?php echo $template === 'modern' ? 'selected' : ''; ?>>Modern</option><option value="classic" <?php echo $template === 'classic' ? 'selected' : ''; ?>>Classic</option><option value="minimal" <?php echo $template === 'minimal' ? 'selected' : ''; ?>>Minimal</option></select></label>
        <?php if ($type === 'summary'): ?><label>Summary<select name="detail"><option value="compact" <?php echo $detail === 'compact' ? 'selected' : ''; ?>>Numbers only</option><option value="full" <?php echo $detail === 'full' ? 'selected' : ''; ?>>Full summary</option></select></label><?php endif; ?>
        <button type="submit">Generate</button>
    </form>

    <?php if ($event): ?>
        <article class="event-report-sheet event-report-template-<?php echo $template; ?>">
            <header><div><span>Event report</span><h2><?php echo htmlspecialchars($eventDisplayTitle, ENT_QUOTES, 'UTF-8'); ?></h2><p><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></p></div><div class="event-report-total"><strong><?php echo $type === 'summary' ? count($counts) : count($rows); ?></strong><span><?php echo $type === 'summary' ? 'report metrics' : 'records'; ?></span></div></header>
            <?php echo $reportBody; ?>
        </article>
        <div class="event-report-export">
            <?php $base = buildUrl('admin/event_reports.php') . '?' . http_build_query(['event' => $eventId, 'type' => $type, 'district' => $districtFilter, 'status' => $statusFilter, 'designation' => $designationFilter, 'template' => $template, 'detail' => $detail]); ?>
            <a class="button-secondary" href="<?php echo htmlspecialchars($base . '&export=doc', ENT_QUOTES, 'UTF-8'); ?>">Export Word</a>
            <a class="button-primary" target="_blank" href="<?php echo htmlspecialchars($base . '&export=pdf', ENT_QUOTES, 'UTF-8'); ?>">Export PDF</a>
        </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
renderAdminLayout('Event Reports', $content, ['current_path' => 'event_reports', 'page_heading' => 'Event Reports', 'body_class' => 'event-reports-admin']);
