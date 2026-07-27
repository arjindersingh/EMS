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
    return $reportType === 'summary' ? 'Attendance Summary' : 'Facilitation Report';
}

function getReportTitle(string $reportType): string
{
    return $reportType === 'summary' ? 'Attendance Summary Report' : 'Facilitation Report';
}

function escapeXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
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

try {
    $pdo = createDbConnection();
    $events = getAllEvents($pdo);
} catch (PDOException $exception) {
    $adminError = 'Database connection failed: ' . $exception->getMessage();
}

if ($pdo !== null) {
    if (isset($_GET['event']) && (int) $_GET['event'] > 0) {
        $selectedEventId = (int) $_GET['event'];
    } elseif (!empty($events)) {
        $selectedEventId = (int) $events[0]['event_id'];
    }

    $reportType = in_array($_GET['report'] ?? 'facilitation', ['facilitation', 'summary'], true) ? (string) ($_GET['report'] ?? 'facilitation') : 'facilitation';
    $sortColumn = in_array($_GET['sort'] ?? 'name', ['name', 'designation', 'institution', 'district', 'checked_in_at', 'mode'], true) ? (string) ($_GET['sort'] ?? 'name') : 'name';
    $sortOrder = in_array($_GET['order'] ?? 'asc', ['asc', 'desc'], true) ? (string) ($_GET['order'] ?? 'asc') : 'asc';

    if ($selectedEventId > 0) {
        $event = getEventById($pdo, $selectedEventId);
        $rows = getAttendanceRows($pdo, $selectedEventId, $sortColumn, $sortOrder);
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'docx' && $pdo !== null && $selectedEventId > 0 && $event !== null) {
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
                <a href="/admin/attendance_reports.php?event=<?php echo (int) $selectedEventId; ?>&report=<?php echo urlencode($reportType); ?>&sort=<?php echo urlencode($sortColumn); ?>&order=<?php echo urlencode($sortOrder); ?>&export=docx"><button type="button">Export DOCX</button></a>
                <a href="/admin"><button type="button">Back to admin</button></a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($event !== null && !empty($rows)): ?>
        <table>
            <thead>
                <tr>
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
    <?php elseif ($event !== null): ?>
        <p class="muted">No attendee records are available for the selected event yet.</p>
    <?php endif; ?>
</div>
</body>
</html>
