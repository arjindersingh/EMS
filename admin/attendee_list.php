<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/events_funcs.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    header('Location: ' . buildUrl('admin'));
    exit;
}

function attendeeListAttendanceTableExists(PDO $pdo): bool
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'event_attendance'
SQL);
    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

function attendeeListRegistrationTableExists(PDO $pdo): bool
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'event_registrations'
SQL);
    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

function attendeeListValue(mixed $value, string $fallback = '—'): string
{
    $value = trim((string) $value);
    return $value === '' ? $fallback : $value;
}

function attendeeListName(array $attendee): string
{
    $parts = array_filter([
        trim((string) ($attendee['salutation'] ?? '')),
        trim((string) ($attendee['name'] ?? '')),
    ], static fn (string $value): bool => $value !== '');

    return attendeeListValue(implode(' ', $parts), 'Guest');
}

function attendeeListFormatDate(mixed $value, string $format = 'd M Y, h:i A'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date($format, $timestamp);
}

function attendeeListFetchAttendees(PDO $pdo, int $eventId, bool $attendanceTableExists, bool $registrationTableExists): array
{
    if ($eventId <= 0 || !$attendanceTableExists || !$registrationTableExists) {
        return [];
    }

    $statement = $pdo->prepare(<<<'SQL'
        SELECT
            ea.attendance_id,
            ea.checked_in_at,
            ea.checked_in_by,
            ea.mode,
            er.registration_id,
            er.salutation,
            er.name,
            er.designation,
            er.institution_name,
            er.mobile,
            er.official_email,
            er.district
        FROM event_attendance ea
        INNER JOIN event_registrations er
            ON er.registration_id = ea.registration_id
           AND er.event_id = ea.event_id
        WHERE ea.event_id = :event_id
        ORDER BY ea.checked_in_at DESC, ea.attendance_id DESC
SQL);
    $statement->execute([':event_id' => $eventId]);

    return $statement->fetchAll() ?: [];
}

function attendeeListFetchSummary(PDO $pdo, int $eventId, bool $attendanceTableExists, bool $registrationTableExists): array
{
    $summary = [
        'checked_in_count' => 0,
        'approved_count' => 0,
        'latest_attendance_id' => 0,
        'latest_checked_in_at' => '',
        'attendance_rate' => 0,
    ];

    if ($eventId <= 0) {
        return $summary;
    }

    if ($registrationTableExists) {
        $approvedStatement = $pdo->prepare(<<<'SQL'
            SELECT COUNT(*)
            FROM event_registrations
            WHERE event_id = :event_id
              AND approval_status = 'approved'
SQL);
        $approvedStatement->execute([':event_id' => $eventId]);
        $summary['approved_count'] = (int) $approvedStatement->fetchColumn();
    }

    if ($attendanceTableExists && $registrationTableExists) {
        $attendanceStatement = $pdo->prepare(<<<'SQL'
            SELECT
                COUNT(*) AS checked_in_count,
                COALESCE(MAX(attendance_id), 0) AS latest_attendance_id,
                MAX(checked_in_at) AS latest_checked_in_at
            FROM event_attendance ea
            INNER JOIN event_registrations er
                ON er.registration_id = ea.registration_id
               AND er.event_id = ea.event_id
            WHERE ea.event_id = :event_id
SQL);
        $attendanceStatement->execute([':event_id' => $eventId]);
        $attendance = $attendanceStatement->fetch() ?: [];
        $summary['checked_in_count'] = (int) ($attendance['checked_in_count'] ?? 0);
        $summary['latest_attendance_id'] = (int) ($attendance['latest_attendance_id'] ?? 0);
        $summary['latest_checked_in_at'] = (string) ($attendance['latest_checked_in_at'] ?? '');
    }

    if ($summary['approved_count'] > 0) {
        $summary['attendance_rate'] = (int) round(($summary['checked_in_count'] / $summary['approved_count']) * 100);
    }

    return $summary;
}

function attendeeListLivePayload(PDO $pdo, int $eventId, ?int $sinceAttendanceId = null): array
{
    $event = $eventId > 0 ? getEventById($pdo, $eventId) : null;
    if (!$event) {
        return [
            'success' => false,
            'message' => 'Please choose a valid event.',
            'event' => null,
            'summary' => attendeeListFetchSummary($pdo, 0, false, false),
            'attendees' => [],
            'has_changes' => false,
        ];
    }

    $attendanceTableExists = attendeeListAttendanceTableExists($pdo);
    $registrationTableExists = attendeeListRegistrationTableExists($pdo);
    $summary = attendeeListFetchSummary($pdo, $eventId, $attendanceTableExists, $registrationTableExists);
    $hasChanges = $sinceAttendanceId === null || $summary['latest_attendance_id'] > $sinceAttendanceId;

    return [
        'success' => true,
        'message' => !$registrationTableExists
            ? 'Registration storage is not available yet.'
            : ($attendanceTableExists ? '' : 'No check-ins have been recorded yet.'),
        'event' => [
            'event_id' => (int) $event['event_id'],
            'event_title' => (string) $event['event_title'],
            'start_date' => (string) ($event['start_date'] ?? ''),
            'venue_name' => (string) ($event['venue_name'] ?? ''),
        ],
        'summary' => $summary,
        'attendees' => $hasChanges ? attendeeListFetchAttendees($pdo, $eventId, $attendanceTableExists, $registrationTableExists) : [],
        'has_changes' => $hasChanges,
    ];
}

