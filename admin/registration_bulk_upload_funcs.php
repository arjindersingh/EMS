<?php

declare(strict_types=1);

const REGISTRATION_BULK_UPLOAD_MAX_FILE_BYTES = 10 * 1024 * 1024;
const REGISTRATION_BULK_UPLOAD_MAX_ROWS = 2000;
const REGISTRATION_BULK_UPLOAD_MAX_PHYSICAL_ROWS = 10000;
const REGISTRATION_BULK_UPLOAD_MAX_COLUMNS = 50;
const REGISTRATION_BULK_UPLOAD_MAX_ZIP_ENTRIES = 250;
const REGISTRATION_BULK_UPLOAD_MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;
const REGISTRATION_BULK_UPLOAD_TEMPORARY_TTL = 3600;

function registrationBulkUploadFields(): array
{
    return [
        'salutation' => 'Salutation',
        'name' => 'Name',
        'designation' => 'Designation',
        'teacher_post' => 'Post',
        'role' => 'Role',
        'institution_name' => 'Institution Name',
        'affiliation_number' => 'Affiliation Number',
        'institution_level' => 'Institution Level',
        'experience' => 'Experience',
        'mobile' => 'Mobile',
        'whatsapp_number' => 'WhatsApp Number',
        'official_email' => 'Official Email',
        'institution_address' => 'Institution Address',
        'city' => 'City',
        'district' => 'District',
        'state' => 'State',
        'country' => 'Country',
        'declaration_accepted' => 'Declaration Accepted',
    ];
}

function registrationBulkUploadRequiredFields(): array
{
    return ['name', 'mobile', 'official_email'];
}

function ensureRegistrationBulkUploadStorage(PDO $pdo): void
{
    ensureRegistrationApprovalStorage($pdo);

    $countryColumn = $pdo->query("SHOW COLUMNS FROM event_registrations LIKE 'country'");
    if ($countryColumn !== false && $countryColumn->fetch() === false) {
        $pdo->exec('ALTER TABLE event_registrations ADD COLUMN country VARCHAR(100) NULL AFTER state');
    }
}

function registrationBulkUploadErrorForUploadCode(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The selected file exceeds the allowed upload size.',
        UPLOAD_ERR_PARTIAL => 'The selected file was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Choose an Excel file before continuing.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload directory configured.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file temporarily.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        default => 'The Excel file upload failed.',
    };
}

function registrationBulkUploadReadUploadedFile(array $upload): array
{
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(registrationBulkUploadErrorForUploadCode($error));
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    $originalName = trim((string) ($upload['name'] ?? ''));
    $size = (int) ($upload['size'] ?? 0);
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || $size <= 0) {
        throw new InvalidArgumentException('The uploaded file could not be verified.');
    }
    if ($size > REGISTRATION_BULK_UPLOAD_MAX_FILE_BYTES) {
        throw new InvalidArgumentException('The Excel file must be 10 MB or smaller.');
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'csv'], true)) {
        throw new InvalidArgumentException('Upload an Excel .xlsx file or a CSV file.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $allowedMimeTypes = $extension === 'xlsx'
        ? [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ]
        : [
            'text/csv',
            'text/plain',
            'application/csv',
            'application/vnd.ms-excel',
            'application/octet-stream',
        ];
    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        throw new InvalidArgumentException('The selected file type does not match its extension.');
    }

    $workbook = $extension === 'xlsx'
        ? registrationBulkUploadParseXlsx($temporaryPath)
        : registrationBulkUploadParseCsv($temporaryPath);
    $workbook['file_name'] = $originalName !== '' ? $originalName : 'registration-import.' . $extension;
    $workbook['file_type'] = $extension;

    return $workbook;
}

function registrationBulkUploadParseCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The CSV file could not be read.');
    }

    try {
        $sample = fgets($handle);
        if ($sample === false) {
            throw new InvalidArgumentException('The CSV file is empty.');
        }
        $delimiter = registrationBulkUploadDetectCsvDelimiter($sample);
        rewind($handle);

        $rows = [];
        $rowNumber = 0;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rowNumber++;
            $values = [];
            foreach ($row as $column => $value) {
                $values[(int) $column] = registrationBulkUploadCleanCellValue((string) $value);
            }
            $rows[] = ['source_row' => $rowNumber, 'values' => $values];
            if (count($rows) > REGISTRATION_BULK_UPLOAD_MAX_PHYSICAL_ROWS) {
                throw new InvalidArgumentException('The file has too many rows to read. Remove blank rows or split it into smaller files and try again.');
            }
        }
    } finally {
        fclose($handle);
    }

    return registrationBulkUploadPrepareWorkbook($rows);
}

function registrationBulkUploadDetectCsvDelimiter(string $sample): string
{
    $candidates = [',' => ',', ';' => ';', "\t" => "\t"];
    $bestDelimiter = ',';
    $highestCount = -1;
    foreach ($candidates as $candidate) {
        $count = substr_count($sample, $candidate);
        if ($count > $highestCount) {
            $highestCount = $count;
            $bestDelimiter = $candidate;
        }
    }
    return $bestDelimiter;
}

function registrationBulkUploadParseXlsx(string $path): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('This server needs the PHP ZIP extension enabled to read Excel .xlsx files.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new InvalidArgumentException('The selected .xlsx file could not be opened.');
    }

    try {
        if ($zip->numFiles > REGISTRATION_BULK_UPLOAD_MAX_ZIP_ENTRIES) {
            throw new InvalidArgumentException('The Excel file contains too many internal files.');
        }

        $uncompressedSize = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $uncompressedSize += (int) ($stat['size'] ?? 0);
            if ($uncompressedSize > REGISTRATION_BULK_UPLOAD_MAX_UNCOMPRESSED_BYTES) {
                throw new InvalidArgumentException('The expanded Excel file is too large to import.');
            }
        }

        $sharedStrings = registrationBulkUploadReadSharedStrings($zip);
        $worksheetPath = registrationBulkUploadFindFirstWorksheetPath($zip);
        $sheet = registrationBulkUploadReadXml(
            registrationBulkUploadReadZipEntry($zip, $worksheetPath),
            'worksheet'
        );

        $spreadsheetNamespace = registrationBulkUploadXmlDefaultNamespace(
            $sheet,
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
        );
        $sheet->registerXPathNamespace('sheet', $spreadsheetNamespace);
        $rowNodes = $sheet->xpath('/sheet:worksheet/sheet:sheetData/sheet:row') ?: [];
        if (!$rowNodes) {
            throw new InvalidArgumentException('The first worksheet does not contain any rows.');
        }

        $rows = [];
        $fallbackRowNumber = 1;
        foreach ($rowNodes as $row) {
            $rowAttributes = $row->attributes();
            $sourceRow = (int) ($rowAttributes['r'] ?? 0);
            if ($sourceRow <= 0) {
                $sourceRow = $fallbackRowNumber;
            }
            $fallbackRowNumber = $sourceRow + 1;

            $values = [];
            $fallbackColumn = 0;
            foreach ($row->children($spreadsheetNamespace)->c as $cell) {
                $cellAttributes = $cell->attributes();
                $reference = (string) ($cellAttributes['r'] ?? '');
                $column = registrationBulkUploadColumnIndex($reference);
                if ($column === null) {
                    $column = $fallbackColumn;
                }
                $fallbackColumn = $column + 1;
                if ($column >= REGISTRATION_BULK_UPLOAD_MAX_COLUMNS) {
                    throw new InvalidArgumentException('The Excel file has more than ' . REGISTRATION_BULK_UPLOAD_MAX_COLUMNS . ' columns.');
                }
                $values[$column] = registrationBulkUploadReadXlsxCell($cell, $sharedStrings, $spreadsheetNamespace);
            }

            $rows[] = ['source_row' => $sourceRow, 'values' => $values];
            if (count($rows) > REGISTRATION_BULK_UPLOAD_MAX_PHYSICAL_ROWS) {
                throw new InvalidArgumentException('The file has too many rows to read. Remove blank rows or split it into smaller files and try again.');
            }
        }
    } finally {
        $zip->close();
    }

    return registrationBulkUploadPrepareWorkbook($rows);
}

