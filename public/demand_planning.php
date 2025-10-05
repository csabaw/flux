<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$warehouses = getWarehouses($mysqli);

$defaultSafety   = 14;
$defaultShipping = 14;
$defaultBuffer   = 28;
$defaultAlpha    = 0.2;
$defaultStart    = (new DateTimeImmutable('first day of -5 months'))->format('Y-m-01');
$defaultEnd      = (new DateTimeImmutable('today'))->format('Y-m-d');

$safetyDays   = isset($_POST['safety_days']) ? max(0, (int) $_POST['safety_days']) : $defaultSafety;
$shippingDays = isset($_POST['shipping_days']) ? max(0, (int) $_POST['shipping_days']) : $defaultShipping;
$bufferDays   = isset($_POST['reorder_days']) ? max(0, (int) $_POST['reorder_days']) : $defaultBuffer;
$alpha        = isset($_POST['alpha']) ? max(0.0, min(1.0, (float) $_POST['alpha'])) : $defaultAlpha;
$periodStart  = $_POST['period_start'] ?? $defaultStart;
$periodEnd    = $_POST['period_end'] ?? $defaultEnd;
$warehouseId  = isset($_POST['warehouse_id']) ? (int) $_POST['warehouse_id'] : 0;

$errors = [];

$selectedWarehouseId = null;
if ($warehouseId > 0) {
    if (isset($warehouses[$warehouseId])) {
        $selectedWarehouseId = $warehouseId;
    } else {
        $errors[] = 'Selected warehouse not found. Showing all warehouses instead.';
    }
}

try {
    $dtStart = new DateTimeImmutable($periodStart);
} catch (Exception $e) {
    $errors[] = 'Start date is invalid. Using default start date.';
    $dtStart = new DateTimeImmutable($defaultStart);
    $periodStart = $dtStart->format('Y-m-d');
}

try {
    $dtEnd = new DateTimeImmutable($periodEnd);
} catch (Exception $e) {
    $errors[] = 'End date is invalid. Using default end date.';
    $dtEnd = new DateTimeImmutable($defaultEnd);
    $periodEnd = $dtEnd->format('Y-m-d');
}

if ($dtEnd < $dtStart) {
    [$dtStart, $dtEnd] = [$dtEnd, $dtStart];
    [$periodStart, $periodEnd] = [$dtStart->format('Y-m-d'), $dtEnd->format('Y-m-d')];
}

$periodDays = max(1, $dtStart->diff($dtEnd)->days + 1);
$coverageDays = $safetyDays + $shippingDays + $bufferDays;

$stockMap = getLatestStock($mysqli, $selectedWarehouseId, null);

$salesByWarehouseSkuDate = [];
$comboKeys = [];

if (!empty($stockMap)) {
    foreach ($stockMap as $wId => $items) {
        foreach ($items as $sku => $_) {
            $comboKeys[$wId . '|' . $sku] = ['warehouse_id' => $wId, 'sku' => $sku];
        }
    }
}

$sql = 'SELECT warehouse_id, sku, sale_date, SUM(quantity) AS qty '
    . 'FROM sales WHERE sale_date BETWEEN ? AND ?';
$params = [$periodStart, $periodEnd];
$types = 'ss';
if ($selectedWarehouseId !== null) {
    $sql .= ' AND warehouse_id = ?';
    $params[] = $selectedWarehouseId;
    $types .= 'i';
}
$sql .= ' GROUP BY warehouse_id, sku, sale_date ORDER BY sale_date';

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $wId = (int) $row['warehouse_id'];
        $sku = (string) $row['sku'];
        $date = (string) $row['sale_date'];
        $qty = (float) $row['qty'];
        if (!isset($salesByWarehouseSkuDate[$wId])) {
            $salesByWarehouseSkuDate[$wId] = [];
        }
        if (!isset($salesByWarehouseSkuDate[$wId][$sku])) {
            $salesByWarehouseSkuDate[$wId][$sku] = [];
        }
        $salesByWarehouseSkuDate[$wId][$sku][$date] = $qty;
        $comboKeys[$wId . '|' . $sku] = ['warehouse_id' => $wId, 'sku' => $sku];
    }
    $stmt->close();
}