function attendeeListXml(string $value): string
{
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';

    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function attendeeListDocxParagraph(string $value, bool $bold = false, int $fontSize = 18): string
{
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    $runProperties = '<w:sz w:val="' . $fontSize . '"/><w:szCs w:val="' . $fontSize . '"/>';
    if ($bold) {
        $runProperties .= '<w:b/><w:bCs/>';
    }

    return '<w:p><w:pPr><w:spacing w:after="0" w:line="220" w:lineRule="auto"/></w:pPr><w:r><w:rPr>'
        . $runProperties
        . '</w:rPr><w:t xml:space="preserve">'
        . attendeeListXml($value)
        . '</w:t></w:r></w:p>';
}

function attendeeListDocxCell(string $value, int $width, bool $isHeader = false): string
{
    $cellProperties = '<w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/><w:tcMar><w:top w:w="85" w:type="dxa"/><w:start w:w="100" w:type="dxa"/><w:bottom w:w="85" w:type="dxa"/><w:end w:w="100" w:type="dxa"/></w:tcMar>';
    if ($isHeader) {
        $cellProperties .= '<w:shd w:val="clear" w:color="auto" w:fill="0F766E"/>';
    }
    $cellProperties .= '</w:tcPr>';

    return '<w:tc>' . $cellProperties . attendeeListDocxParagraph($value, $isHeader, $isHeader ? 16 : 15) . '</w:tc>';
}

function attendeeListBuildDocxDocument(array $event, array $attendees, array $summary): string
{
    $eventTitle = attendeeListValue($event['event_title'] ?? '', 'Event');
    $eventDate = attendeeListFormatDate($event['start_date'] ?? '', 'd M Y');
    $metadata = 'Checked in: ' . (int) ($summary['checked_in_count'] ?? 0)
        . '  |  Approved registrations: ' . (int) ($summary['approved_count'] ?? 0)
        . '  |  Generated: ' . date('d M Y, h:i A');

    $widths = [480, 1740, 2140, 2340, 1760, 900];
    $headers = ['#', 'Attendee', 'Contact', 'Institution', 'Checked in', 'Mode'];
    $grid = '';
    $headerCells = '';
    foreach ($widths as $index => $width) {
        $grid .= '<w:gridCol w:w="' . $width . '"/>';
        $headerCells .= attendeeListDocxCell($headers[$index], $width, true);
    }

    $rows = '<w:tr><w:trPr><w:tblHeader/></w:trPr>' . $headerCells . '</w:tr>';
    foreach ($attendees as $index => $attendee) {
        $contact = implode(' · ', array_filter([
            trim((string) ($attendee['mobile'] ?? '')),
            trim((string) ($attendee['official_email'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));
        $institution = attendeeListValue($attendee['institution_name'] ?? '', '—');
        $designation = trim((string) ($attendee['designation'] ?? ''));
        if ($designation !== '') {
            $institution .= ' · ' . $designation;
        }

        $cells = [
            (string) ($index + 1),
            attendeeListName($attendee),
            attendeeListValue($contact),
            $institution,
            attendeeListFormatDate($attendee['checked_in_at'] ?? ''),
            attendeeListValue($attendee['mode'] ?? ''),
        ];
        $rowCells = '';
        foreach ($cells as $cellIndex => $cell) {
            $rowCells .= attendeeListDocxCell($cell, $widths[$cellIndex]);
        }
        $rows .= '<w:tr>' . $rowCells . '</w:tr>';
    }

    if ($attendees === []) {
        $rows .= '<w:tr>' . attendeeListDocxCell('No attendees have checked in for this event yet.', array_sum($widths)) . '</w:tr>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>'
        . attendeeListDocxParagraph('Live Check-in Attendee List', true, 32)
        . attendeeListDocxParagraph($eventTitle . ($eventDate !== '—' ? ' · ' . $eventDate : ''), true, 22)
        . attendeeListDocxParagraph($metadata, false, 16)
        . '<w:p/>'
        . '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblLayout w:type="fixed"/>'
        . '<w:tblBorders><w:top w:val="single" w:sz="6" w:space="0" w:color="B8C9C5"/><w:start w:val="single" w:sz="6" w:space="0" w:color="B8C9C5"/><w:bottom w:val="single" w:sz="6" w:space="0" w:color="B8C9C5"/><w:end w:val="single" w:sz="6" w:space="0" w:color="B8C9C5"/><w:insideH w:val="single" w:sz="4" w:space="0" w:color="D9E5E2"/><w:insideV w:val="single" w:sz="4" w:space="0" w:color="D9E5E2"/></w:tblBorders></w:tblPr>'
        . '<w:tblGrid>' . $grid . '</w:tblGrid>'
        . $rows
        . '</w:tbl>'
        . '<w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';
}

function attendeeListDownloadFilename(array $event, string $extension): string
{
    $eventTitle = strtolower((string) ($event['event_title'] ?? 'event'));
    $filename = preg_replace('/[^a-z0-9]+/i', '-', 'live-checkin-attendees-' . $eventTitle) ?: 'live-checkin-attendees';

    return trim($filename, '-') . '.' . $extension;
}

function attendeeListExportDocx(array $event, array $attendees, array $summary): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('DOCX export requires the PHP Zip extension.');
    }

    $temporaryFile = tempnam(sys_get_temp_dir(), 'ems-attendees-');
    if ($temporaryFile === false) {
        throw new RuntimeException('Unable to prepare the DOCX export.');
    }

    $zipOpened = false;
    try {
        $zip = new ZipArchive();
        if ($zip->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the DOCX export.');
        }
        $zipOpened = true;

        $docxFiles = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => attendeeListBuildDocxDocument($event, $attendees, $summary),
        ];
        foreach ($docxFiles as $path => $contents) {
            if (!$zip->addFromString($path, $contents)) {
                throw new RuntimeException('Unable to write the DOCX export.');
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('Unable to finalize the DOCX export.');
        }
        $zipOpened = false;

        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . attendeeListDownloadFilename($event, 'docx') . '"');
        header('Content-Length: ' . (string) filesize($temporaryFile));
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($temporaryFile);
    } finally {
        if ($zipOpened) {
            $zip->close();
        }
        if (is_file($temporaryFile)) {
            @unlink($temporaryFile);
        }
    }

    exit;
}

function attendeeListBuildPdfHtml(
    array $event,
    array $attendees,
    array $summary,
    bool $showPrintActions = false,
    bool $autoPrint = false
): string
{
    $eventTitle = attendeeListValue($event['event_title'] ?? '', 'Event');
    $eventDate = attendeeListFormatDate($event['start_date'] ?? '', 'd M Y');
    $eventDetails = array_filter([
        $eventDate === '—' ? '' : $eventDate,
        trim((string) ($event['venue_name'] ?? '')),
    ], static fn (string $value): bool => $value !== '');

    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars('Live Check-in Attendees — ' . $eventTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        @page { size: A4 landscape; margin: 11mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #1f2937; background: #eef4f2; font-family: Arial, sans-serif; }
        .print-actions { position: fixed; z-index: 2; top: 18px; right: 18px; }
        .print-actions button { border: 0; border-radius: 8px; padding: 10px 15px; background: #0f766e; color: #fff; cursor: pointer; font: 700 14px Arial, sans-serif; }
        .sheet { width: min(1180px, calc(100% - 32px)); margin: 32px auto; padding: 28px; background: #fff; box-shadow: 0 14px 40px rgba(15, 23, 42, .12); }
        .sheet-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; padding-bottom: 18px; border-bottom: 3px solid #0f766e; }
        .eyebrow { margin: 0 0 7px; color: #0f766e; font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 0; color: #163c39; font-size: 27px; }
        .event-details { margin: 7px 0 0; color: #64748b; font-size: 13px; }
        .total { min-width: 120px; padding: 12px 16px; border-radius: 10px; background: #ecfdf5; color: #0f766e; text-align: center; }
        .total strong, .total span { display: block; }
        .total strong { font-size: 25px; line-height: 1; }
        .total span { margin-top: 5px; font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
        table { width: 100%; margin-top: 22px; border-collapse: collapse; font-size: 10px; }
        th, td { padding: 8px 7px; border: 1px solid #dce7e4; text-align: left; vertical-align: top; }
        th { background: #113f3a; color: #fff; font-size: 9px; letter-spacing: .05em; text-transform: uppercase; }
        tbody tr:nth-child(even) { background: #f7fbfa; }
        td small { display: block; margin-top: 2px; color: #64748b; font-size: 9px; }
        .empty { padding: 28px; color: #64748b; text-align: center; }
        .footer { margin-top: 14px; color: #64748b; font-size: 9px; text-align: right; }
        @media print {
            body { background: #fff; }
            .print-actions { display: none; }
            .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <?php if ($showPrintActions): ?><div class="print-actions"><button type="button" onclick="window.print()">Save as PDF</button></div><?php endif; ?>
    <main class="sheet">
        <header class="sheet-header">
            <div>
                <p class="eyebrow">Live attendance report</p>
                <h1><?php echo htmlspecialchars($eventTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
                <?php if ($eventDetails !== []): ?><p class="event-details"><?php echo htmlspecialchars(implode(' · ', $eventDetails), ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
            </div>
            <div class="total"><strong><?php echo number_format((int) ($summary['checked_in_count'] ?? 0)); ?></strong><span>checked in</span></div>
        </header>
        <table>
            <thead><tr><th>#</th><th>Attendee</th><th>Institution</th><th>Contact</th><th>Checked in</th><th>Mode</th></tr></thead>
            <tbody>
                <?php if ($attendees === []): ?>
                    <tr><td class="empty" colspan="6">No attendees have checked in for this event yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($attendees as $index => $attendee): ?>
                        <?php $contact = implode(' · ', array_filter([trim((string) ($attendee['mobile'] ?? '')), trim((string) ($attendee['official_email'] ?? ''))], static fn (string $value): bool => $value !== '')); ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars(attendeeListName($attendee), ENT_QUOTES, 'UTF-8'); ?></strong><?php if (!empty($attendee['designation'])): ?><small><?php echo htmlspecialchars((string) $attendee['designation'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></td>
                            <td><strong><?php echo htmlspecialchars(attendeeListValue($attendee['institution_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong><?php if (!empty($attendee['district'])): ?><small><?php echo htmlspecialchars((string) $attendee['district'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></td>
                            <td><?php echo htmlspecialchars(attendeeListValue($contact), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(attendeeListFormatDate($attendee['checked_in_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($attendee['checked_in_by'])): ?><small><?php echo htmlspecialchars((string) $attendee['checked_in_by'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></td>
                            <td><?php echo htmlspecialchars(attendeeListValue($attendee['mode'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="footer">Generated <?php echo htmlspecialchars(date('d M Y, h:i A'), ENT_QUOTES, 'UTF-8'); ?> · Approved registrations: <?php echo number_format((int) ($summary['approved_count'] ?? 0)); ?></div>
    </main>
    <?php if ($autoPrint): ?><script>window.addEventListener('load', function () { window.print(); });</script><?php endif; ?>
</body>
</html>
<?php
    return (string) ob_get_clean();
}

function attendeeListFindPdfBrowser(): ?string
{
    $candidates = [];
    $configuredBrowser = trim((string) getenv('EMS_PDF_BROWSER'));
    if ($configuredBrowser !== '') {
        $candidates[] = $configuredBrowser;
    }

    foreach (['PROGRAMFILES', 'PROGRAMFILES(X86)', 'LOCALAPPDATA'] as $environmentVariable) {
        $basePath = trim((string) getenv($environmentVariable));
        if ($basePath === '') {
            continue;
        }

        $candidates[] = $basePath . DIRECTORY_SEPARATOR . 'Google' . DIRECTORY_SEPARATOR . 'Chrome' . DIRECTORY_SEPARATOR . 'Application' . DIRECTORY_SEPARATOR . 'chrome.exe';
        $candidates[] = $basePath . DIRECTORY_SEPARATOR . 'Microsoft' . DIRECTORY_SEPARATOR . 'Edge' . DIRECTORY_SEPARATOR . 'Application' . DIRECTORY_SEPARATOR . 'msedge.exe';
    }

    $candidates = array_merge($candidates, [
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ]);

    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function attendeeListPdfFileUrl(string $path): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $parts = array_map(
        static fn (string $part): string => str_replace('%3A', ':', rawurlencode($part)),
        explode('/', $path)
    );

    return 'file:///' . implode('/', $parts);
}

function attendeeListRemoveTemporaryPdfDirectory(string $directory): void
{
    $temporaryRoot = realpath(sys_get_temp_dir());
    $resolvedDirectory = realpath($directory);
    if ($temporaryRoot === false || $resolvedDirectory === false) {
        return;
    }

    $expectedPrefix = rtrim($temporaryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ems-attendee-pdf-profile-';
    $matchesPrefix = DIRECTORY_SEPARATOR === '\\'
        ? strncasecmp($resolvedDirectory, $expectedPrefix, strlen($expectedPrefix)) === 0
        : strncmp($resolvedDirectory, $expectedPrefix, strlen($expectedPrefix)) === 0;
    if (!$matchesPrefix) {
        return;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                @unlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                @rmdir($entry->getPathname());
            }
        }
    } catch (UnexpectedValueException) {
        // A browser may already have removed a transient profile file.
    }

    @rmdir($resolvedDirectory);
}

function attendeeListGeneratePdf(string $html): ?string
{
    $browser = attendeeListFindPdfBrowser();
    if ($browser === null || !function_exists('proc_open')) {
        return null;
    }

    $htmlSeed = false;
    $pdfSeed = false;
    $profileSeed = false;
    $htmlFile = null;
    $pdfFile = null;
    $profileDirectory = null;
    $stdoutFile = null;
    $stderrFile = null;
    $process = null;

    try {
        $htmlSeed = tempnam(sys_get_temp_dir(), 'ems-attendee-pdf-');
        $pdfSeed = tempnam(sys_get_temp_dir(), 'ems-attendee-pdf-');
        $profileSeed = tempnam(sys_get_temp_dir(), 'ems-attendee-pdf-profile-');
        if ($htmlSeed === false || $pdfSeed === false || $profileSeed === false) {
            return null;
        }

        $htmlFile = $htmlSeed . '.html';
        $pdfFile = $pdfSeed . '.pdf';
        $profileDirectory = $profileSeed;
        if (!@rename($htmlSeed, $htmlFile) || !@rename($pdfSeed, $pdfFile) || !@unlink($pdfFile) || !@unlink($profileSeed) || !@mkdir($profileDirectory, 0700)) {
            return null;
        }
        if (@file_put_contents($htmlFile, $html, LOCK_EX) === false) {
            return null;
        }

        $stdoutFile = $pdfFile . '.stdout.log';
        $stderrFile = $pdfFile . '.stderr.log';
        $process = @proc_open(
            [
                $browser,
                '--headless',
                '--disable-gpu',
                '--disable-background-networking',
                '--no-first-run',
                '--no-default-browser-check',
                '--no-pdf-header-footer',
                '--run-all-compositor-stages-before-draw',
                '--user-data-dir=' . $profileDirectory,
                '--print-to-pdf=' . $pdfFile,
                attendeeListPdfFileUrl($htmlFile),
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutFile, 'a'],
                2 => ['file', $stderrFile, 'a'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return null;
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $deadline = microtime(true) + 20;
        do {
            $status = proc_get_status($process);
            if (!is_array($status) || !($status['running'] ?? false)) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        if (is_array($status) && ($status['running'] ?? false)) {
            @proc_terminate($process);
        }
        @proc_close($process);
        $process = null;

        $pdf = is_file($pdfFile) ? @file_get_contents($pdfFile) : false;
        return is_string($pdf) && str_starts_with($pdf, '%PDF-') ? $pdf : null;
    } catch (Throwable) {
        return null;
    } finally {
        if (is_resource($process)) {
            @proc_terminate($process);
            @proc_close($process);
        }
        foreach ([$htmlSeed, $htmlFile, $pdfSeed, $pdfFile, $stdoutFile, $stderrFile] as $temporaryFile) {
            if (is_string($temporaryFile) && is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
        if (is_string($profileDirectory)) {
            attendeeListRemoveTemporaryPdfDirectory($profileDirectory);
        }
    }
}

function attendeeListExportPdf(array $event, array $attendees, array $summary): void
{
    $pdf = attendeeListGeneratePdf(attendeeListBuildPdfHtml($event, $attendees, $summary));
    if ($pdf !== null) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . attendeeListDownloadFilename($event, 'pdf') . '"');
        header('Content-Length: ' . (string) strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $pdf;
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo attendeeListBuildPdfHtml($event, $attendees, $summary, true, true);
    exit;
}

if (isset($_GET['live']) && $_GET['live'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $emptySummary = [
        'checked_in_count' => 0,
        'approved_count' => 0,
        'latest_attendance_id' => 0,
        'latest_checked_in_at' => '',
        'attendance_rate' => 0,
    ];
    try {
        $livePdo = createDbConnection();
        $liveEventId = max(0, (int) ($_GET['event'] ?? 0));
        $sinceAttendanceId = isset($_GET['since']) ? max(0, (int) $_GET['since']) : null;
        echo json_encode(attendeeListLivePayload($livePdo, $liveEventId, $sinceAttendanceId), JSON_UNESCAPED_SLASHES);
    } catch (PDOException $exception) {
        echo json_encode([
            'success' => false,
            'message' => 'Unable to refresh the attendee list.',
            'event' => null,
            'summary' => $emptySummary,
            'attendees' => [],
            'has_changes' => false,
        ], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$pdo = null;
$events = [];
$selectedEvent = null;
$payload = [
    'success' => false,
    'message' => '',
    'event' => null,
    'summary' => [
        'checked_in_count' => 0,
        'approved_count' => 0,
        'latest_attendance_id' => 0,
        'latest_checked_in_at' => '',
        'attendance_rate' => 0,
    ],
    'attendees' => [],
    'has_changes' => false,
];
$error = '';
$requestedEventId = max(0, (int) ($_GET['event'] ?? 0));

try {
    $pdo = createDbConnection();
    $events = getAllEvents($pdo);
    if ($requestedEventId === 0 && $events !== []) {
        $requestedEventId = (int) $events[0]['event_id'];
    }
    if ($requestedEventId > 0) {
        $payload = attendeeListLivePayload($pdo, $requestedEventId);
        $selectedEvent = $payload['success'] ? getEventById($pdo, $requestedEventId) : null;
        if (!$payload['success']) {
            $error = (string) $payload['message'];
        }
    }
} catch (PDOException $exception) {
    $error = 'Unable to load live check-in data. Please try again.';
}

$export = strtolower(trim((string) ($_GET['export'] ?? '')));
if ($selectedEvent && in_array($export, ['docx', 'pdf'], true)) {
    try {
        if ($export === 'docx') {
            attendeeListExportDocx($selectedEvent, $payload['attendees'], $payload['summary']);
        }
        attendeeListExportPdf($selectedEvent, $payload['attendees'], $payload['summary']);
    } catch (Throwable $exception) {
        $error = 'Unable to create the ' . strtoupper($export) . ' export. Please try again.';
    }
}

$selectedEventId = (int) ($selectedEvent['event_id'] ?? 0);
$summary = $payload['summary'];
$attendees = $payload['attendees'];
$exportBase = buildUrl('admin/attendee_list.php') . '?event=' . $selectedEventId;

ob_start();
?>
<div class="live-checkin-content" data-live-endpoint="<?php echo htmlspecialchars(buildUrl('admin/attendee_list.php'), ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($error !== ''): ?>
        <div class="alert error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="live-checkin-hero">
        <div class="live-checkin-hero-copy">
            <p class="live-checkin-eyebrow"><span aria-hidden="true"></span> Live attendance board</p>
            <h2>Live Check-in</h2>
            <p>Follow every arrival as it happens. The newest attendee always appears first.</p>
        </div>
        <div class="live-checkin-hero-total" aria-live="polite">
            <strong id="liveCheckinHeroTotal"><?php echo number_format((int) $summary['checked_in_count']); ?></strong>
            <span>checked in</span>
        </div>
    </section>

    <section class="live-checkin-toolbar">
        <form method="get" class="live-checkin-event-picker" id="liveCheckinEventForm">
            <label for="liveCheckinEvent">Choose event</label>
            <div>
                <select name="event" id="liveCheckinEvent" <?php echo $events === [] ? 'disabled' : ''; ?>>
                    <?php if ($events === []): ?>
                        <option value="">No events available</option>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <option value="<?php echo (int) $event['event_id']; ?>" <?php echo (int) $event['event_id'] === $selectedEventId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) $event['event_title'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <button type="submit" class="button-secondary">View event</button>
            </div>
        </form>

        <div class="live-checkin-exports" aria-label="Export attendee list">
            <a class="live-checkin-export-link docx" id="liveCheckinDocx" <?php echo $selectedEvent ? 'href="' . htmlspecialchars($exportBase . '&export=docx', ENT_QUOTES, 'UTF-8') . '"' : 'aria-disabled="true" tabindex="-1"'; ?>>
                <span aria-hidden="true">↓</span> Export DOCX
            </a>
            <a class="live-checkin-export-link pdf" id="liveCheckinPdf" target="_blank" <?php echo $selectedEvent ? 'href="' . htmlspecialchars($exportBase . '&export=pdf', ENT_QUOTES, 'UTF-8') . '"' : 'aria-disabled="true" tabindex="-1"'; ?>>
                <span aria-hidden="true">↓</span> Export PDF
            </a>
        </div>
    </section>

    <section class="live-checkin-summary" aria-live="polite">
        <article class="live-checkin-stat accent-teal">
            <span>Checked in</span>
            <strong id="liveCheckinCount"><?php echo number_format((int) $summary['checked_in_count']); ?></strong>
            <small>Arrivals recorded</small>
        </article>
        <article class="live-checkin-stat accent-blue">
            <span>Approved</span>
            <strong id="liveCheckinApproved"><?php echo number_format((int) $summary['approved_count']); ?></strong>
            <small>Eligible attendees</small>
        </article>
        <article class="live-checkin-stat accent-violet">
            <span>Attendance rate</span>
            <strong id="liveCheckinRate"><?php echo (int) $summary['attendance_rate']; ?>%</strong>
            <div class="live-checkin-progress" aria-hidden="true"><span id="liveCheckinProgress" style="width: <?php echo max(0, min(100, (int) $summary['attendance_rate'])); ?>%"></span></div>
        </article>
        <article class="live-checkin-stat accent-amber">
            <span>Latest check-in</span>
            <strong class="live-checkin-latest-time" id="liveCheckinLatest"><?php echo htmlspecialchars(attendeeListFormatDate($summary['latest_checked_in_at'] ?? '', 'h:i A'), ENT_QUOTES, 'UTF-8'); ?></strong>
            <small id="liveCheckinLatestDate"><?php echo htmlspecialchars(attendeeListFormatDate($summary['latest_checked_in_at'] ?? '', 'd M Y'), ENT_QUOTES, 'UTF-8'); ?></small>
        </article>
    </section>

    <section class="live-checkin-list-panel">
        <header class="live-checkin-list-header">
            <div>
                <p>Latest check-ins first</p>
                <h2 id="liveCheckinEventTitle"><?php echo htmlspecialchars((string) ($selectedEvent['event_title'] ?? 'Choose an event'), ENT_QUOTES, 'UTF-8'); ?></h2>
                <span id="liveCheckinEventMeta"><?php echo htmlspecialchars(attendeeListFormatDate($selectedEvent['start_date'] ?? '', 'd M Y') . (!empty($selectedEvent['venue_name']) ? ' · ' . $selectedEvent['venue_name'] : ''), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="live-checkin-live-status" id="liveCheckinLiveStatus" aria-live="off">
                <span class="live-checkin-pulse" aria-hidden="true"></span>
                <strong>Live</strong>
                <small id="liveCheckinRefreshTime">Updated just now</small>
            </div>
        </header>

        <div class="live-checkin-table-wrap">
            <table class="live-checkin-table">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Attendee</th>
                        <th scope="col">Institution</th>
                        <th scope="col">Contact</th>
                        <th scope="col">Checked in</th>
                        <th scope="col">Mode</th>
                    </tr>
                </thead>
                <tbody id="liveCheckinRows">
                    <?php if ($attendees === []): ?>
                        <tr class="live-checkin-empty-row"><td colspan="6"><div class="live-checkin-empty"><span aria-hidden="true">✓</span><strong>Waiting for the first check-in</strong><p>New attendees will appear here automatically.</p></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($attendees as $index => $attendee): ?>
                            <?php
                            $contact = implode(' · ', array_filter([
                                trim((string) ($attendee['mobile'] ?? '')),
                                trim((string) ($attendee['official_email'] ?? '')),
                            ], static fn (string $value): bool => $value !== ''));
                            ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td data-label="Attendee"><strong><?php echo htmlspecialchars(attendeeListName($attendee), ENT_QUOTES, 'UTF-8'); ?></strong><?php if (!empty($attendee['designation'])): ?><small><?php echo htmlspecialchars((string) $attendee['designation'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></td>
                                <td data-label="Institution"><strong><?php echo htmlspecialchars(attendeeListValue($attendee['institution_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong><?php if (!empty($attendee['district'])): ?><small><?php echo htmlspecialchars((string) $attendee['district'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></td>
                                <td data-label="Contact" class="live-checkin-contact"><?php echo htmlspecialchars(attendeeListValue($contact), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="Checked in"><strong><?php echo htmlspecialchars(attendeeListFormatDate($attendee['checked_in_at'] ?? '', 'h:i A'), ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars(attendeeListFormatDate($attendee['checked_in_at'] ?? '', 'd M Y'), ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($attendee['checked_in_by'])): ?> · <?php echo htmlspecialchars((string) $attendee['checked_in_by'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></small></td>
                                <td data-label="Mode"><span class="live-checkin-mode"><?php echo htmlspecialchars(attendeeListValue($attendee['mode'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
(() => {
    const root = document.querySelector('.live-checkin-content');
    if (!root) return;

    const endpoint = root.dataset.liveEndpoint;
    const eventSelector = document.getElementById('liveCheckinEvent');
    const rowsElement = document.getElementById('liveCheckinRows');
    const eventTitle = document.getElementById('liveCheckinEventTitle');
    const eventMeta = document.getElementById('liveCheckinEventMeta');
    const countElement = document.getElementById('liveCheckinCount');
    const heroTotal = document.getElementById('liveCheckinHeroTotal');
    const approvedElement = document.getElementById('liveCheckinApproved');
    const rateElement = document.getElementById('liveCheckinRate');
    const progressElement = document.getElementById('liveCheckinProgress');
    const latestElement = document.getElementById('liveCheckinLatest');
    const latestDateElement = document.getElementById('liveCheckinLatestDate');
    const refreshTime = document.getElementById('liveCheckinRefreshTime');
    const liveStatus = document.getElementById('liveCheckinLiveStatus');
    const docxLink = document.getElementById('liveCheckinDocx');
    const pdfLink = document.getElementById('liveCheckinPdf');
    const initialPayload = <?php echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
    const numberFormat = new Intl.NumberFormat();
    let selectedEventId = Number(eventSelector?.value || 0);
    let lastAttendanceId = Number(initialPayload?.summary?.latest_attendance_id || 0);
    let activeRequest = null;
    let requestSequence = 0;

    const safeText = value => String(value || '').trim();

    function formatDate(value, options) {
        if (!value) return '—';
        const parsed = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString([], options);
    }

    function eventDescription(event) {
        if (!event) return 'Choose an event to see live arrivals.';
        const parts = [];
        if (event.start_date) parts.push(formatDate(event.start_date, { day: '2-digit', month: 'short', year: 'numeric' }));
        if (event.venue_name) parts.push(event.venue_name);
        return parts.join(' · ') || 'Live attendee list';
    }

    function setText(element, value) {
        if (element) element.textContent = value;
    }

    function updateExportLinks() {
        [docxLink, pdfLink].forEach(link => {
            if (!link) return;
            const exportType = link === docxLink ? 'docx' : 'pdf';
            if (!selectedEventId) {
                link.setAttribute('aria-disabled', 'true');
                link.setAttribute('tabindex', '-1');
                link.removeAttribute('href');
                return;
            }
            link.removeAttribute('aria-disabled');
            link.removeAttribute('tabindex');
            link.href = endpoint + '?event=' + encodeURIComponent(selectedEventId) + '&export=' + exportType;
        });
    }

    function renderSummary(summary = {}) {
        const checkedIn = Number(summary.checked_in_count || 0);
        const approved = Number(summary.approved_count || 0);
        const rate = Math.max(0, Math.min(100, Number(summary.attendance_rate || 0)));
        setText(countElement, numberFormat.format(checkedIn));
        setText(heroTotal, numberFormat.format(checkedIn));
        setText(approvedElement, numberFormat.format(approved));
        setText(rateElement, rate + '%');
        if (progressElement) progressElement.style.width = rate + '%';
        setText(latestElement, formatDate(summary.latest_checked_in_at, { hour: 'numeric', minute: '2-digit' }));
        setText(latestDateElement, formatDate(summary.latest_checked_in_at, { day: '2-digit', month: 'short', year: 'numeric' }));
    }

    function createCell(label, content, className = '') {
        const cell = document.createElement('td');
        cell.dataset.label = label;
        if (className) cell.className = className;
        if (content instanceof Node) {
            cell.append(content);
        } else {
            cell.textContent = content;
        }
        return cell;
    }

    function detailCell(label, primary, secondary = '') {
        const wrapper = document.createDocumentFragment();
        const strong = document.createElement('strong');
        strong.textContent = safeText(primary) || '—';
        wrapper.append(strong);
        if (safeText(secondary)) {
            const small = document.createElement('small');
            small.textContent = secondary;
            wrapper.append(small);
        }
        return createCell(label, wrapper);
    }

    function renderRows(attendees) {
        rowsElement.replaceChildren();
        if (!Array.isArray(attendees) || attendees.length === 0) {
            const row = document.createElement('tr');
            row.className = 'live-checkin-empty-row';
            const cell = document.createElement('td');
            cell.colSpan = 6;
            const empty = document.createElement('div');
            empty.className = 'live-checkin-empty';
            const icon = document.createElement('span');
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = '✓';
            const title = document.createElement('strong');
            title.textContent = 'Waiting for the first check-in';
            const description = document.createElement('p');
            description.textContent = 'New attendees will appear here automatically.';
            empty.append(icon, title, description);
            cell.append(empty);
            row.append(cell);
            rowsElement.append(row);
            return;
        }

        attendees.forEach((attendee, index) => {
            const row = document.createElement('tr');
            const displayName = [safeText(attendee.salutation), safeText(attendee.name)].filter(Boolean).join(' ') || 'Guest';
            const contact = [safeText(attendee.mobile), safeText(attendee.official_email)].filter(Boolean).join(' · ') || '—';
            const checkedInAt = attendee.checked_in_at;
            row.append(
                createCell('#', String(index + 1)),
                detailCell('Attendee', displayName, safeText(attendee.designation)),
                detailCell('Institution', safeText(attendee.institution_name), safeText(attendee.district)),
                createCell('Contact', contact, 'live-checkin-contact'),
                detailCell('Checked in', formatDate(checkedInAt, { hour: 'numeric', minute: '2-digit' }), [formatDate(checkedInAt, { day: '2-digit', month: 'short', year: 'numeric' }), safeText(attendee.checked_in_by)].filter(value => value && value !== '—').join(' · ')),
            );
            const mode = document.createElement('span');
            mode.className = 'live-checkin-mode';
            mode.textContent = safeText(attendee.mode) || '—';
            row.append(createCell('Mode', mode));
            rowsElement.append(row);
        });
    }

    function renderPayload(payload) {
        if (!payload?.success) return;
        renderSummary(payload.summary);
        setText(eventTitle, payload.event?.event_title || 'Choose an event');
        setText(eventMeta, eventDescription(payload.event));
        if (Array.isArray(payload.attendees)) renderRows(payload.attendees);
        lastAttendanceId = Number(payload.summary?.latest_attendance_id || 0);
    }

    function setLiveStatus(message, isError = false) {
        setText(refreshTime, message);
        liveStatus?.classList.toggle('is-error', isError);
    }

    async function refreshLiveList(force = false) {
        if (!selectedEventId) return;
        if (activeRequest) activeRequest.abort();
        const requestedEventId = selectedEventId;
        const requestId = ++requestSequence;
        activeRequest = new AbortController();
        try {
            const requestUrl = endpoint + '?live=1&event=' + encodeURIComponent(requestedEventId)
                + '&since=' + encodeURIComponent(force ? 0 : lastAttendanceId)
                + '&_=' + Date.now();
            const response = await fetch(requestUrl, { cache: 'no-store', signal: activeRequest.signal });
            const payload = await response.json();
            if (requestedEventId !== selectedEventId || requestId !== requestSequence) return;
            if (!payload?.success) {
                setLiveStatus(payload?.message || 'Refresh paused', true);
                return;
            }
            if (force || payload.has_changes) renderPayload(payload);
            lastAttendanceId = Number(payload.summary?.latest_attendance_id || 0);
            setLiveStatus('Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' }));
        } catch (error) {
            if (error?.name !== 'AbortError' && requestedEventId === selectedEventId && requestId === requestSequence) {
                setLiveStatus('Connection paused — retrying', true);
            }
        } finally {
            if (requestId === requestSequence) activeRequest = null;
        }
    }

    eventSelector?.addEventListener('change', () => {
        selectedEventId = Number(eventSelector.value || 0);
        lastAttendanceId = 0;
        updateExportLinks();
        const url = new URL(window.location.href);
        if (selectedEventId) url.searchParams.set('event', String(selectedEventId));
        else url.searchParams.delete('event');
        url.searchParams.delete('live');
        url.searchParams.delete('export');
        window.history.replaceState({}, '', url);
        renderRows([]);
        setText(eventTitle, eventSelector.options[eventSelector.selectedIndex]?.text || 'Choose an event');
        setText(eventMeta, 'Loading live attendee list…');
        refreshLiveList(true);
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshLiveList();
    });

    updateExportLinks();
    window.setInterval(() => refreshLiveList(), 2000);
})();
</script>
<?php
$content = ob_get_clean();

renderAdminLayout('Live Check-in', $content, [
    'current_path' => 'attendee_list',
    'page_heading' => 'Live Check-in Attendees',
    'body_class' => 'live-checkin-page',
]);
