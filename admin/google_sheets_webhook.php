<?php

/**
 * Google Sheets Webhook Handler
 * Receives real-time notifications from Google Form and fetches new data
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/google_sheets_funcs.php';
require_once __DIR__ . '/events_funcs.php';

$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);

if (!$eventId) {
    http_response_code(400);
    echo json_encode(['error' => 'Event ID required']);
    exit;
}

try {
    $pdo = createDbConnection();
    
    // Check if real-time fetching is enabled
    $realtimeConfig = getFetchMethod($pdo, $eventId, 'realtime');
    
    if (!$realtimeConfig || !$realtimeConfig['enabled']) {
        http_response_code(403);
        echo json_encode(['error' => 'Real-time fetch not enabled']);
        exit;
    }
    
    // Get sheet URL
    $sheetURL = getGoogleSheetURL($pdo, $eventId);
    
    if (!$sheetURL) {
        http_response_code(400);
        echo json_encode(['error' => 'Sheet URL not configured']);
        exit;
    }
    
    // Convert to CSV export URL
    $csvURL = convertToCSVExportURL($sheetURL);
    
    if (!$csvURL) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid sheet URL']);
        exit;
    }
    
    if (!acquireRegistrationFetchLock($pdo, $eventId)) {
        http_response_code(409);
        echo json_encode(['error' => 'An import is already running for this event. Please retry.']);
        exit;
    }
    try {
        $result = fetchAndProcessRegistrations($pdo, $eventId, $csvURL);
    } finally {
        releaseRegistrationFetchLock($pdo, $eventId);
    }
    
    // Log webhook call
    error_log("[WEBHOOK] Event {$eventId}: Success={$result['success']}, Duplicates={$result['duplicates']}, Failed={$result['failed']}");
    
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'data' => $result,
    ]);
    
} catch (Exception $e) {
    error_log("Webhook error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
