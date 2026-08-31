<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/events_funcs.php';
require_once __DIR__ . '/registration_approval_funcs.php';
require_once __DIR__ . '/registration_admin_funcs.php';
require_once __DIR__ . '/registration_bulk_upload_funcs.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_authenticated'])) {
    header('Location: ' . buildUrl('admin'));
    exit;
}
if (empty($_SESSION['admin_registration_bulk_upload_csrf'])) {
    $_SESSION['admin_registration_bulk_upload_csrf'] = bin2hex(random_bytes(24));
}

function bulkRegistrationUploadEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bulkRegistrationUploadStatusClass(string $status): string
{
    return match ($status) {
        'Uploaded' => 'uploaded',
        'Skipped' => 'skipped',
        default => 'failed',
    };
}

function bulkRegistrationUploadFindEvent(array $events, int $eventId): ?array
{
    foreach ($events as $event) {
        if ((int) ($event['event_id'] ?? 0) === $eventId) {
            return $event;
        }
    }
    return null;
}

$csrfToken = (string) $_SESSION['admin_registration_bulk_upload_csrf'];
$pdo = null;
$events = [];
$error = '';
$workbook = null;
$mappingRows = [];
$result = null;
$selectedEventId = (int) ($_POST['event_id'] ?? 0);

try {
    registrationBulkUploadPruneTemporaryFiles();
    $pdo = createDbConnection();
    ensureEventsTable($pdo);
    ensureRegistrationBulkUploadStorage($pdo);
    $events = getAllEvents($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid request token. Refresh the page and try again.');
        }

        $action = isset($_POST['start_over']) ? 'start_over' : (string) ($_POST['action'] ?? '');
        if ($action === 'read_excel') {
            $selectedEvent = bulkRegistrationUploadFindEvent($events, $selectedEventId);
            if ($selectedEvent === null) {
                throw new InvalidArgumentException('Select an event before uploading the registration file.');
            }
            $workbook = registrationBulkUploadReadUploadedFile($_FILES['registration_file'] ?? []);
            $workbook['event_id'] = $selectedEventId;
            $workbook['event_title'] = (string) ($selectedEvent['event_title'] ?? '');
            $uploadToken = registrationBulkUploadStoreTemporaryPayload('workbook', $workbook);
            $workbook['upload_token'] = $uploadToken;
            $mappingRows = registrationBulkUploadMappingRows((array) $workbook['headers']);
        } elseif ($action === 'import_records') {
            $uploadToken = (string) ($_POST['upload_token'] ?? '');
            $workbook = registrationBulkUploadLoadTemporaryPayload($uploadToken, 'workbook');
            if ($workbook === null) {
                throw new InvalidArgumentException('This uploaded file is no longer available. Upload it again to continue.');
            }

            $workbook['upload_token'] = $uploadToken;
            $selectedEventId = (int) ($workbook['event_id'] ?? 0);
            $mappingRows = registrationBulkUploadMappingRows((array) $workbook['headers'], $_POST);
            $mappings = registrationBulkUploadBuildMappings($_POST, (array) $workbook['headers']);
            $result = registrationBulkUploadImport($pdo, $workbook, $mappings, $events, $selectedEventId);
            registrationBulkUploadDiscardTemporaryFile($uploadToken);

            $resultToken = registrationBulkUploadStoreTemporaryPayload('result', $result);
            header('Location: ' . buildUrl('admin/registration_bulk_upload.php') . '?result=' . rawurlencode($resultToken));
            exit;
        } elseif ($action === 'start_over') {
            registrationBulkUploadDiscardAllTemporaryFiles();
            header('Location: ' . buildUrl('admin/registration_bulk_upload.php'));
            exit;
        } else {
            throw new InvalidArgumentException('Invalid bulk upload action.');
        }
    }

    $resultToken = (string) ($_GET['result'] ?? '');
    if ($resultToken !== '') {
        $result = registrationBulkUploadLoadTemporaryPayload($resultToken, 'result');
        if ($result === null) {
            $error = 'The upload result is no longer available. Start a new import to continue.';
        }
    }

    $export = strtolower(trim((string) ($_GET['export'] ?? '')));
    $exportList = strtolower(trim((string) ($_GET['list'] ?? '')));
    if (is_array($result) && in_array($export, ['docx', 'pdf'], true) && in_array($exportList, ['uploaded', 'failed'], true)) {
        if ($export === 'docx') {
            registrationBulkUploadExportDocx($result, $exportList);
        } else {
            registrationBulkUploadExportPdf($result, $exportList);
        }
    }
} catch (Throwable $exception) {
    $error = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'The bulk upload page could not be loaded. Please try again.';
}

