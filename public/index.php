<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout();
    header('Location: index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        if (attempt_login($username, $password)) {
            header('Location: index.php');
            exit;
        }
        $errors[] = 'Invalid credentials. Please try again.';
    }
}

$loggedIn = is_logged_in();

$warehouses = [];
$dataSet = [
    'data' => [],
    'summary' => [
        'total_items' => 0,
        'total_reorder_qty' => 0.0,
        'tsv_short_days' => 7,
        'tsv_long_days' => 30,
        'ewma_span' => 14,
        'lookback_days' => $config['lookback_days'],
    ],
];
$filtersUsed = [
    'warehouse_id' => '',
    'sku' => '',
    'tsv_short' => '7',
    'tsv_long' => '30',
    'ewma_span' => '14',
];

if ($loggedIn) {
    $warehouses = getWarehouses($mysqli);

    $selectedWarehouseId = null;
    if (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '') {
        $candidate = (int) $_GET['warehouse_id'];
        if ($candidate > 0) {
            $selectedWarehouseId = $candidate;
        }
    }

    $skuFilter = trim((string) ($_GET['sku'] ?? ''));
    $tsvShort = isset($_GET['tsv_short']) && $_GET['tsv_short'] !== '' ? max(1, (int) $_GET['tsv_short']) : 7;
    $tsvLongInput = isset($_GET['tsv_long']) && $_GET['tsv_long'] !== '' ? max(1, (int) $_GET['tsv_long']) : 30;
    $tsvLong = max($tsvShort, $tsvLongInput);
    $ewmaSpan = isset($_GET['ewma_span']) && $_GET['ewma_span'] !== '' ? max(1, (int) $_GET['ewma_span']) : 14;

    $filtersUsed = [
        'warehouse_id' => $selectedWarehouseId !== null ? (string) $selectedWarehouseId : '',
        'sku' => $skuFilter,
        'tsv_short' => (string) $tsvShort,
        'tsv_long' => (string) $tsvLong,
        'ewma_span' => (string) $ewmaSpan,
    ];

    $calcFilters = [
        'tsv_short' => $tsvShort,
        'tsv_long' => $tsvLong,
        'ewma_span' => $ewmaSpan,
    ];
    if ($selectedWarehouseId !== null) {
        $calcFilters['warehouse_id'] = $selectedWarehouseId;
    }
    if ($skuFilter !== '') {
        $calcFilters['sku'] = $skuFilter;
    }

    $dataSet = calculateDashboardData($mysqli, $config, $calcFilters);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demand Planning Overview</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <span class="navbar-brand">Demand Planning</span>
        <?php if ($loggedIn): ?>
        <div class="d-flex">
            <a class="btn btn-outline-light btn-sm" href="?action=logout">Logout</a>
        </div>
        <?php endif; ?>
    </div>
</nav>
<div class="container py-4">
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($error, ENT_QUOTES) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>

    <?php if (!$loggedIn): ?>
        <div class="row justify-content-center">
            <div class="col-md-5 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title text-center mb-4">Admin Login</h5>
                        <form method="post" novalidate>
                            <input type="hidden" name="action" value="login">
                            <div class="mb-3">
                                <label class="form-label" for="username">Username</label>
                                <input class="form-control" type="text" id="username" name="username" required autofocus>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="password">Password</label>
                                <input class="form-control" type="password" id="password" name="password" required>
                            </div>
                            <button class="btn btn-primary w-100" type="submit">Log in</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php $summary = $dataSet['summary']; ?>
        <div class="row mb-4">
            <div class="col-lg-10 col-xl-9">
                <h1 class="h3 mb-2">Demand Planning Overview</h1>
                <p class="text-muted mb-0">Adjust the filters to evaluate demand velocity, EWMA smoothing, and reorder targets.</p>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get" novalidate>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label" for="warehouse_id">Warehouse</label>
                        <select class="form-select" id="warehouse_id" name="warehouse_id">
                            <option value="">All warehouses</option>
                            <?php foreach ($warehouses as $id => $warehouse): ?>
                                <?php
                                    $selected = $filtersUsed['warehouse_id'] !== '' && (int) $filtersUsed['warehouse_id'] === (int) $id;
                                    $label = trim((string) ($warehouse['code'] ?? ''));
                                    $name = trim((string) ($warehouse['name'] ?? ''));
                                    if ($name !== '' && $name !== $label) {
                                        $label .= ' · ' . $name;
                                    }
                                ?>
                                <option value="<?= (int) $id ?>"<?= $selected ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($label, ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label" for="sku">SKU</label>
                        <input class="form-control" type="text" id="sku" name="sku" value="<?= htmlspecialchars($filtersUsed['sku'], ENT_QUOTES) ?>" placeholder="Optional">
                    </div>
                    <div class="col-md-2 col-lg-2">
                        <label class="form-label" for="tsv_short">TSV (short, days)</label>
                        <input class="form-control" type="number" min="1" id="tsv_short" name="tsv_short" value="<?= htmlspecialchars($filtersUsed['tsv_short'], ENT_QUOTES) ?>">
                    </div>
                    <div class="col-md-2 col-lg-2">
                        <label class="form-label" for="tsv_long">TSV (long, days)</label>
                        <input class="form-control" type="number" min="1" id="tsv_long" name="tsv_long" value="<?= htmlspecialchars($filtersUsed['tsv_long'], ENT_QUOTES) ?>">
                    </div>
                    <div class="col-md-2 col-lg-2">
                        <label class="form-label" for="ewma_span">EWMA span (days)</label>
                        <input class="form-control" type="number" min="1" id="ewma_span" name="ewma_span" value="<?= htmlspecialchars($filtersUsed['ewma_span'], ENT_QUOTES) ?>">
                    </div>
                    <div class="col-md-12 col-lg-2 d-grid">
                        <button class="btn btn-primary" type="submit">Update view</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small mb-2">Total items</div>
                        <div class="h4 mb-0"><?= number_format((int) ($summary['total_items'] ?? 0)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small mb-2">Recommended reorder</div>
                        <div class="h4 mb-1"><?= number_format((float) ($summary['total_reorder_qty'] ?? 0.0), 2) ?></div>
                        <div class="small text-muted">Units across visible SKUs</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small mb-2">Smoothing windows</div>
                        <div class="small mb-1">TSV <?= (int) ($summary['tsv_short_days'] ?? 0) ?>d / <?= (int) ($summary['tsv_long_days'] ?? 0) ?>d</div>
                        <div class="small text-muted mb-0">EWMA span <?= (int) ($summary['ewma_span'] ?? 0) ?>d · Lookback <?= (int) ($summary['lookback_days'] ?? 0) ?>d</div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($dataSet['data'])): ?>
            <div class="alert alert-light border shadow-sm" role="status">
                No demand metrics available for the selected filters.
            </div>
        <?php else: ?>
            <div class="table-responsive shadow-sm rounded-3 bg-white">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Warehouse</th>
                            <th scope="col">SKU</th>
                            <th scope="col">Snapshot</th>
                            <th scope="col" class="text-end">Current stock</th>
                            <th scope="col" class="text-end">TSV <?= (int) ($summary['tsv_short_days'] ?? 0) ?>d</th>
                            <th scope="col" class="text-end">TSV <?= (int) ($summary['tsv_long_days'] ?? 0) ?>d</th>
                            <th scope="col" class="text-end">EWMA</th>
                            <th scope="col" class="text-end">Days of cover</th>
                            <th scope="col" class="text-end">Target stock</th>
                            <th scope="col" class="text-end">Reorder qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dataSet['data'] as $row): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars((string) $row['warehouse_code'], ENT_QUOTES) ?></div>
                                <?php if (!empty($row['warehouse_name'])): ?>
                                <div class="text-muted small"><?= htmlspecialchars((string) $row['warehouse_name'], ENT_QUOTES) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold text-nowrap"><?= htmlspecialchars((string) $row['sku'], ENT_QUOTES) ?></td>
                            <td class="text-nowrap"><?= $row['snapshot_date'] ? htmlspecialchars((string) $row['snapshot_date'], ENT_QUOTES) : '&mdash;' ?></td>
                            <td class="text-end"><?= number_format((float) $row['current_stock'], 2) ?></td>
                            <td class="text-end"><?= number_format((float) $row['tsv_short'], 2) ?></td>
                            <td class="text-end"><?= number_format((float) $row['tsv_long'], 2) ?></td>
                            <td class="text-end"><?= number_format((float) $row['ewma'], 2) ?></td>
                            <td class="text-end">
                                <?= $row['days_of_cover'] !== null ? number_format((float) $row['days_of_cover'], 2) : '&mdash;' ?>
                            </td>
                            <td class="text-end"><?= number_format((float) $row['target_stock'], 2) ?></td>
                            <td class="text-end fw-semibold"><?= number_format((float) $row['reorder_qty'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
