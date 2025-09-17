<?php

declare(strict_types=1);

/**
 * Fetch all warehouses keyed by ID.
 */
function getWarehouses(mysqli $mysqli): array
{
    $warehouses = [];
    $result = $mysqli->query('SELECT id, code, name, created_at FROM warehouses ORDER BY name');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $warehouses[(int) $row['id']] = $row;
        }
        $result->free();
    }
    return $warehouses;
}

function getWarehouseParameters(mysqli $mysqli): array
{
    $params = [];
    $sql = 'SELECT warehouse_id, days_to_cover, ma_window_days, min_avg_daily, safety_stock FROM warehouse_parameters';
    if ($result = $mysqli->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $warehouseId = (int) $row['warehouse_id'];
            $params[$warehouseId] = [
                'days_to_cover' => (int) $row['days_to_cover'],
                'ma_window_days' => (int) $row['ma_window_days'],
                'min_avg_daily' => (float) $row['min_avg_daily'],
                'safety_stock' => (float) $row['safety_stock'],
            ];
        }
        $result->free();
    }
    return $params;
}

function getSkuParameters(mysqli $mysqli): array
{
    $params = [];
    $sql = 'SELECT warehouse_id, sku, days_to_cover, ma_window_days, min_avg_daily, safety_stock FROM sku_parameters';
    if ($result = $mysqli->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $warehouseId = (int) $row['warehouse_id'];
            $sku = $row['sku'];
            if (!isset($params[$warehouseId])) {
                $params[$warehouseId] = [];
            }
            $params[$warehouseId][$sku] = [
                'days_to_cover' => (int) $row['days_to_cover'],
                'ma_window_days' => (int) $row['ma_window_days'],
                'min_avg_daily' => (float) $row['min_avg_daily'],
                'safety_stock' => (float) $row['safety_stock'],
            ];
        }
        $result->free();
    }
    return $params;
}

function getLatestStock(mysqli $mysqli, ?int $warehouseId = null, ?string $sku = null): array
{
    $conditions = [];
    if ($warehouseId !== null) {
        $conditions[] = 'warehouse_id = ' . (int) $warehouseId;
    }
    if ($sku !== null && $sku !== '') {
        $escaped = $mysqli->real_escape_string($sku);
        $conditions[] = "sku = '" . $escaped . "'";
    }
    $where = '';
    if ($conditions) {
        $where = 'WHERE ' . implode(' AND ', $conditions);
    }

    $sql = "SELECT warehouse_id, sku, quantity, snapshot_date FROM ("
        . "SELECT ss.*, ROW_NUMBER() OVER (PARTITION BY warehouse_id, sku ORDER BY snapshot_date DESC, id DESC) AS rn "
        . "FROM stock_snapshots ss {$where}"
        . ") ranked WHERE rn = 1";

    $stock = [];
    if ($result = $mysqli->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $wId = (int) $row['warehouse_id'];
            $stock[$wId][$row['sku']] = [
                'quantity' => (float) $row['quantity'],
                'snapshot_date' => $row['snapshot_date'],
            ];
        }
        $result->free();
    }
    return $stock;
}

function getSalesMap(mysqli $mysqli, int $lookbackDays, ?int $warehouseId = null, ?string $sku = null): array
{
    $startDate = (new \DateTimeImmutable('today'))->modify('-' . $lookbackDays . ' days');
    $params = [$startDate->format('Y-m-d')];
    $types = 's';
    $sql = 'SELECT warehouse_id, sku, sale_date, SUM(quantity) AS quantity '
        . 'FROM sales WHERE sale_date >= ?';

    if ($warehouseId !== null) {
        $sql .= ' AND warehouse_id = ?';
        $params[] = $warehouseId;
        $types .= 'i';
    }
    if ($sku !== null && $sku !== '') {
        $sql .= ' AND sku = ?';
        $params[] = $sku;
        $types .= 's';
    }

    $sql .= ' GROUP BY warehouse_id, sku, sale_date';

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $sales = [];
    while ($row = $result->fetch_assoc()) {
        $wId = (int) $row['warehouse_id'];
        $date = $row['sale_date'];
        $skuCode = $row['sku'];
        $qty = (float) $row['quantity'];
        $sales[$wId][$skuCode][$date] = $qty;
    }
    $stmt->close();
    return $sales;
}