$results = [];
$totalReorder = 0.0;
$today = new DateTimeImmutable('today');

foreach ($comboKeys as $combo) {
    $wId = $combo['warehouse_id'];
    $sku = $combo['sku'];
    $warehouse = $warehouses[$wId] ?? null;
    if ($warehouse === null) {
        continue;
    }

    $stockInfo = $stockMap[$wId][$sku] ?? ['quantity' => 0.0, 'snapshot_date' => null];
    $onHand = (float) $stockInfo['quantity'];

    $dailySales = $salesByWarehouseSkuDate[$wId][$sku] ?? [];

    $periodSold = 0.0;
    $forecast = null;
    for ($cursor = $dtStart; $cursor <= $dtEnd; $cursor = $cursor->modify('+1 day')) {
        $dateKey = $cursor->format('Y-m-d');
        $qty = $dailySales[$dateKey] ?? 0.0;
        $periodSold += $qty;
        if ($forecast === null) {
            $forecast = $qty;
        } else {
            $forecast = $alpha * $qty + (1 - $alpha) * $forecast;
        }
    }

    $avgPerDay = $periodSold / $periodDays;
    $speed = $forecast ?? 0.0;
    if ($speed < 0.01) {
        $speed = 0.0;
    }

    $reorderTarget = (float) $coverageDays * $speed;
    $reorderQty = $reorderTarget > $onHand ? ceil($reorderTarget - $onHand) : 0.0;

    $daysCover = null;
    $stockOutDate = '—';
    if ($speed > 0) {
        $daysCoverFloat = $onHand / $speed;
        if ($daysCoverFloat < 0) {
            $daysCoverFloat = 0.0;
        }
        $daysCover = $daysCoverFloat;
        $floorDays = (int) floor($daysCoverFloat);
        $stockOutDate = $today->add(new DateInterval('P' . $floorDays . 'D'))->format('Y-m-d');
    }

    $belowSafety = $speed > 0 ? ($onHand < $safetyDays * $speed) : false;

    $results[] = [
        'warehouse_id' => $wId,
        'warehouse_code' => (string) $warehouse['code'],
        'warehouse_name' => (string) $warehouse['name'],
        'sku' => $sku,
        'avgPerDay' => $avgPerDay,
        'speed' => $speed,
        'stock' => $onHand,
        'inTransit' => 0,
        'pendingQty' => 0,
        'netAvailable' => $onHand,
        'reorder' => $reorderQty,
        'daysCover' => $daysCover ?? INF,
        'stockOutDate' => $stockOutDate,
        'belowSafety' => $belowSafety,
        'coverageDays' => $coverageDays,
        'periodSold' => $periodSold,
    ];

    $totalReorder += $reorderQty;
}

usort($results, static function (array $a, array $b): int {
    return $b['reorder'] <=> $a['reorder']
        ?: strcmp($a['warehouse_code'], $b['warehouse_code'])
        ?: strcmp($a['sku'], $b['sku']);
});

