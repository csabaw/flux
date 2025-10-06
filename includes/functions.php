<?php

declare(strict_types=1);

/**
 * Fetch all warehouses keyed by ID.
 */
function getWarehouses(mysqli $mysqli): array
{
    $warehouses = [];

    static $hasCreatedAtCache;
    if ($hasCreatedAtCache === null) {
        $hasCreatedAtCache = tableColumnExists($mysqli, 'warehouses', 'created_at');
    }
    $hasCreatedAt = $hasCreatedAtCache;
    $columns = 'id, name' . ($hasCreatedAt ? ', created_at' : '');
    $sql = 'SELECT ' . $columns . ' FROM warehouses ORDER BY name';
    logSqlQuery($sql, 'getWarehouses');

    if ($result = $mysqli->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $warehouses[$id] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'created_at' => $hasCreatedAt ? ($row['created_at'] ?? null) : null,
            ];
        }
        $result->free();
    }

    return $warehouses;
}

/**
 * Determine whether a column exists on a given table.
 */
function tableColumnExists(mysqli $mysqli, string $table, string $column): bool
{
    $tableName = str_replace('`', '``', $table);
    $columnName = $mysqli->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'";
    if ($result = $mysqli->query($sql)) {
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }

    return false;
}

/**
 * Append an SQL statement to the shared log for troubleshooting.
 */
function logSqlQuery(string $sql, string $context = 'query'): void
{
    $logDir = dirname(__DIR__) . '/log';
    if (!is_dir($logDir)) {
        error_clear_last();
        if (!mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            $lastError = error_get_last();
            $reason = $lastError['message'] ?? 'unknown reason';
            $message = sprintf('Unable to create SQL log directory "%s" for context "%s": %s', $logDir, $context, $reason);
            error_log($message);
            // Fallback to standard error log so the query is still observable.
            error_log(sprintf('[sql-fallback] %s: %s', $context, $sql));
            return;
        }
    }

    $logSql = $sql;
    if (preg_match('/^(\s*)elect\b/i', $logSql) === 1) {
        $corrected = preg_replace('/^(\s*)elect\b/i', '$1SELECT', $logSql, 1);
        if (is_string($corrected)) {
            $logSql = $corrected . ' /* auto-corrected missing SELECT keyword */';
        } else {
            $logSql = $sql;
        }
    }

    $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $entry = sprintf('[%s] %s: %s%s', $timestamp, $context, $logSql, PHP_EOL);
    $logFile = $logDir . '/sql.log';
    error_clear_last();
    if (file_put_contents($logFile, $entry, FILE_APPEND) === false) {
        $lastError = error_get_last();
        $reason = $lastError['message'] ?? 'unknown reason';
        $message = sprintf('Unable to write SQL log to "%s" for context "%s": %s', $logFile, $context, $reason);
        error_log($message);
        // Fallback to standard error log so the query is still observable.
        error_log(sprintf('[sql-fallback] %s: %s', $context, $sql));
    }
}

/**
 * Resolve the name of the safety buffer column, upgrading the table if possible.
 */
function resolveSafetyColumn(mysqli $mysqli, string $table): string
{
    static $resolved = [];
    if (isset($resolved[$table])) {
        return $resolved[$table];
    }

    $tableName = str_replace('`', '``', $table);

    if (!tableColumnExists($mysqli, $table, 'safety_days')) {
        if (tableColumnExists($mysqli, $table, 'safety_stock')) {
            $sql = "ALTER TABLE `{$tableName}` CHANGE `safety_stock` `safety_days` DECIMAL(12, 3) NOT NULL";
            $mysqli->query($sql);
        } else {
            $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `safety_days` DECIMAL(12, 3) NOT NULL DEFAULT 0";
            $mysqli->query($sql);
        }
    }

    $safetyColumn = tableColumnExists($mysqli, $table, 'safety_days') ? 'safety_days' : 'safety_stock';
    $resolved[$table] = $safetyColumn;
    return $safetyColumn;
}

function getWarehouseParameters(mysqli $mysqli): array
{
    $params = [];

    $safetyColumn = resolveSafetyColumn($mysqli, 'warehouse_parameters');
    $columnSql = '`' . str_replace('`', '``', $safetyColumn) . '`';
    $sql = 'SELECT warehouse_id, days_to_cover, ma_window_days, min_avg_daily, '
        . $columnSql . ' AS safety_days FROM warehouse_parameters';

    if ($result = $mysqli->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $warehouseId = (int) $row['warehouse_id'];
            $params[$warehouseId] = [
                'days_to_cover' => (int) $row['days_to_cover'],
                'ma_window_days' => (int) $row['ma_window_days'],
                'min_avg_daily' => (float) $row['min_avg_daily'],
                'safety_days' => (float) $row['safety_days'],
            ];
        }
        $result->free();
    }
    return $params;
}

