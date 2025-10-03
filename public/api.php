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
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to compute dashboard data.',
        'details' => $e->getMessage(),
    ]);
}
