<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function getPreviewSessionKey(string $type): string
{
    return $type . '_upload_preview';
}

function clearUploadPreview(string $type): void
{
    $key = getPreviewSessionKey($type);
    if (isset($_SESSION[$key]) && is_array($_SESSION[$key])) {
        $path = $_SESSION[$key]['file_path'] ?? null;
        if (is_string($path) && $path !== '' && file_exists($path)) {
            @unlink($path);
        }
    }
    unset($_SESSION[$key]);
}

function setUploadPreview(string $type, array $data): void
{
    clearUploadPreview($type);
    $_SESSION[getPreviewSessionKey($type)] = $data;
}

function updateUploadPreview(string $type, array $updates): void
{
    $key = getPreviewSessionKey($type);
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        return;
    }
    $_SESSION[$key] = array_merge($_SESSION[$key], $updates);
}

function getUploadPreview(string $type): ?array
{
    $key = getPreviewSessionKey($type);
    $preview = $_SESSION[$key] ?? null;
    return is_array($preview) ? $preview : null;
}

function createCsvPreview(string $filePath, int $maxRows = 5): ?array
{
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return null;
    }
    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return null;
    }
    $rows = [];
    while (count($rows) < $maxRows && ($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);
    return [
        'header' => $header,
        'rows' => $rows,
    ];
}

function createTempUploadPath(string $prefix): string
{
    try {
        $random = bin2hex(random_bytes(8));
    } catch (\Exception $e) {
        $random = str_replace('.', '', uniqid('', true));
    }
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    if ($directory === '') {
        $directory = sys_get_temp_dir();
    }
    return $directory . DIRECTORY_SEPARATOR . $prefix . $random . '.csv';
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout();
    header('Location: index.php');
    exit;
}