function registrationBulkUploadReadSharedStrings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }

    $sharedStrings = registrationBulkUploadReadXml($xml, 'shared strings');
    $namespace = registrationBulkUploadXmlDefaultNamespace(
        $sharedStrings,
        'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
    );
    $sharedStrings->registerXPathNamespace('sheet', $namespace);
    $items = $sharedStrings->xpath('/sheet:sst/sheet:si') ?: [];
    $values = [];
    foreach ($items as $item) {
        $values[] = registrationBulkUploadReadXlsxText($item);
    }
    return $values;
}

function registrationBulkUploadFindFirstWorksheetPath(ZipArchive $zip): string
{
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml !== false && $relationshipsXml !== false) {
        $workbook = registrationBulkUploadReadXml($workbookXml, 'workbook');
        $spreadsheetNamespace = registrationBulkUploadXmlDefaultNamespace(
            $workbook,
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
        );
        $workbookNamespaces = $workbook->getDocNamespaces(true);
        $relationshipNamespace = (string) ($workbookNamespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $workbook->registerXPathNamespace('sheet', $spreadsheetNamespace);
        $sheets = $workbook->xpath('/sheet:workbook/sheet:sheets/sheet:sheet') ?: [];

        if ($sheets) {
            $relationshipAttributes = $sheets[0]->attributes($relationshipNamespace);
            $relationshipId = (string) ($relationshipAttributes['id'] ?? '');
            if ($relationshipId !== '') {
                $relationships = registrationBulkUploadReadXml($relationshipsXml, 'workbook relationships');
                $packageRelationshipNamespace = registrationBulkUploadXmlDefaultNamespace(
                    $relationships,
                    'http://schemas.openxmlformats.org/package/2006/relationships'
                );
                foreach ($relationships->children($packageRelationshipNamespace)->Relationship as $relationship) {
                    $relationshipAttributes = $relationship->attributes();
                    if ((string) ($relationshipAttributes['Id'] ?? '') !== $relationshipId) {
                        continue;
                    }
                    $target = str_replace('\\', '/', (string) ($relationshipAttributes['Target'] ?? ''));
                    if ($target !== '' && !str_contains($target, '..')) {
                        return str_starts_with($target, '/')
                            ? ltrim($target, '/')
                            : 'xl/' . ltrim($target, '/');
                    }
                }
            }
        }
    }

    $worksheets = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        $name = (string) ($stat['name'] ?? '');
        if (preg_match('#^xl/worksheets/[^/]+\\.xml$#i', $name)) {
            $worksheets[] = $name;
        }
    }
    sort($worksheets, SORT_NATURAL | SORT_FLAG_CASE);
    if (!$worksheets) {
        throw new InvalidArgumentException('No worksheet could be found in the Excel file.');
    }
    return $worksheets[0];
}

function registrationBulkUploadReadZipEntry(ZipArchive $zip, string $path): string
{
    $contents = $zip->getFromName($path);
    if ($contents === false) {
        throw new InvalidArgumentException('The Excel file is missing a required worksheet.');
    }
    return $contents;
}

