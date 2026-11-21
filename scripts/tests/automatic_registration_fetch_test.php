<?php

require_once __DIR__ . '/../../admin/automatic_registration_fetch_funcs.php';

function expectAutomaticFetch(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$now = strtotime('2026-01-01T12:00:00Z');
expectAutomaticFetch(isAutomaticRegistrationFetchDue(['interval_minutes' => 5], $now), 'First attempt should be due.');
expectAutomaticFetch(!isAutomaticRegistrationFetchDue(['interval_minutes' => 5, 'last_attempt' => '2026-01-01T11:55:01Z'], $now), 'Attempt ran early.');
expectAutomaticFetch(isAutomaticRegistrationFetchDue(['interval_minutes' => 5, 'last_attempt' => '2026-01-01T11:55:00Z'], $now), 'Attempt was not due at the interval.');
foreach ([0, 4, 'invalid'] as $interval) {
    try {
        isAutomaticRegistrationFetchDue(['interval_minutes' => $interval], $now);
        throw new LogicException('Invalid interval was accepted.');
    } catch (RuntimeException $exception) {
        expectAutomaticFetch(!($exception instanceof LogicException), 'Invalid interval was accepted.');
    }
}

$dsn = getenv('EMS_TEST_MYSQL_DSN');
if (!$dsn) {
    echo "Schedule tests passed; MySQL integration tests skipped (EMS_TEST_MYSQL_DSN is not set).\n";
    exit(0);
}

$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$pdo = new PDO($dsn, getenv('EMS_TEST_MYSQL_USER') ?: 'root', getenv('EMS_TEST_MYSQL_PASSWORD') ?: '', $options);
expectAutomaticFetch($pdo->query('SELECT DATABASE()')->fetchColumn() === 'ems_autofetch_test', 'Use a disposable ems_autofetch_test database.');
$pdo->exec('CREATE TABLE settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY, setting_name VARCHAR(255) NOT NULL UNIQUE,
    setting_type VARCHAR(30), setting_value LONGTEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE events (event_id INT PRIMARY KEY, event_title VARCHAR(100))');
$pdo->exec('CREATE TABLE event_registrations (
    registration_id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL,
    registration_date DATETIME, name VARCHAR(100), official_email VARCHAR(100)
)');
$pdo->exec("INSERT INTO events VALUES (1, 'One'), (2, 'Two'), (3, 'Three')");

class AutomaticFetchTestStream
{
    public $context;
    private int $position = 0;
    private string $data = '';

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        $this->data = $GLOBALS['automaticFetchFixtureCsv'];
        return !$GLOBALS['automaticFetchFixtureFailure'];
    }
    public function stream_read($count): string
    {
        $chunk = substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }
    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }
    public function stream_stat(): array
    {
        return [];
    }
}

