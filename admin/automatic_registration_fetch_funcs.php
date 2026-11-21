<?php

require_once __DIR__ . '/google_sheets_funcs.php';
require_once __DIR__ . '/events_funcs.php';

function isAutomaticRegistrationFetchDue(array $config, int $now): bool
{
    $interval = filter_var($config['interval_minutes'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 5, 'max_range' => 2147483647],
    ]);
    if ($interval === false) {
        throw new RuntimeException('Automatic fetch interval must be at least 5 minutes.');
    }

    $lastAttempt = $config['last_attempt'] ?? null;
    if ($lastAttempt === null || $lastAttempt === '') {
        return true;
    }
    $lastAttemptTime = is_string($lastAttempt) ? strtotime($lastAttempt) : false;
    if ($lastAttemptTime === false) {
        throw new RuntimeException('Automatic fetch last-attempt time is invalid.');
    }
    return $now - $lastAttemptTime >= $interval * 60;
}

function saveAutomaticRegistrationFetchState(PDO $pdo, int $eventId, array $config): void
{
    $stmt = $pdo->prepare('UPDATE settings SET setting_value = :value, updated_at = NOW() WHERE setting_name = :name');
    $stmt->execute([
        ':name' => 'registration_fetch_automatic_' . $eventId,
        ':value' => json_encode($config, JSON_THROW_ON_ERROR),
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Could not update automatic fetch status.');
    }
}

function runAutomaticRegistrationFetches(PDO $pdo): array
{
    ensureSettingsTable($pdo);
    $stmt = $pdo->query("SELECT setting_name FROM settings WHERE setting_name LIKE 'registration_fetch_automatic_%'");
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $messages = [];
    $failures = 0;

    foreach ($names as $name) {
        if (!preg_match('/^registration_fetch_automatic_([1-9][0-9]*)$/', $name, $matches)) {
            continue;
        }
        $eventId = (int) $matches[1];
        $locked = false;
        $config = null;
        $attemptStarted = false;
        try {
            $locked = acquireRegistrationFetchLock($pdo, $eventId);
            if (!$locked) {
                $messages[] = "Event {$eventId}: another import is running; skipped.";
                continue;
            }
            $config = getFetchMethod($pdo, $eventId, 'automatic');
            if ($config === null) {
                continue;
            }
            if (!isset($config['enabled']) || !is_bool($config['enabled'])) {
                throw new RuntimeException('Automatic fetch enabled setting is invalid.');
            }
            if (!$config['enabled']) {
                continue;
            }
            if (!isAutomaticRegistrationFetchDue($config, time())) {
                continue;
            }

            if (!isset($config['automatic_state_version'])) {
                unset($config['last_fetch']);
                $config['automatic_state_version'] = 1;
            }
            $config['last_attempt'] = gmdate('c');
            $config['last_status'] = 'started';
            $config['last_error'] = null;
            unset($config['last_result']);
            saveAutomaticRegistrationFetchState($pdo, $eventId, $config);
            $attemptStarted = true;

            if (!getEventById($pdo, $eventId)) {
                throw new RuntimeException('The event no longer exists.');
            }
            $sheetURL = getGoogleSheetURL($pdo, $eventId);
            $csvURL = $sheetURL ? convertToCSVExportURL($sheetURL) : null;
            if ($csvURL === null) {
                throw new RuntimeException('A valid Google Sheet URL is not configured.');
            }
            $result = fetchAndProcessRegistrations($pdo, $eventId, $csvURL, true);
            $config['last_result'] = [
                'total' => $result['total'],
                'success' => $result['success'],
                'duplicates' => $result['duplicates'],
                'failed' => $result['failed'],
            ];
            if ($result['errors'] || $result['failed'] > 0) {
                $errors = $result['errors'];
                foreach ($result['rows'] as $row) {
                    if ($row['status'] === 'failed') {
                        $errors[] = 'Row ' . $row['row'] . ': ' . $row['message'];
                    }
                    if (count($errors) >= 5) {
                        break;
                    }
                }
                throw new RuntimeException(implode('; ', $errors));
            }
            $config['last_fetch'] = gmdate('c');
            $config['last_status'] = 'success';
            saveAutomaticRegistrationFetchState($pdo, $eventId, $config);
            $messages[] = "Event {$eventId}: imported {$result['success']}, duplicates {$result['duplicates']}, failed {$result['failed']}.";
        } catch (Throwable $exception) {
            $failures++;
            $message = "Event {$eventId}: " . $exception->getMessage();
            $messages[] = $message;
            error_log('Automatic registration fetch: ' . $message);
            if ($locked && is_array($config)) {
                try {
                    if (!$attemptStarted) {
                        $config['last_attempt'] = gmdate('c');
                    }
                    $config['last_status'] = 'failed';
                    $config['last_error'] = $exception->getMessage();
                    saveAutomaticRegistrationFetchState($pdo, $eventId, $config);
                } catch (Throwable $statusException) {
                    $statusMessage = "Event {$eventId}: could not save fetch status: " . $statusException->getMessage();
                    $messages[] = $statusMessage;
                    error_log('Automatic registration fetch: ' . $statusMessage);
                }
            }
        } finally {
            if ($locked) {
                releaseRegistrationFetchLock($pdo, $eventId);
            }
        }
    }
    return ['failures' => $failures, 'messages' => $messages];
}
