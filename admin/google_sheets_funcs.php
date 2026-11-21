<?php

/**
 * Google Sheets Registration Data Fetcher
 * Handles integration with Google Sheets for importing registration data
 */

require_once __DIR__ . '/../config/database.php';

// ============================================================================
// Google Sheets URL Management
// ============================================================================

function saveGoogleSheetURL(PDO $pdo, string $sheetURL, int $eventId): bool
{
    // Ensure settings table exists
    ensureSettingsTable($pdo);
    
    $settingName = 'google_sheet_url_' . $eventId;
    
    // Check if setting already exists
    $stmt = $pdo->prepare('SELECT setting_id FROM settings WHERE setting_name = :name LIMIT 1');
    $stmt->execute([':name' => $settingName]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        // Update existing
        $stmt = $pdo->prepare(<<<'SQL'
            UPDATE settings 
            SET setting_value = :value, updated_at = NOW()
            WHERE setting_name = :name
SQL
        );
    } else {
        // Insert new
        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO settings (setting_name, setting_type, setting_value)
            VALUES (:name, :type, :value)
SQL
        );
        $stmt->bindValue(':type', 'google_sheet_url');
    }
    
    $stmt->bindValue(':name', $settingName);
    $stmt->bindValue(':value', $sheetURL);
    
    return $stmt->execute();
}

function getGoogleSheetURL(PDO $pdo, int $eventId): ?string
{
    ensureSettingsTable($pdo);
    
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_name = :name LIMIT 1');
    $stmt->execute([':name' => 'google_sheet_url_' . $eventId]);
    $result = $stmt->fetch();
    
    return $result ? $result['setting_value'] : null;
}

function deleteGoogleSheetURL(PDO $pdo, int $eventId): bool
{
    $stmt = $pdo->prepare('DELETE FROM settings WHERE setting_name = :name');
    return $stmt->execute([':name' => 'google_sheet_url_' . $eventId]);
}

function getGoogleSheetGid(string $sheetURL): int
{
    return preg_match('/(?:[#&?])gid=(\d+)/', $sheetURL, $matches)
        ? (int) $matches[1]
        : 0;
}

function setGoogleSheetGid(string $sheetURL, int $sheetGid): string
{
    $sheetURL = preg_replace('/#.*$/', '', $sheetURL) ?? $sheetURL;
    $sheetURL = preg_replace('/([?&])gid=\d+(&?)/', '$1', $sheetURL) ?? $sheetURL;
    $sheetURL = preg_replace('/[?&]+$/', '', $sheetURL) ?? $sheetURL;

    return $sheetURL . '#gid=' . $sheetGid;
}

function getGoogleSheetTabs(string $sheetURL): array
{
    if (!preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $sheetURL, $matches)) {
        return [];
    }

    $spreadsheetId = $matches[1];
    
    // Try multiple methods to get sheet tabs
    // Method 1: Try modern HTML parsing
    $tabs = getGoogleSheetTabsFromEditPageModern($spreadsheetId);
    if (!empty($tabs)) {
        return $tabs;
    }
    
    // Method 2: Fallback to discovery by probing CSV exports (tries GIDs 0-5)
    $tabs = discoverGoogleSheetTabsByCSVProbing($spreadsheetId);
    if (!empty($tabs)) {
        return $tabs;
    }
    
    // Method 3: Return at least the default sheet (gid=0)
    // This allows the system to work even if tab discovery fails
    return [['gid' => 0, 'title' => 'Default Worksheet (Sheet1)']];
}

function getGoogleSheetTabsFromEditPageModern(string $spreadsheetId): array
{
    $editUrl = 'https://docs.google.com/spreadsheets/d/' . $spreadsheetId . '/edit?usp=sharing';
    $html = false;
    
    // Try curl first with SSL verification disabled for Windows environments
    if (function_exists('curl_init')) {
        $curl = curl_init($editUrl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);
        $response = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (is_string($response) && $statusCode >= 200 && $statusCode < 300) {
            $html = $response;
        }
    }

    // Fallback to file_get_contents
    if ($html === false && filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
            'http' => [
                'timeout' => 15,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
        ]);
        $html = @file_get_contents($editUrl, false, $context);
    }

    if (!is_string($html) || $html === '') {
        return [];
    }

    // Try multiple parsing strategies
    $tabs = parseGoogleSheetTabsHtmlModern($html);
    if (!empty($tabs)) {
        return $tabs;
    }
    
    return parseGoogleSheetTabsHtml($html);
}

