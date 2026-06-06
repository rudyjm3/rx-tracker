<?php

declare(strict_types=1);

require_once __DIR__ . '/load_env.php';

function env_value(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

$_appTimezone = env_value('APP_TIMEZONE', 'UTC');
if ($_appTimezone !== '') {
    date_default_timezone_set($_appTimezone);
}
unset($_appTimezone);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_value('DB_HOST', '127.0.0.1');
    $port = env_value('DB_PORT', '3306');
    $database = env_value('DB_DATABASE', 'rx_tracker');
    $username = env_value('DB_USERNAME', 'root');
    $password = env_value('DB_PASSWORD', '');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