function registrationBulkUploadReadXml(string $xml, string $label): SimpleXMLElement
{
    if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        throw new InvalidArgumentException('The Excel ' . $label . ' is not valid.');
    }

    $previous = libxml_use_internal_errors(true);
    try {
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        if ($document === false) {
            throw new InvalidArgumentException('The Excel ' . $label . ' could not be read.');
        }
        return $document;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function registrationBulkUploadXmlDefaultNamespace(SimpleXMLElement $document, string $fallback): string
{
    $namespaces = $document->getDocNamespaces(true);
    $namespace = (string) ($namespaces[''] ?? '');
    return $namespace !== '' ? $namespace : $fallback;
}

function registrationBulkUploadReadXlsxCell(SimpleXMLElement $cell, array $sharedStrings, string $namespace): string
{
    $children = $cell->children($namespace);
    $attributes = $cell->attributes();
    $type = (string) ($attributes['t'] ?? '');
    if ($type === 'inlineStr') {
        return registrationBulkUploadReadXlsxText($children->{'is'});
    }

    $value = isset($children->v) ? (string) $children->v : '';
    if ($value === '' && isset($children->f) && trim((string) $children->f) !== '') {
        throw new InvalidArgumentException('The Excel file contains a formula without a saved result. Open it in Excel, recalculate it, save it, and try again.');
    }
    if ($type === 's') {
        $index = ctype_digit($value) ? (int) $value : -1;
        return $sharedStrings[$index] ?? '';
    }

    return registrationBulkUploadCleanCellValue($value);
}

function registrationBulkUploadReadXlsxText(SimpleXMLElement $node): string
{
    $textNodes = $node->xpath('.//*[local-name() = "t"]') ?: [];
    $value = '';
    foreach ($textNodes as $textNode) {
        $value .= (string) $textNode;
    }
    return registrationBulkUploadCleanCellValue($value);
}

function registrationBulkUploadColumnIndex(string $reference): ?int
{
    if (!preg_match('/^([A-Z]+)[0-9]+$/i', $reference, $matches)) {
        return null;
    }

    $index = 0;
    foreach (str_split(strtoupper($matches[1])) as $letter) {
        $index = ($index * 26) + (ord($letter) - 64);
    }
    return $index - 1;
}

function registrationBulkUploadPrepareWorkbook(array $rawRows): array
{
    $headerRowIndex = null;
    foreach ($rawRows as $index => $row) {
        if (registrationBulkUploadRowHasValue((array) ($row['values'] ?? []))) {
            $headerRowIndex = $index;
            break;
        }
    }
    if ($headerRowIndex === null) {
        throw new InvalidArgumentException('The file does not contain a header row.');
    }

    $headerValues = (array) ($rawRows[$headerRowIndex]['values'] ?? []);
    $lastColumn = $headerValues ? max(array_map('intval', array_keys($headerValues))) : -1;
    if ($lastColumn < 0) {
        throw new InvalidArgumentException('The header row is empty.');
    }
    if ($lastColumn + 1 > REGISTRATION_BULK_UPLOAD_MAX_COLUMNS) {
        throw new InvalidArgumentException('The file has more than ' . REGISTRATION_BULK_UPLOAD_MAX_COLUMNS . ' columns.');
    }

    $headers = [];
    $hasNamedHeader = false;
    for ($column = 0; $column <= $lastColumn; $column++) {
        $header = registrationBulkUploadRemoveBom(registrationBulkUploadCleanCellValue((string) ($headerValues[$column] ?? '')));
        if ($header !== '') {
            $hasNamedHeader = true;
        } else {
            $header = 'Column ' . registrationBulkUploadColumnReference($column);
        }
        $headers[] = $header;
    }
    if (!$hasNamedHeader) {
        throw new InvalidArgumentException('The first populated row must contain Excel column headers.');
    }

    $rows = [];
    for ($index = $headerRowIndex + 1, $length = count($rawRows); $index < $length; $index++) {
        $rawValues = (array) ($rawRows[$index]['values'] ?? []);
        $values = array_fill(0, count($headers), '');
        foreach ($rawValues as $column => $value) {
            $column = (int) $column;
            if ($column >= 0 && $column < count($headers)) {
                $values[$column] = registrationBulkUploadCleanCellValue((string) $value);
            }
        }
        if (!registrationBulkUploadRowHasValue($values)) {
            continue;
        }
        $rows[] = [
            'source_row' => (int) ($rawRows[$index]['source_row'] ?? ($index + 1)),
            'values' => $values,
        ];
        if (count($rows) > REGISTRATION_BULK_UPLOAD_MAX_ROWS) {
            throw new InvalidArgumentException('The file has more than ' . REGISTRATION_BULK_UPLOAD_MAX_ROWS . ' data rows. Split it into smaller files and try again.');
        }
    }

    if (!$rows) {
        throw new InvalidArgumentException('No data records were found below the header row.');
    }

    return ['headers' => $headers, 'rows' => $rows];
}

function registrationBulkUploadRowHasValue(array $values): bool
{
    foreach ($values as $value) {
        if (trim((string) $value) !== '') {
            return true;
        }
    }
    return false;
}

function registrationBulkUploadCleanCellValue(string $value): string
{
    return str_replace("\0", '', trim($value));
}

function registrationBulkUploadRemoveBom(string $value): string
{
    return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
}

function registrationBulkUploadColumnReference(int $index): string
{
    $reference = '';
    $number = $index + 1;
    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $reference = chr(65 + $remainder) . $reference;
        $number = intdiv($number - 1, 26);
    }
    return $reference;
}