function resolveParameters(
    int $warehouseId,
    string $sku,
    array $defaults,
    array $warehouseParams,
    array $skuParams
): array {
    if (isset($skuParams[$warehouseId][$sku])) {
        $params = $skuParams[$warehouseId][$sku];
    } elseif (isset($warehouseParams[$warehouseId])) {
        $params = $warehouseParams[$warehouseId];
    } else {
        $params = $defaults;
    }

    return [
        'days_to_cover' => (int) ($params['days_to_cover'] ?? $defaults['days_to_cover']),
        'ma_window_days' => max(1, (int) ($params['ma_window_days'] ?? $defaults['ma_window_days'])),
        'min_avg_daily' => max(0.0, (float) ($params['min_avg_daily'] ?? $defaults['min_avg_daily'])),
        'safety_stock' => max(0.0, (float) ($params['safety_stock'] ?? $defaults['safety_stock'])),
    ];
}

function calculateDashboardData(mysqli $mysqli, array $config, array $filters = []): array
{
    $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
    $sku = $filters['sku'] ?? null;

    $warehouses = getWarehouses($mysqli);
    $warehouseParams = getWarehouseParameters($mysqli);
    $skuParams = getSkuParameters($mysqli);
    $stockMap = getLatestStock($mysqli, $warehouseId, $sku);
    $salesMap = getSalesMap($mysqli, $config['lookback_days'], $warehouseId, $sku);

    $comboKeys = [];
    foreach ($stockMap as $wId => $items) {
        foreach ($items as $skuCode => $_) {
            $comboKeys[$wId . '|' . $skuCode] = ['warehouse_id' => $wId, 'sku' => $skuCode];
        }
    }
    foreach ($salesMap as $wId => $items) {
        foreach ($items as $skuCode => $_) {
            $comboKeys[$wId . '|' . $skuCode] = ['warehouse_id' => $wId, 'sku' => $skuCode];
        }
    }

    $today = new \DateTimeImmutable('today');
    $data = [];
    $totalReorder = 0.0;

    foreach ($comboKeys as $key => $combo) {
        $wId = $combo['warehouse_id'];
        $skuCode = $combo['sku'];
        $warehouse = $warehouses[$wId] ?? null;
        if (!$warehouse) {
            continue;
        }

        $params = resolveParameters($wId, $skuCode, $config['defaults'], $warehouseParams, $skuParams);
        $maWindow = $params['ma_window_days'];

        $salesByDate = $salesMap[$wId][$skuCode] ?? [];
        $totalQty = 0.0;
        $dailySeries = [];
        for ($i = 0; $i < $maWindow; $i++) {
            $date = $today->modify('-' . $i . ' days')->format('Y-m-d');
            $qty = $salesByDate[$date] ?? 0.0;
            $dailySeries[$date] = $qty;
            $totalQty += $qty;
        }
        ksort($dailySeries);
        $movingAverage = $totalQty / max(1, $maWindow);
        $effectiveAvg = $movingAverage;
        if ($params['min_avg_daily'] > 0 && $movingAverage < $params['min_avg_daily']) {
            $effectiveAvg = $params['min_avg_daily'];
        }

        $stockInfo = $stockMap[$wId][$skuCode] ?? ['quantity' => 0.0, 'snapshot_date' => null];
        $currentStock = (float) $stockInfo['quantity'];
        $snapshotDate = $stockInfo['snapshot_date'];

        $targetStock = $effectiveAvg * $params['days_to_cover'] + $params['safety_stock'];
        $reorderQty = max(0.0, $targetStock - $currentStock);

        $daysOfCover = null;
        if ($effectiveAvg > 0) {
            $daysOfCover = $currentStock / $effectiveAvg;
        }

        $data[] = [
            'warehouse_id' => $wId,
            'warehouse_code' => $warehouse['code'],
            'warehouse_name' => $warehouse['name'],
            'sku' => $skuCode,
            'current_stock' => round($currentStock, 2),
            'snapshot_date' => $snapshotDate,
            'moving_average' => round($movingAverage, 2),
            'effective_avg' => round($effectiveAvg, 2),
            'days_of_cover' => $daysOfCover !== null ? round($daysOfCover, 2) : null,
            'target_stock' => round($targetStock, 2),
            'reorder_qty' => round($reorderQty, 2),
            'safety_stock' => round($params['safety_stock'], 2),
            'days_to_cover' => $params['days_to_cover'],
            'ma_window_days' => $params['ma_window_days'],
            'min_avg_daily' => $params['min_avg_daily'],
            'daily_series' => $dailySeries,
        ];

        $totalReorder += $reorderQty;
    }

    usort($data, function ($a, $b) {
        return strcmp($a['warehouse_code'], $b['warehouse_code']) ?: strcmp($a['sku'], $b['sku']);
    });

    return [
        'data' => $data,
        'summary' => [
            'total_items' => count($data),
            'total_reorder_qty' => round($totalReorder, 2),
        ],
    ];
}

