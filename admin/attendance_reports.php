<?php

require_once __DIR__ . '/settings_funcs.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    header('Location: /admin');
    exit;
}

function getAllEvents(PDO $pdo): array
{
    $statement = $pdo->query('SELECT event_id, event_title, start_date, end_date, venue_name, city, state, country FROM events ORDER BY start_date DESC, event_title ASC');
    return $statement ? $statement->fetchAll() : [];
}

function getEventById(PDO $pdo, int $eventId): ?array
{
    $statement = $pdo->prepare('SELECT event_id, event_title, start_date, end_date, venue_name, city, state, country, event_description FROM events WHERE event_id = :event_id LIMIT 1');
    $statement->execute([':event_id' => $eventId]);
    return $statement->fetch() ?: null;
}

function getAttendanceRows(PDO $pdo, int $eventId, string $sortColumn, string $sortOrder): array
{
    $allowedColumns = [
        'name' => 'er.name',
        'designation' => 'er.designation',
        'institution' => 'er.institution_name',
        'district' => 'er.district',
        'checked_in_at' => 'ea.checked_in_at',
        'mode' => 'ea.mode',
    ];

    $column = $allowedColumns[$sortColumn] ?? 'er.name';
    $order = in_array($sortOrder, ['asc', 'desc'], true) ? $sortOrder : 'asc';

    $statement = $pdo->prepare(<<<'SQL'
        SELECT
            ea.attendance_id,
            ea.checked_in_at,
            ea.mode,
            er.registration_id,
            er.name,
            er.designation,
            er.institution_name,
            er.institution_level,
            er.district,
            er.city,
            er.state,
            er.official_email,
            er.whatsapp_number,
            e.event_title,
            e.start_date,
            e.end_date,
            e.venue_name,
            e.city AS event_city,
            e.state AS event_state,
            e.country AS event_country
        FROM event_attendance ea
        INNER JOIN event_registrations er ON er.registration_id = ea.registration_id
        INNER JOIN events e ON e.event_id = ea.event_id
        WHERE ea.event_id = :event_id
        ORDER BY {$column} {$order}, ea.attendance_id ASC
SQL);

    $statement->execute([':event_id' => $eventId]);
    return $statement->fetchAll() ?: [];
}

function getReportTypeLabel(string $reportType): string
{
    return match ($reportType) {
        'summary' => 'Attendance Summary',
        'event_summary' => 'Summary Event Attendance',
        default => 'Facilitation Report',
    };
}

function getReportTitle(string $reportType): string
{
    return match ($reportType) {
        'summary' => 'Attendance Summary Report',
        'event_summary' => 'Summary Event Attendance Report',
        default => 'Facilitation Report',
    };
}

function normalizeInstitutionLevel(string $value): string
{
    $normalized = trim(strtolower($value));
    if ($normalized === '') {
        return 'Not specified';
    }

    if (str_contains($normalized, 'senior secondary') || str_contains($normalized, 'senior sec') || str_contains($normalized, 'sr. secondary')) {
        return 'Senior Secondary';
    }

    if (str_contains($normalized, 'higher secondary') || str_contains($normalized, 'higher sec')) {
        return 'Higher Secondary';
    }

    if (str_contains($normalized, 'secondary')) {
        return 'Secondary';
    }

    if (str_contains($normalized, 'primary')) {
        return 'Primary';
    }

    if (str_contains($normalized, 'college') || str_contains($normalized, 'university')) {
        return 'College/University';
    }

    if (str_contains($normalized, 'school')) {
        return 'School';
    }

    return ucwords($value);
}

function normalizeRoleCategory(string $value): string
{
    $normalized = trim(strtolower($value));
    if ($normalized === '') {
        return 'Other';
    }

    if (preg_match('/principal|headmaster|head teacher|director/i', $normalized)) {
        return 'Principal';
    }

    if (preg_match('/teacher|lecturer|faculty|educator|trainer/i', $normalized)) {
        return 'Teacher';
    }

    if (preg_match('/coordinator|administrator|manager|supervisor|hod|head/i', $normalized)) {
        return 'Administrator/Coordinator';
    }

    return 'Other';
}