function discoverGoogleSheetTabsByCSVProbing(string $spreadsheetId): array
{
    // Try to detect available sheets by probing CSV exports
    // Google Sheets uses sequential GIDs starting from 0
    // We'll try GIDs 0-10 to find accessible sheets
    
    $discoveredTabs = [];
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
        'http' => [
            'timeout' => 10,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ],
    ]);
    
    for ($gid = 0; $gid <= 10; $gid++) {
        $csvUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid={$gid}";
        $csvData = @file_get_contents($csvUrl, false, $context);
        
        // If we get CSV data, this GID is valid
        if ($csvData !== false && is_string($csvData) && strlen($csvData) > 0) {
            // Extract the first line as a sample to verify it's valid CSV
            $lines = explode("\n", trim($csvData));
            if (!empty($lines[0])) {
                // Successfully accessed this sheet
                // Try to extract a meaningful name from the content or use a generic one
                $discoveredTabs[$gid] = ['gid' => $gid, 'title' => 'Sheet ' . ($gid + 1)];
            }
        }
    }
    
    return array_values($discoveredTabs);
}

function parseGoogleSheetTabsHtml(string $html): array
{
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $patterns = [
        '/\{[^{}]*"sheetId"\s*:\s*(\d+)[^{}]*"(?:title|name)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"[^{}]*\}/s',
        '/\{[^{}]*"(?:title|name)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"[^{}]*"sheetId"\s*:\s*(\d+)[^{}]*\}/s',
    ];
    $tabsByGid = [];

    foreach ($patterns as $patternIndex => $pattern) {
        if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($matches as $match) {
            $gid = (int) ($patternIndex === 0 ? $match[1] : $match[2]);
            $encodedTitle = $patternIndex === 0 ? $match[2] : $match[1];
            $title = json_decode('"' . $encodedTitle . '"');
            if (is_string($title) && $title !== '') {
                $tabsByGid[$gid] = ['gid' => $gid, 'title' => $title];
            }
        }
    }

    return array_values($tabsByGid);
}

function parseGoogleSheetTabsHtmlModern(string $html): array
{
    // Modern Google Sheets stores sheet metadata in JavaScript objects
    // Look for patterns like: "properties":{"sheetId":0,"title":"Sheet1"...}
    
    $tabsByGid = [];
    
    // Pattern 1: Look for properties objects with sheetId and title
    if (preg_match_all('/"properties"\s*:\s*\{[^}]*"sheetId"\s*:\s*(\d+)[^}]*"title"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"[^}]*\}/', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $gid = (int) $match[1];
            $title = json_decode('"' . $match[2] . '"');
            if (is_string($title) && $title !== '' && !isset($tabsByGid[$gid])) {
                $tabsByGid[$gid] = ['gid' => $gid, 'title' => $title];
            }
        }
    }
    
    // Pattern 2: Alternative format with title before sheetId
    if (preg_match_all('/"properties"\s*:\s*\{[^}]*"title"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"[^}]*"sheetId"\s*:\s*(\d+)[^}]*\}/', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $gid = (int) $match[2];
            $title = json_decode('"' . $match[1] . '"');
            if (is_string($title) && $title !== '' && !isset($tabsByGid[$gid])) {
                $tabsByGid[$gid] = ['gid' => $gid, 'title' => $title];
            }
        }
    }
    
    // Pattern 3: Look for sheet objects in arrays
    if (empty($tabsByGid) && preg_match_all('/"sheetId"\s*:\s*(\d+)[^}]*"title"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $gid = (int) $match[1];
            $title = json_decode('"' . $match[2] . '"');
            if (is_string($title) && $title !== '' && !isset($tabsByGid[$gid])) {
                $tabsByGid[$gid] = ['gid' => $gid, 'title' => $title];
            }
        }
    }
    
    return array_values($tabsByGid);
}

function convertToCSVExportURL(string $sheetURL): ?string
{
    // Match Google Sheets URL pattern
    if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $sheetURL, $matches)) {
        $spreadsheetId = $matches[1];
        $sheetId = 0; // Default to first sheet
        
        // Extract sheet ID if present (gid parameter)
        if (preg_match('/[#&]gid=([0-9]+)/', $sheetURL, $gidMatches)) {
            $sheetId = $gidMatches[1];
        }
        
        return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid={$sheetId}";
    }
    
    return null;
}

