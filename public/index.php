<?php

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = createDbConnection();
    $pdo->query('SELECT 1');

    $config = getDbConfig();
    unset($config['password']);

    echo '<h1>EMS is ready</h1>';
    echo '<p>PHP and MySQL are configured successfully.</p>';
    echo '<pre>' . htmlspecialchars(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '</pre>';
} catch (PDOException $exception) {
    echo '<h1>Database connection failed</h1>';
    echo '<p>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p>Update your database settings in <code>.env</code> or start the MySQL container.</p>';
}