function buildSummaryData(array $rows): array
{
    $districts = [];
    $institutionLevels = [];
    $roleCategories = [];

    foreach ($rows as $row) {
        $district = trim((string) ($row['district'] ?? '')) !== '' ? trim((string) ($row['district'] ?? '')) : 'Not specified';
        $districts[$district] = ($districts[$district] ?? 0) + 1;

        $institutionLevel = normalizeInstitutionLevel((string) ($row['institution_level'] ?? ''));
        $institutionLevels[$institutionLevel] = ($institutionLevels[$institutionLevel] ?? 0) + 1;

        $roleCategory = normalizeRoleCategory((string) ($row['designation'] ?? ''));
        $roleCategories[$roleCategory] = ($roleCategories[$roleCategory] ?? 0) + 1;
    }

    ksort($districts);
    ksort($institutionLevels);
    ksort($roleCategories);

    return [
        'total_attendees' => count($rows),
        'districts' => $districts,
        'institution_levels' => $institutionLevels,
        'role_categories' => $roleCategories,
    ];
}

function escapeXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function filterRowsByExcludedIds(array $rows, array $excludedIds): array
{
    if ($excludedIds === []) {
        return $rows;
    }

    $excluded = array_map('intval', $excludedIds);
    return array_values(array_filter($rows, static function (array $row) use ($excluded): bool {
        return !in_array((int) ($row['registration_id'] ?? 0), $excluded, true);
    }));
}

function buildDocx(string $reportType, array $event, array $rows): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to export DOCX files.');
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'ems-report-');
    if ($tempFile === false) {
        throw new RuntimeException('Unable to create temporary export file.');
    }

    $zip = new ZipArchive();
    $zip->open($tempFile, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>
XML);
    $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML);

    $paragraphs = [];
    $paragraphs[] = '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t>' . escapeXml($event['event_title'] ?? 'Event') . '</w:t></w:r></w:p>';
    $paragraphs[] = '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>' . escapeXml(getReportTitle($reportType)) . '</w:t></w:r></w:p>';
    $paragraphs[] = '<w:p><w:r><w:t>Event Date: ' . escapeXml((string) (($event['start_date'] ?? '') . (!empty($event['end_date']) ? ' to ' . $event['end_date'] : ''))) . '</w:t></w:r></w:p>';
    $paragraphs[] = '<w:p><w:r><w:t>Venue: ' . escapeXml((string) ($event['venue_name'] ?? '')) . '</w:t></w:r></w:p>';
    $paragraphs[] = '<w:p><w:r><w:t>Location: ' . escapeXml(trim((string) (($event['city'] ?? '') . (!empty($event['state']) ? ', ' . $event['state'] : '') . (!empty($event['country']) ? ', ' . $event['country'] : '')))) . '</w:t></w:r></w:p>';
    $paragraphs[] = '<w:p><w:r><w:t>Generated: ' . escapeXml(date('Y-m-d H:i:s')) . '</w:t></w:r></w:p>';
    $paragraphs[] = '<w:p><w:r><w:t></w:t></w:r></w:p>';

    if ($reportType === 'facilitation') {
        foreach ($rows as $row) {
            $paragraphs[] = '<w:p><w:r><w:t>Name: ' . escapeXml((string) ($row['name'] ?? '')) . '</w:t></w:r></w:p>';
            $paragraphs[] = '<w:p><w:r><w:t>Designation: ' . escapeXml((string) ($row['designation'] ?? '')) . '</w:t></w:r></w:p>';
            $paragraphs[] = '<w:p><w:r><w:t>Institute: ' . escapeXml((string) ($row['institution_name'] ?? '')) . '</w:t></w:r></w:p>';
            $paragraphs[] = '<w:p><w:r><w:t>District: ' . escapeXml((string) ($row['district'] ?? '')) . '</w:t></w:r></w:p>';
            $paragraphs[] = '<w:p><w:r><w:t></w:t></w:r></w:p>';
        }
    } elseif ($reportType === 'event_summary') {
        $summaryData = buildSummaryData($rows);
        $paragraphs[] = '<w:p><w:r><w:t>Total attendees: ' . escapeXml((string) $summaryData['total_attendees']) . '</w:t></w:r></w:p>';
        $paragraphs[] = '<w:p><w:r><w:t>By district:</w:t></w:r></w:p>';
        foreach ($summaryData['districts'] as $district => $count) {
            $paragraphs[] = '<w:p><w:r><w:t>- ' . escapeXml($district) . ': ' . escapeXml((string) $count) . '</w:t></w:r></w:p>';
        }
        $paragraphs[] = '<w:p><w:r><w:t>By institution level:</w:t></w:r></w:p>';
        foreach ($summaryData['institution_levels'] as $level => $count) {
            $paragraphs[] = '<w:p><w:r><w:t>- ' . escapeXml($level) . ': ' . escapeXml((string) $count) . '</w:t></w:r></w:p>';
        }
        $paragraphs[] = '<w:p><w:r><w:t>By role category:</w:t></w:r></w:p>';
        foreach ($summaryData['role_categories'] as $role => $count) {
            $paragraphs[] = '<w:p><w:r><w:t>- ' . escapeXml($role) . ': ' . escapeXml((string) $count) . '</w:t></w:r></w:p>';
        }
    } else {
        foreach ($rows as $row) {
            $paragraphs[] = '<w:p><w:r><w:t>Name: ' . escapeXml((string) ($row['name'] ?? '')) . ' | Designation: ' . escapeXml((string) ($row['designation'] ?? '')) . ' | Institute: ' . escapeXml((string) ($row['institution_name'] ?? '')) . ' | District: ' . escapeXml((string) ($row['district'] ?? '')) . '</w:t></w:r></w:p>';
            $paragraphs[] = '<w:p><w:r><w:t>Checked In: ' . escapeXml((string) ($row['checked_in_at'] ?? '')) . ' | Mode: ' . escapeXml((string) ($row['mode'] ?? '')) . '</w:t></w:r></w:p>';
            $paragraphs[] = '<w:p><w:r><w:t></w:t></w:r></w:p>';
        }
    }

    $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    {PARAGRAPHS}
    <w:sectPr>
      <w:pgSz w:w="12240" w:h="15840"/>
      <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/>
    </w:sectPr>
  </w:body>
