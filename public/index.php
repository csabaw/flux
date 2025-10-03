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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demand Planning Dashboard</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#007BFF',
                        'background-light': '#F8F9FA',
                        'background-dark': '#18181B',
                        'card-light': '#FFFFFF',
                        'card-dark': '#27272A',
                        'text-light': '#1F2937',
                        'text-dark': '#F4F4F5',
                        'subtext-light': '#6B7280',
                        'subtext-dark': '#A1A1AA',
                        'border-light': '#E5E7EB',
                        'border-dark': '#3F3F46'
                    },
                    fontFamily: {
                        display: ['Inter', 'sans-serif']
                    },
                    borderRadius: {
                        DEFAULT: '0.5rem'
                    }
                }
            }
        };
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-text-light dark:text-text-dark">
<div class="flex flex-col min-h-screen">
    <header class="bg-card-light dark:bg-card-dark shadow-sm border-b border-border-light dark:border-border-dark">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center space-x-4">
                    <span class="material-icons text-primary" style="font-size: 28px;">analytics</span>
                    <h1 class="text-xl font-bold">Demand Planning Dashboard</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <button type="button" class="p-2 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700">
                        <span class="material-icons text-subtext-light dark:text-subtext-dark">notifications</span>
                    </button>
                    <div class="relative">
                        <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuA1EHhdTmdIUXzI6p094ec-Hebua5dTvkHsKRbw_19As05IrBSX88cMCM25ccIq5Emug1XArfd7dr6BYMnETiJPff2-Cp8auQ4i2F-nXIfgnIFfhYVJGhpbpPo47c_nPMK-KeSDtrCppYqxzacY9SylKYIadsqfSOHSwWY81lZQ7SzRWYdIL-h7hjQ8PgFpoizqcbpeEWsQNiCxgcaObVde2ZKuHNZqo25HLldjtWvdYrLRhebEmmMwoVXMMJPTaNv_G_r9El3Bjh8" alt="User avatar" class="h-9 w-9 rounded-full object-cover">
                        <span class="absolute right-0 bottom-0 block h-2.5 w-2.5 rounded-full bg-green-400 ring-2 ring-white dark:ring-card-dark"></span>
                    </div>
                    <?php if (is_logged_in()): ?>
                    <a href="?action=logout" class="ml-2 px-4 py-2 text-sm font-medium text-primary bg-primary/10 dark:bg-primary/20 rounded-md hover:bg-primary/20 dark:hover:bg-primary/30 transition">Logout</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>
    <main class="flex-grow p-4 sm:p-6 lg:px-8 lg:p-8">
        <div class="max-w-7xl mx-auto space-y-6">
            <?php foreach ($messages as $message): ?>
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800 dark:border-green-900 dark:bg-green-900/20 dark:text-green-200">
                    <?= htmlspecialchars($message, ENT_QUOTES) ?>
                </div>
            <?php endforeach; ?>
            <?php foreach ($errors as $error): ?>
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-700 dark:border-red-900 dark:bg-red-900/20 dark:text-red-200">
                    <?= htmlspecialchars($error, ENT_QUOTES) ?>
                </div>
            <?php endforeach; ?>

            <?php if (!is_logged_in()): ?>
                <div class="flex justify-center py-10">
                    <div class="w-full max-w-md bg-card-light dark:bg-card-dark border border-border-light dark:border-border-dark rounded-lg shadow-sm p-6">
                        <h2 class="text-xl font-semibold text-center mb-6">Admin Login</h2>
                        <form method="post" class="space-y-4" novalidate>
                            <input type="hidden" name="action" value="login">
                            <div>
                                <label for="username" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Username</label>
                                <input type="text" id="username" name="username" required autofocus class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                            </div>
                            <div>
                                <label for="password" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Password</label>
                                <input type="password" id="password" name="password" required class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                            </div>
                            <button type="submit" class="w-full inline-flex justify-center rounded-md bg-primary px-4 py-2 text-white font-semibold shadow-sm hover:bg-primary/90 transition">Login</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <?php
                    $navButton = function (string $section, string $label) use ($activeSection): string {
                        $base = 'px-4 py-2 text-sm font-medium rounded-md flex-1 text-center transition';
                        if ($section === $activeSection) {
                            return $base . ' bg-primary text-white shadow';
                        }
                        return $base . ' text-subtext-light dark:text-subtext-dark hover:bg-gray-100 dark:hover:bg-gray-700';
                    };
                ?>
                <nav class="bg-card-light dark:bg-card-dark border border-border-light dark:border-border-dark rounded-lg p-2">
                    <div class="flex flex-col sm:flex-row gap-2" id="dashboardTabs">
                        <button type="button" data-section="dashboard" class="<?= $navButton('dashboard', 'Dashboard') ?>">Dashboard</button>
                        <button type="button" data-section="imports" class="<?= $navButton('imports', 'Data Import') ?>">Data Import</button>
                        <button type="button" data-section="warehouses" class="<?= $navButton('warehouses', 'Warehouses') ?>">Warehouses</button>
                        <button type="button" data-section="parameters" class="<?= $navButton('parameters', 'Parameters') ?>">Parameters</button>
                    </div>
                </nav>

                <section id="section-dashboard" class="<?= $activeSection === 'dashboard' ? '' : 'hidden' ?> space-y-6">
                    <div class="bg-card-light dark:bg-card-dark p-6 rounded-lg shadow-sm border border-border-light dark:border-border-dark">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
                            <div>
                                <h2 class="text-2xl font-semibold">Demand &amp; Replenishment</h2>
                                <p class="text-sm text-subtext-light dark:text-subtext-dark">Track inventory across warehouses and calculate reorder quantities.</p>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto">
                                <div class="relative w-full sm:w-56">
                                    <span class="material-icons absolute left-3 top-1/2 -translate-y-1/2 text-subtext-light dark:text-subtext-dark">warehouse</span>
                                    <select id="warehouseFilter" class="w-full pl-10 pr-4 py-2 rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark focus:outline-none focus:ring-2 focus:ring-primary">
                                        <option value="">All Warehouses</option>
                                        <?php foreach ($warehouses as $warehouse): ?>
                                            <option value="<?= (int) $warehouse['id'] ?>"><?= htmlspecialchars($warehouse['code'] . ' · ' . $warehouse['name'], ENT_QUOTES) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="relative w-full sm:w-56">
                                    <span class="material-icons absolute left-3 top-1/2 -translate-y-1/2 text-subtext-light dark:text-subtext-dark">search</span>
                                    <input type="search" id="skuFilter" placeholder="Filter by SKU" class="w-full pl-10 pr-4 py-2 rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark focus:outline-none focus:ring-2 focus:ring-primary">
                                </div>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left" id="demandTable">
                                <thead>
                                    <tr class="border-b border-border-light dark:border-border-dark text-sm text-subtext-light dark:text-subtext-dark">
                                        <th class="p-4">
                                            <button type="button" data-sort="warehouse" class="sort-button flex items-center gap-1 font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                                <span>Warehouse</span>
                                                <span class="material-icons sort-icon text-base opacity-0 transition">arrow_drop_down</span>
                                            </button>
                                        </th>
                                        <th class="p-4">
                                            <button type="button" data-sort="sku" class="sort-button flex items-center gap-1 font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                                <span>SKU</span>
                                                <span class="material-icons sort-icon text-base opacity-0 transition">arrow_drop_down</span>
                                            </button>
                                        </th>
                                        <th class="p-4 text-right">
                                            <button type="button" data-sort="current_stock" class="sort-button flex items-center gap-1 w-full justify-end font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                                <span>Stock</span>
                                                <span class="material-icons sort-icon text-base opacity-0 transition">arrow_drop_down</span>
                                            </button>
                                        </th>
                                        <th class="p-4 text-right">
                                            <button type="button" data-sort="moving_average" class="sort-button flex items-center gap-1 w-full justify-end font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                                <span>Moving Avg</span>
                                                <span class="material-icons sort-icon text-base opacity-0 transition">arrow_drop_down</span>
                                            </button>
                                        </th>
                                        <th class="p-4 text-right">
                                            <button type="button" data-sort="days_of_cover" class="sort-button flex items-center gap-1 w-full justify-end font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                                <span>Days of Cover</span>
                                                <span class="material-icons sort-icon text-base opacity-0 transition">arrow_drop_down</span>
                                            </button>
                                        </th>
                                        <th class="p-4 text-right">
                                            <button type="button" data-sort="reorder_qty" class="sort-button flex items-center gap-1 w-full justify-end font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                                <span>Reorder Qty</span>
                                                <span class="material-icons sort-icon text-base opacity-0 transition">arrow_drop_down</span>
                                            </button>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody id="demandTableBody" class="divide-y divide-border-light dark:divide-border-dark"></tbody>
                            </table>
                        </div>
                        <div class="pt-4 border-t border-border-light dark:border-border-dark mt-4 space-y-2">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 text-sm text-subtext-light dark:text-subtext-dark">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:gap-4 gap-2">
                                    <label for="rowsPerPageSelect" class="flex items-center gap-2">
                                        <span class="whitespace-nowrap">Rows per page</span>
                                        <select id="rowsPerPageSelect" class="rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                            <option value="10">10</option>
                                            <option value="25">25</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                        </select>
                                    </label>
                                    <div id="tableSummary">No data loaded.</div>
                                </div>
                                <div id="tablePagination" class="flex items-center gap-1 hidden">
                                    <button type="button" id="paginationPrev" class="px-3 py-1 rounded-md border border-border-light dark:border-border-dark text-sm font-medium text-subtext-light dark:text-subtext-dark hover:bg-gray-100 dark:hover:bg-gray-700 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50 disabled:cursor-not-allowed">Previous</button>
                                    <div id="paginationPages" class="flex items-center gap-1"></div>
                                    <button type="button" id="paginationNext" class="px-3 py-1 rounded-md border border-border-light dark:border-border-dark text-sm font-medium text-subtext-light dark:text-subtext-dark hover:bg-gray-100 dark:hover:bg-gray-700 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50 disabled:cursor-not-allowed">Next</button>
                                </div>
                            </div>
                            <div class="text-xs text-subtext-light dark:text-subtext-dark">Click a row to view the demand trend.</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="bg-card-light dark:bg-card-dark p-6 rounded-lg shadow-sm border border-border-light dark:border-border-dark">
                            <h3 class="text-lg font-semibold mb-4">Summary</h3>
                            <div class="space-y-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-subtext-light dark:text-subtext-dark">Total Items</span>
                                    <span class="text-xl font-bold" id="summaryItems">0</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-subtext-light dark:text-subtext-dark">Total Reorder Qty</span>
                                    <span class="text-xl font-bold" id="summaryReorder">0</span>
                                </div>
                            </div>
                            <div class="mt-6 h-48">
                                <canvas id="reorderChart"></canvas>
                            </div>
                        </div>
                        <div class="lg:col-span-2 bg-card-light dark:bg-card-dark p-6 rounded-lg shadow-sm border border-border-light dark:border-border-dark">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <h3 class="text-lg font-semibold">Rolling Demand (Last Window)</h3>
                                    <p class="text-sm text-subtext-light dark:text-subtext-dark">Select a row in the table to visualize its recent demand trend.</p>
                                </div>
                                <div class="flex items-center space-x-2 text-sm text-primary font-medium">
                                    <div class="w-4 h-1 bg-primary rounded-full"></div>
                                    <span id="trendSelectedLabel">No SKU selected</span>
                                </div>
                            </div>
                            <div class="h-64">
                                <canvas id="trendChart"></canvas>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="section-imports" class="<?= $activeSection === 'imports' ? '' : 'hidden' ?> space-y-6">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="bg-card-light dark:bg-card-dark p-6 rounded-lg shadow-sm border border-border-light dark:border-border-dark">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold">Upload Daily Sales CSV</h3>
                            </div>
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
                                <p class="text-sm text-subtext-light dark:text-subtext-dark mb-4">Preview the uploaded file and choose the columns for sale date, SKU, and quantity.</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm text-subtext-light dark:text-subtext-dark mb-4">
                                    <div><span class="font-medium text-text-light dark:text-text-dark">Warehouse:</span> <?= htmlspecialchars($salesWarehouseLabel, ENT_QUOTES) ?></div>
                                    <div><span class="font-medium text-text-light dark:text-text-dark">File:</span> <?= htmlspecialchars((string) ($salesPreview['filename'] ?? 'uploaded.csv'), ENT_QUOTES) ?></div>
                                    <div><span class="font-medium text-text-light dark:text-text-dark">Rows previewed:</span> <?= $salesSampleCount ?></div>
                                </div>
                                <form method="post" class="space-y-4">
                                    <input type="hidden" name="action" value="confirm_sales">
                                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        <?php foreach ($salesHeader as $index => $columnLabel):
                                            $displayLabel = trim((string) $columnLabel) !== '' ? (string) $columnLabel : 'Column ' . ($index + 1);
                                        ?>
                                            <div class="border border-border-light dark:border-border-dark rounded-lg p-4">
                                                <div class="text-xs uppercase tracking-wide text-subtext-light dark:text-subtext-dark mb-1">Column <?= $index + 1 ?></div>
                                                <div class="font-medium truncate" title="<?= htmlspecialchars($displayLabel, ENT_QUOTES) ?>">
                                                    <?= htmlspecialchars($displayLabel, ENT_QUOTES) ?>
                                                </div>
                                                <div class="mt-3 space-y-2">
                                                    <?php foreach ($salesFields as $fieldKey => $fieldLabel):
                                                        $checked = isset($salesColumnMap[$fieldKey]) && (int) $salesColumnMap[$fieldKey] === (int) $index;
                                                    ?>
                                                        <label class="flex items-center space-x-2 text-sm">
                                                            <input type="checkbox" class="column-checkbox rounded border-border-light dark:border-border-dark text-primary focus:ring-primary" id="sales-<?= $fieldKey ?>-<?= $index ?>" name="column_map[<?= $fieldKey ?>]" value="<?= $index ?>" data-field="<?= $fieldKey ?>" <?= $checked ? 'checked' : '' ?>>
                                                            <span>Use as <?= htmlspecialchars($fieldLabel, ENT_QUOTES) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($salesHeader)): ?>
                                            <div class="sm:col-span-2 lg:col-span-3">
                                                <div class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-yellow-800">No columns detected in the uploaded file.</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="overflow-x-auto border border-border-light dark:border-border-dark rounded-lg">
                                        <table class="min-w-full text-sm">
                                            <thead class="bg-background-light dark:bg-background-dark text-subtext-light dark:text-subtext-dark">
                                                <tr>
                                                    <?php if ($salesHeaderCount > 0): ?>
                                                        <?php foreach ($salesHeader as $columnLabel):
                                                            $headerLabel = trim((string) $columnLabel) !== '' ? (string) $columnLabel : 'Column';
                                                        ?>
                                                            <th class="px-4 py-2 font-medium"><?= htmlspecialchars($headerLabel, ENT_QUOTES) ?></th>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <th class="px-4 py-2 font-medium">Data</th>
                                                    <?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if ($salesSampleCount > 0): ?>
                                                    <?php foreach ($salesRows as $row): ?>
                                                        <tr class="border-t border-border-light dark:border-border-dark">
                                                            <?php if ($salesHeaderCount > 0): ?>
                                                                <?php for ($i = 0; $i < $salesHeaderCount; $i++): ?>
                                                                    <td class="px-4 py-2 text-text-light dark:text-text-dark"><?= htmlspecialchars((string) ($row[$i] ?? ''), ENT_QUOTES) ?></td>
                                                                <?php endfor; ?>
                                                            <?php else: ?>
                                                                <td class="px-4 py-2 text-text-light dark:text-text-dark"><?= htmlspecialchars(implode(', ', array_map('strval', $row)), ENT_QUOTES) ?></td>
                                                            <?php endif; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="<?= max(1, $salesHeaderCount) ?>" class="px-4 py-6 text-center text-subtext-light dark:text-subtext-dark">No data rows detected.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                        <p class="px-4 py-2 text-xs text-subtext-light dark:text-subtext-dark">Showing the first <?= $salesSampleCount ?> row<?= $salesSampleCount === 1 ? '' : 's' ?> from the file.</p>
                                    </div>
                                    <div class="flex flex-wrap gap-3">
                                        <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary/90 transition">Import Sales</button>
                                    </div>
                                </form>
                                <form method="post" class="mt-2">
                                    <input type="hidden" name="action" value="cancel_sales_preview">
                                    <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700">Cancel preview</button>
                                </form>
                            <?php else: ?>
                                <p class="text-sm text-subtext-light dark:text-subtext-dark mb-4">Upload a CSV for a single warehouse. After the upload you'll choose the columns for date, SKU, and quantity.</p>
                                <form method="post" enctype="multipart/form-data" class="space-y-4">
                                    <input type="hidden" name="action" value="preview_sales">
                                    <div>
                                        <label for="salesWarehouse" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Warehouse</label>
                                        <select id="salesWarehouse" name="warehouse_id" required class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                                            <option value="">Choose...</option>
                                            <?php foreach ($warehouses as $warehouse): ?>
                                                <option value="<?= (int) $warehouse['id'] ?>"><?= htmlspecialchars($warehouse['code'] . ' · ' . $warehouse['name'], ENT_QUOTES) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="salesCsv" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Sales CSV</label>
                                        <input type="file" id="salesCsv" name="sales_csv" accept=".csv" required class="w-full text-sm text-subtext-light dark:text-subtext-dark file:mr-4 file:rounded-md file:border-0 file:bg-primary file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-primary/90">
                                        <p class="mt-2 text-xs text-subtext-light dark:text-subtext-dark">Include columns for sale date, SKU, and quantity.</p>
                                    </div>
                                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary/90 transition">Upload &amp; Preview</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="bg-card-light dark:bg-card-dark p-6 rounded-lg shadow-sm border border-border-light dark:border-border-dark">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold">Upload Stock Snapshot CSV</h3>
                            </div>
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
                                    $stockSnapshotDate = (string) ($stockPreview['snapshot_date'] ?? '');
                                    $stockColumnMap = is_array($stockPreview['column_map'] ?? null) ? $stockPreview['column_map'] : [];
                                    $stockFields = ['sku' => 'SKU', 'quantity' => 'Quantity'];
                                ?>
                                <p class="text-sm text-subtext-light dark:text-subtext-dark mb-4">Preview the uploaded file and choose the columns for SKU and quantity.</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm text-subtext-light dark:text-subtext-dark mb-4">
                                    <div><span class="font-medium text-text-light dark:text-text-dark">Warehouse:</span> <?= htmlspecialchars($stockWarehouseLabel, ENT_QUOTES) ?></div>
                                    <div><span class="font-medium text-text-light dark:text-text-dark">Snapshot date:</span> <?= htmlspecialchars($stockSnapshotDate, ENT_QUOTES) ?></div>
                                    <div><span class="font-medium text-text-light dark:text-text-dark">File:</span> <?= htmlspecialchars((string) ($stockPreview['filename'] ?? 'uploaded.csv'), ENT_QUOTES) ?></div>
                                    <div><span class="font-medium text-text-light dark:text-text-dark">Rows previewed:</span> <?= $stockSampleCount ?></div>
                                </div>
                                <form method="post" class="space-y-4">
                                    <input type="hidden" name="action" value="confirm_stock">
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <?php foreach ($stockHeader as $index => $columnLabel):
                                            $displayLabel = trim((string) $columnLabel) !== '' ? (string) $columnLabel : 'Column ' . ($index + 1);
                                        ?>
                                            <div class="border border-border-light dark:border-border-dark rounded-lg p-4">
                                                <div class="text-xs uppercase tracking-wide text-subtext-light dark:text-subtext-dark mb-1">Column <?= $index + 1 ?></div>
                                                <div class="font-medium truncate" title="<?= htmlspecialchars($displayLabel, ENT_QUOTES) ?>">
                                                    <?= htmlspecialchars($displayLabel, ENT_QUOTES) ?>
                                                </div>
                                                <div class="mt-3 space-y-2">
                                                    <?php foreach ($stockFields as $fieldKey => $fieldLabel):
                                                        $checked = isset($stockColumnMap[$fieldKey]) && (int) $stockColumnMap[$fieldKey] === (int) $index;
                                                    ?>
                                                        <label class="flex items-center space-x-2 text-sm">
                                                            <input type="checkbox" class="column-checkbox rounded border-border-light dark:border-border-dark text-primary focus:ring-primary" id="stock-<?= $fieldKey ?>-<?= $index ?>" name="column_map[<?= $fieldKey ?>]" value="<?= $index ?>" data-field="<?= $fieldKey ?>" <?= $checked ? 'checked' : '' ?>>
                                                            <span>Use as <?= htmlspecialchars($fieldLabel, ENT_QUOTES) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($stockHeader)): ?>
                                            <div class="sm:col-span-2">
                                                <div class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-yellow-800">No columns detected in the uploaded file.</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="overflow-x-auto border border-border-light dark:border-border-dark rounded-lg">
                                        <table class="min-w-full text-sm">
                                            <thead class="bg-background-light dark:bg-background-dark text-subtext-light dark:text-subtext-dark">
                                                <tr>
                                                    <?php if ($stockHeaderCount > 0): ?>
                                                        <?php foreach ($stockHeader as $columnLabel):
                                                            $headerLabel = trim((string) $columnLabel) !== '' ? (string) $columnLabel : 'Column';
                                                        ?>
                                                            <th class="px-4 py-2 font-medium"><?= htmlspecialchars($headerLabel, ENT_QUOTES) ?></th>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <th class="px-4 py-2 font-medium">Data</th>
                                                    <?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if ($stockSampleCount > 0): ?>
                                                    <?php foreach ($stockRows as $row): ?>
                                                        <tr class="border-t border-border-light dark:border-border-dark">
                                                            <?php if ($stockHeaderCount > 0): ?>
                                                                <?php for ($i = 0; $i < $stockHeaderCount; $i++): ?>
                                                                    <td class="px-4 py-2 text-text-light dark:text-text-dark"><?= htmlspecialchars((string) ($row[$i] ?? ''), ENT_QUOTES) ?></td>
                                                                <?php endfor; ?>
                                                            <?php else: ?>
                                                                <td class="px-4 py-2 text-text-light dark:text-text-dark"><?= htmlspecialchars(implode(', ', array_map('strval', $row)), ENT_QUOTES) ?></td>
                                                            <?php endif; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="<?= max(1, $stockHeaderCount) ?>" class="px-4 py-6 text-center text-subtext-light dark:text-subtext-dark">No data rows detected.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                        <p class="px-4 py-2 text-xs text-subtext-light dark:text-subtext-dark">Showing the first <?= $stockSampleCount ?> row<?= $stockSampleCount === 1 ? '' : 's' ?> from the file.</p>
                                    </div>
                                    <div class="flex flex-wrap gap-3">
                                        <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary/90 transition">Import Stock Snapshot</button>
                                    </div>
                                </form>
                                <form method="post" class="mt-2">
                                    <input type="hidden" name="action" value="cancel_stock_preview">
                                    <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700">Cancel preview</button>
                                </form>
                            <?php else: ?>
                                <p class="text-sm text-subtext-light dark:text-subtext-dark mb-4">Upload the current inventory levels for a warehouse. We'll use it as the starting point for demand calculations.</p>
                                <form method="post" enctype="multipart/form-data" class="space-y-4">
                                    <input type="hidden" name="action" value="preview_stock">
                                    <div>
                                        <label for="stockWarehouse" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Warehouse</label>
                                        <select id="stockWarehouse" name="warehouse_id" required class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                                            <option value="">Choose...</option>
                                            <?php foreach ($warehouses as $warehouse): ?>
                                                <option value="<?= (int) $warehouse['id'] ?>"><?= htmlspecialchars($warehouse['code'] . ' · ' . $warehouse['name'], ENT_QUOTES) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="snapshotDate" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Snapshot Date</label>
                                        <input type="date" id="snapshotDate" name="snapshot_date" required class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                                    </div>
                                    <div>
                                        <label for="stockCsv" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Stock CSV</label>
                                        <input type="file" id="stockCsv" name="stock_csv" accept=".csv" required class="w-full text-sm text-subtext-light dark:text-subtext-dark file:mr-4 file:rounded-md file:border-0 file:bg-primary file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-primary/90">
                                        <p class="mt-2 text-xs text-subtext-light dark:text-subtext-dark">Include columns for SKU and quantity on hand.</p>
                                    </div>
                                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary/90 transition">Upload &amp; Preview</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section id="section-warehouses" class="<?= $activeSection === 'warehouses' ? '' : 'hidden' ?> space-y-6">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="bg-card-light dark:bg-card-dark p-6 rounded-lg shadow-sm border border-border-light dark:border-border-dark">
                            <h3 class="text-lg font-semibold mb-4">Add or Update Warehouse</h3>
                            <form method="post" class="space-y-4">
                                <input type="hidden" name="action" value="add_warehouse">
                                <div>
                                    <label for="warehouseCode" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Warehouse Code</label>
                                    <input type="text" id="warehouseCode" name="warehouse_code" maxlength="50" required class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                                </div>
                                <div>
                                    <label for="warehouseName" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Warehouse Name</label>
                                    <input type="text" id="warehouseName" name="warehouse_name" maxlength="120" placeholder="Optional" class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                                </div>
                                <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary/90 transition">Save Warehouse</button>
                            </form>
                        </div>
                        <div class="bg-card-light dark:bg-card-dark p-6 rounded-lg shadow-sm border border-border-light dark:border-border-dark">
                            <h3 class="text-lg font-semibold mb-4">Existing Warehouses</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-left text-sm">
                                    <thead class="border-b border-border-light dark:border-border-dark text-subtext-light dark:text-subtext-dark">
                                        <tr>
                                            <th class="px-4 py-2">Code</th>
                                            <th class="px-4 py-2">Name</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($warehouses)): ?>
                                            <?php foreach ($warehouses as $warehouse): ?>
                                                <tr class="border-b border-border-light dark:border-border-dark last:border-0">
                                                    <td class="px-4 py-2 text-text-light dark:text-text-dark"><?= htmlspecialchars($warehouse['code'], ENT_QUOTES) ?></td>
                                                    <td class="px-4 py-2 text-text-light dark:text-text-dark"><?= htmlspecialchars($warehouse['name'], ENT_QUOTES) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="2" class="px-4 py-6 text-center text-subtext-light dark:text-subtext-dark">No warehouses available.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="section-parameters" class="<?= $activeSection === 'parameters' ? '' : 'hidden' ?> space-y-6">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="bg-card-light dark:bg-card-dark p-6 rounded-lg shadow-sm border border-border-light dark:border-border-dark">
                            <h3 class="text-lg font-semibold mb-4">Warehouse Parameters</h3>
                            <form method="post" class="space-y-4">
                                <input type="hidden" name="action" value="save_parameters">
                                <div>
                                    <label for="paramWarehouse" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Warehouse</label>
                                    <select id="paramWarehouse" name="warehouse_id" required class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                                        <option value="">Choose...</option>
                                        <?php foreach ($warehouses as $warehouse):
                                            $warehouseId = (int) $warehouse['id'];
                                            $params = $warehouseParams[$warehouseId] ?? $defaults;
                                        ?>
                                            <option value="<?= $warehouseId ?>" data-params='<?= json_encode($params, JSON_THROW_ON_ERROR) ?>'><?= htmlspecialchars($warehouse['code'] . ' · ' . $warehouse['name'], ENT_QUOTES) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="paramSku" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">SKU Override (optional)</label>
                                    <input type="text" id="paramSku" name="sku" placeholder="Enter SKU to override" class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label for="paramDaysCover" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Days to Cover</label>
                                        <input type="number" min="0" step="1" id="paramDaysCover" name="days_to_cover" value="<?= (int) $defaults['days_to_cover'] ?>" required class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                                    </div>
                                    <div>
                                        <label for="paramWindow" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">MA Window (days)</label>
                                        <input type="number" min="1" step="1" id="paramWindow" name="ma_window_days" value="<?= (int) $defaults['ma_window_days'] ?>" required class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                                    </div>
                                    <div>
                                        <label for="paramMinAvg" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Min Avg Daily</label>
                                        <input type="number" min="0" step="0.01" id="paramMinAvg" name="min_avg_daily" value="<?= htmlspecialchars(number_format((float) $defaults['min_avg_daily'], 2, '.', ''), ENT_QUOTES) ?>" required class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                                    </div>
                                    <div>
                                        <label for="paramSafety" class="block text-sm font-medium text-subtext-light dark:text-subtext-dark mb-1">Safety Days</label>
                                        <input type="number" min="0" step="0.01" id="paramSafety" name="safety_days" value="<?= htmlspecialchars(number_format((float) $defaults['safety_days'], 2, '.', ''), ENT_QUOTES) ?>" required class="w-full rounded-md border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                                    </div>
                                </div>
                                <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary/90 transition">Save Parameters</button>
                            </form>
                        </div>
                        <div class="bg-card-light dark:bg-card-dark p-6 rounded-lg shadow-sm border border-border-light dark:border-border-dark">
                            <h3 class="text-lg font-semibold mb-4">SKU Overrides</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-left text-sm">
                                    <thead class="border-b border-border-light dark:border-border-dark text-subtext-light dark:text-subtext-dark">
                                        <tr>
                                            <th class="px-4 py-2">Warehouse</th>
                                            <th class="px-4 py-2">SKU</th>
                                            <th class="px-4 py-2">Days to Cover</th>
                                            <th class="px-4 py-2">MA Window</th>
                                            <th class="px-4 py-2">Min Avg</th>
                                            <th class="px-4 py-2">Safety Days</th>
                                            <th class="px-4 py-2"></th>
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
                                                    <tr class="border-b border-border-light dark:border-border-dark last:border-0">
                                                        <td class="px-4 py-2 text-text-light dark:text-text-dark"><?= htmlspecialchars($warehouse['code'], ENT_QUOTES) ?></td>
                                                        <td class="px-4 py-2 text-text-light dark:text-text-dark"><?= htmlspecialchars($skuCode, ENT_QUOTES) ?></td>
                                                        <td class="px-4 py-2 text-text-light dark:text-text-dark"><?= (int) $params['days_to_cover'] ?></td>
                                                        <td class="px-4 py-2 text-text-light dark:text-text-dark"><?= (int) $params['ma_window_days'] ?></td>
                                                        <td class="px-4 py-2 text-text-light dark:text-text-dark"><?= htmlspecialchars(number_format((float) $params['min_avg_daily'], 2), ENT_QUOTES) ?></td>
                                                        <td class="px-4 py-2 text-text-light dark:text-text-dark"><?= htmlspecialchars(number_format((float) $params['safety_days'], 2), ENT_QUOTES) ?></td>
                                                        <td class="px-4 py-2 text-right">
                                                            <form method="post" class="inline">
                                                                <input type="hidden" name="action" value="delete_sku_param">
                                                                <input type="hidden" name="warehouse_id" value="<?= (int) $warehouseId ?>">
                                                                <input type="hidden" name="sku" value="<?= htmlspecialchars($skuCode, ENT_QUOTES) ?>">
                                                                <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700">Remove</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach;
                                            endforeach;
                                        else: ?>
                                            <tr>
                                                <td colspan="7" class="px-4 py-6 text-center text-subtext-light dark:text-subtext-dark">No SKU overrides configured.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    const sections = document.querySelectorAll('section[id^="section-"]');
    const navButtons = document.querySelectorAll('#dashboardTabs button[data-section]');
    navButtons.forEach((button) => {
        button.addEventListener('click', () => {
            navButtons.forEach((btn) => btn.classList.remove('bg-primary', 'text-white', 'shadow'));
            navButtons.forEach((btn) => btn.classList.add('text-subtext-light', 'dark:text-subtext-dark'));
            button.classList.add('bg-primary', 'text-white', 'shadow');
            button.classList.remove('text-subtext-light', 'dark:text-subtext-dark');

            sections.forEach((section) => {
                if (section.id === `section-${button.dataset.section}`) {
                    section.classList.remove('hidden');
                } else {
                    section.classList.add('hidden');
                }
            });
        });
    });

    let reorderChart;
    let trendChart;
    let currentRowsMap = new Map();
    let selectedRowKey = null;
    let allRows = [];
    let currentPage = 0;
    let rowsPerPage = 10;
    let sortState = { column: 'reorder_qty', direction: 'desc' };
    let sortButtons = [];

    const columnGetters = {
        warehouse: (row) => `${row.warehouse_code ?? ''} · ${row.warehouse_name ?? ''}`.toLowerCase(),
        sku: (row) => String(row.sku ?? '').toLowerCase(),
        current_stock: (row) => Number(row.current_stock) || 0,
        moving_average: (row) => Number(row.moving_average) || 0,
        days_of_cover: (row) => Number(row.days_of_cover) || 0,
        reorder_qty: (row) => Number(row.reorder_qty) || 0
    };

    function getRowKey(row) {
        return `${row.warehouse_id}|${row.sku}`;
    }

    function formatInteger(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '0';
        }
        return Math.round(number).toLocaleString();
    }

    function formatDecimal(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '0.00';
        }
        return number.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function createDaysBadge(value) {
        const span = document.createElement('span');
        span.className = 'px-2 py-1 text-xs font-semibold rounded-full';
        const number = Number(value);
        if (!Number.isFinite(number)) {
            span.textContent = '—';
            span.classList.add('bg-gray-200', 'text-gray-600');
            return span;
        }
        if (number <= 0) {
            span.classList.add('bg-red-100', 'text-red-800');
        } else if (number <= 5) {
            span.classList.add('bg-yellow-100', 'text-yellow-800');
        } else {
            span.classList.add('bg-green-100', 'text-green-800');
        }
        span.textContent = formatInteger(number);
        return span;
    }

    function setRows(rows, summary) {
        allRows = Array.isArray(rows) ? [...rows] : [];
        currentRowsMap = new Map(allRows.map((row) => [getRowKey(row), row]));
        applySort();
        currentPage = 0;
        ensureSelectedRow();
        renderTablePage();
        updateSortIndicators();
        updateSummary(allRows, summary);
        updateReorderChart(allRows);
    }

    function applySort() {
        const getter = columnGetters[sortState.column];
        if (!getter) {
            return;
        }
        const direction = sortState.direction === 'asc' ? 1 : -1;
        allRows.sort((a, b) => {
            const valueA = getter(a);
            const valueB = getter(b);
            if (typeof valueA === 'number' && typeof valueB === 'number') {
                return (valueA - valueB) * direction;
            }
            if (valueA < valueB) {
                return -1 * direction;
            }
            if (valueA > valueB) {
                return 1 * direction;
            }
            return 0;
        });
    }

    function ensureSelectedRow() {
        if (allRows.length === 0) {
            selectedRowKey = null;
            updateTrendChart(null);
            return;
        }
        if (!selectedRowKey || !currentRowsMap.has(selectedRowKey)) {
            selectedRowKey = getRowKey(allRows[0]);
            currentPage = 0;
        }
        updateTrendChart(selectedRowKey);
    }

    function renderTablePage() {
        const tbody = document.getElementById('demandTableBody');
        if (!tbody) {
            return;
        }
        tbody.innerHTML = '';
        const totalRows = allRows.length;
        if (totalRows === 0) {
            const emptyRow = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 6;
            cell.className = 'p-6 text-center text-subtext-light dark:text-subtext-dark';
            cell.textContent = 'No data available for the selected filters.';
            emptyRow.appendChild(cell);
            tbody.appendChild(emptyRow);
            updateTableSummary(0, 0, 0);
            updatePaginationControls(0);
            return;
        }
        const totalPages = Math.max(1, Math.ceil(totalRows / rowsPerPage));
        if (currentPage >= totalPages) {
            currentPage = totalPages - 1;
        }
        const start = currentPage * rowsPerPage;
        const end = Math.min(start + rowsPerPage, totalRows);
        const pageRows = allRows.slice(start, end);

        pageRows.forEach((row) => {
            const key = getRowKey(row);
            const tr = document.createElement('tr');
            tr.dataset.rowKey = key;
            tr.className = 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 transition';

            const warehouseCell = document.createElement('td');
            warehouseCell.className = 'p-4 whitespace-nowrap';
            warehouseCell.textContent = `${row.warehouse_code} · ${row.warehouse_name}`;
            tr.appendChild(warehouseCell);

            const skuCell = document.createElement('td');
            skuCell.className = 'p-4 whitespace-nowrap font-medium text-primary';
            skuCell.textContent = row.sku;
            tr.appendChild(skuCell);

            const stockCell = document.createElement('td');
            stockCell.className = 'p-4 whitespace-nowrap text-right';
            stockCell.textContent = formatInteger(row.current_stock);
            tr.appendChild(stockCell);

            const movingAvgCell = document.createElement('td');
            movingAvgCell.className = 'p-4 whitespace-nowrap text-right';
            movingAvgCell.textContent = formatDecimal(row.moving_average);
            tr.appendChild(movingAvgCell);

            const daysCell = document.createElement('td');
            daysCell.className = 'p-4 whitespace-nowrap text-right';
            daysCell.appendChild(createDaysBadge(row.days_of_cover));
            tr.appendChild(daysCell);

            const reorderCell = document.createElement('td');
            reorderCell.className = 'p-4 whitespace-nowrap text-right font-bold';
            reorderCell.textContent = formatInteger(row.reorder_qty);
            tr.appendChild(reorderCell);

            tr.addEventListener('click', () => {
                selectedRowKey = key;
                updateRowSelection();
                updateTrendChart(key);
            });

            tbody.appendChild(tr);
        });
        updateRowSelection();
        updateTableSummary(start, end, totalRows);
        updatePaginationControls(totalPages);
    }

    function updateRowSelection() {
        const tbody = document.getElementById('demandTableBody');
        if (!tbody) {
            return;
        }
        tbody.querySelectorAll('tr').forEach((row) => {
            row.classList.remove('bg-primary/5', 'dark:bg-primary/10', 'border-l-4', 'border-primary');
            if (row.dataset.rowKey === selectedRowKey) {
                row.classList.add('bg-primary/5', 'dark:bg-primary/10', 'border-l-4', 'border-primary');
            }
        });
    }

    function updateTableSummary(start, end, total) {
        const summary = document.getElementById('tableSummary');
        if (!summary) {
            return;
        }
        if (total === 0) {
            summary.textContent = 'No data loaded.';
            return;
        }
        summary.textContent = `Showing ${start + 1} to ${end} of ${total} entries`;
    }

    function updatePaginationControls(totalPages) {
        const container = document.getElementById('tablePagination');
        const prevButton = document.getElementById('paginationPrev');
        const nextButton = document.getElementById('paginationNext');
        const pagesContainer = document.getElementById('paginationPages');
        if (!container || !prevButton || !nextButton || !pagesContainer) {
            return;
        }
        if (totalPages <= 1) {
            container.classList.add('hidden');
            pagesContainer.innerHTML = '';
            prevButton.disabled = true;
            nextButton.disabled = true;
            prevButton.classList.add('opacity-50', 'cursor-not-allowed');
            nextButton.classList.add('opacity-50', 'cursor-not-allowed');
            return;
        }
        container.classList.remove('hidden');
        prevButton.disabled = currentPage === 0;
        nextButton.disabled = currentPage >= totalPages - 1;
        prevButton.classList.toggle('opacity-50', prevButton.disabled);
        prevButton.classList.toggle('cursor-not-allowed', prevButton.disabled);
        nextButton.classList.toggle('opacity-50', nextButton.disabled);
        nextButton.classList.toggle('cursor-not-allowed', nextButton.disabled);
        pagesContainer.innerHTML = '';
        getPageList(totalPages).forEach((page) => {
            if (page === 'ellipsis') {
                const span = document.createElement('span');
                span.className = 'px-2 text-subtext-light dark:text-subtext-dark';
                span.textContent = '…';
                pagesContainer.appendChild(span);
                return;
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = page + 1;
            button.className = 'px-3 py-1 rounded-md text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
            if (page === currentPage) {
                button.classList.add('bg-primary', 'text-white', 'shadow');
            } else {
                button.classList.add('bg-card-light', 'dark:bg-card-dark', 'text-subtext-light', 'dark:text-subtext-dark', 'border', 'border-border-light', 'dark:border-border-dark', 'hover:bg-gray-100', 'dark:hover:bg-gray-700');
            }
            button.addEventListener('click', () => {
                goToPage(page);
            });
            pagesContainer.appendChild(button);
        });
    }

    function getPageList(totalPages) {
        const pages = [];
        if (totalPages <= 6) {
            for (let i = 0; i < totalPages; i += 1) {
                pages.push(i);
            }
            return pages;
        }
        pages.push(0);
        const start = Math.max(currentPage - 1, 1);
        const end = Math.min(currentPage + 1, totalPages - 2);
        if (start > 1) {
            pages.push('ellipsis');
        }
        for (let i = start; i <= end; i += 1) {
            pages.push(i);
        }
        if (end < totalPages - 2) {
            pages.push('ellipsis');
        }
        pages.push(totalPages - 1);
        return pages;
    }

    function goToPage(pageIndex) {
        const totalPages = Math.max(1, Math.ceil(allRows.length / rowsPerPage));
        if (pageIndex < 0 || pageIndex >= totalPages) {
            return;
        }
        currentPage = pageIndex;
        renderTablePage();
    }

    function changePage(delta) {
        goToPage(currentPage + delta);
    }

    function updateSortIndicators() {
        sortButtons.forEach((button) => {
            const icon = button.querySelector('.sort-icon');
            const isActive = button.dataset.sort === sortState.column;
            if (icon) {
                icon.classList.toggle('opacity-0', !isActive);
                if (isActive) {
                    icon.textContent = sortState.direction === 'asc' ? 'arrow_drop_up' : 'arrow_drop_down';
                }
            }
            button.classList.toggle('text-primary', isActive);
        });
    }

    function setSort(column) {
        if (!column || !columnGetters[column]) {
            return;
        }
        if (sortState.column === column) {
            sortState = {
                column,
                direction: sortState.direction === 'asc' ? 'desc' : 'asc'
            };
        } else {
            const defaultDirection = column === 'warehouse' || column === 'sku' ? 'asc' : 'desc';
            sortState = { column, direction: defaultDirection };
        }
        applySort();
        let targetPage = 0;
        if (selectedRowKey && currentRowsMap.has(selectedRowKey)) {
            const index = allRows.findIndex((row) => getRowKey(row) === selectedRowKey);
            if (index >= 0) {
                targetPage = Math.floor(index / rowsPerPage);
            } else if (allRows.length > 0) {
                selectedRowKey = getRowKey(allRows[0]);
                updateTrendChart(selectedRowKey);
            } else {
                selectedRowKey = null;
                updateTrendChart(null);
            }
        } else if (allRows.length > 0) {
            selectedRowKey = getRowKey(allRows[0]);
            updateTrendChart(selectedRowKey);
        } else {
            selectedRowKey = null;
            updateTrendChart(null);
        }
        currentPage = targetPage;
        renderTablePage();
        updateSortIndicators();
    }

    function updateSummary(rows, summary) {
        const itemsEl = document.getElementById('summaryItems');
        const reorderEl = document.getElementById('summaryReorder');
        if (!itemsEl || !reorderEl) {
            return;
        }
        const totalItems = summary && Number.isFinite(Number(summary.total_items))
            ? Number(summary.total_items)
            : rows.length;
        const totalReorder = summary && Number.isFinite(Number(summary.total_reorder_qty))
            ? Number(summary.total_reorder_qty)
            : rows.reduce((sum, row) => {
                const value = Number(row.reorder_qty);
                return Number.isFinite(value) ? sum + value : sum;
            }, 0);
        itemsEl.textContent = totalItems.toLocaleString();
        reorderEl.textContent = Math.round(totalReorder).toLocaleString();
    }

    function updateReorderChart(rows) {
        const ctx = document.getElementById('reorderChart');
        if (!ctx) {
            return;
        }
        const topRows = [...rows]
            .sort((a, b) => Number(b.reorder_qty || 0) - Number(a.reorder_qty || 0))
            .slice(0, 10);
        const labels = topRows.map((row) => `${row.warehouse_code}-${row.sku}`);
        const values = topRows.map((row) => Number(row.reorder_qty) || 0);

        if (reorderChart) {
            reorderChart.destroy();
        }
        reorderChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Reorder Quantity',
                    data: values,
                    backgroundColor: '#007BFF'
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    function updateTrendChart(rowKey) {
        const ctx = document.getElementById('trendChart');
        const labelEl = document.getElementById('trendSelectedLabel');
        if (!ctx || !labelEl) {
            return;
        }
        if (trendChart) {
            trendChart.destroy();
        }
        if (!rowKey || !currentRowsMap.has(rowKey)) {
            labelEl.textContent = 'No SKU selected';
            trendChart = new Chart(ctx, {
                type: 'line',
                data: { labels: [], datasets: [{ data: [], borderColor: '#007BFF', tension: 0.3, fill: false }] },
                options: { scales: { y: { beginAtZero: true } } }
            });
            return;
        }
        const row = currentRowsMap.get(rowKey);
        labelEl.textContent = `${row.warehouse_code}-${row.sku}`;
        const labels = Object.keys(row.daily_series || {});
        const values = labels.map((date) => row.daily_series[date]);
        trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: `${row.warehouse_code}-${row.sku}`,
                    data: values,
                    borderColor: '#007BFF',
                    backgroundColor: 'rgba(0, 123, 255, 0.15)',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    }

    function refreshDashboard() {
        const warehouseId = document.getElementById('warehouseFilter');
        const skuFilter = document.getElementById('skuFilter');
        if (!warehouseId || !skuFilter) {
            return;
        }
        const params = new URLSearchParams();
        if (warehouseId.value) params.append('warehouse_id', warehouseId.value);
        if (skuFilter.value.trim()) params.append('sku', skuFilter.value.trim());

        const url = 'api.php' + (params.toString() ? `?${params.toString()}` : '');
        fetch(url, { credentials: 'same-origin' })
            .then((response) => response.json())
            .then((payload) => {
                const rows = Array.isArray(payload.data) ? payload.data : [];
                const summary = payload && typeof payload.summary === 'object' ? payload.summary : null;
                setRows(rows, summary);
            })
            .catch(() => {
                setRows([], { total_items: 0, total_reorder_qty: 0 });
                const summary = document.getElementById('tableSummary');
                if (summary) {
                    summary.textContent = 'Unable to load dashboard data.';
                }
                console.error('Unable to load dashboard data.');
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

    document.addEventListener('DOMContentLoaded', () => {
        sortButtons = Array.from(document.querySelectorAll('#demandTable .sort-button'));
        sortButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (button.dataset.sort) {
                    setSort(button.dataset.sort);
                }
            });
        });
        updateSortIndicators();

        const prevButton = document.getElementById('paginationPrev');
        const nextButton = document.getElementById('paginationNext');
        if (prevButton) {
            prevButton.addEventListener('click', () => changePage(-1));
        }
        if (nextButton) {
            nextButton.addEventListener('click', () => changePage(1));
        }

        const rowsPerPageSelect = document.getElementById('rowsPerPageSelect');
        if (rowsPerPageSelect) {
            rowsPerPageSelect.value = String(rowsPerPage);
            rowsPerPageSelect.addEventListener('change', () => {
                const value = Number(rowsPerPageSelect.value);
                if (!Number.isFinite(value) || value <= 0) {
                    return;
                }
                rowsPerPage = value;
                currentPage = 0;
                renderTablePage();
            });
        }

        const warehouseFilter = document.getElementById('warehouseFilter');
        const skuFilter = document.getElementById('skuFilter');
        if (warehouseFilter) {
            warehouseFilter.addEventListener('change', refreshDashboard);
        }
        if (skuFilter) {
            skuFilter.addEventListener('input', () => {
                clearTimeout(skuFilter._timer);
                skuFilter._timer = setTimeout(refreshDashboard, 400);
            });
        }
        refreshDashboard();
        setupColumnCheckboxes();
    });
</script>
</body>
</html>