function registrationBulkUploadSuggestedMappings(array $headers): array
{
    $rows = [];
    foreach (registrationBulkUploadFields() as $field => $label) {
        $rows[] = [
            'field' => $field,
            'header' => registrationBulkUploadSuggestedHeader($field, $headers),
            'default' => $field === 'declaration_accepted' ? '1' : '',
        ];
    }
    return $rows;
}

function registrationBulkUploadSuggestedHeader(string $field, array $headers): string
{
    $aliases = [
        'salutation' => ['title'],
        'name' => ['fullname', 'participantname', 'candidatename'],
        'teacher_post' => ['post', 'teacherpost'],
        'institution_name' => ['institution', 'schoolname', 'organisation', 'organization'],
        'affiliation_number' => ['affiliationno', 'affiliationnumber'],
        'institution_level' => ['institutiontype', 'schoollevel'],
        'experience' => ['experienceyears', 'yearsofexperience'],
        'mobile' => ['mobilenumber', 'phone', 'phonenumber', 'contactnumber'],
        'whatsapp_number' => ['whatsapp', 'whatsappno', 'whatsappnumber'],
        'official_email' => ['email', 'emailid', 'emailaddress', 'officialemail'],
        'institution_address' => ['address', 'schooladdress', 'institutionaddress'],
        'declaration_accepted' => ['declaration', 'accepted'],
    ];
    $expected = array_merge([registrationBulkUploadNormalizeHeader($field)], $aliases[$field] ?? []);
    foreach ($headers as $index => $header) {
        if (in_array(registrationBulkUploadNormalizeHeader((string) $header), $expected, true)) {
            return 'column-' . $index;
        }
    }
    return '';
}

function registrationBulkUploadNormalizeHeader(string $value): string
{
    $normalized = strtolower(trim($value));
    return (string) preg_replace('/[^a-z0-9]+/', '', $normalized);
}

function registrationBulkUploadMappingRows(array $headers, ?array $submittedValues = null): array
{
    $rows = registrationBulkUploadSuggestedMappings($headers);
    if ($submittedValues === null) {
        return $rows;
    }

    $fields = is_array($submittedValues['mapping_field'] ?? null) ? $submittedValues['mapping_field'] : [];
    $headerChoices = is_array($submittedValues['mapping_header'] ?? null) ? $submittedValues['mapping_header'] : [];
    $defaults = is_array($submittedValues['mapping_default'] ?? null) ? $submittedValues['mapping_default'] : [];
    $allowedFields = registrationBulkUploadFields();

    foreach ($rows as $index => &$row) {
        $field = (string) ($fields[$index] ?? '');
        $row['field'] = isset($allowedFields[$field]) ? $field : '';

        $headerChoice = (string) ($headerChoices[$index] ?? '');
        if (preg_match('/^column-([0-9]+)$/', $headerChoice, $matches) && isset($headers[(int) $matches[1]])) {
            $row['header'] = $headerChoice;
        } else {
            $row['header'] = '';
        }
        $row['default'] = (string) ($defaults[$index] ?? '');
    }
    unset($row);

    return $rows;
}