</w:document>
XML;

    $zip->addFromString('word/document.xml', str_replace('{PARAGRAPHS}', implode('', $paragraphs), $documentXml));
    $zip->addFromString('word/styles.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:style w:type="paragraph" w:default="1" w:styleId="Normal">
    <w:name w:val="Normal"/>
    <w:qFormat/>
    <w:pPr><w:spacing w:after="160"/></w:pPr>
    <w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Title">
    <w:name w:val="Title"/>
    <w:basedOn w:val="Normal"/>
    <w:qFormat/>
    <w:pPr><w:spacing w:after="240"/></w:pPr>
    <w:rPr><w:b/><w:sz w:val="32"/></w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading1">
    <w:name w:val="heading 1"/>
    <w:basedOn w:val="Normal"/>
    <w:qFormat/>
    <w:pPr><w:spacing w:after="240"/></w:pPr>
    <w:rPr><w:b/><w:sz w:val="28"/></w:rPr>
  </w:style>
</w:styles>
XML);
    $zip->close();

    return $tempFile;
}

$pdo = null;
$events = [];
$selectedEventId = 0;
$reportType = 'facilitation';
$sortColumn = 'name';
$sortOrder = 'asc';
$rows = [];
$event = null;
$adminError = '';
$adminSuccess = '';
$excludedIds = [];
$summaryData = [];

try {
    $pdo = createDbConnection();
    $events = getAllEvents($pdo);
} catch (PDOException $exception) {
    $adminError = 'Database connection failed: ' . $exception->getMessage();
}

