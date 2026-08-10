<?php

declare(strict_types=1);

function loadEnvFile(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            continue;
        }

        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            putenv(sprintf('%s=%s', $name, $value));
        }
    }
}

loadEnvFile(__DIR__ . '/../.env');

function getAppBasePath(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    $scriptFilename = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $documentRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');

    $normalizedDocumentRoot = rtrim($documentRoot, '/');
    $normalizedScriptFilename = rtrim($scriptFilename, '/');

    if ($normalizedScriptFilename !== '' && $normalizedDocumentRoot !== '' && str_starts_with(strtolower($normalizedScriptFilename), strtolower($normalizedDocumentRoot))) {
        $relativeScript = substr($normalizedScriptFilename, strlen($normalizedDocumentRoot));
        $relativeDir = dirname($relativeScript);
        $segments = array_values(array_filter(explode('/', trim($relativeDir, '/')), static fn (string $segment): bool => $segment !== ''));

        if (!empty($segments) && strtolower(end($segments)) === 'admin') {
            array_pop($segments);
        }

        if (!empty($segments)) {
            return '/' . implode('/', $segments);
        }

        return '';
    }

    $normalizedScriptName = '/' . trim($scriptName, '/');
    if ($normalizedScriptName !== '/') {
        $scriptDir = rtrim(dirname($normalizedScriptName), '/');
        $segments = array_values(array_filter(explode('/', trim($scriptDir, '/')), static fn (string $segment): bool => $segment !== ''));

        if (!empty($segments) && strtolower(end($segments)) === 'admin') {
            array_pop($segments);
        }

        if (!empty($segments)) {
            return '/' . implode('/', $segments);
        }
    }

    return '';
}

function buildUrl(string $path = ''): string
{
    $basePath = rtrim(getAppBasePath(), '/');
    $normalizedPath = '/' . ltrim($path, '/');

    if ($basePath === '') {
        return $normalizedPath;
    }

    $segments = array_map(static fn (string $segment): string => rawurlencode($segment), array_filter(explode('/', trim($normalizedPath, '/')), static fn (string $segment): bool => $segment !== ''));
    $encodedPath = '/' . implode('/', $segments);

    return $basePath . $encodedPath;
}

function getDbConfig(): array
{
    return [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => (int) (getenv('DB_PORT') ?: '3306'),
        'database' => getenv('DB_DATABASE') ?: 'ems_db',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
    ];
}

function createDbConnection(): PDO
{
    $config = getDbConfig();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['database']
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        try {
            return new PDO($dsn, $config['username'], $config['password'], $options);
        } catch (PDOException $exception) {
            if ($attempt === 10) {
                throw $exception;
            }

            sleep(1);
        }
    }

    throw new PDOException('Unable to connect to the database.');
}