/**
 * Insert or update a warehouse record.
 *
 * @return array{id:int, created:bool}
 */
function upsertWarehouse(mysqli $mysqli, string $code, ?string $name = null): array
{
    $code = trim($code);
    $name = $name !== null && $name !== '' ? trim($name) : $code;
    $stmt = $mysqli->prepare('SELECT id, name FROM warehouses WHERE code = ?');
    if (!$stmt) {
        return ['id' => 0, 'created' => false];
    }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $stmt->bind_result($id, $existingName);
    if ($stmt->fetch()) {
        $stmt->close();
        if ($name && $name !== $existingName) {
            $update = $mysqli->prepare('UPDATE warehouses SET name = ? WHERE id = ?');
            if ($update) {
                $update->bind_param('si', $name, $id);
                $update->execute();
                $update->close();
            }
        }
        return ['id' => (int) $id, 'created' => false];
    }
    $stmt->close();

    $insert = $mysqli->prepare('INSERT INTO warehouses (code, name) VALUES (?, ?)');
    if (!$insert) {
        return ['id' => 0, 'created' => false];
    }
    $insert->bind_param('ss', $code, $name);
    $insert->execute();
    $newId = $insert->insert_id;
    $insert->close();
    return ['id' => (int) $newId, 'created' => true];
}

function importSalesCsv(mysqli $mysqli, string $filePath): array
{
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ['success' => false, 'message' => 'Unable to open uploaded file.'];
    }
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['success' => false, 'message' => 'CSV file is empty.'];
    }

    $columns = array_map('strtolower', $header);
    $required = ['warehouse_code', 'sku', 'sale_date', 'quantity'];
    foreach ($required as $col) {
        if (!in_array($col, $columns, true)) {
            fclose($handle);
            return ['success' => false, 'message' => 'Missing required column: ' . $col];
        }
    }

    $index = array_flip($columns);
    $insert = $mysqli->prepare('INSERT INTO sales (warehouse_id, sku, sale_date, quantity) VALUES (?, ?, ?, ?)');
    if (!$insert) {
        fclose($handle);
        return ['success' => false, 'message' => 'Failed to prepare sales insert statement.'];
    }
    $insert->bind_param('issd', $warehouseId, $sku, $saleDate, $quantity);

    $rowCount = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) !== count($columns)) {
            continue;
        }
        $warehouseCode = trim($row[$index['warehouse_code']]);
        $sku = trim($row[$index['sku']]);
        $saleDate = trim($row[$index['sale_date']]);
        $quantity = (float) $row[$index['quantity']];
        if ($warehouseCode === '' || $sku === '' || $saleDate === '') {
            continue;
        }
        if (!is_numeric($row[$index['quantity']])) {
            continue;
        }
        $date = \DateTime::createFromFormat('Y-m-d', $saleDate);
        if (!$date) {
            continue;
        }
        $saleDate = $date->format('Y-m-d');
        $warehouseResult = upsertWarehouse($mysqli, $warehouseCode);
        $warehouseId = $warehouseResult['id'];
        if ($warehouseId <= 0) {
            continue;
        }
        $insert->execute();
        $rowCount++;
    }

    $insert->close();
    fclose($handle);

    return ['success' => true, 'message' => "Imported {$rowCount} sales rows."];
}

