<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/../admin/automatic_registration_fetch_funcs.php';

try {
    $result = runAutomaticRegistrationFetches(createDbConnection());
    foreach ($result['messages'] as $message) {
        echo $message . PHP_EOL;
    }
    exit($result['failures'] > 0 ? 1 : 0);
} catch (Throwable $exception) {
    $message = 'Automatic registration fetch failed: ' . $exception->getMessage();
    error_log($message);
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
