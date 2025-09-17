<?php

declare(strict_types=1);

// Set application timezone
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Europe/Rome');
}

// Load environment variables from .env
$rootPath = dirname(__DIR__);
$envPath = $rootPath . DIRECTORY_SEPARATOR . '.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        [$name, $value] = array_map('trim', explode('=', $line, 2) + [1 => '']);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
}

function env(string $key, $default = null) {
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return $value;
}

$config = [
    'db' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'flux'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],
    'auth' => [
        'username' => env('ADMIN_USER', 'admin'),
        'password' => env('ADMIN_PASS', 'changeme'),
    ],
    'defaults' => [
        'days_to_cover' => (int) env('DEFAULT_DAYS_TO_COVER', 14),
        'ma_window_days' => (int) env('DEFAULT_MA_WINDOW_DAYS', 7),
        'min_avg_daily' => (float) env('DEFAULT_MIN_AVG_DAILY', 1),
        'safety_days' => (float) env('DEFAULT_SAFETY_DAYS', 0),
    ],
    'lookback_days' => (int) env('MAX_LOOKBACK_DAYS', 90),
];