// ============================================================================
// Fetch and Parse Google Sheet Data
// ============================================================================

function fetchGoogleSheetData(string $csvExportURL): ?array
{
    try {
        // Set stream context for SSL and timeout
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
            'http' => [
                'timeout' => 10,
            ],
        ]);
        
        $csvData = @file_get_contents($csvExportURL, false, $context);
        
        if ($csvData === false) {
            return null;
        }
        
        // Parse CSV
        $lines = explode("\n", trim($csvData));
        if (empty($lines)) {
            return null;
        }
        
        $headers = str_getcsv($lines[0], ',', '"', '\\');
        $data = [];
        
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }
            
            $values = str_getcsv($line, ',', '"', '\\');
            $row = [];
            
            foreach ($headers as $index => $header) {
                $row[trim($header)] = trim($values[$index] ?? '');
            }
            
            $data[] = $row;
        }
        
        return [
            'headers' => array_map('trim', $headers),
            'data' => $data,
        ];
    } catch (Exception $e) {
        error_log('Error fetching Google Sheet: ' . $e->getMessage());
        return null;
    }
}

// ============================================================================
// Field Mapping Management
// ============================================================================

function saveFieldMapping(PDO $pdo, int $eventId, array $fieldMapping, array $defaultValues = [], array $rowFields = []): bool
{
    ensureSettingsTable($pdo);
    
    $settingName = 'registration_field_mapping_' . $eventId;
    $mappingJSON = json_encode([
        'format_version' => 2,
        'field_mapping' => $fieldMapping,
        'default_values' => $defaultValues,
        'row_fields' => $rowFields,
    ]);
    if ($mappingJSON === false) {
        return false;
    }
    
    // Check if setting already exists
    $stmt = $pdo->prepare('SELECT setting_id FROM settings WHERE setting_name = :name LIMIT 1');
    $stmt->execute([':name' => $settingName]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        // Update existing
        $stmt = $pdo->prepare(<<<'SQL'
            UPDATE settings 
            SET setting_value = :value, updated_at = NOW()
            WHERE setting_name = :name
SQL
        );
    } else {
        // Insert new
        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO settings (setting_name, setting_type, setting_value)
            VALUES (:name, :type, :value)
SQL
        );
        $stmt->bindValue(':type', 'field_mapping');
    }
    
    $stmt->bindValue(':name', $settingName);
    $stmt->bindValue(':value', $mappingJSON);
    
    return $stmt->execute();
}

function getFieldMapping(PDO $pdo, int $eventId): array
{
    ensureSettingsTable($pdo);
    
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_name = :name LIMIT 1');
    $stmt->execute([':name' => 'registration_field_mapping_' . $eventId]);
    $result = $stmt->fetch();
    
    if (!$result) {
        return [];
    }
    
    $mapping = json_decode($result['setting_value'], true);
    if (!is_array($mapping)) {
        return [];
    }

    $fieldMapping = ($mapping['format_version'] ?? null) === 2
        && isset($mapping['field_mapping'])
        && is_array($mapping['field_mapping'])
        ? $mapping['field_mapping']
        : $mapping;
    unset($fieldMapping['registration_id'], $fieldMapping['event_id'], $fieldMapping['registration_date']);

    return $fieldMapping;
}

function getFieldDefaultValues(PDO $pdo, int $eventId): array
{
    ensureSettingsTable($pdo);

    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_name = :name LIMIT 1');
    $stmt->execute([':name' => 'registration_field_mapping_' . $eventId]);
    $result = $stmt->fetch();
    if (!$result) {
        return [];
    }

    $mapping = json_decode($result['setting_value'], true);
    $defaultValues = is_array($mapping)
        && ($mapping['format_version'] ?? null) === 2
        && isset($mapping['default_values'])
        && is_array($mapping['default_values'])
        ? $mapping['default_values']
        : [];
    unset($defaultValues['registration_id'], $defaultValues['event_id'], $defaultValues['registration_date']);

    return $defaultValues;
}