function registrationBulkUploadBuildMappings(array $submittedValues, array $headers): array
{
    $fields = is_array($submittedValues['mapping_field'] ?? null) ? $submittedValues['mapping_field'] : [];
    $headerChoices = is_array($submittedValues['mapping_header'] ?? null) ? $submittedValues['mapping_header'] : [];
    $defaults = is_array($submittedValues['mapping_default'] ?? null) ? $submittedValues['mapping_default'] : [];
    if (count($fields) > count(registrationBulkUploadFields()) + 10) {
        throw new InvalidArgumentException('Too many field mappings were submitted.');
    }

    $availableFields = registrationBulkUploadFields();
    $mappings = [];
    $usedHeaders = [];
    foreach ($fields as $index => $field) {
        $field = trim((string) $field);
        if ($field === '') {
            continue;
        }
        if (!isset($availableFields[$field])) {
            throw new InvalidArgumentException('One of the selected registration fields is not valid.');
        }
        if (isset($mappings[$field])) {
            throw new InvalidArgumentException('Each registration field can only be mapped once.');
        }

        $headerChoice = trim((string) ($headerChoices[$index] ?? ''));
        $default = (string) ($defaults[$index] ?? '');
        if ($headerChoice !== '') {
            if (!preg_match('/^column-([0-9]+)$/', $headerChoice, $matches) || !isset($headers[(int) $matches[1]])) {
                throw new InvalidArgumentException('One of the selected Excel columns is no longer available.');
            }
            $headerIndex = (int) $matches[1];
            if (isset($usedHeaders[$headerIndex])) {
                throw new InvalidArgumentException('Each Excel column can only be mapped to one registration field.');
            }
            $usedHeaders[$headerIndex] = true;
            $mappings[$field] = ['header_index' => $headerIndex, 'default' => null];
            continue;
        }

        if (trim($default) !== '') {
            $mappings[$field] = ['header_index' => null, 'default' => $default];
        }
    }

    if (!$mappings) {
        throw new InvalidArgumentException('Map at least one registration field before importing.');
    }
    foreach (registrationBulkUploadRequiredFields() as $requiredField) {
        if (!isset($mappings[$requiredField])) {
            throw new InvalidArgumentException('Map the required "' . registrationBulkUploadFields()[$requiredField] . '" field before importing.');
        }
    }

    return $mappings;
}

function registrationBulkUploadMapRecord(array $row, array $mappings): array
{
    $values = (array) ($row['values'] ?? []);
    $record = [];
    foreach ($mappings as $field => $mapping) {
        $headerIndex = $mapping['header_index'];
        $record[$field] = $headerIndex === null
            ? (string) $mapping['default']
            : (string) ($values[$headerIndex] ?? '');
    }
    return $record;
}

function registrationBulkUploadImport(PDO $pdo, array $workbook, array $mappings, array $events, int $eventId): array
{
    $validEventIds = [];
    $eventTitles = [];
    foreach ($events as $event) {
        $currentEventId = (int) ($event['event_id'] ?? 0);
        $validEventIds[$currentEventId] = true;
        $eventTitles[$currentEventId] = trim((string) ($event['event_title'] ?? ''));
    }
    if ($eventId <= 0 || !isset($validEventIds[$eventId])) {
        throw new InvalidArgumentException('Select a valid event before importing registrations.');
    }

    $results = [];
    $summary = ['total' => 0, 'uploaded' => 0, 'failed' => 0, 'skipped' => 0];
    foreach ((array) ($workbook['rows'] ?? []) as $row) {
        $summary['total']++;
        $sourceRow = (int) ($row['source_row'] ?? 0);
        if (!registrationBulkUploadRowHasValue((array) ($row['values'] ?? []))) {
            $summary['skipped']++;
            $results[] = [
                'source_row' => $sourceRow,
                'name' => '',
                'status' => 'Skipped',
                'detail' => 'The row is blank.',
            ];
            continue;
        }

        $record = registrationBulkUploadMapRecord($row, $mappings);
        $record['event_id'] = (string) $eventId;
        $name = trim((string) ($record['name'] ?? ''));
        try {
            $pdo->beginTransaction();
            $registrationId = saveAdministrativeRegistration($pdo, $record);
            $pdo->commit();
            $summary['uploaded']++;
            $results[] = [
                'source_row' => $sourceRow,
                'name' => $name,
                'status' => 'Uploaded',
                'detail' => 'Registration ID: ' . $registrationId,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $summary['failed']++;
            $results[] = [
                'source_row' => $sourceRow,
                'name' => $name,
                'status' => 'Failed',
                'detail' => registrationBulkUploadRecordError($exception),
            ];
        }
    }

    return [
        'file_name' => (string) ($workbook['file_name'] ?? 'registration import'),
        'event_id' => $eventId,
        'event_title' => $eventTitles[$eventId] ?? '',
        'summary' => $summary,
        'results' => $results,
    ];
}

function registrationBulkUploadRecordError(Throwable $exception): string
{
    if ($exception instanceof InvalidArgumentException) {
        return $exception->getMessage();
    }
    return 'Could not save this record. Check the mapped values and try again.';
}

function registrationBulkUploadTemporaryDirectory(): string
{
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ems-registration-bulk-upload';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The server could not prepare temporary import storage.');
    }
    return $directory;
}

