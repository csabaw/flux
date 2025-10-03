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
                $name = trim($_POST['warehouse_name'] ?? '');
                if ($name === '') {
                    $errors[] = 'Warehouse name is required.';
                    break;
                }
                $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
                if ($nameLength > 120) {
                    $errors[] = 'Warehouse name must be 120 characters or fewer.';
                    break;
                }
                $result = upsertWarehouse($mysqli, $name);
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


$cardClass = "rounded-2xl border border-white/5 bg-[#23262b] p-6 shadow-[0_24px_48px_rgba(8,10,12,0.35)]";
$inputClass = "mt-1 block w-full rounded-xl border border-white/10 bg-[#1d2026] px-3 py-2 text-sm text-gray-100 shadow-inner transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40";
$labelClass = "text-sm font-semibold text-gray-300";
$helperClass = "mt-1 text-xs text-gray-600 dark:text-gray-300";
$buttonPrimaryClass = "inline-flex items-center justify-center gap-2 rounded-full bg-primary px-5 py-2 text-sm font-semibold text-white shadow-lg shadow-primary/30 transition hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary/40";
$buttonSecondaryClass = "inline-flex items-center justify-center gap-2 rounded-full border border-white/10 bg-[#1f2227] px-5 py-2 text-sm font-semibold text-gray-200 transition hover:border-primary/60 hover:text-white focus:outline-none focus:ring-2 focus:ring-primary/30";
$checkboxClass = "h-4 w-4 rounded border-white/30 bg-[#1d2026] text-primary focus:ring-primary/60";
$tabBaseClass = "section-tab border-b-2 border-transparent px-4 py-3 text-sm font-semibold tracking-wide text-gray-400 transition focus:outline-none focus:ring-2 focus:ring-primary/30";
$tabActiveClass = "border-primary text-white";
$tabInactiveClass = "hover:border-white/10 hover:text-white";
$tabs = [
    'dashboard' => 'Dashboard',
    'imports' => 'Data Import',
    'warehouses' => 'Warehouses',
    'parameters' => 'Parameters',
];

?>
<!DOCTYPE html>

<html lang="en" class="dark h-full">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Multi-Warehouse Demand Planning</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Noto+Sans:wght@400;500;700;900&display=swap">
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#0f91bd',
                        'background-light': '#f6f7f8',
                        'background-dark': '#101d22',
                    },
                    fontFamily: {
                        display: ['Manrope', 'Noto Sans', 'ui-sans-serif', 'system-ui'],
                    },
                    borderRadius: {
                        DEFAULT: '0.25rem',
                        lg: '0.5rem',
                        xl: '0.75rem',
                        full: '9999px',
                    },
                },
            },
        };
    </script>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined">
    <link rel="stylesheet" href="styles.css">
</head>