$GLOBALS['automaticFetchFixtureFailure'] = false;
$GLOBALS['automaticFetchFixtureCsv'] = "Name,Email\nGuest,guest@example.test\n";
stream_wrapper_unregister('https');
stream_wrapper_register('https', AutomaticFetchTestStream::class);
try {
    saveGoogleSheetURL($pdo, 'https://docs.google.com/spreadsheets/d/test_sheet/edit#gid=0', 1);
    saveFieldMapping($pdo, 1, ['name' => 'Name', 'official_email' => 'Email']);
    saveFetchMethod($pdo, 1, 'automatic', true, 5);
    saveFetchMethod($pdo, 2, 'automatic', false, 5);
    saveFetchMethod($pdo, 3, 'automatic', true, 5);

    $result = runAutomaticRegistrationFetches($pdo);
    expectAutomaticFetch($result['failures'] === 1, 'Missing sheet error was not reported.');
    $config = getFetchMethod($pdo, 1, 'automatic');
    expectAutomaticFetch((int) $pdo->query('SELECT COUNT(*) FROM event_registrations')->fetchColumn() === 1, 'Due event was not imported.');
    expectAutomaticFetch(!empty($config['last_fetch']) && $config['last_status'] === 'success'
        && $config['last_result']['success'] === 1, 'Success status was not recorded.');
    $missingSheet = getFetchMethod($pdo, 3, 'automatic');
    expectAutomaticFetch($missingSheet['last_status'] === 'failed' && !empty($missingSheet['last_error']), 'Failure status was not recorded.');
    expectAutomaticFetch(empty(getFetchMethod($pdo, 2, 'automatic')['last_attempt']), 'Disabled event was attempted.');

    $result = runAutomaticRegistrationFetches($pdo);
    expectAutomaticFetch($result['failures'] === 0 && $result['messages'] === [], 'Events were retried before their interval.');

    $config['last_attempt'] = gmdate('c', time() - 301);
    saveAutomaticRegistrationFetchState($pdo, 1, $config);
    $secondPdo = new PDO($dsn, getenv('EMS_TEST_MYSQL_USER') ?: 'root', getenv('EMS_TEST_MYSQL_PASSWORD') ?: '', $options);
    expectAutomaticFetch(acquireRegistrationFetchLock($secondPdo, 1), 'Could not acquire test lock.');
    $result = runAutomaticRegistrationFetches($pdo);
    expectAutomaticFetch(str_contains(implode(' ', $result['messages']), 'another import is running'), 'Overlapping import was not skipped.');
    releaseRegistrationFetchLock($secondPdo, 1);

    $result = runAutomaticRegistrationFetches($pdo);
    $config = getFetchMethod($pdo, 1, 'automatic');
    expectAutomaticFetch($result['failures'] === 0 && $config['last_result']['duplicates'] === 1, 'Repeat import did not skip duplicate.');
    expectAutomaticFetch((int) $pdo->query('SELECT COUNT(*) FROM event_registrations')->fetchColumn() === 1, 'Duplicate registration was inserted.');

    $config['last_attempt'] = gmdate('c', time() - 301);
    saveAutomaticRegistrationFetchState($pdo, 1, $config);
    $GLOBALS['automaticFetchFixtureFailure'] = true;
    $result = runAutomaticRegistrationFetches($pdo);
    $failedConfig = getFetchMethod($pdo, 1, 'automatic');
    expectAutomaticFetch($result['failures'] === 1 && $failedConfig['last_status'] === 'failed'
        && !empty($failedConfig['last_error']), 'Sheet failure was not recorded.');
    expectAutomaticFetch(runAutomaticRegistrationFetches($pdo)['failures'] === 0, 'Failure was retried too early.');
    saveFetchMethod($pdo, 1, 'automatic', true, 10);
    $savedConfig = getFetchMethod($pdo, 1, 'automatic');
    expectAutomaticFetch($savedConfig['last_attempt'] === $failedConfig['last_attempt'] && $savedConfig['last_error'] === $failedConfig['last_error'], 'Saving settings erased automatic status.');
    saveFetchMethod($pdo, 1, 'automatic', false, 10);
    saveFetchMethod($pdo, 1, 'automatic', true, 10);
    expectAutomaticFetch(isAutomaticRegistrationFetchDue(getFetchMethod($pdo, 1, 'automatic'), time()), 'Re-enabled event was not immediately due.');
    $GLOBALS['automaticFetchFixtureFailure'] = false;
    $result = runAutomaticRegistrationFetches($pdo);
    $recoveredConfig = getFetchMethod($pdo, 1, 'automatic');
    expectAutomaticFetch($result['failures'] === 0 && empty($recoveredConfig['last_error'])
        && $recoveredConfig['last_result']['duplicates'] === 1, 'Retry after re-enabling did not recover.');

    $recoveredConfig['last_attempt'] = gmdate('c', time() - 601);
    saveAutomaticRegistrationFetchState($pdo, 1, $recoveredConfig);
    $GLOBALS['automaticFetchFixtureCsv'] = "Name,Email\nGuest,guest@example.test\nNo Email,\n";
    $result = runAutomaticRegistrationFetches($pdo);
    $missingKey = getFetchMethod($pdo, 1, 'automatic');
    expectAutomaticFetch($result['failures'] === 1 && $missingKey['last_result']['failed'] === 1
        && str_contains($missingKey['last_error'], 'email or mobile'), 'Rows without a duplicate key were not reported.');
    expectAutomaticFetch((int) $pdo->query('SELECT COUNT(*) FROM event_registrations')->fetchColumn() === 1, 'Row without a duplicate key was imported.');
    $manual = fetchAndProcessRegistrations($pdo, 1, 'https://docs.google.com/spreadsheets/d/test_sheet/export?format=csv');
    expectAutomaticFetch($manual['success'] === 1 && $manual['failed'] === 0, 'Manual import behavior changed for a row without a duplicate key.');
} finally {
    stream_wrapper_restore('https');
}

echo "Schedule and MySQL integration tests passed.\n";