function getSkuParameters(mysqli $mysqli): array
{
    $params = [];

    $safetyColumn = resolveSafetyColumn($mysqli, 'sku_parameters');
    $columnSql = '`' . str_replace('`', '``', $safetyColumn) . '`';
    $sql = 'SELECT warehouse_id, sku, days_to_cover, ma_window_days, min_avg_daily, '
        . $columnSql . ' AS safety_days FROM sku_parameters';

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
                'safety_days' => (float) $row['safety_days'],
            ];
        }
        $result->free();
    }
    return $params;
}

function getLatestStock(mysqli $mysqli, ?int $warehouseId = null, ?string $sku = null): array
{
    static $hasProductNameColumn;
    if ($hasProductNameColumn === null) {
        $hasProductNameColumn = tableColumnExists($mysqli, 'stock_snapshots', 'product_name');
    }

    $conditions = [];
    if ($warehouseId !== null && $warehouseId > 0) {
        $conditions[] = 'warehouse_id = ' . (int) $warehouseId;
    }
    if ($sku !== null && $sku !== '') {
        $escaped = $mysqli->real_escape_string($sku);
        $likePattern = "'%" . $escaped . "%'";
        if ($hasProductNameColumn) {
            $conditions[] = "(sku LIKE " . $likePattern . " OR product_name LIKE " . $likePattern . ")";
        } else {
            $conditions[] = "sku LIKE " . $likePattern;
        }
    }
    $where = '';
    if ($conditions) {
        $where = 'WHERE ' . implode(' AND ', $conditions);
    }

    $columns = 'warehouse_id, sku, quantity, snapshot_date';
    if ($hasProductNameColumn) {
        $columns .= ', product_name';
    }

    $sql = 'SELECT ' . $columns . ' '
        . 'FROM stock_snapshots '
        . $where
        . ' ORDER BY warehouse_id, sku, snapshot_date DESC, id DESC';

    logSqlQuery($sql, 'getLatestStock');

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
            if ($hasProductNameColumn) {
                $stock[$wId][$skuCode]['product_name'] = (string) ($row['product_name'] ?? '');
            }
        }
        $result->free();
    }
    return $stock;
}

function getSalesMap(mysqli $mysqli, int $lookbackDays, ?int $warehouseId = null, array|string|null $skuFilter = null): array
{
    $startDate = (new \DateTimeImmutable('today'))->modify('-' . $lookbackDays . ' days');
    $params = [$startDate->format('Y-m-d')];
    $types = 's';
    $sql = 'SELECT warehouse_id, sku, sale_date, SUM(quantity) AS quantity '
        . 'FROM sales WHERE sale_date >= ?';

    if ($warehouseId !== null && $warehouseId > 0) {
        $sql .= ' AND warehouse_id = ?';
        $params[] = $warehouseId;
        $types .= 'i';
    }
    if (is_array($skuFilter)) {
        $normalized = [];
        foreach ($skuFilter as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $normalized[$candidate] = true;
        }

        if ($normalized === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($normalized), '?'));
        $sql .= ' AND sku IN (' . $placeholders . ')';
        foreach (array_keys($normalized) as $skuCode) {
            $params[] = $skuCode;
            $types .= 's';
        }
    } elseif (is_string($skuFilter) && $skuFilter !== '') {
        $sql .= ' AND sku LIKE ?';
        $params[] = '%' . $skuFilter . '%';
        $types .= 's';
    }

    $sql .= ' GROUP BY warehouse_id, sku, sale_date';

    $logSql = $sql;
    if ($params !== []) {
        $encodedParams = json_encode($params);
        if (is_string($encodedParams)) {
            $logSql .= ' /* params: ' . $encodedParams . ' */';
        }
    }
    logSqlQuery($logSql, 'getSalesMap');

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
        'safety_days' => max(0.0, (float) ($params['safety_days'] ?? $defaults['safety_days'])),
    ];
}

