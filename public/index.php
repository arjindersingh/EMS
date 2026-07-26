<?php

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = createDbConnection();
    $pdo->query('SELECT 1');

    $config = getDbConfig();
    unset($config['password']);

    include __DIR__ . '/home.php';
} catch (PDOException $exception) {
    echo '<h1>Database connection failed</h1>';
    echo '<p>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p>Update your database settings in <code>.env</code> or start the MySQL container.</p>';
}