$totalItems = count($results);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demand Planning</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
    <style>
        body { background: #fafbfc; }
        .legend-badge { margin-right: 1rem; }
        .table-responsive { max-height: 640px; overflow-y: auto; position: relative; }
        #planning thead th { position: sticky; top: 0; z-index: 10; background: #343a40; color: #fff; }
    </style>
</head>
<body class="py-4">
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="text-primary">Demand Planning</h1>
        <a href="index.php" class="btn btn-outline-secondary">&larr; Back to Dashboard</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-warning">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="alert alert-light border">
        <span class="legend-badge badge bg-danger">Red</span> Below safety stock &nbsp;
        <span class="legend-badge badge bg-warning text-dark">Yellow</span> Cover &lt; total days &nbsp;
        <span class="legend-badge badge bg-success">Green</span> Cover ≥ total days
    </div>

    <div class="card mb-4 p-4">
        <form method="post" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label for="warehouse_id" class="form-label">Warehouse</label>
                <select name="warehouse_id" id="warehouse_id" class="form-select">
                    <option value="0">All Warehouses</option>
                    <?php foreach ($warehouses as $id => $warehouse): ?>
                        <option value="<?= (int) $id ?>" <?= $selectedWarehouseId === (int) $id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($warehouse['code'] . ' — ' . $warehouse['name'], ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="safety_days" class="form-label">Safety days</label>
                <input type="number" min="0" class="form-control" name="safety_days" id="safety_days" value="<?= htmlspecialchars((string) $safetyDays, ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <label for="shipping_days" class="form-label">Shipping days</label>
                <input type="number" min="0" class="form-control" name="shipping_days" id="shipping_days" value="<?= htmlspecialchars((string) $shippingDays, ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <label for="reorder_days" class="form-label">Buffer days</label>
                <input type="number" min="0" class="form-control" name="reorder_days" id="reorder_days" value="<?= htmlspecialchars((string) $bufferDays, ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <label for="alpha" class="form-label">Alpha (EWMA)</label>
                <input type="number" step="0.01" min="0" max="1" class="form-control" name="alpha" id="alpha" value="<?= htmlspecialchars((string) $alpha, ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <label for="period_start" class="form-label">Start date</label>
                <input type="date" class="form-control" name="period_start" id="period_start" value="<?= htmlspecialchars($periodStart, ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <label for="period_end" class="form-label">End date</label>
                <input type="date" class="form-control" name="period_end" id="period_end" value="<?= htmlspecialchars($periodEnd, ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Calculate</button>
            </div>
        </form>
    </div>

    <div class="card p-4">
        <p class="text-muted mb-3">
            Period: <strong><?= htmlspecialchars($periodStart, ENT_QUOTES) ?> — <?= htmlspecialchars($periodEnd, ENT_QUOTES) ?></strong>
            (<?= $periodDays ?> days) |
            Items: <strong><?= $totalItems ?></strong> |
            Total Reorder: <strong><?= number_format($totalReorder, 2) ?></strong>
        </p>
        <div class="table-responsive">
            <table id="planning" class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>Warehouse</th>
                    <th>SKU</th>
                    <th>Avg/day (hist.)</th>
                    <th>Speed/day (EWMA)</th>
                    <th>Days Cover</th>
                    <th>Stock-Out</th>
                    <th>On Hand</th>
                    <th>Reorder</th>
                    <th>Sold (period)</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $item):
                    if ($item['speed'] == 0.0) {
                        $rowClass = 'table-success';
                    } elseif ($item['belowSafety']) {
                        $rowClass = 'table-danger';
                    } else {
                        $rowClass = ($item['daysCover'] < $item['coverageDays']) ? 'table-warning' : 'table-success';
                    }
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td><?= htmlspecialchars($item['warehouse_code'] . ' — ' . $item['warehouse_name'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($item['sku'], ENT_QUOTES) ?></td>
                        <td><?= number_format($item['avgPerDay'], 2) ?></td>
                        <td><?= number_format($item['speed'], 2) ?></td>
                        <td><?= is_finite($item['daysCover']) ? number_format($item['daysCover'], 2) : '∞' ?></td>
                        <td><?= htmlspecialchars($item['stockOutDate'], ENT_QUOTES) ?></td>
                        <td><?= number_format($item['stock'], 2) ?></td>
                        <td><?= number_format($item['reorder'], 2) ?></td>
                        <td><?= number_format($item['periodSold'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script>
    $(function () {
        $('#planning').DataTable({
            pageLength: 200,
            ordering: true,
            order: [[7, 'desc']],
            dom: 'Bfrtip',
            buttons: ['csv', 'excel', 'print'],
            stripeClasses: []
        });
    });
</script>
</body>
</html>