function importStockCsv(mysqli $mysqli, string $filePath): array
{
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ['success' => false, 'message' => 'Unable to open uploaded file.'];
    }
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['success' => false, 'message' => 'CSV file is empty.'];
    }

    $columns = array_map('strtolower', $header);
    $required = ['warehouse_code', 'sku', 'snapshot_date', 'quantity'];
    foreach ($required as $col) {
        if (!in_array($col, $columns, true)) {
            fclose($handle);
            return ['success' => false, 'message' => 'Missing required column: ' . $col];
        }
    }

    $index = array_flip($columns);
    $insert = $mysqli->prepare('INSERT INTO stock_snapshots (warehouse_id, sku, snapshot_date, quantity) VALUES (?, ?, ?, ?)');
    if (!$insert) {
        fclose($handle);
        return ['success' => false, 'message' => 'Failed to prepare stock insert statement.'];
    }
    $insert->bind_param('issd', $warehouseId, $sku, $snapshotDate, $quantity);

    $rowCount = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) !== count($columns)) {
            continue;
        }
        $warehouseCode = trim($row[$index['warehouse_code']]);
        $sku = trim($row[$index['sku']]);
        $snapshotDate = trim($row[$index['snapshot_date']]);
        $quantity = (float) $row[$index['quantity']];
        if ($warehouseCode === '' || $sku === '' || $snapshotDate === '') {
            continue;
        }
        if (!is_numeric($row[$index['quantity']])) {
            continue;
        }
        $date = \DateTime::createFromFormat('Y-m-d', $snapshotDate);
        if (!$date) {
            continue;
        }
        $snapshotDate = $date->format('Y-m-d');
        $warehouseResult = upsertWarehouse($mysqli, $warehouseCode);
        $warehouseId = $warehouseResult['id'];
        if ($warehouseId <= 0) {
            continue;
        }
        $insert->execute();
        $rowCount++;
    }

    $insert->close();
    fclose($handle);

    return ['success' => true, 'message' => "Imported {$rowCount} stock rows."];
}

function saveParameters(mysqli $mysqli, int $warehouseId, array $values, ?string $sku = null): bool
{
    $days = max(1, (int) $values['days_to_cover']);
    $ma = max(1, (int) $values['ma_window_days']);
    $min = max(0.0, (float) $values['min_avg_daily']);
    $safety = max(0.0, (float) $values['safety_stock']);

    if ($sku === null || $sku === '') {
        $sql = 'INSERT INTO warehouse_parameters (warehouse_id, days_to_cover, ma_window_days, min_avg_daily, safety_stock) '
            . 'VALUES (?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE days_to_cover = VALUES(days_to_cover), ma_window_days = VALUES(ma_window_days), '
            . 'min_avg_daily = VALUES(min_avg_daily), safety_stock = VALUES(safety_stock)';
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iiidd', $warehouseId, $days, $ma, $min, $safety);
    } else {
        $sql = 'INSERT INTO sku_parameters (warehouse_id, sku, days_to_cover, ma_window_days, min_avg_daily, safety_stock) '
            . 'VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE days_to_cover = VALUES(days_to_cover), ma_window_days = VALUES(ma_window_days), '
            . 'min_avg_daily = VALUES(min_avg_daily), safety_stock = VALUES(safety_stock)';
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isiidd', $warehouseId, $sku, $days, $ma, $min, $safety);
    }

    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

function deleteSkuParameter(mysqli $mysqli, int $warehouseId, string $sku): bool
{
    $stmt = $mysqli->prepare('DELETE FROM sku_parameters WHERE warehouse_id = ? AND sku = ?');
    $stmt->bind_param('is', $warehouseId, $sku);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected > 0;
}