function getFieldMappingRows(PDO $pdo, int $eventId, array $registrationFields): array
{
    ensureSettingsTable($pdo);

    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_name = :name LIMIT 1');
    $stmt->execute([':name' => 'registration_field_mapping_' . $eventId]);
    $result = $stmt->fetch();
    if (!$result) {
        return $registrationFields;
    }

    $mapping = json_decode($result['setting_value'], true);
    $rowFields = is_array($mapping) && ($mapping['format_version'] ?? null) === 2
        ? ($mapping['row_fields'] ?? null)
        : null;
    if (!is_array($rowFields)) {
        return $registrationFields;
    }

    $validFields = array_flip($registrationFields);
    $rowFields = array_values(array_filter(
        $rowFields,
        static fn ($field): bool => is_string($field) && isset($validFields[$field])
    ));

    return count($rowFields) === count($registrationFields)
        && count(array_unique($rowFields)) === count($registrationFields)
        ? $rowFields
        : $registrationFields;
}

// ============================================================================
// Fetch Method Management (Manual, Automatic, Real-time)
// ============================================================================

function acquireRegistrationFetchLock(PDO $pdo, int $eventId, int $timeout = 0): bool
{
    $stmt = $pdo->prepare("SELECT GET_LOCK(CONCAT('ems_rf_', LEFT(SHA2(CONCAT(DATABASE(), ':', :event_id), 256), 48)), :timeout)");
    $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
    $stmt->bindValue(':timeout', $timeout, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetchColumn();
    if ($result === null || $result === false) {
        throw new RuntimeException('Unable to acquire the registration fetch lock.');
    }
    return (int) $result === 1;
}

function releaseRegistrationFetchLock(PDO $pdo, int $eventId): void
{
    $stmt = $pdo->prepare("SELECT RELEASE_LOCK(CONCAT('ems_rf_', LEFT(SHA2(CONCAT(DATABASE(), ':', :event_id), 256), 48)))");
    $stmt->execute([':event_id' => $eventId]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Unable to release the registration fetch lock.');
    }
}

function saveFetchMethod(PDO $pdo, int $eventId, string $method, bool $enabled, ?int $intervalMinutes = null): bool
{
    ensureSettingsTable($pdo);

    $locked = false;
    if ($method === 'automatic') {
        $locked = acquireRegistrationFetchLock($pdo, $eventId, 5);
        if (!$locked) {
            throw new RuntimeException('An import is running for this event. Please try saving the fetch settings again.');
        }
    }
    try {
        $existingData = $method === 'automatic' ? (getFetchMethod($pdo, $eventId, $method) ?? []) : [];
        $methodData = [
            'enabled' => $enabled,
            'interval_minutes' => $intervalMinutes,
            'last_fetch' => $method === 'automatic' ? ($existingData['last_fetch'] ?? null) : date('Y-m-d H:i:s'),
        ];
        if ($method === 'automatic') {
            $methodData = array_merge($existingData, $methodData);
            if ($enabled && empty($existingData['enabled'])) {
                unset($methodData['last_attempt'], $methodData['last_status'], $methodData['last_error'], $methodData['last_result']);
            }
        }

        $settingName = 'registration_fetch_' . $method . '_' . $eventId;
        $stmt = $pdo->prepare('SELECT setting_id FROM settings WHERE setting_name = :name LIMIT 1');
        $stmt->execute([':name' => $settingName]);
        $exists = $stmt->fetch();

        if ($exists) {
            $stmt = $pdo->prepare(<<<'SQL'
            UPDATE settings 
            SET setting_value = :value, updated_at = NOW()
            WHERE setting_name = :name
SQL
            );
        } else {
            $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO settings (setting_name, setting_type, setting_value)
            VALUES (:name, :type, :value)
SQL
            );
            $stmt->bindValue(':type', 'fetch_method');
        }

        $stmt->bindValue(':name', $settingName);
        $stmt->bindValue(':value', json_encode($methodData, JSON_THROW_ON_ERROR));
        return $stmt->execute();
    } finally {
        if ($locked) {
            releaseRegistrationFetchLock($pdo, $eventId);
        }
    }
}