ob_start();
?>
<div class="bulk-registration-upload">
    <?php if ($error !== ''): ?>
        <div class="alert error" role="alert"><?php echo bulkRegistrationUploadEscape($error); ?></div>
    <?php endif; ?>

    <?php if (is_array($result)): ?>
        <?php
        $summary = (array) ($result['summary'] ?? []);
        $uploadedRecords = registrationBulkUploadResultsForList($result, 'uploaded');
        $failedRecords = registrationBulkUploadResultsForList($result, 'failed');
        $resultExportBase = buildUrl('admin/registration_bulk_upload.php') . '?result=' . rawurlencode((string) ($_GET['result'] ?? ''));
        ?>
        <section class="bulk-import-result-panel">
            <div class="bulk-import-result-heading">
                <div>
                    <p class="bulk-import-eyebrow">Registration import complete</p>
                    <h2><?php echo bulkRegistrationUploadEscape((string) ($result['file_name'] ?? 'Registration import')); ?></h2>
                    <p class="small">Event: <strong><?php echo bulkRegistrationUploadEscape((string) ($result['event_title'] ?? 'Event')); ?></strong> (ID: <?php echo (int) ($result['event_id'] ?? 0); ?>)</p>
                    <p class="small">Rows are grouped below into a successfully uploaded list and a failed/skipped list.</p>
                </div>
                <form method="post" class="bulk-import-start-over">
                    <input type="hidden" name="csrf_token" value="<?php echo bulkRegistrationUploadEscape($csrfToken); ?>">
                    <button type="submit" name="action" value="start_over" class="button-secondary">Upload another file</button>
                </form>
            </div>

            <div class="bulk-import-summary" aria-label="Import summary">
                <div><span>Total rows</span><strong><?php echo (int) ($summary['total'] ?? 0); ?></strong></div>
                <div class="uploaded"><span>Uploaded</span><strong><?php echo (int) ($summary['uploaded'] ?? 0); ?></strong></div>
                <div class="skipped"><span>Skipped</span><strong><?php echo (int) ($summary['skipped'] ?? 0); ?></strong></div>
                <div class="failed"><span>Failed</span><strong><?php echo (int) ($summary['failed'] ?? 0); ?></strong></div>
            </div>

            <section class="bulk-import-result-list">
                <div class="bulk-import-result-list-heading">
                    <h3>Successfully uploaded (<?php echo count($uploadedRecords); ?>)</h3>
                    <div class="bulk-import-exports live-checkin-exports" aria-label="Export uploaded list">
                        <a class="live-checkin-export-link docx" href="<?php echo bulkRegistrationUploadEscape($resultExportBase . '&list=uploaded&export=docx'); ?>">
                            <span aria-hidden="true">↓</span> Export DOCX
                        </a>
                        <a class="live-checkin-export-link pdf" target="_blank" href="<?php echo bulkRegistrationUploadEscape($resultExportBase . '&list=uploaded&export=pdf'); ?>">
                            <span aria-hidden="true">↓</span> Export PDF
                        </a>
                    </div>
                </div>
                <div class="bulk-import-results-table-wrap">
                    <table class="bulk-import-results-table">
                        <caption class="bulk-import-table-caption">Registration rows that were uploaded successfully.</caption>
                        <thead><tr><th scope="col">Excel Row</th><th scope="col">Name</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead>
                        <tbody>
                            <?php if ($uploadedRecords === []): ?>
                                <tr><td colspan="4">No rows were uploaded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($uploadedRecords as $record): ?>
                                    <?php $status = (string) ($record['status'] ?? 'Failed'); ?>
                                    <tr>
                                        <td data-label="Excel Row"><?php echo (int) ($record['source_row'] ?? 0); ?></td>
                                        <td data-label="Name"><?php echo bulkRegistrationUploadEscape((string) (($record['name'] ?? '') !== '' ? $record['name'] : '—')); ?></td>
                                        <td data-label="Status"><span class="bulk-import-status <?php echo bulkRegistrationUploadStatusClass($status); ?>"><?php echo bulkRegistrationUploadEscape($status); ?></span></td>
                                        <td data-label="Details"><?php echo bulkRegistrationUploadEscape((string) ($record['detail'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="bulk-import-result-list">
                <div class="bulk-import-result-list-heading">
                    <h3>Failed &amp; skipped (<?php echo count($failedRecords); ?>)</h3>
                    <div class="bulk-import-exports live-checkin-exports" aria-label="Export failed list">
                        <a class="live-checkin-export-link docx" href="<?php echo bulkRegistrationUploadEscape($resultExportBase . '&list=failed&export=docx'); ?>">
                            <span aria-hidden="true">↓</span> Export DOCX
                        </a>
                        <a class="live-checkin-export-link pdf" target="_blank" href="<?php echo bulkRegistrationUploadEscape($resultExportBase . '&list=failed&export=pdf'); ?>">
                            <span aria-hidden="true">↓</span> Export PDF
                        </a>
                    </div>
                </div>
                <div class="bulk-import-results-table-wrap">
                    <table class="bulk-import-results-table">
                        <caption class="bulk-import-table-caption">Registration rows that failed or were skipped.</caption>
                        <thead><tr><th scope="col">Excel Row</th><th scope="col">Name</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead>
                        <tbody>
                            <?php if ($failedRecords === []): ?>
                                <tr><td colspan="4">No rows failed or were skipped.</td></tr>
                            <?php else: ?>
                                <?php foreach ($failedRecords as $record): ?>
                                    <?php $status = (string) ($record['status'] ?? 'Failed'); ?>
                                    <tr>
                                        <td data-label="Excel Row"><?php echo (int) ($record['source_row'] ?? 0); ?></td>
                                        <td data-label="Name"><?php echo bulkRegistrationUploadEscape((string) (($record['name'] ?? '') !== '' ? $record['name'] : '—')); ?></td>
                                        <td data-label="Status"><span class="bulk-import-status <?php echo bulkRegistrationUploadStatusClass($status); ?>"><?php echo bulkRegistrationUploadEscape($status); ?></span></td>
                                        <td data-label="Details"><?php echo bulkRegistrationUploadEscape((string) ($record['detail'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    <?php elseif (is_array($workbook)): ?>
        <?php
        $headers = (array) ($workbook['headers'] ?? []);
        $fileName = (string) ($workbook['file_name'] ?? 'Uploaded spreadsheet');
        $rowCount = count((array) ($workbook['rows'] ?? []));
        $fields = registrationBulkUploadFields();
        $requiredFields = array_fill_keys(registrationBulkUploadRequiredFields(), true);
        $selectedEvent = bulkRegistrationUploadFindEvent($events, (int) ($workbook['event_id'] ?? 0));
        ?>
        <section class="bulk-import-mapping-panel">
            <p class="bulk-import-eyebrow">Step 2 of 2 · Match columns</p>
            <h2>Map the registration fields</h2>
            <p class="small">
                <strong><?php echo bulkRegistrationUploadEscape($fileName); ?></strong> contains <?php echo count($headers); ?> header(s) and <?php echo $rowCount; ?> data row(s).
                Select an Excel header for a registration field, or leave that dropdown empty and enter a fixed value in the third column.
            </p>
            <p class="small">All records will be saved to <code>event_registrations</code> for <strong><?php echo bulkRegistrationUploadEscape((string) ($selectedEvent['event_title'] ?? $workbook['event_title'] ?? 'the selected event')); ?></strong> (ID: <?php echo (int) ($workbook['event_id'] ?? 0); ?>). Name, Mobile, and Official Email must be mapped. The Excel header value takes priority whenever both a header and fixed value are present.</p>

            <?php if (!$events): ?>
                <div class="alert error">Create at least one event before importing registrations.</div>
            <?php endif; ?>

            <form method="post" id="bulk-import-mapping-form">
                <input type="hidden" name="csrf_token" value="<?php echo bulkRegistrationUploadEscape($csrfToken); ?>">
                <input type="hidden" name="action" value="import_records">
                <input type="hidden" name="upload_token" value="<?php echo bulkRegistrationUploadEscape((string) ($workbook['upload_token'] ?? '')); ?>">

                <div class="bulk-import-mapping-table-wrap">
                    <table class="bulk-import-mapping-table">
                    <caption class="bulk-import-table-caption">Match registration fields to Excel headers or fixed values.</caption>
                        <thead><tr><th scope="col">Registration Table Field</th><th scope="col">Excel Column Header</th><th scope="col">Fixed Value (if no Excel column)</th></tr></thead>
                        <tbody>
                            <?php foreach ($mappingRows as $index => $mapping): ?>
                                <tr>
                                    <td data-label="Registration Table Field">
                                        <select name="mapping_field[]" class="bulk-import-field" aria-label="Registration table field">
                                            <option value="">Do not import this field</option>
                                            <?php foreach ($fields as $field => $label): ?>
                                                <option value="<?php echo bulkRegistrationUploadEscape($field); ?>" <?php echo ($mapping['field'] ?? '') === $field ? 'selected' : ''; ?>>
                                                    <?php echo bulkRegistrationUploadEscape($label . ' (' . $field . ')' . (isset($requiredFields[$field]) ? ' - required' : '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td data-label="Excel Column Header">
                                        <select name="mapping_header[]" class="bulk-import-header" aria-label="Excel column header">
                                            <option value="">Use fixed value</option>
                                            <?php foreach ($headers as $headerIndex => $header): ?>
                                                <?php $value = 'column-' . $headerIndex; ?>
                                                <option value="<?php echo bulkRegistrationUploadEscape($value); ?>" <?php echo ($mapping['header'] ?? '') === $value ? 'selected' : ''; ?>>
                                                    <?php echo bulkRegistrationUploadEscape((string) $header . ' (Column ' . registrationBulkUploadColumnReference((int) $headerIndex) . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td data-label="Fixed Value">
                                        <input
                                            type="text"
                                            name="mapping_default[]"
                                            class="bulk-import-default"
                                            aria-label="Fixed value when no Excel column is selected"
                                            value="<?php echo bulkRegistrationUploadEscape((string) ($mapping['default'] ?? '')); ?>"
                                            placeholder="Used only without an Excel column"
                                        >
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="bulk-import-actions">
                    <button type="submit" id="bulk-import-submit" <?php echo !$events ? 'disabled' : ''; ?>>Import registrations</button>
                    <button type="submit" name="start_over" value="1" class="button-secondary">Choose a different file</button>
                </div>
                <p class="small" id="bulk-import-progress" aria-live="polite"></p>
            </form>
        </section>
    <?php else: ?>
        <section class="bulk-import-upload-panel">
            <p class="bulk-import-eyebrow">Step 1 of 2 · Upload spreadsheet</p>
            <h2>Bulk upload registrations</h2>
            <p>Upload a modern Excel <code>.xlsx</code> file. Its first populated row is used as the Excel column headers for the mapping step. CSV files are also supported.</p>
            <p class="small">Files can contain up to <?php echo REGISTRATION_BULK_UPLOAD_MAX_ROWS; ?> data rows and must be 10 MB or smaller.</p>

            <?php if (!$events): ?>
                <div class="alert error">Create an event before uploading registration data.</div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="bulk-import-upload-form">
                <input type="hidden" name="csrf_token" value="<?php echo bulkRegistrationUploadEscape($csrfToken); ?>">
                <input type="hidden" name="action" value="read_excel">
                <label for="bulk_import_event_id">
                    <span>Event</span>
                    <select id="bulk_import_event_id" name="event_id" required>
                        <option value="">Select event</option>
                        <?php foreach ($events as $event): ?>
                            <option value="<?php echo (int) $event['event_id']; ?>" <?php echo $selectedEventId === (int) $event['event_id'] ? 'selected' : ''; ?>>
                                <?php echo bulkRegistrationUploadEscape((string) $event['event_title'] . ' (ID: ' . (int) $event['event_id'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label for="registration_file">
                    <span>Excel or CSV file</span>
                    <input id="registration_file" name="registration_file" type="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                </label>
                <button type="submit" <?php echo !$events ? 'disabled' : ''; ?>>Read file and match columns</button>
            </form>
        </section>
    <?php endif; ?>
</div>

<?php if (is_array($workbook) && !is_array($result)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('bulk-import-mapping-form');
    if (!form) return;

    function updateFixedValue(row) {
        var header = row.querySelector('.bulk-import-header');
        var input = row.querySelector('.bulk-import-default');
        if (!header || !input) return;
        input.readOnly = header.value !== '';
        input.setAttribute('aria-disabled', header.value !== '' ? 'true' : 'false');
        input.classList.toggle('is-readonly', header.value !== '');
    }

    form.querySelectorAll('.bulk-import-mapping-table tbody tr').forEach(function (row) {
        updateFixedValue(row);
        var header = row.querySelector('.bulk-import-header');
        if (header) header.addEventListener('change', function () { updateFixedValue(row); });
    });

    form.addEventListener('submit', function (event) {
        if (event.submitter && event.submitter.name === 'start_over') return;
        var submit = document.getElementById('bulk-import-submit');
        var progress = document.getElementById('bulk-import-progress');
        if (submit) submit.disabled = true;
        if (progress) progress.textContent = 'Uploading registration records. The row-by-row results will appear when the import is complete.';
    });
});
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();

renderAdminLayout('Bulk Upload Registrations', $content, [
    'current_path' => 'registration_bulk_upload',
    'page_heading' => 'Bulk Upload Registrations',
    'body_class' => 'bulk-registration-upload-page',
]);
