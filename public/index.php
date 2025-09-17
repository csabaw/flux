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

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        if (attempt_login($username, $password)) {
            header('Location: index.php');
            exit;
        }
        $errors[] = 'Invalid credentials. Please try again.';
    } elseif (!is_logged_in()) {
        $errors[] = 'Please log in to continue.';
    } else {
        switch ($action) {
            case 'upload_sales':
                if (isset($_FILES['sales_csv']) && $_FILES['sales_csv']['error'] === UPLOAD_ERR_OK) {
                    $result = importSalesCsv($mysqli, $_FILES['sales_csv']['tmp_name']);
                    if ($result['success']) {
                        $messages[] = $result['message'];
                    } else {
                        $errors[] = $result['message'];
                    }
                } else {
                    $errors[] = 'Please choose a CSV file to upload.';
                }
                break;
            case 'upload_stock':
                if (isset($_FILES['stock_csv']) && $_FILES['stock_csv']['error'] === UPLOAD_ERR_OK) {
                    $result = importStockCsv($mysqli, $_FILES['stock_csv']['tmp_name']);
                    if ($result['success']) {
                        $messages[] = $result['message'];
                    } else {
                        $errors[] = $result['message'];
                    }
                } else {
                    $errors[] = 'Please choose a CSV file to upload.';
                }
                break;
            case 'save_parameters':
                $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
                $sku = trim($_POST['sku'] ?? '');
                if ($warehouseId <= 0) {
                    $errors[] = 'Please choose a warehouse.';
                    break;
                }
                $values = [
                    'days_to_cover' => $_POST['days_to_cover'] ?? 0,
                    'ma_window_days' => $_POST['ma_window_days'] ?? 0,
                    'min_avg_daily' => $_POST['min_avg_daily'] ?? 0,
                    'safety_stock' => $_POST['safety_stock'] ?? 0,
                ];
                if (saveParameters($mysqli, $warehouseId, $values, $sku ?: null)) {
                    $messages[] = $sku !== '' ? 'SKU override saved.' : 'Warehouse parameters saved.';
                } else {
                    $errors[] = 'Unable to save parameters. Please try again.';
                }
                break;
            case 'delete_sku_param':
                $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
                $sku = trim($_POST['sku'] ?? '');
                if ($warehouseId <= 0 || $sku === '') {
                    $errors[] = 'Invalid warehouse or SKU.';
                    break;
                }
                if (deleteSkuParameter($mysqli, $warehouseId, $sku)) {
                    $messages[] = 'SKU override removed.';
                } else {
                    $errors[] = 'Unable to remove SKU override.';
                }
                break;
            case 'add_warehouse':
                $code = strtoupper(trim($_POST['warehouse_code'] ?? ''));
                $name = trim($_POST['warehouse_name'] ?? '');
                if ($code === '') {
                    $errors[] = 'Warehouse code is required.';
                    break;
                }
                $codeLength = function_exists('mb_strlen') ? mb_strlen($code) : strlen($code);
                if ($codeLength > 50) {
                    $errors[] = 'Warehouse code must be 50 characters or fewer.';
                    break;
                }
                if ($name !== '') {
                    $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
                    if ($nameLength > 120) {
                        $errors[] = 'Warehouse name must be 120 characters or fewer.';
                        break;
                    }
                }
                $result = upsertWarehouse($mysqli, $code, $name ?: null);
                if ($result['id'] <= 0) {
                    $errors[] = 'Unable to save warehouse. Please try again.';
                    break;
                }
                $messages[] = $result['created'] ? 'Warehouse created.' : 'Warehouse updated.';
                break;
        }
    }
}

$warehouses = getWarehouses($mysqli);
$warehouseParams = getWarehouseParameters($mysqli);
$skuParams = getSkuParameters($mysqli);
$defaults = $config['defaults'];

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Multi-Warehouse Demand Planning</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <span class="navbar-brand">Demand Planning Dashboard</span>
        <?php if (is_logged_in()): ?>
        <div class="d-flex">
            <a class="btn btn-outline-light btn-sm" href="?action=logout">Logout</a>
        </div>
        <?php endif; ?>
    </div>