function calculateDashboardData(mysqli $mysqli, array $config, array $filters = []): array
{
    $warehouseId = null;
    if (isset($filters['warehouse_id'])) {
        $candidate = (int) $filters['warehouse_id'];
        if ($candidate > 0) {
            $warehouseId = $candidate;
        }
    }
    $searchTerm = null;
    if (array_key_exists('sku', $filters)) {
        $candidate = $filters['sku'];
        if (is_string($candidate)) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $searchTerm = $candidate;
            }
        }
    }

    $warehouses = getWarehouses($mysqli);
    $warehouseParams = getWarehouseParameters($mysqli);
    $skuParams = getSkuParameters($mysqli);
    $stockMap = getLatestStock($mysqli, $warehouseId, $searchTerm);

    $matchingSkuList = null;
    if ($searchTerm !== null) {
        $matchingSkuSet = [];
        foreach ($stockMap as $items) {
            foreach ($items as $skuCode => $info) {
                if (!is_string($skuCode)) {
                    continue;
                }
                $skuCode = trim($skuCode);
                if ($skuCode === '') {
                    continue;
                }

                $productName = (string) ($info['product_name'] ?? '');
                if (
                    stripos($skuCode, $searchTerm) !== false
                    || ($productName !== '' && stripos($productName, $searchTerm) !== false)
                ) {
                    $matchingSkuSet[$skuCode] = true;
                }
            }
        }

        foreach ($skuParams as $items) {
            foreach ($items as $skuCode => $_) {
                if (!is_string($skuCode)) {
                    continue;
                }
                $skuCode = trim($skuCode);
                if ($skuCode === '') {
                    continue;
                }
                if (stripos($skuCode, $searchTerm) !== false) {
                    $matchingSkuSet[$skuCode] = true;
                }
            }
        }

        $matchingSkuList = array_keys($matchingSkuSet);
    }

    if ($searchTerm !== null) {
        if ($matchingSkuList === []) {
            $salesMap = getSalesMap($mysqli, $config['lookback_days'], $warehouseId, $searchTerm);
        } else {
            $salesMap = getSalesMap($mysqli, $config['lookback_days'], $warehouseId, $matchingSkuList);
        }
    } else {
        $salesMap = getSalesMap($mysqli, $config['lookback_days'], $warehouseId, null);
    }

    $comboKeys = [];
    $registerCombo = static function (int $wId, string $skuCode) use (&$comboKeys, $warehouseId): void {
        if ($wId <= 0 || $skuCode === '') {
            return;
        }
        if ($warehouseId !== null && $warehouseId > 0 && $wId !== $warehouseId) {
            return;
        }

        $comboKeys[$wId . '|' . $skuCode] = ['warehouse_id' => $wId, 'sku' => $skuCode];
    };

    foreach ($stockMap as $wId => $items) {
        foreach ($items as $skuCode => $_) {
            $registerCombo((int) $wId, (string) $skuCode);
        }
    }
    foreach ($salesMap as $wId => $items) {
        foreach ($items as $skuCode => $_) {
            $registerCombo((int) $wId, (string) $skuCode);
        }
    }
    foreach ($skuParams as $wId => $items) {
        foreach ($items as $skuCode => $_) {
            $registerCombo((int) $wId, (string) $skuCode);
        }
    }

    $today = new \DateTimeImmutable('today');
    $chartDaysLimit = isset($config['chart_max_days']) ? (int) $config['chart_max_days'] : 30;
    if ($chartDaysLimit < 0) {
        $chartDaysLimit = 0;
    }
    $lookbackDays = isset($config['lookback_days']) ? (int) $config['lookback_days'] : 0;
    if ($lookbackDays < 0) {
        $lookbackDays = 0;
    }
    $data = [];
    $totalReorder = 0.0;

    foreach ($comboKeys as $key => $combo) {
        $wId = $combo['warehouse_id'];
        $skuCode = $combo['sku'];
        $warehouse = $warehouses[$wId] ?? null;
        $warehouseName = '';
        $warehouseCode = '';
        if ($warehouse) {
            $warehouseName = (string) ($warehouse['name'] ?? '');
            $warehouseCode = (string) ($warehouse['code'] ?? '');
        }

        $warehouseName = trim($warehouseName);
        if ($warehouseName === '') {
            $warehouseName = 'Warehouse #' . $wId;
        }

        $params = resolveParameters($wId, $skuCode, $config['defaults'], $warehouseParams, $skuParams);
        $maWindow = $params['ma_window_days'];

        $salesByDate = $salesMap[$wId][$skuCode] ?? [];
        $totalQty = 0.0;
        $dailySeries = [];

        $chartSeriesDays = $chartDaysLimit > 0 ? $chartDaysLimit : 0;
        if ($lookbackDays > 0) {
            $chartSeriesDays = $chartSeriesDays > 0
                ? min($chartSeriesDays, $lookbackDays)
                : $lookbackDays;
        }
        if ($chartSeriesDays <= 0) {
            $chartSeriesDays = $maWindow;
        }

        $seriesHorizon = max($maWindow, $chartSeriesDays);

        for ($i = 0; $i < $seriesHorizon; $i++) {
            $date = $today->modify('-' . $i . ' days')->format('Y-m-d');
            $qty = $salesByDate[$date] ?? 0.0;
            if ($i < $chartSeriesDays) {
                $dailySeries[$date] = $qty;
            }
            if ($i < $maWindow) {
                $totalQty += $qty;
            }
        }
        if ($dailySeries !== []) {
            ksort($dailySeries);
        }
        $movingAverage = $totalQty / max(1, $maWindow);
        $effectiveAvg = $movingAverage;
        if ($params['min_avg_daily'] > 0 && $movingAverage < $params['min_avg_daily']) {
            $effectiveAvg = $params['min_avg_daily'];
        }

        $stockInfo = $stockMap[$wId][$skuCode] ?? ['quantity' => 0.0, 'snapshot_date' => null, 'product_name' => ''];
        $currentStock = (float) $stockInfo['quantity'];
        $snapshotDate = $stockInfo['snapshot_date'];
        $productName = (string) ($stockInfo['product_name'] ?? '');

        if ($searchTerm !== null) {
            $needle = $searchTerm;
            if (stripos($skuCode, $needle) === false && stripos($productName, $needle) === false) {
                continue;
            }
        }

        $targetStock = $effectiveAvg * ($params['days_to_cover'] + $params['safety_days']);
        $reorderQty = max(0.0, $targetStock - $currentStock);

        $daysOfCover = null;
        if ($effectiveAvg > 0) {
            $daysOfCover = $currentStock / $effectiveAvg;
        }

        $roundedStock = (int) round($currentStock);
        $roundedDaysOfCover = $daysOfCover !== null ? (int) round($daysOfCover) : null;
        $roundedTargetStock = (int) round($targetStock);
        $roundedReorderQty = (int) round($reorderQty);
        $roundedSafetyDays = (int) round($params['safety_days']);

        $data[] = [
            'warehouse_id' => $wId,
            'warehouse_name' => $warehouseName,
            'warehouse_code' => $warehouseCode,
            'sku' => $skuCode,
            'product_name' => $productName,
            'current_stock' => $roundedStock,
            'snapshot_date' => $snapshotDate,
            'moving_average' => round($movingAverage, 2),
            'effective_avg' => round($effectiveAvg, 2),
            'days_of_cover' => $roundedDaysOfCover,
            'target_stock' => $roundedTargetStock,
            'reorder_qty' => $roundedReorderQty,
            'safety_days' => $roundedSafetyDays,
            'days_to_cover' => $params['days_to_cover'],
            'ma_window_days' => $params['ma_window_days'],
            'min_avg_daily' => $params['min_avg_daily'],
            'daily_series' => $dailySeries,
        ];

        $totalReorder += $roundedReorderQty;
    }

    usort($data, function ($a, $b) {
        return strcmp($a['warehouse_name'], $b['warehouse_name']) ?: strcmp($a['sku'], $b['sku']);
    });

    return [
        'data' => $data,
        'summary' => [
            'total_items' => count($data),
            'total_reorder_qty' => (int) round($totalReorder),
        ],
    ];
}

