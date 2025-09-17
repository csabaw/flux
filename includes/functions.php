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

    $sql = 'SELECT warehouse_id, sku, quantity, snapshot_date '
        . 'FROM stock_snapshots '
        . $where
        . ' ORDER BY warehouse_id, sku, snapshot_date DESC, id DESC';

    $stock = [];
    if ($result = $mysqli->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $wId = (int) $row['warehouse_id'];
            $skuCode = $row['sku'];
            if (!isset($stock[$wId])) {
                $stock[$wId] = [];
            }
            if (isset($stock[$wId][$skuCode])) {
                continue;
            }
            $stock[$wId][$skuCode] = [
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

/**
 * Normalize a user-provided date string to the canonical Y-m-d format.
 */
function normalizeDateString(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $value = preg_replace('/\b(\d{1,2})(st|nd|rd|th)\b/i', '$1', $value);
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $candidates = [];
    $addCandidate = static function (string $candidate) use (&$candidates): void {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return;
        }
        if (!in_array($candidate, $candidates, true)) {
            $candidates[] = $candidate;
        }
    };

    $addCandidate($value);

    $normalizedWhitespace = preg_replace('/\s+/', ' ', $value);
    if (is_string($normalizedWhitespace)) {
        $addCandidate($normalizedWhitespace);
    }

    $separatorVariants = [
        str_replace(['/', '\\'], '-', $value),
        str_replace('.', '-', $value),
        str_replace(',', '', $value),
    ];
    foreach ($separatorVariants as $variant) {
        if (is_string($variant)) {
            $addCandidate($variant);
        }
    }

    $baseFormats = [
        'Y-m-d', 'Y/m/d', 'Y.m.d', 'Y m d', 'Ymd', 'Y-n-j', 'Y/n/j', 'Y.n.j', 'Y d m', 'Y j n',
        'd/m/Y', 'j/n/Y', 'd/m/y', 'j/n/y', 'dmY', 'dmy', 'd m Y', 'd m y',
        'd-m-Y', 'j-n-Y', 'd-m-y', 'j-n-y',
        'd.m.Y', 'j.n.Y', 'd.m.y', 'j.n.y',
        'm/d/Y', 'n/j/Y', 'm/d/y', 'n/j/y', 'mdY', 'mdy', 'm d Y', 'm d y',
        'm-d-Y', 'n-j-Y', 'm-d-y', 'n-j-y',
        'm.d.Y', 'n.j.Y', 'm.d.y', 'n.j.y',
        'd M Y', 'j M Y', 'd M y', 'j M y',
        'M d Y', 'M j Y', 'M d y', 'M j y',
        'd M, Y', 'j M, Y', 'd M, y', 'j M, y',
        'M d, Y', 'M j, Y', 'M d, y', 'M j, y',
        'd F Y', 'j F Y', 'd F y', 'j F y',
        'F d Y', 'F j Y', 'F d y', 'F j y',
        'D, d M Y', 'D, j M Y', 'D d M Y', 'D j M Y',
        'l, d F Y', 'l, j F Y', 'l d F Y', 'l j F Y',
    ];

    $timeSuffixes = [
        '',
        ' H:i',
        ' H:i:s',
        ' H:i:s.u',
        ' H:i:sP',
        ' H:iP',
        ' H:i:s.uP',
        ' H:iO',
        ' H:i:sO',
        ' H:i:s.uO',
        ' g:i A',
        ' g:i:s A',
        ' g:i a',
        ' g:i:s a',
    ];

    $isoFormats = [
        '!Y-m-d\TH:i',
        '!Y-m-d\TH:iP',
        '!Y-m-d\TH:iO',
        '!Y-m-d\TH:i:s',
        '!Y-m-d\TH:i:sP',
        '!Y-m-d\TH:i:sO',
        '!Y-m-d\TH:i:s.u',
        '!Y-m-d\TH:i:s.uP',
        '!Y-m-d\TH:i:s.uO',
    ];

    foreach ($candidates as $candidate) {
        foreach ($baseFormats as $format) {
            foreach ($timeSuffixes as $suffix) {
                $fullFormat = '!' . $format . $suffix;
                $date = \DateTime::createFromFormat($fullFormat, $candidate);
                if ($date instanceof \DateTime) {
                    $errors = \DateTime::getLastErrors();
                    if ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0)) {
                        return $date->format('Y-m-d');
                    }
                }
            }
        }

        foreach ($isoFormats as $format) {
            $date = \DateTime::createFromFormat($format, $candidate);
            if ($date instanceof \DateTime) {
                $errors = \DateTime::getLastErrors();
                if ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0)) {
                    return $date->format('Y-m-d');
                }
            }
        }
    }

    foreach ($candidates as $candidate) {
        $parsed = date_parse($candidate);
        if (is_array($parsed)
            && ($parsed['error_count'] ?? 0) === 0
            && ($parsed['warning_count'] ?? 0) === 0
            && isset($parsed['year'], $parsed['month'], $parsed['day'])
            && $parsed['year'] !== false
            && $parsed['month'] !== false
            && $parsed['day'] !== false
        ) {
            $year = (int) $parsed['year'];
            $month = (int) $parsed['month'];
            $day = (int) $parsed['day'];
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
    }

    return null;
}