if ($pdo !== null) {
    $requestEvent = (int) ($_GET['event'] ?? $_POST['event'] ?? 0);
    $requestReport = (string) ($_GET['report'] ?? $_POST['report'] ?? 'facilitation');
    $requestSort = (string) ($_GET['sort'] ?? $_POST['sort'] ?? 'name');
    $requestOrder = (string) ($_GET['order'] ?? $_POST['order'] ?? 'asc');

    if ($requestEvent > 0) {
        $selectedEventId = $requestEvent;
    } elseif (!empty($events)) {
        $selectedEventId = (int) $events[0]['event_id'];
    }

    $reportType = in_array($requestReport, ['facilitation', 'summary', 'event_summary'], true) ? $requestReport : 'facilitation';
    $sortColumn = in_array($requestSort, ['name', 'designation', 'institution', 'district', 'checked_in_at', 'mode'], true) ? $requestSort : 'name';
    $sortOrder = in_array($requestOrder, ['asc', 'desc'], true) ? $requestOrder : 'asc';

    if ($selectedEventId > 0) {
        $event = getEventById($pdo, $selectedEventId);
        $rows = getAttendanceRows($pdo, $selectedEventId, $sortColumn, $sortOrder);
        $summaryData = $reportType === 'event_summary' ? buildSummaryData($rows) : [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action = (string) ($_POST['action'] ?? '')) !== '') {
    $excludedIds = array_map('intval', (array) ($_POST['exclude_ids'] ?? []));
    $excludedIds = array_values(array_filter($excludedIds));

    if ($selectedEventId > 0 && $event !== null && $pdo !== null) {
        $rows = filterRowsByExcludedIds($rows, $excludedIds);
        $summaryData = $reportType === 'event_summary' ? buildSummaryData($rows) : [];
    }

    if ($action === 'export_docx' && $pdo !== null && $selectedEventId > 0 && $event !== null) {
        try {
            $tempFile = buildDocx($reportType, $event, $rows);
            $filename = strtolower(str_replace(' ', '-', getReportTitle($reportType))) . '-' . $selectedEventId . '.docx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tempFile));
            readfile($tempFile);
            unlink($tempFile);
            exit;
        } catch (Throwable $exception) {
            $adminError = 'Unable to export DOCX: ' . $exception->getMessage();
        }
    } elseif ($action === 'apply_filter') {
        $adminSuccess = count($excludedIds) > 0 ? 'Removed ' . count($excludedIds) . ' attendee(s) from the report preview.' : 'No attendees were excluded.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Reports</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; line-height: 1.6; color: #222; }
        .card { max-width: 1200px; margin: 0 auto; padding: 1.5rem 2rem; border: 1px solid #d0d7de; border-radius: 8px; background: #f8f9fa; }
        .alert { padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: 6px; }
        .alert.error { background: #ffe8e8; color: #9c1c1c; }
        .alert.success { background: #e8f7eb; color: #20653d; }
        .toolbar { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
        label { font-weight: bold; }
        select, button, input { padding: 0.6rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #d0d7de; padding: 0.6rem; text-align: left; vertical-align: top; }
        th { background: #eef2f7; }
        a.sort-link { text-decoration: none; color: inherit; }
        .muted { color: #666; }
        .summary { background: #fff; border: 1px solid #d0d7de; border-radius: 6px; padding: 1rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>Attendance Reports</h1>
    <p>Create event reports with different templates and export them to DOCX.</p>

    <?php if ($adminError !== ''): ?><div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($adminSuccess !== ''): ?><div class="alert success"><?php echo htmlspecialchars((string) $adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <form method="get" class="toolbar">
        <label for="event">Event</label>
        <select id="event" name="event" onchange="this.form.submit()">
            <?php foreach ($events as $eventOption): ?>
                <option value="<?php echo (int) $eventOption['event_id']; ?>" <?php echo (int) $eventOption['event_id'] === $selectedEventId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($eventOption['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="report">Report type</label>
        <select id="report" name="report" onchange="this.form.submit()">
            <option value="facilitation" <?php echo $reportType === 'facilitation' ? 'selected' : ''; ?>>Facilitation Report</option>
            <option value="summary" <?php echo $reportType === 'summary' ? 'selected' : ''; ?>>Attendance Summary</option>
            <option value="event_summary" <?php echo $reportType === 'event_summary' ? 'selected' : ''; ?>>Summary Event Attendance</option>
        </select>

        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortColumn, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="order" value="<?php echo htmlspecialchars($sortOrder, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit">Load</button>
    </form>

    <?php if ($event !== null): ?>
            <div class="summary">
            <strong><?php echo htmlspecialchars(getReportTitle($reportType), ENT_QUOTES, 'UTF-8'); ?></strong>
            <div><strong>Event:</strong> <?php echo htmlspecialchars((string) ($event['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>Date:</strong> <?php echo htmlspecialchars((string) (($event['start_date'] ?? '') . (!empty($event['end_date']) ? ' to ' . $event['end_date'] : '')), ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>Venue:</strong> <?php echo htmlspecialchars((string) ($event['venue_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>Location:</strong> <?php echo htmlspecialchars(trim((string) (($event['city'] ?? '') . (!empty($event['state']) ? ', ' . $event['state'] : '') . (!empty($event['country']) ? ', ' . $event['country'] : ''))), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="toolbar" style="margin-top: 0.75rem;">
                <a href="/admin"><button type="button">Back to admin</button></a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($event !== null && $reportType === 'event_summary' && !empty($rows)): ?>
        <div class="summary">
            <h3>Summary overview</h3>
            <div><strong>Total attendees:</strong> <?php echo (int) ($summaryData['total_attendees'] ?? 0); ?></div>
            <div><strong>Principals:</strong> <?php echo (int) ($summaryData['role_categories']['Principal'] ?? 0); ?></div>
            <div><strong>Teachers:</strong> <?php echo (int) ($summaryData['role_categories']['Teacher'] ?? 0); ?></div>
            <div><strong>Other roles:</strong> <?php echo (int) ($summaryData['role_categories']['Other'] ?? 0); ?></div>
        </div>

        <div class="summary">
            <h3>Attendees by district</h3>
            <table>
                <thead><tr><th>District</th><th>Count</th></tr></thead>
                <tbody>
                    <?php foreach (($summaryData['districts'] ?? []) as $district => $count): ?>
                        <tr><td><?php echo htmlspecialchars((string) $district, ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int) $count; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="summary">
            <h3>Attendees by institution level</h3>
            <table>
                <thead><tr><th>Institution level</th><th>Count</th></tr></thead>
                <tbody>
                    <?php foreach (($summaryData['institution_levels'] ?? []) as $level => $count): ?>
                        <tr><td><?php echo htmlspecialchars((string) $level, ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int) $count; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="summary">
            <h3>Attendees by role category</h3>
            <table>
                <thead><tr><th>Role category</th><th>Count</th></tr></thead>
                <tbody>
                    <?php foreach (($summaryData['role_categories'] ?? []) as $role => $count): ?>
                        <tr><td><?php echo htmlspecialchars((string) $role, ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int) $count; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($event !== null && !empty($rows)): ?>
        <form method="post">
            <input type="hidden" name="event" value="<?php echo (int) $selectedEventId; ?>">
            <input type="hidden" name="report" value="<?php echo htmlspecialchars($reportType, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortColumn, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="order" value="<?php echo htmlspecialchars($sortOrder, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="toolbar" style="margin-top: 0.75rem;">
                <button type="submit" name="action" value="apply_filter">Remove selected attendees</button>
                <button type="submit" name="action" value="export_docx">Export DOCX</button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Exclude</th>
                        <th><a class="sort-link" href="/admin/attendance_reports.php?event=<?php echo (int) $selectedEventId; ?>&report=<?php echo urlencode($reportType); ?>&sort=name&order=<?php echo $sortColumn === 'name' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>">Name</a></th>
                        <th><a class="sort-link" href="/admin/attendance_reports.php?event=<?php echo (int) $selectedEventId; ?>&report=<?php echo urlencode($reportType); ?>&sort=designation&order=<?php echo $sortColumn === 'designation' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>">Designation</a></th>
                        <th><a class="sort-link" href="/admin/attendance_reports.php?event=<?php echo (int) $selectedEventId; ?>&report=<?php echo urlencode($reportType); ?>&sort=institution&order=<?php echo $sortColumn === 'institution' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>">Institute</a></th>
                        <th><a class="sort-link" href="/admin/attendance_reports.php?event=<?php echo (int) $selectedEventId; ?>&report=<?php echo urlencode($reportType); ?>&sort=district&order=<?php echo $sortColumn === 'district' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>">District</a></th>
                        <?php if ($reportType === 'summary'): ?>
                            <th><a class="sort-link" href="/admin/attendance_reports.php?event=<?php echo (int) $selectedEventId; ?>&report=<?php echo urlencode($reportType); ?>&sort=checked_in_at&order=<?php echo $sortColumn === 'checked_in_at' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>">Checked In</a></th>
                            <th><a class="sort-link" href="/admin/attendance_reports.php?event=<?php echo (int) $selectedEventId; ?>&report=<?php echo urlencode($reportType); ?>&sort=mode&order=<?php echo $sortColumn === 'mode' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>">Mode</a></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><input type="checkbox" name="exclude_ids[]" value="<?php echo (int) ($row['registration_id'] ?? 0); ?>"></td>
                            <td><?php echo htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($row['designation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($row['institution_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($row['district'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <?php if ($reportType === 'summary'): ?>
                                <td><?php echo htmlspecialchars((string) ($row['checked_in_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['mode'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
    <?php elseif ($event !== null): ?>
        <p class="muted">No attendee records are available for the selected event yet.</p>
    <?php endif; ?>
</div>
</body>
</html>