</nav>
<div class="container my-4">
    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message, ENT_QUOTES) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($error, ENT_QUOTES) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>

    <?php if (!is_logged_in()): ?>
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
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
                            <button class="btn btn-primary w-100" type="submit">Sign in</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <ul class="nav nav-pills mb-4" id="dashboardTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-section="dashboard" type="button">Dashboard</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-section="imports" type="button">Data Import</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-section="warehouses" type="button">Warehouses</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-section="parameters" type="button">Parameters</button>
            </li>
        </ul>

        <section id="section-dashboard">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card shadow-sm h-100">
                        <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
                            <h5 class="mb-0">Demand &amp; Replenishment</h5>
                            <div class="d-flex flex-wrap gap-2">
                                <select class="form-select form-select-sm" id="warehouseFilter">
                                    <option value="">All Warehouses</option>
                                    <?php foreach ($warehouses as $warehouse): ?>
                                        <option value="<?= (int) $warehouse['id'] ?>"><?= htmlspecialchars($warehouse['code'] . ' · ' . $warehouse['name'], ENT_QUOTES) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" class="form-control form-control-sm" id="skuFilter" placeholder="Filter by SKU">
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped align-middle" id="demandTable" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th>Warehouse</th>
                                            <th>SKU</th>
                                            <th>Stock</th>
                                            <th>Snapshot</th>
                                            <th>Moving Avg</th>
                                            <th>Days of Cover</th>
                                            <th>Target Stock</th>
                                            <th>Reorder Qty</th>
                                            <th>Safety Stock</th>
                                            <th class="d-none">Key</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Summary</h5>
                        </div>
                        <div class="card-body">
                            <p class="mb-1"><strong>Total Items:</strong> <span id="summaryItems">0</span></p>
                            <p class="mb-3"><strong>Total Reorder Qty:</strong> <span id="summaryReorder">0</span></p>
                            <canvas id="reorderChart" height="220"></canvas>
                        </div>
                    </div>
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <h5 class="mb-0">Rolling Demand (Last Window)</h5>
                        </div>
                        <div class="card-body small">
                            <p class="text-muted">Select a row in the table to visualize its recent demand trend.</p>
                            <canvas id="trendChart" height="220"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="section-imports" class="d-none">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Upload Daily Sales CSV</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Columns required: <code>warehouse_code, sku, sale_date (YYYY-MM-DD), quantity</code>.</p>
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="upload_sales">
                                <div class="mb-3">
                                    <input class="form-control" type="file" name="sales_csv" accept=".csv" required>
                                </div>
                                <button class="btn btn-primary" type="submit">Import Sales</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Upload Stock Snapshot CSV</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Columns required: <code>warehouse_code, sku, snapshot_date (YYYY-MM-DD), quantity</code>.</p>
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="upload_stock">
                                <div class="mb-3">
                                    <input class="form-control" type="file" name="stock_csv" accept=".csv" required>
                                </div>
                                <button class="btn btn-primary" type="submit">Import Stock</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="section-warehouses" class="d-none">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Add Warehouse</h5>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="add_warehouse">
                                <div class="mb-3">
                                    <label class="form-label" for="warehouse_code">Code</label>
                                    <input class="form-control" type="text" id="warehouse_code" name="warehouse_code" maxlength="50" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="warehouse_name">Name</label>
                                    <input class="form-control" type="text" id="warehouse_name" name="warehouse_name" maxlength="120" placeholder="Optional">
                                </div>
                                <p class="form-text">Codes must be unique. Warehouses are also created automatically during CSV imports.</p>
                                <button class="btn btn-primary" type="submit">Save Warehouse</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Existing Warehouses</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Name</th>
                                            <th>Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($warehouses as $warehouse):
                                            $createdLabel = '—';
                                            if (!empty($warehouse['created_at'])) {
                                                try {
                                                    $createdLabel = (new \DateTimeImmutable($warehouse['created_at']))->format('Y-m-d H:i');
                                                } catch (\Exception $e) {
                                                    $createdLabel = $warehouse['created_at'];
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($warehouse['code'], ENT_QUOTES) ?></td>
                                            <td><?= htmlspecialchars($warehouse['name'], ENT_QUOTES) ?></td>
                                            <td><?= htmlspecialchars($createdLabel, ENT_QUOTES) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($warehouses)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted py-3">No warehouses yet.</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="section-parameters" class="d-none">
            <div class="row g-4">
                <div class="col-12 col-xl-8 col-xxl-7">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Edit Parameters</h5>
                        </div>
                        <div class="card-body">
                            <form class="row g-3" method="post">
                                <input type="hidden" name="action" value="save_parameters">
                                <div class="col-md-6">
                                    <label class="form-label" for="paramWarehouse">Warehouse</label>
                                    <select class="form-select" id="paramWarehouse" name="warehouse_id" required>
                                        <option value="">Select warehouse</option>
                                        <?php foreach ($warehouses as $warehouse): ?>
                                            <option value="<?= (int) $warehouse['id'] ?>"><?= htmlspecialchars($warehouse['code'] . ' · ' . $warehouse['name'], ENT_QUOTES) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="paramSku">SKU Override <span class="text-muted">(optional)</span></label>
                                    <input class="form-control" type="text" id="paramSku" name="sku" placeholder="Leave blank for warehouse default">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="paramDaysCover">Days to Cover</label>
                                    <input class="form-control" type="number" min="1" id="paramDaysCover" name="days_to_cover" value="<?= (int) $defaults['days_to_cover'] ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="paramWindow">MA Window (days)</label>
                                    <input class="form-control" type="number" min="1" id="paramWindow" name="ma_window_days" value="<?= (int) $defaults['ma_window_days'] ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="paramMinAvg">Min Avg Daily</label>
                                    <input class="form-control" type="number" step="0.01" min="0" id="paramMinAvg" name="min_avg_daily" value="<?= htmlspecialchars((string) $defaults['min_avg_daily'], ENT_QUOTES) ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="paramSafety">Safety Stock</label>
                                    <input class="form-control" type="number" step="0.01" min="0" id="paramSafety" name="safety_stock" value="<?= htmlspecialchars((string) $defaults['safety_stock'], ENT_QUOTES) ?>" required>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-primary" type="submit">Save Parameters</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mt-1">
                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Warehouse Parameters</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Warehouse</th>
                                            <th>Days to Cover</th>
                                            <th>MA Window</th>
                                            <th>Min Avg</th>
                                            <th>Safety</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($warehouses as $warehouse):
                                            $params = $warehouseParams[$warehouse['id']] ?? $defaults;
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($warehouse['code'] . ' · ' . $warehouse['name'], ENT_QUOTES) ?></td>
                                            <td><?= (int) $params['days_to_cover'] ?></td>
                                            <td><?= (int) $params['ma_window_days'] ?></td>
                                            <td><?= htmlspecialchars(number_format((float) $params['min_avg_daily'], 2), ENT_QUOTES) ?></td>
                                            <td><?= htmlspecialchars(number_format((float) $params['safety_stock'], 2), ENT_QUOTES) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($warehouses)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-3">No warehouses yet.</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0">SKU Overrides</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Warehouse</th>
                                            <th>SKU</th>
                                            <th>Days to Cover</th>
                                            <th>MA Window</th>
                                            <th>Min Avg</th>
                                            <th>Safety</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($skuParams)):
                                            foreach ($skuParams as $warehouseId => $items):
                                                $warehouse = $warehouses[$warehouseId] ?? null;
                                                if (!$warehouse) {
                                                    continue;
                                                }
                                                foreach ($items as $skuCode => $params): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($warehouse['code'], ENT_QUOTES) ?></td>
                                                        <td><?= htmlspecialchars($skuCode, ENT_QUOTES) ?></td>
                                                        <td><?= (int) $params['days_to_cover'] ?></td>
                                                        <td><?= (int) $params['ma_window_days'] ?></td>
                                                        <td><?= htmlspecialchars(number_format((float) $params['min_avg_daily'], 2), ENT_QUOTES) ?></td>
                                                        <td><?= htmlspecialchars(number_format((float) $params['safety_stock'], 2), ENT_QUOTES) ?></td>
                                                        <td>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="action" value="delete_sku_param">
                                                                <input type="hidden" name="warehouse_id" value="<?= (int) $warehouseId ?>">
                                                                <input type="hidden" name="sku" value="<?= htmlspecialchars($skuCode, ENT_QUOTES) ?>">
                                                                <button class="btn btn-link btn-sm text-danger" type="submit">Remove</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach;
                                            endforeach;
                                        else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-3">No SKU overrides configured.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    const sections = document.querySelectorAll('section[id^="section-"]');
    document.querySelectorAll('#dashboardTabs .nav-link').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('#dashboardTabs .nav-link').forEach((link) => link.classList.remove('active'));
            btn.classList.add('active');
            const sectionId = 'section-' + btn.dataset.section;
            sections.forEach((section) => {
                if (section.id === sectionId) {
                    section.classList.remove('d-none');
                } else {
                    section.classList.add('d-none');
                }
            });
        });
    });

    let demandTable;
    let reorderChart;
    let trendChart;
    let currentRowsMap = new Map();

    function refreshDashboard() {
        const warehouseId = document.getElementById('warehouseFilter').value;
        const skuFilter = document.getElementById('skuFilter').value.trim();
        const params = new URLSearchParams();
        if (warehouseId) params.append('warehouse_id', warehouseId);
        if (skuFilter) params.append('sku', skuFilter);

        const query = params.toString();
        const url = 'api.php' + (query ? '?' + query : '');
        fetch(url, { credentials: 'same-origin' })
            .then((response) => response.json())
            .then((payload) => {
                const rows = payload.data || [];
                currentRowsMap = new Map();
                demandTable.clear();
                rows.forEach((row) => {
                    const key = `${row.warehouse_id}|${row.sku}`;
                    currentRowsMap.set(key, row);
                    demandTable.row.add([
                        `${row.warehouse_code} · ${row.warehouse_name}`,
                        row.sku,
                        row.current_stock,
                        row.snapshot_date || '',
                        row.moving_average,
                        row.days_of_cover,
                        row.target_stock,
                        row.reorder_qty,
                        row.safety_stock,
                        key,
                    ]);
                });
                demandTable.draw();

                document.getElementById('summaryItems').textContent = rows.length;
                document.getElementById('summaryReorder').textContent = rows
                    .reduce((sum, row) => sum + parseFloat(row.reorder_qty), 0)
                    .toLocaleString('en-GB', { maximumFractionDigits: 2 });

                const topRows = [...rows].sort((a, b) => b.reorder_qty - a.reorder_qty).slice(0, 10);
                const labels = topRows.map((row) => `${row.warehouse_code}-${row.sku}`);
                const values = topRows.map((row) => row.reorder_qty);

                if (reorderChart) {
                    reorderChart.destroy();
                }
                const ctx = document.getElementById('reorderChart');
                reorderChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{
                            label: 'Reorder Qty',
                            data: values,
                            backgroundColor: '#00979d',
                        }],
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: { beginAtZero: true },
                        },
                    },
                });

                const summaryBody = document.getElementById('summaryItems').closest('.card-body');
                if (summaryBody) {
                    summaryBody.classList.toggle('text-muted', rows.length === 0);
                }

                if (trendChart) {
                    trendChart.destroy();
                }
                renderTrendSeries();

                $('#demandTable tbody').off('click').on('click', 'tr', function () {
                    const data = demandTable.row(this).data();
                    if (!data) return;
                    const key = data[9];
                    const detail = currentRowsMap.get(key);
                    if (!detail) return;
                    renderTrendSeries(detail);
                });
            })
            .catch((error) => {
                console.error('Failed to load dashboard data', error);
            });
    }

    function renderTrendSeries(row) {
        const ctx = document.getElementById('trendChart');
        if (trendChart) {
            trendChart.destroy();
        }
        if (!row) {
            trendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{ data: [], borderColor: '#006F7A', tension: 0.3, fill: false }],
                },
                options: {
                    scales: {
                        y: { beginAtZero: true },
                    },
                },
            });
            return;
        }
        const labels = Object.keys(row.daily_series || {});
        const values = labels.map((date) => row.daily_series[date]);
        trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: `${row.warehouse_code}-${row.sku}`,
                    data: values,
                    borderColor: '#006F7A',
                    backgroundColor: 'rgba(0, 151, 157, 0.2)',
                    tension: 0.3,
                    fill: true,
                }],
            },
            options: {
                scales: {
                    y: { beginAtZero: true },
                },
            },
        });
    }

    $(document).ready(function () {
        const numberRenderer = $.fn.dataTable.render.number(',', '.', 2);
        demandTable = $('#demandTable').DataTable({
            paging: true,
            searching: false,
            info: false,
            order: [[7, 'desc']],
            columnDefs: [
                {
                    targets: [2, 4, 6, 7, 8],
                    render: function (data) {
                        if (data === null || data === '') {
                            return '0.00';
                        }
                        return numberRenderer.display(parseFloat(data));
                    },
                },
                {
                    targets: 5,
                    render: function (data) {
                        if (data === null || data === '' || Number.isNaN(parseFloat(data))) {
                            return '—';
                        }
                        return numberRenderer.display(parseFloat(data));
                    },
                },
                {
                    targets: 3,
                    render: function (data) {
                        return data || '—';
                    },
                },
                {
                    targets: 9,
                    visible: false,
                    searchable: false,
                },
            ],
        });

        document.getElementById('warehouseFilter').addEventListener('change', refreshDashboard);
        document.getElementById('skuFilter').addEventListener('input', function () {
            clearTimeout(this._timer);
            this._timer = setTimeout(refreshDashboard, 400);
        });

        refreshDashboard();
    });
</script>
</body>
</html>
