<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login_for_api();

header('Content-Type: application/json');

$filters = [];
if (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '') {
    $warehouseFilter = (int) $_GET['warehouse_id'];
    if ($warehouseFilter > 0) {
        $filters['warehouse_id'] = $warehouseFilter;
    }
}
if (isset($_GET['sku']) && $_GET['sku'] !== '') {
    $filters['sku'] = trim((string) $_GET['sku']);
}

try {
    $payload = calculateDashboardData($mysqli, $config, $filters);
    echo json_encode($payload);
} catch (\Throwable $e) {
    $logDir = __DIR__ . '/../log';
    $logFile = $logDir . '/api-errors.log';
    $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
    $requestUri = $_SERVER['REQUEST_URI'] ?? 'unknown';
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    $logEntry = sprintf(
        "[%s] %s: %s in %s:%d\nRequest: %s %s\nStack trace:\n%s\n\n",
        $timestamp,
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $requestMethod,
        $requestUri,
        $e->getTraceAsString()
    );

    try {
        if (@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write to API error log.');
        }
    } catch (\Throwable $logError) {
        error_log('API logging failure: ' . $logError->getMessage() . ' | Original: ' . $e->getMessage());
    }

    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to compute dashboard data.',
        'details' => $e->getMessage(),
    ]);
}