$messages = [];
$errors = [];
$lastAction = $_POST['action'] ?? '';

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
            case 'preview_sales':
                $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
                $warehousesList = getWarehouses($mysqli);
                if ($warehouseId <= 0 || !isset($warehousesList[$warehouseId])) {
                    $errors[] = 'Please choose a warehouse.';
                    break;
                }
                if (!isset($_FILES['sales_csv']) || $_FILES['sales_csv']['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Please choose a CSV file to upload.';
                    break;
                }
                $destination = createTempUploadPath('flux_sales_');
                if (!move_uploaded_file($_FILES['sales_csv']['tmp_name'], $destination)) {
                    @unlink($destination);
                    $errors[] = 'Failed to store uploaded file.';
                    break;
                }
                $preview = createCsvPreview($destination);
                if ($preview === null) {
                    @unlink($destination);
                    $errors[] = 'CSV file is empty or invalid.';
                    break;
                }
                setUploadPreview('sales', [
                    'warehouse_id' => $warehouseId,
                    'file_path' => $destination,
                    'header' => $preview['header'],
                    'rows' => $preview['rows'],
                    'filename' => $_FILES['sales_csv']['name'] ?? 'uploaded.csv',
                    'column_map' => [],
                    'uploaded_at' => time(),
                ]);
                $messages[] = 'File uploaded. Please select the columns to import.';
                break;
            case 'confirm_sales':
                $preview = getUploadPreview('sales');
                if (!$preview) {
                    $errors[] = 'Please upload a sales CSV first.';
                    break;
                }
                $columnInput = $_POST['column_map'] ?? [];
                if (!is_array($columnInput)) {
                    $columnInput = [];
                }
                $columnMap = [];
                $requiredSalesFields = ['sale_date' => 'sale date', 'sku' => 'SKU', 'quantity' => 'quantity'];
                foreach ($requiredSalesFields as $key => $label) {
                    if (!isset($columnInput[$key]) || $columnInput[$key] === '') {
                        $errors[] = 'Please select a column for the ' . $label . '.';
                        continue;
                    }
                    $columnMap[$key] = (int) $columnInput[$key];
                }
                $header = $preview['header'] ?? [];
                $headerCount = is_array($header) ? count($header) : 0;
                foreach ($columnMap as $idx) {
                    if ($idx < 0 || $idx >= $headerCount) {
                        $errors[] = 'One or more selected columns are invalid.';
                        break;
                    }
                }
                if (!empty($columnMap)) {
                    updateUploadPreview('sales', ['column_map' => $columnMap]);
                }
                $warehouseId = (int) ($preview['warehouse_id'] ?? 0);
                $warehousesList = getWarehouses($mysqli);
                if ($warehouseId <= 0 || !isset($warehousesList[$warehouseId])) {
                    $errors[] = 'The selected warehouse could not be found.';
                }
                $filePath = $preview['file_path'] ?? '';
                if (!is_string($filePath) || $filePath === '' || !file_exists($filePath)) {
                    $errors[] = 'Uploaded file is no longer available. Please upload it again.';
                }
                if (!empty($errors)) {
                    break;
                }
                $result = importSalesCsv($mysqli, $filePath, $warehouseId, $columnMap);
                if ($result['success']) {
                    $messages[] = $result['message'];
                    clearUploadPreview('sales');
                } else {
                    $errors[] = $result['message'];
                }
                break;
            case 'cancel_sales_preview':
                clearUploadPreview('sales');
                $messages[] = 'Sales upload canceled.';
                break;
            case 'preview_stock':
                $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
                $warehousesList = getWarehouses($mysqli);
                if ($warehouseId <= 0 || !isset($warehousesList[$warehouseId])) {
                    $errors[] = 'Please choose a warehouse.';
                    break;
                }
                $snapshotInput = trim($_POST['snapshot_date'] ?? '');
                if ($snapshotInput === '') {
                    $errors[] = 'Please provide a snapshot date.';
                    break;
                }

                $snapshotDate = normalizeDateString($snapshotInput);
                if ($snapshotDate === null) {
                    $errors[] = 'Snapshot date could not be recognized. Please use a valid date.';

                    break;
                }
                if (!isset($_FILES['stock_csv']) || $_FILES['stock_csv']['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Please choose a CSV file to upload.';
                    break;
                }
                $destination = createTempUploadPath('flux_stock_');
                if (!move_uploaded_file($_FILES['stock_csv']['tmp_name'], $destination)) {
                    @unlink($destination);
                    $errors[] = 'Failed to store uploaded file.';
                    break;
                }
                $preview = createCsvPreview($destination);
                if ($preview === null) {
                    @unlink($destination);
                    $errors[] = 'CSV file is empty or invalid.';
                    break;
                }
                setUploadPreview('stock', [
                    'warehouse_id' => $warehouseId,
                    'file_path' => $destination,
                    'header' => $preview['header'],
                    'rows' => $preview['rows'],
                    'filename' => $_FILES['stock_csv']['name'] ?? 'uploaded.csv',

                    'snapshot_date' => $snapshotDate,

                    'column_map' => [],
                    'uploaded_at' => time(),
                ]);
                $messages[] = 'File uploaded. Please select the columns to import.';
                break;
            case 'confirm_stock':
                $preview = getUploadPreview('stock');
                if (!$preview) {
                    $errors[] = 'Please upload a stock snapshot CSV first.';
                    break;
                }
                $columnInput = $_POST['column_map'] ?? [];
                if (!is_array($columnInput)) {
                    $columnInput = [];
                }
                $columnMap = [];
                $requiredStockFields = ['sku' => 'SKU', 'quantity' => 'quantity'];
                foreach ($requiredStockFields as $key => $label) {
                    if (!isset($columnInput[$key]) || $columnInput[$key] === '') {
                        $errors[] = 'Please select a column for the ' . $label . '.';
                        continue;
                    }
                    $columnMap[$key] = (int) $columnInput[$key];
                }
                $header = $preview['header'] ?? [];
                $headerCount = is_array($header) ? count($header) : 0;
                foreach ($columnMap as $idx) {
                    if ($idx < 0 || $idx >= $headerCount) {
                        $errors[] = 'One or more selected columns are invalid.';
                        break;
                    }
                }
                if (!empty($columnMap)) {
                    updateUploadPreview('stock', ['column_map' => $columnMap]);
                }
                $warehouseId = (int) ($preview['warehouse_id'] ?? 0);
                $warehousesList = getWarehouses($mysqli);
                if ($warehouseId <= 0 || !isset($warehousesList[$warehouseId])) {
                    $errors[] = 'The selected warehouse could not be found.';
                }
                $snapshotDate = $preview['snapshot_date'] ?? null;
                if (!is_string($snapshotDate) || $snapshotDate === '') {
                    $errors[] = 'Snapshot date is missing. Please upload the file again.';
                }
                $filePath = $preview['file_path'] ?? '';
                if (!is_string($filePath) || $filePath === '' || !file_exists($filePath)) {
                    $errors[] = 'Uploaded file is no longer available. Please upload it again.';
                }
                if (!empty($errors)) {
                    break;
                }
                $result = importStockCsv($mysqli, $filePath, $warehouseId, $columnMap, $snapshotDate);
                if ($result['success']) {
                    $messages[] = $result['message'];
                    clearUploadPreview('stock');
                } else {
                    $errors[] = $result['message'];
                }
                break;
            case 'cancel_stock_preview':
                clearUploadPreview('stock');
                $messages[] = 'Stock upload canceled.';
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
                    'safety_days' => $_POST['safety_days'] ?? 0,
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
$salesPreview = getUploadPreview('sales');
$stockPreview = getUploadPreview('stock');
$defaults = $config['defaults'];

$activeSection = 'dashboard';
$importActions = [
    'preview_sales',
    'confirm_sales',
    'cancel_sales_preview',
    'preview_stock',
    'confirm_stock',
    'cancel_stock_preview',
];
$warehouseActions = ['add_warehouse'];
$parameterActions = ['save_parameters', 'delete_sku_param'];

if ($salesPreview || $stockPreview) {
    $activeSection = 'imports';
} elseif (in_array($lastAction, $importActions, true)) {
    $activeSection = 'imports';
} elseif (in_array($lastAction, $warehouseActions, true)) {
    $activeSection = 'warehouses';
} elseif (in_array($lastAction, $parameterActions, true)) {
    $activeSection = 'parameters';
}

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
<div class="container-fluid my-4 px-4">
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
                <button class="nav-link<?= $activeSection === 'dashboard' ? ' active' : '' ?>" data-section="dashboard" type="button">Dashboard</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeSection === 'imports' ? ' active' : '' ?>" data-section="imports" type="button">Data Import</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeSection === 'warehouses' ? ' active' : '' ?>" data-section="warehouses" type="button">Warehouses</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeSection === 'parameters' ? ' active' : '' ?>" data-section="parameters" type="button">Parameters</button>
            </li>
        </ul>

        <section id="section-dashboard"<?= $activeSection === 'dashboard' ? '' : ' class="d-none"' ?>>
            <div class="row g-4 mb-4">
                <div class="col-12">
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
                                            <th>Moving Avg</th>
                                            <th>Days of Cover</th>
                                            <th>Reorder Qty</th>
                                            <th class="d-none">Key</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row g-4 align-items-stretch">
                <div class="col-lg-4 col-xl-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Summary</h5>
                        </div>
                        <div class="card-body">
                            <p class="mb-1"><strong>Total Items:</strong> <span id="summaryItems">0</span></p>
                            <p class="mb-3"><strong>Total Reorder Qty:</strong> <span id="summaryReorder">0</span></p>
                            <canvas id="reorderChart" height="220"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8 col-xl-9">
                    <div class="card shadow-sm h-100">
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

        <section id="section-imports"<?= $activeSection === 'imports' ? '' : ' class="d-none"' ?>>
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Upload Daily Sales CSV</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($salesPreview): ?>
                                <?php
                                    $salesHeader = is_array($salesPreview['header'] ?? null) ? $salesPreview['header'] : [];
                                    $salesRows = is_array($salesPreview['rows'] ?? null) ? $salesPreview['rows'] : [];
                                    $salesHeaderCount = count($salesHeader);
                                    $salesSampleCount = count($salesRows);
                                    $salesWarehouseId = (int) ($salesPreview['warehouse_id'] ?? 0);
                                    $salesWarehouseInfo = $warehouses[$salesWarehouseId] ?? null;
                                    $salesWarehouseLabel = $salesWarehouseInfo
                                        ? ($salesWarehouseInfo['code'] . ' · ' . $salesWarehouseInfo['name'])
                                        : ('ID ' . $salesWarehouseId);
                                    $salesColumnMap = is_array($salesPreview['column_map'] ?? null) ? $salesPreview['column_map'] : [];
                                    $salesFields = ['sale_date' => 'Sale Date', 'sku' => 'SKU', 'quantity' => 'Quantity'];
                                ?>
                                <p class="text-muted">Preview the uploaded file and choose the columns for sale date, SKU, and quantity.</p>
                                <div class="mb-3 small">
                                    <div><strong>Warehouse:</strong> <?= htmlspecialchars($salesWarehouseLabel, ENT_QUOTES) ?></div>
                                    <div><strong>File:</strong> <?= htmlspecialchars((string) ($salesPreview['filename'] ?? 'uploaded.csv'), ENT_QUOTES) ?></div>
                                    <div><strong>Rows previewed:</strong> <?= $salesSampleCount ?></div>
                                </div>
                                <form method="post">
                                    <input type="hidden" name="action" value="confirm_sales">
                                    <div class="row g-3 mb-3">
                                        <?php foreach ($salesHeader as $index => $columnLabel):
                                            $displayLabel = trim((string) $columnLabel) !== '' ? (string) $columnLabel : 'Column ' . ($index + 1);
                                        ?>
                                        <div class="col-md-4">
                                            <div class="border rounded p-3 h-100">
                                                <div class="small text-muted text-uppercase mb-1">Column <?= $index + 1 ?></div>
                                                <div class="fw-semibold text-truncate" title="<?= htmlspecialchars($displayLabel, ENT_QUOTES) ?>">
                                                    <?= htmlspecialchars($displayLabel, ENT_QUOTES) ?>
                                                </div>
                                                <div class="mt-2">
                                                    <?php foreach ($salesFields as $fieldKey => $fieldLabel):
                                                        $checked = isset($salesColumnMap[$fieldKey]) && (int) $salesColumnMap[$fieldKey] === (int) $index;
                                                    ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input column-checkbox" type="checkbox" id="sales-<?= $fieldKey ?>-<?= $index ?>" name="column_map[<?= $fieldKey ?>]" value="<?= $index ?>" data-field="<?= $fieldKey ?>" <?= $checked ? 'checked' : '' ?>>
                                                        <label class="form-check-label small" for="sales-<?= $fieldKey ?>-<?= $index ?>">Use as <?= htmlspecialchars($fieldLabel, ENT_QUOTES) ?></label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($salesHeader)): ?>
                                        <div class="col-12">
                                            <div class="alert alert-warning mb-0">No columns detected in the uploaded file.</div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-responsive mb-3">
                                        <table class="table table-sm table-striped align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <?php if ($salesHeaderCount > 0): ?>
                                                        <?php foreach ($salesHeader as $columnLabel):
                                                            $headerLabel = trim((string) $columnLabel) !== '' ? (string) $columnLabel : 'Column';
                                                        ?>
                                                        <th><?= htmlspecialchars($headerLabel, ENT_QUOTES) ?></th>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <th>Data</th>
                                                    <?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if ($salesSampleCount > 0): ?>
                                                    <?php foreach ($salesRows as $row): ?>
                                                    <tr>
                                                        <?php if ($salesHeaderCount > 0): ?>
                                                            <?php for ($i = 0; $i < $salesHeaderCount; $i++): ?>
                                                            <td><?= htmlspecialchars((string) ($row[$i] ?? ''), ENT_QUOTES) ?></td>
                                                            <?php endfor; ?>
                                                        <?php else: ?>
                                                            <td><?= htmlspecialchars(implode(', ', array_map('strval', $row)), ENT_QUOTES) ?></td>
                                                        <?php endif; ?>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="<?= max(1, $salesHeaderCount) ?>" class="text-center text-muted">No data rows detected.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                        <p class="text-muted small mt-2 mb-0">Showing the first <?= $salesSampleCount ?> row<?= $salesSampleCount === 1 ? '' : 's' ?> from the file.</p>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-primary" type="submit">Import Sales</button>
                                    </div>
                                </form>
                                <form method="post" class="mt-2">
                                    <input type="hidden" name="action" value="cancel_sales_preview">
                                    <button class="btn btn-link text-danger p-0" type="submit">Cancel preview</button>
                                </form>
                            <?php else: ?>
                                <p class="text-muted">Upload a CSV for a single warehouse. After the upload you'll choose the columns for date, SKU, and quantity.</p>
                                <form method="post" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="preview_sales">
                                    <div class="mb-3">
                                        <label class="form-label" for="salesWarehouse">Warehouse</label>
                                        <select class="form-select" id="salesWarehouse" name="warehouse_id" required>
                                            <option value="">Select warehouse</option>
                                            <?php foreach ($warehouses as $warehouse): ?>
                                                <option value="<?= (int) $warehouse['id'] ?>"><?= htmlspecialchars($warehouse['code'] . ' · ' . $warehouse['name'], ENT_QUOTES) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="salesCsv">Daily Sales CSV</label>
                                        <input class="form-control" type="file" id="salesCsv" name="sales_csv" accept=".csv" required>
                                        <div class="form-text">Ensure the file includes columns for sale date (YYYY-MM-DD), SKU, and quantity.</div>
                                    </div>
                                    <button class="btn btn-primary" type="submit">Preview Sales File</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Upload Stock Snapshot CSV</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($stockPreview): ?>
                                <?php
                                    $stockHeader = is_array($stockPreview['header'] ?? null) ? $stockPreview['header'] : [];
                                    $stockRows = is_array($stockPreview['rows'] ?? null) ? $stockPreview['rows'] : [];
                                    $stockHeaderCount = count($stockHeader);
                                    $stockSampleCount = count($stockRows);
                                    $stockWarehouseId = (int) ($stockPreview['warehouse_id'] ?? 0);
                                    $stockWarehouseInfo = $warehouses[$stockWarehouseId] ?? null;
                                    $stockWarehouseLabel = $stockWarehouseInfo
                                        ? ($stockWarehouseInfo['code'] . ' · ' . $stockWarehouseInfo['name'])
                                        : ('ID ' . $stockWarehouseId);
                                    $stockColumnMap = is_array($stockPreview['column_map'] ?? null) ? $stockPreview['column_map'] : [];
                                    $stockFields = ['sku' => 'SKU', 'quantity' => 'Quantity'];
                                    $stockSnapshotDate = (string) ($stockPreview['snapshot_date'] ?? '');
                                ?>
                                <p class="text-muted">Preview the uploaded file and choose the columns for SKU and quantity.</p>
                                <div class="mb-3 small">
                                    <div><strong>Warehouse:</strong> <?= htmlspecialchars($stockWarehouseLabel, ENT_QUOTES) ?></div>
                                    <div><strong>Snapshot date:</strong> <?= htmlspecialchars($stockSnapshotDate, ENT_QUOTES) ?></div>
                                    <div><strong>File:</strong> <?= htmlspecialchars((string) ($stockPreview['filename'] ?? 'uploaded.csv'), ENT_QUOTES) ?></div>
                                    <div><strong>Rows previewed:</strong> <?= $stockSampleCount ?></div>
                                </div>
                                <form method="post">
                                    <input type="hidden" name="action" value="confirm_stock">
                                    <div class="row g-3 mb-3">
                                        <?php foreach ($stockHeader as $index => $columnLabel):
                                            $displayLabel = trim((string) $columnLabel) !== '' ? (string) $columnLabel : 'Column ' . ($index + 1);
                                        ?>
                                        <div class="col-md-4">
                                            <div class="border rounded p-3 h-100">
                                                <div class="small text-muted text-uppercase mb-1">Column <?= $index + 1 ?></div>
                                                <div class="fw-semibold text-truncate" title="<?= htmlspecialchars($displayLabel, ENT_QUOTES) ?>">
                                                    <?= htmlspecialchars($displayLabel, ENT_QUOTES) ?>
                                                </div>
                                                <div class="mt-2">
                                                    <?php foreach ($stockFields as $fieldKey => $fieldLabel):
                                                        $checked = isset($stockColumnMap[$fieldKey]) && (int) $stockColumnMap[$fieldKey] === (int) $index;
                                                    ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input column-checkbox" type="checkbox" id="stock-<?= $fieldKey ?>-<?= $index ?>" name="column_map[<?= $fieldKey ?>]" value="<?= $index ?>" data-field="<?= $fieldKey ?>" <?= $checked ? 'checked' : '' ?>>
                                                        <label class="form-check-label small" for="stock-<?= $fieldKey ?>-<?= $index ?>">Use as <?= htmlspecialchars($fieldLabel, ENT_QUOTES) ?></label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($stockHeader)): ?>
                                        <div class="col-12">
                                            <div class="alert alert-warning mb-0">No columns detected in the uploaded file.</div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-responsive mb-3">
                                        <table class="table table-sm table-striped align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <?php if ($stockHeaderCount > 0): ?>
                                                        <?php foreach ($stockHeader as $columnLabel):
                                                            $headerLabel = trim((string) $columnLabel) !== '' ? (string) $columnLabel : 'Column';
                                                        ?>
                                                        <th><?= htmlspecialchars($headerLabel, ENT_QUOTES) ?></th>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <th>Data</th>
                                                    <?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if ($stockSampleCount > 0): ?>
                                                    <?php foreach ($stockRows as $row): ?>
                                                    <tr>
                                                        <?php if ($stockHeaderCount > 0): ?>
                                                            <?php for ($i = 0; $i < $stockHeaderCount; $i++): ?>
                                                            <td><?= htmlspecialchars((string) ($row[$i] ?? ''), ENT_QUOTES) ?></td>
                                                            <?php endfor; ?>
                                                        <?php else: ?>
                                                            <td><?= htmlspecialchars(implode(', ', array_map('strval', $row)), ENT_QUOTES) ?></td>
                                                        <?php endif; ?>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="<?= max(1, $stockHeaderCount) ?>" class="text-center text-muted">No data rows detected.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                        <p class="text-muted small mt-2 mb-0">Showing the first <?= $stockSampleCount ?> row<?= $stockSampleCount === 1 ? '' : 's' ?> from the file.</p>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-primary" type="submit">Import Stock</button>
                                    </div>
                                </form>
                                <form method="post" class="mt-2">
                                    <input type="hidden" name="action" value="cancel_stock_preview">
                                    <button class="btn btn-link text-danger p-0" type="submit">Cancel preview</button>
                                </form>
                            <?php else: ?>
                                <p class="text-muted">Upload a snapshot CSV for a single warehouse. After the upload you'll choose the columns for SKU and quantity.</p>
                                <form method="post" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="preview_stock">
                                    <div class="mb-3">
                                        <label class="form-label" for="stockWarehouse">Warehouse</label>
                                        <select class="form-select" id="stockWarehouse" name="warehouse_id" required>
                                            <option value="">Select warehouse</option>
                                            <?php foreach ($warehouses as $warehouse): ?>
                                                <option value="<?= (int) $warehouse['id'] ?>"><?= htmlspecialchars($warehouse['code'] . ' · ' . $warehouse['name'], ENT_QUOTES) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="stockSnapshotDate">Snapshot Date</label>
                                        <input class="form-control" type="date" id="stockSnapshotDate" name="snapshot_date" required>
                                        <div class="form-text">All rows in the file will be imported with this snapshot date.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="stockCsv">Stock Snapshot CSV</label>
                                        <input class="form-control" type="file" id="stockCsv" name="stock_csv" accept=".csv" required>
                                        <div class="form-text">Ensure the file includes columns for SKU and quantity.</div>
                                    </div>
                                    <button class="btn btn-primary" type="submit">Preview Stock File</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="section-warehouses"<?= $activeSection === 'warehouses' ? '' : ' class="d-none"' ?>>
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

        <section id="section-parameters"<?= $activeSection === 'parameters' ? '' : ' class="d-none"' ?>>
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
                                    <label class="form-label" for="paramSafety">Safety Days</label>
                                    <input class="form-control" type="number" step="0.01" min="0" id="paramSafety" name="safety_days" value="<?= htmlspecialchars((string) $defaults['safety_days'], ENT_QUOTES) ?>" required>
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
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($warehouses)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3">No warehouses yet.</td>
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
                                            <th>Safety Days</th>
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
                                                        <td><?= htmlspecialchars(number_format((float) $params['safety_days'], 2), ENT_QUOTES) ?></td>
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
    const integerFormatter = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

    function normalizeDaysValue(value) {
        if (value === null || value === undefined) {
            return null;
        }
        if (typeof value === 'string' && value.trim() === '') {
            return null;
        }
        const numericValue = Number(value);
        if (!Number.isFinite(numericValue)) {
            return null;
        }
        return numericValue;
    }

    function createDaysBadge(value, normalizedValue) {
        const numericValue = normalizedValue ?? normalizeDaysValue(value);
        if (numericValue === null) {
            return '<span class="badge rounded-pill text-bg-secondary">—</span>';
        }
        const rounded = Math.round(numericValue);
        const label = integerFormatter.format(rounded);
        if (rounded <= 0) {
            return `<span class="badge rounded-pill text-bg-danger">${label}</span>`;
        }
        if (rounded <= 5) {
            return `<span class="badge rounded-pill text-bg-warning text-dark">${label}</span>`;
        }
        return `<span class="badge rounded-pill text-bg-success">${label}</span>`;
    }

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
                        row.moving_average,
                        row.days_of_cover,
                        row.reorder_qty,
                        key,
                    ]);
                });
                demandTable.draw();

                document.getElementById('summaryItems').textContent = rows.length;
                const totalReorder = rows.reduce((sum, row) => {
                    const value = Number(row.reorder_qty);
                    return Number.isFinite(value) ? sum + value : sum;
                }, 0);
                document.getElementById('summaryReorder').textContent = Math.round(totalReorder)
                    .toLocaleString('en-GB', { maximumFractionDigits: 0 });

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
                    const key = data[6];
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

    function setupColumnCheckboxes() {
        document.querySelectorAll('.column-checkbox').forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                if (!checkbox.checked) {
                    return;
                }
                const field = checkbox.dataset.field;
                if (!field) {
                    return;
                }
                document.querySelectorAll(`.column-checkbox[data-field="${field}"]`).forEach((other) => {
                    if (other !== checkbox) {
                        other.checked = false;
                    }
                });
            });
        });
    }

    $(document).ready(function () {
        const decimalRenderer = $.fn.dataTable.render.number(',', '.', 2);
        const integerRenderer = $.fn.dataTable.render.number(',', '.', 0);
        demandTable = $('#demandTable').DataTable({
            paging: true,
            searching: false,
            info: false,
            order: [[5, 'desc']],
            columnDefs: [
                {
                    targets: [2, 5],
                    render: function (data) {
                        if (data === null || data === '') {
                            return '0';
                        }
                        const numericValue = Number(data);
                        if (!Number.isFinite(numericValue)) {
                            return '0';
                        }
                        return integerRenderer.display(Math.round(numericValue));
                    },
                },
                {
                    targets: 4,
                    render: function (data, type) {
                        const normalized = normalizeDaysValue(data);
                        if (type === 'display') {
                            return createDaysBadge(data, normalized);
                        }
                        if (type === 'filter') {
                            return normalized === null ? '' : String(Math.round(normalized));
                        }
                        return normalized === null ? null : normalized;
                    },
                },
                {
                    targets: 3,
                    render: function (data) {
                        if (data === null || data === '') {
                            return '0.00';
                        }
                        const numericValue = Number(data);
                        if (!Number.isFinite(numericValue)) {
                            return '0.00';
                        }
                        return decimalRenderer.display(numericValue);
                    },
                },
                {
                    targets: 6,
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
        setupColumnCheckboxes();
    });
</script>
</body>
</html>