function registrationBulkUploadTemporarySessionKey(): string
{
    return 'registration_bulk_upload_temporary_files';
}

function registrationBulkUploadPruneTemporaryFiles(): void
{
    registrationBulkUploadPruneExpiredTemporaryPayloads();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $key = registrationBulkUploadTemporarySessionKey();
    $files = $_SESSION[$key] ?? [];
    if (!is_array($files)) {
        $_SESSION[$key] = [];
        return;
    }

    foreach ($files as $token => $metadata) {
        if (!is_array($metadata) || (int) ($metadata['expires_at'] ?? 0) < time()) {
            registrationBulkUploadDiscardTemporaryFile((string) $token);
        }
    }
}

function registrationBulkUploadPruneExpiredTemporaryPayloads(): void
{
    $directory = registrationBulkUploadTemporaryDirectory();
    $cutoff = time() - REGISTRATION_BULK_UPLOAD_TEMPORARY_TTL;
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $fileName = basename($path);
        $modifiedAt = filemtime($path);
        if (
            preg_match('/^(workbook|result)-[a-f0-9]{48}\\.json$/', $fileName) === 1
            && $modifiedAt !== false
            && $modifiedAt <= $cutoff
        ) {
            registrationBulkUploadDeleteTemporaryPath($path);
        }
    }
}

function registrationBulkUploadStoreTemporaryPayload(string $kind, array $payload): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Your session is not available for this upload.');
    }

    registrationBulkUploadPruneTemporaryFiles();
    $token = bin2hex(random_bytes(24));
    $path = registrationBulkUploadTemporaryDirectory() . DIRECTORY_SEPARATOR . $kind . '-' . $token . '.json';
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('The uploaded spreadsheet could not be saved temporarily.');
    }

    $key = registrationBulkUploadTemporarySessionKey();
    $_SESSION[$key][$token] = [
        'path' => $path,
        'kind' => $kind,
        'expires_at' => time() + REGISTRATION_BULK_UPLOAD_TEMPORARY_TTL,
    ];
    return $token;
}

function registrationBulkUploadLoadTemporaryPayload(string $token, string $expectedKind): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token) || session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    registrationBulkUploadPruneTemporaryFiles();
    $metadata = $_SESSION[registrationBulkUploadTemporarySessionKey()][$token] ?? null;
    if (!is_array($metadata) || ($metadata['kind'] ?? '') !== $expectedKind) {
        return null;
    }

    $path = (string) ($metadata['path'] ?? '');
    $contents = is_file($path) ? file_get_contents($path) : false;
    if ($contents === false) {
        registrationBulkUploadDiscardTemporaryFile($token);
        return null;
    }

    try {
        $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        registrationBulkUploadDiscardTemporaryFile($token);
        return null;
    }

    return is_array($payload) ? $payload : null;
}

function registrationBulkUploadDiscardTemporaryFile(string $token): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $key = registrationBulkUploadTemporarySessionKey();
    $metadata = $_SESSION[$key][$token] ?? null;
    if (is_array($metadata)) {
        registrationBulkUploadDeleteTemporaryPath((string) ($metadata['path'] ?? ''));
    }
    unset($_SESSION[$key][$token]);
}

function registrationBulkUploadDiscardAllTemporaryFiles(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $key = registrationBulkUploadTemporarySessionKey();
    foreach ((array) ($_SESSION[$key] ?? []) as $token => $_metadata) {
        registrationBulkUploadDiscardTemporaryFile((string) $token);
    }
}

function registrationBulkUploadDeleteTemporaryPath(string $path): void
{
    if ($path === '' || !is_file($path)) {
        return;
    }

    $directory = realpath(registrationBulkUploadTemporaryDirectory());
    $file = realpath($path);
    if ($directory === false || $file === false || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR)) {
        return;
    }
    unlink($file);
}