function getFetchMethod(PDO $pdo, int $eventId, string $method): ?array
{
    ensureSettingsTable($pdo);
    
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_name = :name LIMIT 1');
    $stmt->execute([':name' => 'registration_fetch_' . $method . '_' . $eventId]);
    $result = $stmt->fetch();
    
    if (!$result) {
        return null;
    }
    
    $data = json_decode($result['setting_value'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid fetch method configuration for event ' . $eventId . '.');
    }
    return $data;
}

// ============================================================================
// Duplicate Check
// ============================================================================

function findDuplicateRegistrationId(PDO $pdo, int $eventId, array $rowData, array $fieldMapping, array $defaultValues = [], ?bool &$hasDuplicateKey = null): ?int
{
    $hasDuplicateKey = false;
    $tableName = getRegistrationTableName($pdo);
    if ($tableName === null) {
        throw new RuntimeException('Registration table was not found.');
    }

    $registrationColumns = getRegistrationTableColumns($pdo);
    $emailColumn = in_array('official_email', $registrationColumns, true) ? 'official_email' : 'email';
    $phoneColumn = in_array('mobile', $registrationColumns, true) ? 'mobile' : 'phone';

    foreach ([$emailColumn, $phoneColumn] as $targetField) {
        if (!in_array($targetField, $registrationColumns, true)) {
            continue;
        }

        $sheetField = (string) ($fieldMapping[$targetField] ?? '');
        $value = $sheetField !== '' ? ($rowData[$sheetField] ?? '') : '';
        $value = $value !== '' ? $value : ($defaultValues[$targetField] ?? '');
        if (!is_scalar($value) || trim((string) $value) === '') {
            continue;
        }

        $hasDuplicateKey = true;
        $stmt = $pdo->prepare(
            'SELECT registration_id FROM `' . $tableName . '` WHERE event_id = :event_id AND `' . $targetField . '` = :value LIMIT 1'
        );
        $stmt->execute([
            ':event_id' => $eventId,
            ':value' => $value,
        ]);
        $registrationId = $stmt->fetchColumn();
        if ($registrationId !== false) {
            return (int) $registrationId;
        }
    }

    return null;
}

// ============================================================================
// Insert Registration Data
// ============================================================================

function insertRegistrationFromMapping(PDO $pdo, int $eventId, array $rowData, array $fieldMapping, array $defaultValues = [], ?string &$error = null, ?int &$registrationId = null): bool
{
    try {
        $tableName = getRegistrationTableName($pdo);
        if ($tableName === null) {
            throw new RuntimeException('Registration table was not found.');
        }
        $registrationColumns = getRegistrationTableColumns($pdo);
        $registrationFields = getRegistrationTableFields($pdo);

        // Prepare data for insertion
        $insertData = [];
        if (in_array('event_id', $registrationColumns, true)) {
            $insertData['event_id'] = $eventId;
        }
        if (in_array('registration_date', $registrationColumns, true)) {
            $insertData['registration_date'] = date('Y-m-d H:i:s');
        }
        if (in_array('pass_code', $registrationColumns, true)) {
            $insertData['pass_code'] = null;
        }
        
        // Map row data to table columns
        foreach ($fieldMapping as $dbField => $sheetField) {
            if (!in_array($dbField, $registrationFields, true)) {
                continue;
            }
            if (empty($sheetField)) {
                // Use default value if mapping is empty
                if (isset($defaultValues[$dbField])) {
                    $insertData[$dbField] = $defaultValues[$dbField];
                }
            } else {
                // Use value from sheet
                $sheetValue = $rowData[$sheetField] ?? '';
                $insertData[$dbField] = $sheetValue !== '' ? $sheetValue : ($defaultValues[$dbField] ?? '');
            }
        }
        
        // Build dynamic INSERT query
        $columns = implode(', ', array_keys($insertData));
        $placeholders = implode(', ', array_fill(0, count($insertData), '?'));
        
        if ($insertData === []) {
            throw new RuntimeException('No registration fields are available to insert.');
        }

        $quotedColumns = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', array_keys($insertData)));
        $stmt = $pdo->prepare("INSERT INTO `{$tableName}` ({$quotedColumns}) VALUES ($placeholders)");
        
        if (!$stmt->execute(array_values($insertData))) {
            return false;
        }

        $registrationId = (int) $pdo->lastInsertId();
        return true;
    } catch (Throwable $e) {
        error_log('Error inserting registration: ' . $e->getMessage());
        $error = $e->getMessage();
        return false;
    }
}

// ============================================================================
// Batch Fetch and Process
// ============================================================================

function fetchAndProcessRegistrations(PDO $pdo, int $eventId, string $csvExportURL, bool $requireDuplicateKey = false): array
{
    $result = [
        'success' => 0,
        'failed' => 0,
        'duplicates' => 0,
        'errors' => [],
        'rows' => [],
        'total' => 0,
    ];

    if ($eventId <= 0) {
        $result['errors'][] = 'A valid event must be selected before importing registrations.';
        return $result;
    }
    
    // Fetch Google Sheet data
    $sheetData = fetchGoogleSheetData($csvExportURL);
    if (!$sheetData) {
        $result['errors'][] = 'Failed to fetch data from Google Sheet';
        return $result;
    }
    
    // Get field mapping and default values
    $fieldMapping = getFieldMapping($pdo, $eventId);
    if (empty($fieldMapping)) {
        $result['errors'][] = 'No field mapping configured';
        return $result;
    }
    $defaultValues = getFieldDefaultValues($pdo, $eventId);
    $event = getEventById($pdo, $eventId);
    $eventTitle = (string) ($event['event_title'] ?? $eventId);
    
    // Process each row
    foreach ($sheetData['data'] as $index => $rowData) {
        // Skip empty rows
        if (empty(array_filter($rowData))) {
            continue;
        }

        $result['total']++;
        $rowResult = [
            'row' => $index + 2,
            'event' => $eventTitle,
            'registration_id' => null,
            'name' => '',
            'status' => 'failed',
            'message' => '',
        ];
        $nameSheetField = (string) ($fieldMapping['name'] ?? '');
        $name = $nameSheetField !== '' ? ($rowData[$nameSheetField] ?? '') : '';
        $name = $name !== '' ? $name : ($defaultValues['name'] ?? '');
        $rowResult['name'] = is_scalar($name) ? trim((string) $name) : '';

        try {
            $hasDuplicateKey = false;
            $duplicateRegistrationId = findDuplicateRegistrationId($pdo, $eventId, $rowData, $fieldMapping, $defaultValues, $hasDuplicateKey);
            if ($requireDuplicateKey && !$hasDuplicateKey) {
                $result['failed']++;
                $rowResult['message'] = 'Automatic import requires a non-empty mapped email or mobile number for duplicate detection.';
            } elseif ($duplicateRegistrationId !== null) {
                $result['duplicates']++;
                $rowResult['status'] = 'duplicate';
                $rowResult['registration_id'] = $duplicateRegistrationId;
                $rowResult['message'] = 'Registration already exists for this event.';
            } else {
                $insertError = null;
                $registrationId = null;
                if (insertRegistrationFromMapping($pdo, $eventId, $rowData, $fieldMapping, $defaultValues, $insertError, $registrationId)) {
                    $result['success']++;
                    $rowResult['status'] = 'success';
                    $rowResult['registration_id'] = $registrationId;
                    $rowResult['message'] = 'Registration imported successfully.';
                } else {
                    $result['failed']++;
                    $rowResult['message'] = $insertError ?: 'Database insert failed.';
                }
            }
        } catch (Throwable $exception) {
            $result['failed']++;
            $rowResult['message'] = $exception->getMessage();
            error_log('Error processing Google Sheet row ' . $rowResult['row'] . ': ' . $exception->getMessage());
        }

        $result['rows'][] = $rowResult;
    }
    
    return $result;
}

// ============================================================================
// Get Registration Table Fields
// ============================================================================

function getRegistrationTableName(PDO $pdo): ?string
{
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['event_registrations', 'registrations'] as $candidate) {
        if (in_array($candidate, $tables, true)) {
            return $candidate;
        }
    }

    return null;
}

function getRegistrationTableColumns(PDO $pdo): array
{
    $tableName = getRegistrationTableName($pdo);
    if ($tableName === null) {
        return [];
    }

    $stmt = $pdo->query('DESCRIBE `' . $tableName . '`');
    $fields = [];
    while ($row = $stmt->fetch()) {
        $fields[] = $row['Field'];
    }

    return $fields;
}

function getRegistrationTableFields(PDO $pdo): array
{
    return array_values(array_diff(
        getRegistrationTableColumns($pdo),
        ['registration_id', 'event_id', 'registration_date', 'pass_code', 'qr_code_path']
    ));
}

// ============================================================================
// Settings Table Initialization
// ============================================================================

function ensureSettingsTable(PDO $pdo): void
{
    try {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS settings (
                setting_id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(255) NOT NULL UNIQUE,
                setting_value LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_setting_key (setting_key)
            )
SQL
        );
    } catch (Exception $e) {
        error_log('Error creating settings table: ' . $e->getMessage());
    }
}