/**
 * Insert or update a warehouse record.
 *
 * @return array{id:int, created:bool}
 */
function upsertWarehouse(mysqli $mysqli, string $name): array
{
    $name = trim($name);
    if ($name === '') {
        return ['id' => 0, 'created' => false];
    }

    $stmt = $mysqli->prepare('SELECT id FROM warehouses WHERE name = ?');
    if (!$stmt) {
        return ['id' => 0, 'created' => false];
    }

    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->bind_result($id);
    if ($stmt->fetch()) {
        $stmt->close();
        return ['id' => (int) $id, 'created' => false];
    }
    $stmt->close();

    $insert = $mysqli->prepare('INSERT INTO warehouses (name) VALUES (?)');
    if (!$insert) {
        return ['id' => 0, 'created' => false];
    }

    $insert->bind_param('s', $name);
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
        $required = ['warehouse_name', 'sku', 'sale_date', 'quantity'];
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
            $warehouseName = trim((string) $row[$index['warehouse_name']]);
            $skuRaw = trim((string) $row[$index['sku']]);
            $saleDateRaw = trim((string) $row[$index['sale_date']]);
            $quantityValue = normalizeCsvNumber($row[$index['quantity']] ?? null);
            if ($warehouseName === '' || $skuRaw === '' || $saleDateRaw === '' || $quantityValue === null) {
                continue;
            }

            $normalizedDate = normalizeDateString($saleDateRaw);
            if ($normalizedDate === null) {
                continue;
            }
            $saleDateParam = $normalizedDate;

            $warehouseResult = upsertWarehouse($mysqli, $warehouseName);
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
        if (isset($columnMap['product_name'])) {
            $idx = (int) $columnMap['product_name'];
            if ($idx >= 0 && $idx < $columnCount) {
                $index['product_name'] = $idx;
            }
        }
        if ($snapshotOverride === null && !isset($index['snapshot_date'])) {
            fclose($handle);
            return ['success' => false, 'message' => 'Please provide a snapshot date.'];
        }
    } else {
        $columns = array_map('strtolower', $header);
        $required = ['warehouse_name', 'sku', 'snapshot_date', 'quantity'];
        foreach ($required as $col) {
            if (!in_array($col, $columns, true)) {
                fclose($handle);
                return ['success' => false, 'message' => 'Missing required column: ' . $col];
            }
        }

        $index = array_flip($columns);
        if (!isset($index['product_name']) && isset($index['name'])) {
            $index['product_name'] = $index['name'];
        }
    }

    $insert = $mysqli->prepare('INSERT INTO stock_snapshots (warehouse_id, sku, snapshot_date, quantity, product_name) VALUES (?, ?, ?, ?, ?)');
    if (!$insert) {
        fclose($handle);
        return ['success' => false, 'message' => 'Failed to prepare stock insert statement.'];
    }
    $warehouseIdParam = $useMapping ? (int) $warehouseId : 0;
    $skuParam = '';
    $snapshotDateParam = $snapshotOverride ?? '';
    $quantityParam = 0.0;
    $productNameParam = '';
    $insert->bind_param('issds', $warehouseIdParam, $skuParam, $snapshotDateParam, $quantityParam, $productNameParam);

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
            if (isset($index['product_name'])) {
                $nameRaw = $row[$index['product_name']] ?? '';
                $productNameParam = is_string($nameRaw) ? trim($nameRaw) : (string) $nameRaw;
            } else {
                $productNameParam = '';
            }
        } else {
            if (count($row) !== $columnCount) {
                continue;
            }
            $warehouseName = trim((string) $row[$index['warehouse_name']]);
            $skuRaw = trim((string) $row[$index['sku']]);
            $snapshotRaw = trim((string) $row[$index['snapshot_date']]);
            $quantityValue = normalizeCsvNumber($row[$index['quantity']] ?? null);
            if ($warehouseName === '' || $skuRaw === '' || $snapshotRaw === '' || $quantityValue === null) {
                continue;
            }

            $normalizedDate = normalizeDateString($snapshotRaw);
            if ($normalizedDate === null) {
                continue;
            }
            $snapshotDateParam = $normalizedDate;

            $warehouseResult = upsertWarehouse($mysqli, $warehouseName);
            $warehouseIdParam = $warehouseResult['id'];
            if ($warehouseIdParam <= 0) {
                continue;
            }
            $skuParam = $skuRaw;
            $quantityParam = $quantityValue;
            if (isset($index['product_name'])) {
                $nameRaw = $row[$index['product_name']] ?? '';
                $productNameParam = is_string($nameRaw) ? trim($nameRaw) : (string) $nameRaw;
            } else {
                $productNameParam = '';
            }
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
    $safety = max(0.0, (float) $values['safety_days']);

    if ($sku === null || $sku === '') {

        $column = resolveSafetyColumn($mysqli, 'warehouse_parameters');
        $columnSql = '`' . str_replace('`', '``', $column) . '`';
        $sql = 'INSERT INTO warehouse_parameters (warehouse_id, days_to_cover, ma_window_days, min_avg_daily, ' . $columnSql . ') '
            . 'VALUES (?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE days_to_cover = VALUES(days_to_cover), ma_window_days = VALUES(ma_window_days), '
            . 'min_avg_daily = VALUES(min_avg_daily), ' . $columnSql . ' = VALUES(' . $columnSql . ')';

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iiidd', $warehouseId, $days, $ma, $min, $safety);
    } else {

        $column = resolveSafetyColumn($mysqli, 'sku_parameters');
        $columnSql = '`' . str_replace('`', '``', $column) . '`';
        $sql = 'INSERT INTO sku_parameters (warehouse_id, sku, days_to_cover, ma_window_days, min_avg_daily, ' . $columnSql . ') '
            . 'VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE days_to_cover = VALUES(days_to_cover), ma_window_days = VALUES(ma_window_days), '
            . 'min_avg_daily = VALUES(min_avg_daily), ' . $columnSql . ' = VALUES(' . $columnSql . ')';

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