/**
 * Normalize a numeric value from a CSV cell into a non-negative float.
 *
 * @param mixed $value
 */
function normalizeCsvNumber($value): ?float
{
    if (is_int($value) || is_float($value)) {
        $number = (float) $value;
    } else {
        if ($value === null) {
            return null;
        }
        $stringValue = is_string($value) ? trim($value) : trim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        $negative = strpos($stringValue, '-') !== false
            || (strpos($stringValue, '(') !== false && strpos($stringValue, ')') !== false);

        $normalized = preg_replace('/[^0-9.,-]/', '', $stringValue);
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        $normalized = str_replace(["\u{00A0}", ' '], '', $normalized);

        $commaPos = strrpos($normalized, ',');
        $dotPos = strrpos($normalized, '.');
        if ($commaPos !== false && $dotPos !== false) {
            if ($commaPos > $dotPos) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($commaPos !== false) {
            $parts = explode(',', $normalized);
            if (count($parts) > 1) {
                $lastPart = end($parts);
                if ($lastPart !== false && strlen($lastPart) === 3) {
                    $normalized = implode('', $parts);
                } else {
                    $normalized = implode('.', $parts);
                }
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        }

        $normalized = str_replace('-', '', $normalized);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;
        if ($negative) {
            $number = -abs($number);
        }
    }

    if ($number < 0) {
        return 0.0;
    }

    return $number;
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
    $warehouseId = isset($filters['warehouse_id']) && $filters['warehouse_id'] !== ''
        ? (int) $filters['warehouse_id']
        : null;
    $sku = isset($filters['sku']) && $filters['sku'] !== ''
        ? (string) $filters['sku']
        : null;

    $tsvShort = isset($filters['tsv_short']) ? max(1, (int) $filters['tsv_short']) : 7;
    $tsvLong = isset($filters['tsv_long']) ? max($tsvShort, (int) $filters['tsv_long']) : max(30, $tsvShort);
    if ($tsvLong < $tsvShort) {
        $tsvLong = $tsvShort;
    }
    $ewmaSpan = isset($filters['ewma_span']) ? max(1, (int) $filters['ewma_span']) : 14;

    $lookbackDays = max($config['lookback_days'], $tsvLong + $ewmaSpan);

    $warehouses = getWarehouses($mysqli);
    $warehouseParams = getWarehouseParameters($mysqli);
    $skuParams = getSkuParameters($mysqli);
    $stockMap = getLatestStock($mysqli, $warehouseId, $sku);
    $salesMap = getSalesMap($mysqli, $lookbackDays, $warehouseId, $sku);

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
    $seriesDates = [];
    for ($i = $lookbackDays - 1; $i >= 0; $i--) {
        $seriesDates[] = $today->modify('-' . $i . ' days')->format('Y-m-d');
    }

    $data = [];
    $totalReorder = 0.0;

    foreach ($comboKeys as $combo) {
        $wId = $combo['warehouse_id'];
        $skuCode = $combo['sku'];
        $warehouse = $warehouses[$wId] ?? null;
        if (!$warehouse) {
            continue;
        }

        $params = resolveParameters($wId, $skuCode, $config['defaults'], $warehouseParams, $skuParams);

        $salesByDate = $salesMap[$wId][$skuCode] ?? [];
        $dailySeries = [];
        $seriesValues = [];
        foreach ($seriesDates as $date) {
            $qty = (float) ($salesByDate[$date] ?? 0.0);
            $dailySeries[$date] = $qty;
            $seriesValues[] = $qty;
        }

        $shortSlice = array_slice($seriesValues, -$tsvShort);
        $shortDenominator = min($tsvShort, count($shortSlice));
        $tsvShortAvg = $shortDenominator > 0 ? array_sum($shortSlice) / $shortDenominator : 0.0;

        $longSlice = array_slice($seriesValues, -$tsvLong);
        $longDenominator = min($tsvLong, count($longSlice));
        $tsvLongAvg = $longDenominator > 0 ? array_sum($longSlice) / $longDenominator : 0.0;

        $alpha = 2 / ($ewmaSpan + 1);
        $ewma = null;
        foreach ($seriesValues as $value) {
            if ($ewma === null) {
                $ewma = $value;
                continue;
            }
            $ewma = $alpha * $value + (1 - $alpha) * $ewma;
        }
        if ($ewma === null) {
            $ewma = 0.0;
        }

        $effectiveAvg = max($ewma, $params['min_avg_daily']);

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
            'tsv_short' => round($tsvShortAvg, 2),
            'tsv_long' => round($tsvLongAvg, 2),
            'ewma' => round($ewma, 2),
            'days_of_cover' => $daysOfCover !== null ? round($daysOfCover, 2) : null,
            'target_stock' => round($targetStock, 2),
            'reorder_qty' => round($reorderQty, 2),
            'safety_stock' => round($params['safety_stock'], 2),
            'days_to_cover' => $params['days_to_cover'],
            'min_avg_daily' => $params['min_avg_daily'],
            'ewma_span' => $ewmaSpan,
            'tsv_short_days' => $tsvShort,
            'tsv_long_days' => $tsvLong,
            'daily_series' => $dailySeries,
        ];

        $totalReorder += $reorderQty;
    }

    usort($data, static function ($a, $b) {
        return strcmp($a['warehouse_code'], $b['warehouse_code']) ?: strcmp($a['sku'], $b['sku']);
    });

    return [
        'data' => $data,
        'summary' => [
            'total_items' => count($data),
            'total_reorder_qty' => round($totalReorder, 2),
            'tsv_short_days' => $tsvShort,
            'tsv_long_days' => $tsvLong,
            'ewma_span' => $ewmaSpan,
            'lookback_days' => $lookbackDays,
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

function importSalesCsv(
    mysqli $mysqli,
    string $filePath,
    ?int $warehouseId = null,
    ?array $columnMap = null
): array {
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ['success' => false, 'message' => 'Unable to open uploaded file.'];
    }
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['success' => false, 'message' => 'CSV file is empty.'];
    }

    $columnCount = count($header);
    $useMapping = $columnMap !== null && $warehouseId !== null;

    if ($useMapping) {
        $index = [];
        foreach (['sale_date', 'sku', 'quantity'] as $field) {
            if (!isset($columnMap[$field])) {
                fclose($handle);
                return ['success' => false, 'message' => 'Please select a column for ' . $field . '.'];
            }
            $idx = (int) $columnMap[$field];
            if ($idx < 0 || $idx >= $columnCount) {
                fclose($handle);
                return ['success' => false, 'message' => 'Invalid column selection provided.'];
            }
            $index[$field] = $idx;
        }
    } else {
        $columns = array_map('strtolower', $header);
        $required = ['warehouse_code', 'sku', 'sale_date', 'quantity'];
        foreach ($required as $col) {
            if (!in_array($col, $columns, true)) {
                fclose($handle);
                return ['success' => false, 'message' => 'Missing required column: ' . $col];
            }
        }
        $index = array_flip($columns);
    }

    $insert = $mysqli->prepare('INSERT INTO sales (warehouse_id, sku, sale_date, quantity) VALUES (?, ?, ?, ?)');
    if (!$insert) {
        fclose($handle);
        return ['success' => false, 'message' => 'Failed to prepare sales insert statement.'];
    }
    $warehouseIdParam = $useMapping ? (int) $warehouseId : 0;
    $skuParam = '';
    $saleDateParam = '';
    $quantityParam = 0.0;
    $insert->bind_param('issd', $warehouseIdParam, $skuParam, $saleDateParam, $quantityParam);

    $rowCount = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if ($useMapping) {
            $saleDateRaw = $row[$index['sale_date']] ?? '';
            $skuRaw = $row[$index['sku']] ?? '';
            $quantityRaw = $row[$index['quantity']] ?? null;
            $saleDateRaw = is_string($saleDateRaw) ? trim($saleDateRaw) : (string) $saleDateRaw;
            $skuRaw = is_string($skuRaw) ? trim($skuRaw) : (string) $skuRaw;
            $quantityValue = normalizeCsvNumber($quantityRaw);
            if ($skuRaw === '' || $saleDateRaw === '' || $quantityValue === null) {
                continue;
            }

            $normalizedDate = normalizeDateString($saleDateRaw);
            if ($normalizedDate === null) {

                continue;
            }
            $warehouseIdParam = (int) $warehouseId;
            $skuParam = $skuRaw;

            $saleDateParam = $normalizedDate;
            $quantityParam = $quantityValue;
        } else {
            if (count($row) !== $columnCount) {
                continue;
            }
            $warehouseCode = trim((string) $row[$index['warehouse_code']]);
            $skuRaw = trim((string) $row[$index['sku']]);
            $saleDateRaw = trim((string) $row[$index['sale_date']]);
            $quantityValue = normalizeCsvNumber($row[$index['quantity']] ?? null);
            if ($warehouseCode === '' || $skuRaw === '' || $saleDateRaw === '' || $quantityValue === null) {
                continue;
            }

            $normalizedDate = normalizeDateString($saleDateRaw);
            if ($normalizedDate === null) {
                continue;
            }
            $saleDateParam = $normalizedDate;

            $warehouseResult = upsertWarehouse($mysqli, $warehouseCode);
            $warehouseIdParam = $warehouseResult['id'];
            if ($warehouseIdParam <= 0) {
                continue;
            }
            $skuParam = $skuRaw;
            $quantityParam = $quantityValue;
        }
        $insert->execute();
        $rowCount++;
    }

    $insert->close();
    fclose($handle);

    return ['success' => true, 'message' => "Imported {$rowCount} sales rows."];
}

function importStockCsv(
    mysqli $mysqli,
    string $filePath,
    ?int $warehouseId = null,
    ?array $columnMap = null,
    ?string $snapshotDateOverride = null
): array {
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ['success' => false, 'message' => 'Unable to open uploaded file.'];
    }
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['success' => false, 'message' => 'CSV file is empty.'];
    }

    $columnCount = count($header);
    $useMapping = $columnMap !== null && $warehouseId !== null;
    $snapshotOverride = null;
    if ($snapshotDateOverride !== null && $snapshotDateOverride !== '') {

        $normalizedOverride = normalizeDateString($snapshotDateOverride);
        if ($normalizedOverride === null) {
            fclose($handle);
            return ['success' => false, 'message' => 'Invalid snapshot date provided.'];
        }
        $snapshotOverride = $normalizedOverride;
    }

    if ($useMapping) {
        $index = [];
        foreach (['sku', 'quantity'] as $field) {
            if (!isset($columnMap[$field])) {
                fclose($handle);
                return ['success' => false, 'message' => 'Please select a column for ' . $field . '.'];
            }
            $idx = (int) $columnMap[$field];
            if ($idx < 0 || $idx >= $columnCount) {
                fclose($handle);
                return ['success' => false, 'message' => 'Invalid column selection provided.'];
            }
            $index[$field] = $idx;
        }
        if (isset($columnMap['snapshot_date'])) {
            $idx = (int) $columnMap['snapshot_date'];
            if ($idx >= 0 && $idx < $columnCount) {
                $index['snapshot_date'] = $idx;
            }
        }
        if ($snapshotOverride === null && !isset($index['snapshot_date'])) {
            fclose($handle);
            return ['success' => false, 'message' => 'Please provide a snapshot date.'];
        }
    } else {
        $columns = array_map('strtolower', $header);
        $required = ['warehouse_code', 'sku', 'snapshot_date', 'quantity'];
        foreach ($required as $col) {
            if (!in_array($col, $columns, true)) {
                fclose($handle);
                return ['success' => false, 'message' => 'Missing required column: ' . $col];
            }
        }

        $index = array_flip($columns);
    }

    $insert = $mysqli->prepare('INSERT INTO stock_snapshots (warehouse_id, sku, snapshot_date, quantity) VALUES (?, ?, ?, ?)');
    if (!$insert) {
        fclose($handle);
        return ['success' => false, 'message' => 'Failed to prepare stock insert statement.'];
    }
    $warehouseIdParam = $useMapping ? (int) $warehouseId : 0;
    $skuParam = '';
    $snapshotDateParam = $snapshotOverride ?? '';
    $quantityParam = 0.0;
    $insert->bind_param('issd', $warehouseIdParam, $skuParam, $snapshotDateParam, $quantityParam);

    $rowCount = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if ($useMapping) {
            $skuRaw = $row[$index['sku']] ?? '';
            $quantityRaw = $row[$index['quantity']] ?? null;
            $skuRaw = is_string($skuRaw) ? trim($skuRaw) : (string) $skuRaw;
            $quantityValue = normalizeCsvNumber($quantityRaw);
            if ($skuRaw === '' || $quantityValue === null) {
                continue;
            }
            if ($snapshotOverride !== null) {
                $snapshotDateParam = $snapshotOverride;
            } else {
                $snapshotRaw = $row[$index['snapshot_date']] ?? '';
                $snapshotRaw = is_string($snapshotRaw) ? trim($snapshotRaw) : (string) $snapshotRaw;
                if ($snapshotRaw === '') {
                    continue;
                }

                $normalizedDate = normalizeDateString($snapshotRaw);
                if ($normalizedDate === null) {
                    continue;
                }
                $snapshotDateParam = $normalizedDate;

            }
            $warehouseIdParam = (int) $warehouseId;
            $skuParam = $skuRaw;
            $quantityParam = $quantityValue;
        } else {
            if (count($row) !== $columnCount) {
                continue;
            }
            $warehouseCode = trim((string) $row[$index['warehouse_code']]);
            $skuRaw = trim((string) $row[$index['sku']]);
            $snapshotRaw = trim((string) $row[$index['snapshot_date']]);
            $quantityValue = normalizeCsvNumber($row[$index['quantity']] ?? null);
            if ($warehouseCode === '' || $skuRaw === '' || $snapshotRaw === '' || $quantityValue === null) {
                continue;
            }

            $normalizedDate = normalizeDateString($snapshotRaw);
            if ($normalizedDate === null) {
                continue;
            }
            $snapshotDateParam = $normalizedDate;

            $warehouseResult = upsertWarehouse($mysqli, $warehouseCode);
            $warehouseIdParam = $warehouseResult['id'];
            if ($warehouseIdParam <= 0) {
                continue;
            }
            $skuParam = $skuRaw;
            $quantityParam = $quantityValue;
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