<body class="min-h-screen bg-[#121417] font-display text-gray-200 antialiased">
    <div class="flex min-h-screen flex-col">
        <header class="border-b border-white/10 bg-[#1b1e23]/95 backdrop-blur">
            <div class="mx-auto flex w-full max-w-none flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-2xl bg-primary/20 text-primary">

                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none" class="size-6">
                            <path d="M24 45.8096C19.6865 45.8096 15.4698 44.5305 11.8832 42.134C8.29667 39.7376 5.50128 36.3314 3.85056 32.3462C2.19985 28.361 1.76794 23.9758 2.60947 19.7452C3.451 15.5145 5.52816 11.6284 8.57829 8.5783C11.6284 5.52817 15.5145 3.45101 19.7452 2.60948C23.9758 1.76795 28.361 2.19986 32.3462 3.85057C36.3314 5.50129 39.7376 8.29668 42.134 11.8833C44.5305 15.4698 45.8096 19.6865 45.8096 24L24 24L24 45.8096Z" fill="currentColor" />
                        </svg>
                    </div>
                    <div>

                        <p class="text-[11px] font-semibold uppercase tracking-[0.35em] text-primary/80">FluxForecast</p>
                        <h1 class="text-lg font-semibold text-white">Demand Planning Workspace</h1>

                    </div>
                </div>
                <?php if (is_logged_in()): ?>
                <nav class="flex flex-1 justify-center">

                    <div class="flex flex-wrap items-center gap-6">

                        <?php foreach ($tabs as $tabKey => $tabLabel):
                            $isActive = $activeSection === $tabKey;
                            $buttonClasses = $tabBaseClass . ' ' . ($isActive ? $tabActiveClass : $tabInactiveClass);
                        ?>
                        <button
                            type="button"

                            class="<?= $buttonClasses ?> uppercase tracking-[0.2em]"

                            data-section-trigger="<?= htmlspecialchars($tabKey, ENT_QUOTES) ?>"
                            aria-selected="<?= $isActive ? 'true' : 'false' ?>"
                        >
                            <?= htmlspecialchars($tabLabel, ENT_QUOTES) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </nav>
                <?php endif; ?>
                <div class="flex items-center gap-3">
                    <?php if (is_logged_in()): ?>

                    <a href="?action=logout" class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-[#23262b] px-5 py-2 text-sm font-semibold text-gray-200 transition hover:border-primary/60 hover:text-white focus:outline-none focus:ring-2 focus:ring-primary/30">

                        <span class="material-symbols-outlined text-base">logout</span>
                        <span>Log out</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </header>
        <main class="flex-1 px-4 py-10 sm:px-6 lg:px-8">
            <div class="mx-auto w-full max-w-none space-y-6">
                <?php foreach ($messages as $message): ?>

                    <div class="flex items-start justify-between gap-4 rounded-2xl border border-emerald-400/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100 shadow-lg shadow-emerald-900/20" role="alert" data-alert>
                        <span><?= htmlspecialchars($message, ENT_QUOTES) ?></span>
                        <button type="button" class="text-emerald-200 transition hover:text-white" data-dismiss-alert>&times;</button>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($errors as $error): ?>
                    <div class="flex items-start justify-between gap-4 rounded-2xl border border-rose-400/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-200 shadow-lg shadow-rose-900/30" role="alert" data-alert>
                        <span><?= htmlspecialchars($error, ENT_QUOTES) ?></span>
                        <button type="button" class="text-rose-200 transition hover:text-white" data-dismiss-alert>&times;</button>

                    </div>
                <?php endforeach; ?>

                <?php if (!is_logged_in()): ?>
                    <div class="flex min-h-[60vh] items-center justify-center">
                        <div class="w-full max-w-md <?= $cardClass ?> sm:p-8">
                            <div class="mb-6 text-center">
                                <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">Admin Login</h2>
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Sign in to access warehouse demand insights.</p>
                            </div>
                            <form method="post" class="space-y-4" novalidate>
                                <input type="hidden" name="action" value="login">
                                <div>
                                    <label class="<?= $labelClass ?>" for="username">Username</label>
                                    <input class="<?= $inputClass ?>" type="text" id="username" name="username" required autofocus>
                                </div>
                                <div>
                                    <label class="<?= $labelClass ?>" for="password">Password</label>
                                    <input class="<?= $inputClass ?>" type="password" id="password" name="password" required>
                                </div>
                                <button class="<?= $buttonPrimaryClass ?> w-full" type="submit">Sign in</button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 class="text-3xl font-semibold text-white">Operations Overview</h2>
                            <p class="text-sm text-gray-400">Monitor inventory coverage, demand trends, and configuration across warehouses.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="<?= $buttonSecondaryClass ?>" data-section-trigger="imports">
                                <span class="material-symbols-outlined text-base">upload_file</span>
                                <span>Go to Imports</span>
                            </button>
                            <button type="button" class="<?= $buttonSecondaryClass ?>" data-section-trigger="parameters">
                                <span class="material-symbols-outlined text-base">tune</span>
                                <span>Adjust Parameters</span>
                            </button>
                        </div>
                    </div>

                    <section data-section="dashboard" class="space-y-8<?= $activeSection === 'dashboard' ? '' : ' hidden' ?>">
                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="<?= $cardClass ?>">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.35em] text-gray-400">Tracked SKUs</p>
                                <p class="mt-3 text-4xl font-semibold text-white" id="summaryItems">0</p>
                                <p class="mt-3 text-sm text-gray-400">SKUs across selected filters</p>
                            </div>
                            <div class="<?= $cardClass ?>">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.35em] text-gray-400">Total Reorder</p>
                                <p class="mt-3 text-4xl font-semibold text-primary" id="summaryReorder">0</p>
                                <p class="mt-3 text-sm text-gray-400">Units required to hit coverage targets</p>
                            </div>
                            <div class="<?= $cardClass ?>">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.35em] text-gray-400">Low Coverage (&le; 7 days)</p>
                                <p class="mt-3 text-4xl font-semibold text-orange-400" id="summaryLowCover">0</p>
                                <p class="mt-3 text-sm text-gray-400">SKUs below protection threshold</p>
                            </div>
                            <div class="<?= $cardClass ?>">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.35em] text-gray-400">Warehouses</p>
                                <p class="mt-3 text-4xl font-semibold text-yellow-400" id="summaryWarehouses">0</p>
                                <p class="mt-3 text-sm text-gray-400">Active locations in focus</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[2fr_1fr]">
                            <div class="<?= $cardClass ?>">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                    <div>
                                        <h3 class="text-lg font-semibold text-white">Demand &amp; Replenishment</h3>
                                        <p class="text-sm text-gray-400">Latest coverage by SKU and warehouse.</p>
                                    </div>
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                        <label class="sr-only" for="warehouseFilter">Warehouse</label>
                                        <select id="warehouseFilter" name="warehouse_id" class="<?= $inputClass ?> sm:w-56">
                                            <option value="">All Warehouses</option>
                                            <?php foreach ($warehouses as $warehouse): ?>
                                                <option value="<?= (int) $warehouse['id'] ?>"><?= htmlspecialchars($warehouse['name'], ENT_QUOTES) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label class="sr-only" for="skuFilter">SKU filter</label>
                                        <input id="skuFilter" type="text" placeholder="Filter by SKU" class="<?= $inputClass ?> sm:w-48">
                                    </div>
                                </div>
                                <div id="demandTableContainer" class="mt-6 overflow-hidden rounded-2xl border border-white/10 bg-white/[0.02] shadow-lg shadow-black/30">
                                    <div id="dashboardLoading" class="hidden px-6 py-10 text-center text-sm text-gray-400" role="status" aria-live="polite" aria-hidden="true">
                                        <div class="flex flex-col items-center justify-center gap-3">
                                            <span class="loading-spinner" aria-hidden="true"></span>
                                            <span>Loading latest demand data&hellip;</span>
                                        </div>
                                    </div>
                                    <div id="demandTableScroll" class="overflow-x-auto">
                                        <table id="demandTable" class="w-full min-w-[960px] table-auto text-sm text-gray-200">
                                            <thead class="bg-white/[0.03] text-xs font-medium uppercase tracking-[0.3em] text-gray-400">
                                                <tr>
                                                    <th class="px-6 py-4 text-left text-gray-300">
                                                        <button type="button" class="sort-button flex w-full items-center gap-1 text-left text-xs font-medium uppercase tracking-[0.3em] text-gray-300 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" data-sort-key="warehouse">
                                                            <span>Warehouse</span>
                                                            <span class="material-symbols-outlined text-base leading-none opacity-0 transition" data-sort-icon>arrow_upward</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-6 py-4 text-left text-gray-300">
                                                        <button type="button" class="sort-button flex w-full items-center gap-1 text-left text-xs font-medium uppercase tracking-[0.3em] text-gray-300 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" data-sort-key="sku">
                                                            <span>SKU</span>
                                                            <span class="material-symbols-outlined text-base leading-none opacity-0 transition" data-sort-icon>arrow_upward</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-6 py-4 text-left text-gray-300">
                                                        <button type="button" class="sort-button flex w-full items-center gap-1 text-left text-xs font-medium uppercase tracking-[0.3em] text-gray-300 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" data-sort-key="onHand">
                                                            <span>On-hand</span>
                                                            <span class="material-symbols-outlined text-base leading-none opacity-0 transition" data-sort-icon>arrow_upward</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-6 py-4 text-left text-gray-300">
                                                        <button type="button" class="sort-button flex w-full items-center gap-1 text-left text-xs font-medium uppercase tracking-[0.3em] text-gray-300 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" data-sort-key="movingAverage">
                                                            <span>Moving Avg</span>
                                                            <span class="material-symbols-outlined text-base leading-none opacity-0 transition" data-sort-icon>arrow_upward</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-6 py-4 text-left text-gray-300">
                                                        <button type="button" class="sort-button flex w-full items-center gap-1 text-left text-xs font-medium uppercase tracking-[0.3em] text-gray-300 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" data-sort-key="daysOfCover">
                                                            <span>Days of Cover</span>
                                                            <span class="material-symbols-outlined text-base leading-none opacity-0 transition" data-sort-icon>arrow_upward</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-6 py-4 text-left text-gray-300">
                                                        <button type="button" class="sort-button flex w-full items-center gap-1 text-left text-xs font-medium uppercase tracking-[0.3em] text-gray-300 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" data-sort-key="reorderQty">
                                                            <span>Reorder Qty</span>
                                                            <span class="material-symbols-outlined text-base leading-none opacity-0 transition" data-sort-icon>arrow_upward</span>
                                                        </button>
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-white/5 bg-white/[0.01]"></tbody>
                                        </table>
                                    </div>
                                    <div id="demandEmptyState" class="hidden border-t border-white/5 bg-transparent py-10 text-center text-sm text-gray-500 dark:text-gray-400">No SKUs found for the selected filters.</div>
                                </div>
                            </div>
                            <div class="flex flex-col gap-6">
                                <div class="<?= $cardClass ?>">
                                    <h3 class="text-lg font-semibold text-white">Reorder Highlights</h3>
                                    <p class="text-sm text-gray-400">Top 10 SKUs ranked by reorder quantity.</p>
                                    <div class="mt-4 h-48">
                                        <canvas id="reorderChart"></canvas>
                                        <div id="reorderEmptyState" class="hidden py-6 text-center text-sm text-gray-500 dark:text-gray-400">Upload demand and stock data to see reorder insights.</div>
                                    </div>
                                </div>
                                <div class="<?= $cardClass ?>">
                                    <h3 class="text-lg font-semibold text-white">Rolling Demand Trend</h3>
                                    <p class="text-sm text-gray-400">Select a SKU row to visualize its recent daily demand.</p>
                                    <div class="mt-4 h-48">
                                        <canvas id="trendChart"></canvas>
                                        <div id="trendEmptyState" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">Choose a SKU from the table to explore its demand pattern.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section data-section="imports" class="space-y-6<?= $activeSection === 'imports' ? '' : ' hidden' ?>">
                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                            <div class="<?= $cardClass ?>">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Upload Daily Sales CSV</h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-200">Upload a file and map the sale date, SKU, and quantity columns.</p>
                                    </div>
                                    <?php if ($salesPreview): ?>
                                    <span class="inline-flex items-center rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">Preview ready</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-6 space-y-6">
                                    <?php if ($salesPreview): ?>
                                        <?php
                                            $salesHeader = is_array($salesPreview['header'] ?? null) ? $salesPreview['header'] : [];
                                            $salesRows = is_array($salesPreview['rows'] ?? null) ? $salesPreview['rows'] : [];
                                            $salesHeaderCount = count($salesHeader);
                                            $salesSampleCount = count($salesRows);
                                            $salesWarehouseId = (int) ($salesPreview['warehouse_id'] ?? 0);
                                            $salesWarehouseInfo = $warehouses[$salesWarehouseId] ?? null;
                                            $salesWarehouseLabel = $salesWarehouseInfo
                                                ? $salesWarehouseInfo['name']
                                                : ('ID ' . $salesWarehouseId);
                                            $salesColumnMap = is_array($salesPreview['column_map'] ?? null) ? $salesPreview['column_map'] : [];
                                            $salesFields = ['sale_date' => 'Sale Date', 'sku' => 'SKU', 'quantity' => 'Quantity'];
                                        ?>
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-200">Warehouse</p>
                                                <p class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-50"><?= htmlspecialchars($salesWarehouseLabel, ENT_QUOTES) ?></p>
                                            </div>
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-200">File</p>
                                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-100"><?= htmlspecialchars((string) ($salesPreview['filename'] ?? 'uploaded.csv'), ENT_QUOTES) ?></p>
                                            </div>
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-200">Rows previewed</p>
                                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-100"><?= $salesSampleCount ?></p>
                                            </div>
                                        </div>
                                        <form method="post" class="space-y-6">
                                            <input type="hidden" name="action" value="confirm_sales">
                                            <div class="grid gap-4 md:grid-cols-3">
                                                <?php foreach ($salesHeader as $index => $columnLabel):
                                                    $displayLabel = trim((string) $columnLabel) !== '' ? (string) $columnLabel : 'Column ' . ($index + 1);
                                                ?>
                                                <div class="flex flex-col gap-2 rounded-xl border border-white/10 bg-[#1f2227] p-4 text-sm text-gray-200 shadow-lg shadow-black/40">
                                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-200">Column <?= $index + 1 ?></p>
                                                    <p class="truncate font-medium text-gray-900 dark:text-gray-50" title="<?= htmlspecialchars($displayLabel, ENT_QUOTES) ?>"><?= htmlspecialchars($displayLabel, ENT_QUOTES) ?></p>
                                                    <div class="mt-2 space-y-2">
                                                        <?php foreach ($salesFields as $fieldKey => $fieldLabel):
                                                            $checked = isset($salesColumnMap[$fieldKey]) && (int) $salesColumnMap[$fieldKey] === (int) $index;
                                                        ?>
                                                        <label class="flex items-center gap-2 text-xs font-medium text-gray-600 dark:text-gray-100">
                                                            <input class="<?= $checkboxClass ?> column-checkbox" type="checkbox" id="sales-<?= $fieldKey ?>-<?= $index ?>" name="column_map[<?= $fieldKey ?>]" value="<?= $index ?>" data-field="<?= $fieldKey ?>" <?= $checked ? 'checked' : '' ?>>
                                                            <span>Use as <?= htmlspecialchars($fieldLabel, ENT_QUOTES) ?></span>
                                                        </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php if (empty($salesHeader)): ?>
                                            <div class="rounded-xl border border-amber-400/60 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">No columns detected in the uploaded file.</div>
                                            <?php endif; ?>
                                            <div class="overflow-x-auto rounded-xl border border-white/10">
                                                <table class="min-w-full divide-y divide-white/10 text-sm text-gray-200">
                                                    <thead class="bg-[#1b1e23]/60">
                                                        <tr>
                                                            <?php if ($salesHeaderCount > 0): ?>
                                                                <?php foreach ($salesHeader as $columnLabel):
                                                                    $headerLabel = trim((string) $columnLabel) !== '' ? (string) $columnLabel : 'Column';
                                                                ?>
                                                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-[0.18em] text-gray-200"><?= htmlspecialchars($headerLabel, ENT_QUOTES) ?></th>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-[0.18em] text-gray-200">Data</th>
                                                            <?php endif; ?>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-white/10">
                                                        <?php if ($salesSampleCount > 0): ?>
                                                            <?php foreach ($salesRows as $row): ?>
                                                            <tr class="bg-[#1c1f25] text-sm text-gray-100">
                                                                <?php if ($salesHeaderCount > 0): ?>
                                                                    <?php for ($i = 0; $i < $salesHeaderCount; $i++): ?>
                                                                    <td class="px-4 py-2 text-gray-100"><?= htmlspecialchars((string) ($row[$i] ?? ''), ENT_QUOTES) ?></td>
                                                                    <?php endfor; ?>
                                                                <?php else: ?>
                                                                    <td class="px-4 py-2 text-gray-100"><?= htmlspecialchars(implode(', ', array_map('strval', $row)), ENT_QUOTES) ?></td>
                                                                <?php endif; ?>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <tr>
                                                                <td class="px-4 py-6 text-center text-gray-600 dark:text-gray-300">No data rows detected.</td>
                                                            </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                                <p class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">Showing the first <?= $salesSampleCount ?> row<?= $salesSampleCount === 1 ? '' : 's' ?> from the file.</p>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-3">
                                                <button class="<?= $buttonPrimaryClass ?>" type="submit">Import Sales</button>
                                            </div>
                                        </form>
                                        <form method="post" class="mt-2">
                                            <input type="hidden" name="action" value="cancel_sales_preview">
                                            <button class="inline-flex items-center text-sm font-semibold text-rose-500 transition hover:text-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-400/40" type="submit">Cancel preview</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" enctype="multipart/form-data" class="space-y-4">
                                            <input type="hidden" name="action" value="preview_sales">
                                            <div>
                                                <label class="<?= $labelClass ?>" for="salesWarehouse">Warehouse</label>
                                                <select class="<?= $inputClass ?>" id="salesWarehouse" name="warehouse_id" required>
                                                    <option value="">Select warehouse</option>
                                                    <?php foreach ($warehouses as $warehouse): ?>
                                                        <option value="<?= (int) $warehouse['id'] ?>"><?= htmlspecialchars($warehouse['name'], ENT_QUOTES) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="<?= $labelClass ?>" for="salesCsv">Daily Sales CSV</label>
                                                <input class="<?= $inputClass ?>" type="file" id="salesCsv" name="sales_csv" accept=".csv" required>
                                                <p class="<?= $helperClass ?>">Include sale date (YYYY-MM-DD), SKU, and quantity columns.</p>
                                            </div>
                                            <button class="<?= $buttonPrimaryClass ?>" type="submit">Preview Sales File</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="<?= $cardClass ?>">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Upload Stock Snapshot CSV</h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-200">Provide the latest stock position and map SKU and quantity columns.</p>
                                    </div>
                                    <?php if ($stockPreview): ?>
                                    <span class="inline-flex items-center rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">Preview ready</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-6 space-y-6">
                                    <?php if ($stockPreview): ?>
                                        <?php
                                            $stockHeader = is_array($stockPreview['header'] ?? null) ? $stockPreview['header'] : [];
                                            $stockRows = is_array($stockPreview['rows'] ?? null) ? $stockPreview['rows'] : [];
                                            $stockHeaderCount = count($stockHeader);
                                            $stockSampleCount = count($stockRows);
                                            $stockWarehouseId = (int) ($stockPreview['warehouse_id'] ?? 0);
                                            $stockWarehouseInfo = $warehouses[$stockWarehouseId] ?? null;
                                            $stockWarehouseLabel = $stockWarehouseInfo
                                                ? $stockWarehouseInfo['name']
                                                : ('ID ' . $stockWarehouseId);
                                            $stockColumnMap = is_array($stockPreview['column_map'] ?? null) ? $stockPreview['column_map'] : [];
                                            $stockFields = ['sku' => 'SKU', 'quantity' => 'Quantity'];
                                            $stockSnapshotDate = (string) ($stockPreview['snapshot_date'] ?? '');
                                        ?>
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-200">Warehouse</p>
                                                <p class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-50"><?= htmlspecialchars($stockWarehouseLabel, ENT_QUOTES) ?></p>
                                            </div>
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-200">Snapshot date</p>
                                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-100"><?= htmlspecialchars($stockSnapshotDate, ENT_QUOTES) ?></p>
                                            </div>
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-200">File</p>
                                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-100"><?= htmlspecialchars((string) ($stockPreview['filename'] ?? 'uploaded.csv'), ENT_QUOTES) ?></p>
                                            </div>
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-200">Rows previewed</p>
                                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-100"><?= $stockSampleCount ?></p>
                                            </div>
                                        </div>
                                        <form method="post" class="space-y-6">
                                            <input type="hidden" name="action" value="confirm_stock">
                                            <div class="grid gap-4 md:grid-cols-3">
                                                <?php foreach ($stockHeader as $index => $columnLabel):
                                                    $displayLabel = trim((string) $columnLabel) !== '' ? (string) $columnLabel : 'Column ' . ($index + 1);
                                                ?>
                                                <div class="flex flex-col gap-2 rounded-xl border border-white/10 bg-[#1f2227] p-4 text-sm text-gray-200 shadow-lg shadow-black/40">
                                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-200">Column <?= $index + 1 ?></p>
                                                    <p class="truncate font-medium text-gray-900 dark:text-gray-50" title="<?= htmlspecialchars($displayLabel, ENT_QUOTES) ?>"><?= htmlspecialchars($displayLabel, ENT_QUOTES) ?></p>
                                                    <div class="mt-2 space-y-2">
                                                        <?php foreach ($stockFields as $fieldKey => $fieldLabel):
                                                            $checked = isset($stockColumnMap[$fieldKey]) && (int) $stockColumnMap[$fieldKey] === (int) $index;
                                                        ?>
                                                        <label class="flex items-center gap-2 text-xs font-medium text-gray-600 dark:text-gray-100">
                                                            <input class="<?= $checkboxClass ?> column-checkbox" type="checkbox" id="stock-<?= $fieldKey ?>-<?= $index ?>" name="column_map[<?= $fieldKey ?>]" value="<?= $index ?>" data-field="<?= $fieldKey ?>" <?= $checked ? 'checked' : '' ?>>
                                                            <span>Use as <?= htmlspecialchars($fieldLabel, ENT_QUOTES) ?></span>
                                                        </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php if (empty($stockHeader)): ?>
                                            <div class="rounded-xl border border-amber-400/60 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">No columns detected in the uploaded file.</div>
                                            <?php endif; ?>
                                            <div class="overflow-x-auto rounded-xl border border-white/10">
                                                <table class="min-w-full divide-y divide-white/10 text-sm text-gray-200">
                                                    <thead class="bg-[#1b1e23]/60">
                                                        <tr>
                                                            <?php if ($stockHeaderCount > 0): ?>
                                                                <?php foreach ($stockHeader as $columnLabel):
                                                                    $headerLabel = trim((string) $columnLabel) !== '' ? (string) $columnLabel : 'Column';
                                                                ?>
                                                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-[0.18em] text-gray-200"><?= htmlspecialchars($headerLabel, ENT_QUOTES) ?></th>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-[0.18em] text-gray-200">Data</th>
                                                            <?php endif; ?>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-white/10">
                                                        <?php if ($stockSampleCount > 0): ?>
                                                            <?php foreach ($stockRows as $row): ?>
                                                            <tr class="bg-[#1c1f25] text-sm text-gray-100">
                                                                <?php if ($stockHeaderCount > 0): ?>
                                                                    <?php for ($i = 0; $i < $stockHeaderCount; $i++): ?>
                                                                    <td class="px-4 py-2 text-gray-100"><?= htmlspecialchars((string) ($row[$i] ?? ''), ENT_QUOTES) ?></td>
                                                                    <?php endfor; ?>
                                                                <?php else: ?>
                                                                    <td class="px-4 py-2 text-gray-100"><?= htmlspecialchars(implode(', ', array_map('strval', $row)), ENT_QUOTES) ?></td>
                                                                <?php endif; ?>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <tr>
                                                                <td class="px-4 py-6 text-center text-gray-600 dark:text-gray-300">No data rows detected.</td>
                                                            </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                                <p class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">Showing the first <?= $stockSampleCount ?> row<?= $stockSampleCount === 1 ? '' : 's' ?> from the file.</p>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-3">
                                                <button class="<?= $buttonPrimaryClass ?>" type="submit">Import Stock</button>
                                            </div>
                                        </form>
                                        <form method="post" class="mt-2">
                                            <input type="hidden" name="action" value="cancel_stock_preview">
                                            <button class="inline-flex items-center text-sm font-semibold text-rose-500 transition hover:text-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-400/40" type="submit">Cancel preview</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" enctype="multipart/form-data" class="space-y-4">
                                            <input type="hidden" name="action" value="preview_stock">
                                            <div>
                                                <label class="<?= $labelClass ?>" for="stockWarehouse">Warehouse</label>
                                                <select class="<?= $inputClass ?>" id="stockWarehouse" name="warehouse_id" required>
                                                    <option value="">Select warehouse</option>
                                                    <?php foreach ($warehouses as $warehouse): ?>
                                                        <option value="<?= (int) $warehouse['id'] ?>"><?= htmlspecialchars($warehouse['name'], ENT_QUOTES) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="<?= $labelClass ?>" for="snapshotDate">Snapshot Date</label>
                                                <input class="<?= $inputClass ?>" type="date" id="snapshotDate" name="snapshot_date" required>
                                            </div>
                                            <div>
                                                <label class="<?= $labelClass ?>" for="stockCsv">Stock CSV</label>
                                                <input class="<?= $inputClass ?>" type="file" id="stockCsv" name="stock_csv" accept=".csv" required>
                                                <p class="<?= $helperClass ?>">Include SKU and quantity columns representing the stock snapshot.</p>
                                            </div>
                                            <button class="<?= $buttonPrimaryClass ?>" type="submit">Preview Stock File</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section data-section="warehouses" class="space-y-6<?= $activeSection === 'warehouses' ? '' : ' hidden' ?>">
                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_1fr]">
                            <div class="<?= $cardClass ?>">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Add or Update Warehouse</h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-200">Create new warehouses or update existing names.</p>
                                <form method="post" class="mt-6 space-y-4">
                                    <input type="hidden" name="action" value="add_warehouse">
                                    <div>
                                        <label class="<?= $labelClass ?>" for="warehouseName">Warehouse Name</label>
                                        <input class="<?= $inputClass ?>" type="text" id="warehouseName" name="warehouse_name" maxlength="120" required>
                                    </div>
                                    <button class="<?= $buttonPrimaryClass ?>" type="submit">Save Warehouse</button>
                                </form>
                            </div>
                            <div class="<?= $cardClass ?>">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Existing Warehouses</h3>
                                <div class="mt-4 overflow-x-auto">
                                    <table class="min-w-full divide-y divide-white/10 text-sm text-gray-200">
                                        <thead class="bg-[#1b1e23]/60 text-xs font-semibold uppercase tracking-[0.18em] text-gray-200">
                                            <tr>
                                                <th class="px-4 py-3 text-left">Name</th>
                                                <th class="px-4 py-3 text-left">Created</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/10">
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
                                            <tr class="bg-[#1c1f25] text-sm text-gray-100">
                                                <td class="px-4 py-2 font-medium text-white"><?= htmlspecialchars($warehouse['name'], ENT_QUOTES) ?></td>
                                                <td class="px-4 py-2 text-gray-700 dark:text-gray-200"><?= htmlspecialchars($createdLabel, ENT_QUOTES) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($warehouses)): ?>
                                            <tr>
                                                <td class="px-4 py-6 text-center text-gray-600 dark:text-gray-300" colspan="2">No warehouses yet.</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section data-section="parameters" class="space-y-6<?= $activeSection === 'parameters' ? '' : ' hidden' ?>">
                        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[2fr_1fr]">
                            <div class="<?= $cardClass ?>">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Edit Parameters</h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-200">Set global warehouse defaults or override a specific SKU.</p>
                                <form method="post" class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                    <input type="hidden" name="action" value="save_parameters">
                                    <div class="md:col-span-1 xl:col-span-1">
                                        <label class="<?= $labelClass ?>" for="paramWarehouse">Warehouse</label>
                                        <select class="<?= $inputClass ?>" id="paramWarehouse" name="warehouse_id" required>
                                            <option value="">Select warehouse</option>
                                            <?php foreach ($warehouses as $warehouse): ?>
                                                <option value="<?= (int) $warehouse['id'] ?>"><?= htmlspecialchars($warehouse['name'], ENT_QUOTES) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="md:col-span-1 xl:col-span-2">
                                        <label class="<?= $labelClass ?>" for="paramSku">SKU Override <span class="font-normal text-gray-600 dark:text-gray-300">(optional)</span></label>
                                        <input class="<?= $inputClass ?>" type="text" id="paramSku" name="sku" placeholder="Leave blank for warehouse default">
                                    </div>
                                    <div>
                                        <label class="<?= $labelClass ?>" for="paramDaysCover">Days to Cover</label>
                                        <input class="<?= $inputClass ?>" type="number" min="1" id="paramDaysCover" name="days_to_cover" value="<?= (int) $defaults['days_to_cover'] ?>" required>
                                    </div>
                                    <div>
                                        <label class="<?= $labelClass ?>" for="paramWindow">MA Window (days)</label>
                                        <input class="<?= $inputClass ?>" type="number" min="1" id="paramWindow" name="ma_window_days" value="<?= (int) $defaults['ma_window_days'] ?>" required>
                                    </div>
                                    <div>
                                        <label class="<?= $labelClass ?>" for="paramMinAvg">Min Avg Daily</label>
                                        <input class="<?= $inputClass ?>" type="number" step="0.01" min="0" id="paramMinAvg" name="min_avg_daily" value="<?= htmlspecialchars((string) $defaults['min_avg_daily'], ENT_QUOTES) ?>" required>
                                    </div>
                                    <div>
                                        <label class="<?= $labelClass ?>" for="paramSafety">Safety Days</label>
                                        <input class="<?= $inputClass ?>" type="number" step="0.01" min="0" id="paramSafety" name="safety_days" value="<?= htmlspecialchars((string) $defaults['safety_days'], ENT_QUOTES) ?>" required>
                                    </div>
                                    <div class="md:col-span-2 xl:col-span-3">
                                        <button class="<?= $buttonPrimaryClass ?>" type="submit">Save Parameters</button>
                                    </div>
                                </form>
                            </div>
                            <div class="flex flex-col gap-6">
                                <div class="<?= $cardClass ?>">
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Warehouse Parameters</h3>
                                    <div class="mt-4 overflow-x-auto">
                                        <table class="min-w-full divide-y divide-white/10 text-sm text-gray-200">
                                            <thead class="bg-[#1b1e23]/60 text-xs font-semibold uppercase tracking-[0.18em] text-gray-200">
                                                <tr>
                                                    <th class="px-4 py-3 text-left">Warehouse</th>
                                                    <th class="px-4 py-3 text-left">Days to Cover</th>
                                                    <th class="px-4 py-3 text-left">MA Window</th>
                                                    <th class="px-4 py-3 text-left">Min Avg</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-white/10">
                                                <?php foreach ($warehouses as $warehouse):
                                                    $params = $warehouseParams[$warehouse['id']] ?? $defaults;
                                                ?>
                                                <tr class="bg-[#1c1f25] text-sm text-gray-100">
                                                    <td class="px-4 py-2 font-medium text-white"><?= htmlspecialchars($warehouse['name'], ENT_QUOTES) ?></td>
                                                    <td class="px-4 py-2 text-gray-100"><?= (int) $params['days_to_cover'] ?></td>
                                                    <td class="px-4 py-2 text-gray-100"><?= (int) $params['ma_window_days'] ?></td>
                                                    <td class="px-4 py-2 text-gray-100"><?= htmlspecialchars(number_format((float) $params['min_avg_daily'], 2), ENT_QUOTES) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($warehouses)): ?>
                                                <tr>
                                                    <td class="px-4 py-6 text-center text-gray-600 dark:text-gray-300" colspan="4">No warehouses configured yet.</td>
                                                </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="<?= $cardClass ?>">
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">SKU Overrides</h3>
                                    <div class="mt-4 overflow-x-auto">
                                        <table class="min-w-full divide-y divide-white/10 text-sm text-gray-200">
                                            <thead class="bg-[#1b1e23]/60 text-xs font-semibold uppercase tracking-[0.18em] text-gray-200">
                                                <tr>
                                                    <th class="px-4 py-3 text-left">Warehouse</th>
                                                    <th class="px-4 py-3 text-left">SKU</th>
                                                    <th class="px-4 py-3 text-left">Days to Cover</th>
                                                    <th class="px-4 py-3 text-left">MA Window</th>
                                                    <th class="px-4 py-3 text-left">Min Avg</th>
                                                    <th class="px-4 py-3 text-left">Safety Days</th>
                                                    <th class="px-4 py-3 text-left"></th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-white/10">
                                                <?php if (!empty($skuParams)):
                                                    foreach ($skuParams as $warehouseId => $items):
                                                        $warehouse = $warehouses[$warehouseId] ?? null;
                                                        if (!$warehouse) {
                                                            continue;
                                                        }
                                                        foreach ($items as $skuCode => $params): ?>
                                                            <tr class="bg-[#1c1f25] text-sm text-gray-100">
                                                                <td class="px-4 py-2 font-medium text-white"><?= htmlspecialchars($warehouse['name'], ENT_QUOTES) ?></td>
                                                                <td class="px-4 py-2 text-gray-100"><?= htmlspecialchars($skuCode, ENT_QUOTES) ?></td>
                                                                <td class="px-4 py-2 text-gray-100"><?= (int) $params['days_to_cover'] ?></td>
                                                                <td class="px-4 py-2 text-gray-100"><?= (int) $params['ma_window_days'] ?></td>
                                                                <td class="px-4 py-2 text-gray-100"><?= htmlspecialchars(number_format((float) $params['min_avg_daily'], 2), ENT_QUOTES) ?></td>
                                                                <td class="px-4 py-2 text-gray-100"><?= htmlspecialchars(number_format((float) $params['safety_days'], 2), ENT_QUOTES) ?></td>
                                                                <td class="px-4 py-2">
                                                                    <form method="post">
                                                                        <input type="hidden" name="action" value="delete_sku_param">
                                                                        <input type="hidden" name="warehouse_id" value="<?= (int) $warehouseId ?>">
                                                                        <input type="hidden" name="sku" value="<?= htmlspecialchars($skuCode, ENT_QUOTES) ?>">
                                                                        <button class="inline-flex items-center text-sm font-semibold text-rose-500 transition hover:text-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-400/40" type="submit">Remove</button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach;
                                                    endforeach;
                                                else: ?>
                                                    <tr>
                                                        <td class="px-4 py-6 text-center text-gray-600 dark:text-gray-300" colspan="7">No SKU overrides configured.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
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
        const sectionTriggers = document.querySelectorAll('[data-section-trigger]');
        const navButtons = document.querySelectorAll('.section-tab[data-section-trigger]');
        const sections = document.querySelectorAll('section[data-section]');
        const ACTIVE_CLASSES = ['bg-primary', 'text-white', 'shadow-sm'];
        const INACTIVE_CLASSES = ['text-gray-600', 'hover:bg-primary/10', 'hover:text-primary', 'dark:text-gray-300', 'dark:hover:text-primary'];
        let reorderChart;
        let trendChart;
        let currentRows = [];
        let currentRowsMap = new Map();
        let currentSort = { column: null, direction: 'asc' };
        let selectedRowEl = null;
        let selectedRowKey = null;
        const SORT_CONFIG = {
            warehouse: {
                type: 'string',
                getValue: (row) => `${row.warehouse_code ?? ''} ${row.warehouse_name ?? ''}`.trim().toLowerCase(),
            },
            sku: {
                type: 'string',
                getValue: (row) => (row.sku ?? '').toString().toLowerCase(),
            },
            onHand: {
                type: 'number',
                getValue: (row) => Number(row.current_stock),
            },
            movingAverage: {
                type: 'number',
                getValue: (row) => Number(row.moving_average),
            },
            daysOfCover: {
                type: 'number',
                getValue: (row) => normalizeDaysValue(row.days_of_cover),
            },
            reorderQty: {
                type: 'number',
                getValue: (row) => Number(row.reorder_qty),
            },
        };
        const integerFormatter = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

        function setActiveSection(id) {
            sections.forEach((section) => {
                const isActive = section.dataset.section === id;
                section.classList.toggle('hidden', !isActive);
            });
            navButtons.forEach((button) => {
                const isActive = button.dataset.sectionTrigger === id;
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                ACTIVE_CLASSES.forEach((cls) => button.classList.toggle(cls, isActive));
                INACTIVE_CLASSES.forEach((cls) => button.classList.toggle(cls, !isActive));
            });
        }

        function setupNavigation() {
            if (!sectionTriggers.length) return;
            sectionTriggers.forEach((button) => {
                button.addEventListener('click', () => {
                    const target = button.dataset.sectionTrigger;
                    if (target) {
                        setActiveSection(target);
                    }
                });
            });
            const initiallyActive = document.querySelector('.section-tab[data-section-trigger][aria-selected="true"]');
            if (initiallyActive) {
                setActiveSection(initiallyActive.dataset.sectionTrigger);
            } else if (navButtons[0]) {
                setActiveSection(navButtons[0].dataset.sectionTrigger);
            }
        }

        function setupColorScheme() {
            document.documentElement.classList.add('dark');
        }

        function dismissAlerts() {
            document.querySelectorAll('[data-dismiss-alert]').forEach((button) => {
                button.addEventListener('click', () => {
                    const container = button.closest('[data-alert]');
                    if (container) {
                        container.remove();
                    }
                });
            });
        }

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

        function createDaysBadgeElement(value) {
            const normalized = normalizeDaysValue(value);
            const span = document.createElement('span');
            span.className = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold';
            if (normalized === null) {
                span.textContent = '—';
                span.classList.add('bg-gray-200', 'text-gray-600', 'dark:bg-gray-700', 'dark:text-gray-300');
                return span;
            }
            const rounded = Math.round(normalized);
            span.textContent = `${rounded}d`;
            if (rounded <= 0) {
                span.classList.add('bg-rose-100', 'text-rose-700', 'dark:bg-rose-500/20', 'dark:text-rose-200');
            } else if (rounded <= 7) {
                span.classList.add('bg-orange-100', 'text-orange-700', 'dark:bg-orange-500/20', 'dark:text-orange-200');
            } else {
                span.classList.add('bg-emerald-100', 'text-emerald-700', 'dark:bg-emerald-500/20', 'dark:text-emerald-200');
            }
            return span;
        }

        function formatInteger(value) {
            const numericValue = Number(value);
            if (!Number.isFinite(numericValue)) {
                return '0';
            }
            return integerFormatter.format(Math.round(numericValue));
        }

        function setDashboardLoading(isLoading) {
            const loadingEl = document.getElementById('dashboardLoading');
            const tableScroll = document.getElementById('demandTableScroll');
            const tableContainer = document.getElementById('demandTableContainer');
            const tableBody = document.querySelector('#demandTable tbody');
            const hasRows = !!(tableBody && tableBody.children.length > 0);

            if (loadingEl) {
                if (isLoading) {
                    loadingEl.classList.remove('hidden');
                    loadingEl.setAttribute('aria-hidden', 'false');
                } else {
                    loadingEl.classList.add('hidden');
                    loadingEl.setAttribute('aria-hidden', 'true');
                }
            }

            if (tableScroll) {
                if (isLoading && !hasRows) {
                    tableScroll.classList.add('hidden');
                } else {
                    tableScroll.classList.remove('hidden');
                }
            }

            if (tableContainer) {
                tableContainer.setAttribute('aria-busy', isLoading ? 'true' : 'false');
                tableContainer.classList.toggle('is-loading', isLoading && hasRows);
            }

            if (isLoading) {
                const emptyState = document.getElementById('demandEmptyState');
                if (emptyState) {
                    emptyState.classList.add('hidden');
                }
            }
        }

        function refreshDashboard() {
            const warehouseSelect = document.getElementById('warehouseFilter');
            const skuInput = document.getElementById('skuFilter');
            const tableBody = document.querySelector('#demandTable tbody');
            const emptyState = document.getElementById('demandEmptyState');
            if (!warehouseSelect || !skuInput || !tableBody) {
                return;
            }
            const params = new URLSearchParams();
            if (warehouseSelect.value) params.append('warehouse_id', warehouseSelect.value);
            if (skuInput.value.trim()) params.append('sku', skuInput.value.trim());
            const url = 'api.php' + (params.toString() ? `?${params.toString()}` : '');

            setDashboardLoading(true);

            fetch(url, { credentials: 'same-origin' })
                .then((response) => response.json())
                .then((payload) => {
                    const rows = payload.data || [];
                    currentRows = rows.slice();
                    currentRowsMap = new Map();
                    currentRows.forEach((row) => {
                        const key = `${row.warehouse_id}|${row.sku}`;
                        currentRowsMap.set(key, row);
                    });
                    if (selectedRowKey && !currentRowsMap.has(selectedRowKey)) {
                        selectedRowKey = null;
                        selectedRowEl = null;
                    }
                    if (emptyState) {
                        emptyState.classList.toggle('hidden', rows.length > 0);
                    }

                    tableBody.innerHTML = '';
                    rows.forEach((row) => {
                            const key = `${row.warehouse_id}|${row.sku}`;
                            currentRowsMap.set(key, row);
                            const tr = document.createElement('tr');
                            tr.dataset.key = key;
                            tr.className = 'group cursor-pointer transition-colors odd:bg-white/[0.01] even:bg-white/[0.02] hover:bg-primary/20 hover:bg-opacity-40';

                            const warehouseCell = document.createElement('td');
                            warehouseCell.className = 'px-6 py-4 font-semibold text-white';
                            warehouseCell.textContent = row.warehouse_name || '';
                            tr.appendChild(warehouseCell);

                            const skuCell = document.createElement('td');
                            skuCell.className = 'px-6 py-4 text-gray-300';
                            skuCell.textContent = row.sku;
                            tr.appendChild(skuCell);

                            const stockCell = document.createElement('td');
                            stockCell.className = 'px-6 py-4 text-gray-300';
                            stockCell.textContent = formatInteger(row.current_stock);
                            tr.appendChild(stockCell);

                            const maCell = document.createElement('td');
                            maCell.className = 'px-6 py-4 text-gray-300';
                            maCell.textContent = Number(row.moving_average || 0).toFixed(2);
                            tr.appendChild(maCell);

                            const coverCell = document.createElement('td');
                            coverCell.className = 'px-6 py-4';
                            coverCell.appendChild(createDaysBadgeElement(row.days_of_cover));
                            tr.appendChild(coverCell);

                            const reorderCell = document.createElement('td');
                            reorderCell.className = 'px-6 py-4 text-gray-300';
                            reorderCell.textContent = formatInteger(row.reorder_qty);
                            tr.appendChild(reorderCell);

                        tableBody.appendChild(tr);
                    });

                    const summaryItems = document.getElementById('summaryItems');
                    if (summaryItems) {
                        summaryItems.textContent = rows.length;
                    }
                    const totalReorder = rows.reduce((sum, row) => {
                        const value = Number(row.reorder_qty);
                        return Number.isFinite(value) ? sum + value : sum;
                    }, 0);
                    const summaryReorder = document.getElementById('summaryReorder');
                    if (summaryReorder) {
                        summaryReorder.textContent = formatInteger(totalReorder);
                    }
                    const lowCover = rows.filter((row) => {
                        const normalized = normalizeDaysValue(row.days_of_cover);
                        return normalized !== null && normalized <= 7;
                    }).length;
                    const summaryLowCover = document.getElementById('summaryLowCover');
                    if (summaryLowCover) {
                        summaryLowCover.textContent = lowCover;
                    }
                    const warehouseSet = new Set(rows.map((row) => row.warehouse_id));
                    const summaryWarehouses = document.getElementById('summaryWarehouses');
                    if (summaryWarehouses) {
                        summaryWarehouses.textContent = warehouseSet.size;
                    }

                    const reorderContainer = document.getElementById('reorderChart');
                    const reorderEmpty = document.getElementById('reorderEmptyState');
                    const topRows = [...rows].sort((a, b) => b.reorder_qty - a.reorder_qty).slice(0, 10);
                    if (reorderContainer) {
                        if (reorderChart) {
                            reorderChart.destroy();
                        }
                        if (topRows.length === 0) {
                            if (reorderEmpty) reorderEmpty.classList.remove('hidden');
                        } else {
                            if (reorderEmpty) reorderEmpty.classList.add('hidden');
                            reorderChart = new Chart(reorderContainer.getContext('2d'), {
                                type: 'bar',
                                data: {
                                    labels: topRows.map((row) => `${row.warehouse_name || 'Warehouse'}-${row.sku}`),
                                    datasets: [{
                                        label: 'Reorder Qty',
                                        data: topRows.map((row) => row.reorder_qty),
                                        backgroundColor: 'rgba(15, 145, 189, 0.75)',
                                        borderRadius: 8,
                                    }],
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: { display: false },
                                    },
                                    scales: {
                                        x: { ticks: { color: '#6b7280' } },
                                        y: {
                                            beginAtZero: true,
                                            ticks: { color: '#6b7280' },
                                        },
                                    },
                                },
                            });
                        }
                    }

                    if (trendChart) {
                        trendChart.destroy();
                    }
                    renderTrendSeries();
                    const trendEmpty = document.getElementById('trendEmptyState');
                    if (trendEmpty) {
                        trendEmpty.classList.remove('hidden');
                    }
                })
                .catch((error) => {
                    console.error('Failed to load dashboard data', error);
                })
                .finally(() => {
                    setDashboardLoading(false);
                });
        }

        function updateDemandEmptyState(hasRows) {
            const emptyState = document.getElementById('demandEmptyState');
            if (emptyState) {
                emptyState.classList.toggle('hidden', hasRows);
            }
        }

        function getSortedRows() {
            if (!currentSort.column || !SORT_CONFIG[currentSort.column]) {
                return currentRows.slice();
            }
            const config = SORT_CONFIG[currentSort.column];
            const directionMultiplier = currentSort.direction === 'desc' ? -1 : 1;
            return currentRows.slice().sort((a, b) => {
                const aValue = config.getValue(a);
                const bValue = config.getValue(b);

                if (config.type === 'number') {
                    const aNumber = Number(aValue);
                    const bNumber = Number(bValue);
                    const aValid = Number.isFinite(aNumber);
                    const bValid = Number.isFinite(bNumber);
                    if (!aValid && !bValid) return 0;
                    if (!aValid) return 1;
                    if (!bValid) return -1;
                    if (aNumber === bNumber) return 0;
                    return aNumber > bNumber ? directionMultiplier : -directionMultiplier;
                }

                const aString = (aValue ?? '').toString();
                const bString = (bValue ?? '').toString();
                return aString.localeCompare(bString, undefined, { sensitivity: 'base' }) * directionMultiplier;
            });
        }

        function renderDemandTable(rows) {
            const tableBody = document.querySelector('#demandTable tbody');
            if (!tableBody) {
                return;
            }
            tableBody.innerHTML = '';
            let highlightedRow = null;
            rows.forEach((row) => {
                const key = `${row.warehouse_id}|${row.sku}`;
                const tr = document.createElement('tr');
                tr.dataset.key = key;
                tr.className = 'group cursor-pointer transition-colors odd:bg-white/[0.01] even:bg-white/[0.02] hover:bg-primary/20 hover:bg-opacity-40';

                const warehouseCell = document.createElement('td');
                warehouseCell.className = 'px-6 py-4 font-semibold text-white';
                const warehouseCode = row.warehouse_code ?? '';
                const warehouseName = row.warehouse_name ?? '';
                warehouseCell.textContent = warehouseName ? `${warehouseCode} · ${warehouseName}` : warehouseCode;
                tr.appendChild(warehouseCell);

                const skuCell = document.createElement('td');
                skuCell.className = 'px-6 py-4 text-gray-300';
                skuCell.textContent = row.sku ?? '';
                tr.appendChild(skuCell);

                const stockCell = document.createElement('td');
                stockCell.className = 'px-6 py-4 text-gray-300';
                stockCell.textContent = formatInteger(row.current_stock);
                tr.appendChild(stockCell);

                const maCell = document.createElement('td');
                maCell.className = 'px-6 py-4 text-gray-300';
                maCell.textContent = Number(row.moving_average || 0).toFixed(2);
                tr.appendChild(maCell);

                const coverCell = document.createElement('td');
                coverCell.className = 'px-6 py-4';
                coverCell.appendChild(createDaysBadgeElement(row.days_of_cover));
                tr.appendChild(coverCell);

                const reorderCell = document.createElement('td');
                reorderCell.className = 'px-6 py-4 text-gray-300';
                reorderCell.textContent = formatInteger(row.reorder_qty);
                tr.appendChild(reorderCell);

                if (selectedRowKey && key === selectedRowKey) {
                    tr.classList.add('ring-2', 'ring-primary/50');
                    highlightedRow = tr;
                }

                tableBody.appendChild(tr);
            });
            selectedRowEl = highlightedRow;
        }

        function updateSortIndicators() {
            const sortButtons = document.querySelectorAll('#demandTable thead .sort-button[data-sort-key]');
            sortButtons.forEach((button) => {
                const key = button.dataset.sortKey;
                const icon = button.querySelector('[data-sort-icon]');
                if (!icon) return;
                if (currentSort.column === key) {
                    icon.textContent = currentSort.direction === 'asc' ? 'arrow_upward' : 'arrow_downward';
                    icon.classList.remove('opacity-0');
                    icon.setAttribute('aria-hidden', 'false');
                } else {
                    icon.classList.add('opacity-0');
                    icon.setAttribute('aria-hidden', 'true');
                }
            });
        }

        function toggleSort(columnKey) {
            if (!SORT_CONFIG[columnKey]) {
                return;
            }
            if (currentSort.column === columnKey) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.column = columnKey;
                currentSort.direction = 'asc';
            }
            renderDemandTable(getSortedRows());
            updateSortIndicators();
        }

        function setupSorting() {
            const sortButtons = document.querySelectorAll('#demandTable thead .sort-button[data-sort-key]');
            sortButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    toggleSort(button.dataset.sortKey);
                });
            });
        }

        function handleRowClick(event) {
            const tableBodyEl = document.querySelector('#demandTable tbody');
            if (!tableBodyEl) return;
            const rowElement = event.target.closest('tr[data-key]');
            if (!rowElement) {
                return;
            }
            if (selectedRowEl) {
                selectedRowEl.classList.remove('ring-2', 'ring-primary/50');
            }
            selectedRowEl = rowElement;
            selectedRowEl.classList.add('ring-2', 'ring-primary/50');
            selectedRowKey = rowElement.dataset.key;
            const detail = currentRowsMap.get(rowElement.dataset.key);
            if (detail) {
                const trendEmpty = document.getElementById('trendEmptyState');
                if (trendEmpty) {
                    trendEmpty.classList.add('hidden');
                }
                renderTrendSeries(detail);
            }
        }

        function renderTrendSeries(row) {
            const canvas = document.getElementById('trendChart');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            if (trendChart) {
                trendChart.destroy();
            }
            if (!row) {
                trendChart = new Chart(ctx, {
                    type: 'line',
                    data: { labels: [], datasets: [{ data: [], borderColor: '#0f91bd', tension: 0.3 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } },
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
                        label: `${row.warehouse_name || 'Warehouse'}-${row.sku}`,
                        data: values,
                        borderColor: '#0f91bd',
                        backgroundColor: 'rgba(15, 145, 189, 0.2)',
                        tension: 0.35,
                        fill: true,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: '#6b7280' } },
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#6b7280' },
                        },
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

        function setupFilters() {
            const warehouseSelect = document.getElementById('warehouseFilter');
            const skuInput = document.getElementById('skuFilter');
            if (!warehouseSelect || !skuInput) return;
            warehouseSelect.addEventListener('change', refreshDashboard);
            let timeout;
            skuInput.addEventListener('input', () => {
                clearTimeout(timeout);
                timeout = setTimeout(refreshDashboard, 350);
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            setupColorScheme();
            setupNavigation();
            dismissAlerts();
            setupColumnCheckboxes();
            setupFilters();
            setupSorting();
            const tableBodyEl = document.querySelector('#demandTable tbody');
            if (tableBodyEl) {
                tableBodyEl.addEventListener('click', handleRowClick);
            }
            refreshDashboard();
        });
    </script>
</body>
</html>
