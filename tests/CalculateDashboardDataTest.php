<?php

declare(strict_types=1);

namespace Flux\Tests;

final class FakeResult
{
    /** @var array<int, array<string, mixed>> */
    private $rows;

    /** @var int */
    private $index = 0;

    /** @var int */
    public $num_rows;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
        $this->num_rows = count($this->rows);
    }

    public function fetch_assoc(): ?array
    {
        if ($this->index >= $this->num_rows) {
            return null;
        }

        return $this->rows[$this->index++];
    }

    public function free(): void
    {
        // No-op for the fake result set.
    }
}

final class FakeStmt
{
    /** @var array<int, array<string, mixed>> */
    private $rows;

    /** @var string */
    private $types = '';

    /** @var array<int, mixed> */
    private $params = [];

    /** @var callable|null */
    private $onBind;

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param callable|null $onBind
     */
    public function __construct(array $rows, ?callable $onBind = null)
    {
        $this->rows = array_values($rows);
        $this->onBind = $onBind;
    }

    public function bind_param(string $types, &...$params): bool
    {
        $this->types = $types;
        $copiedParams = [];
        foreach ($params as $param) {
            $copiedParams[] = $param;
        }
        $this->params = $copiedParams;

        if ($this->onBind !== null) {
            ($this->onBind)($types, $copiedParams);
        }

        return true;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function get_result(): FakeResult
    {
        return new FakeResult($this->rows);
    }

    public function close(): bool
    {
        // No-op for the fake statement.
        return true;
    }

    public function getTypes(): string
    {
        return $this->types;
    }

    /**
     * @return array<int, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }
}

final class FakeMysqli extends \mysqli
{
    /** @var array<string, array<int, array<string, mixed>>|bool> */
    private $queryResults;

    /** @var array<string, array<int, array<string, mixed>>> */
    private $preparedResults;

    /** @var array<string, array{types: string, params: array<int, mixed>}> */
    private $boundStatements = [];

    /**
     * @param array<string, array<int, array<string, mixed>>|bool> $queryResults
     * @param array<string, array<int, array<string, mixed>>> $preparedResults
     */
    public function __construct(array $queryResults, array $preparedResults)
    {
        // Intentionally avoid calling the parent constructor to skip opening a real connection.
        $this->queryResults = $queryResults;
        $this->preparedResults = $preparedResults;
    }

    #[\ReturnTypeWillChange]
    public function query(string $sql, int $resultMode = MYSQLI_STORE_RESULT)
    {
        if (!array_key_exists($sql, $this->queryResults)) {
            throw new \RuntimeException('Unexpected query: ' . $sql);
        }

        $result = $this->queryResults[$sql];
        if ($result === false) {
            return false;
        }
        if ($result === true) {
            return true;
        }

        return new FakeResult($result);
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $sql)
    {
        if (!array_key_exists($sql, $this->preparedResults)) {
            throw new \RuntimeException('Unexpected prepared statement: ' . $sql);
        }

        $onBind = function (string $types, array $params) use ($sql): void {
            $this->boundStatements[$sql] = [
                'types' => $types,
                'params' => $params,
            ];
        };

        return new FakeStmt($this->preparedResults[$sql], $onBind);
    }

    public function real_escape_string(string $value): string
    {
        return addslashes($value);
    }

    /**
     * @return array{types: string, params: array<int, mixed>}|null
     */
    public function getBoundParams(string $sql): ?array
    {
        return $this->boundStatements[$sql] ?? null;
    }
}

require __DIR__ . '/../includes/functions.php';

function assertSame($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $prefix = $message !== '' ? $message . ' - ' : '';
        throw new \RuntimeException($prefix . 'Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
    }
}

function baseQueryResults(): array
{
    return [
        "SHOW COLUMNS FROM `warehouses` LIKE 'created_at'" => [],
        'SELECT id, name FROM warehouses ORDER BY name' => [
            ['id' => 1, 'name' => 'Rome'],
        ],
        "SHOW COLUMNS FROM `warehouse_parameters` LIKE 'safety_days'" => [[]],
        'SELECT warehouse_id, days_to_cover, ma_window_days, min_avg_daily, `safety_days` AS safety_days FROM warehouse_parameters' => [
            [
                'warehouse_id' => 1,
                'days_to_cover' => 10,
                'ma_window_days' => 7,
                'min_avg_daily' => 1.0,
                'safety_days' => 2.0,
            ],
        ],
        "SHOW COLUMNS FROM `sku_parameters` LIKE 'safety_days'" => [[]],
        'SELECT warehouse_id, sku, days_to_cover, ma_window_days, min_avg_daily, `safety_days` AS safety_days FROM sku_parameters' => [
            [
                'warehouse_id' => 1,
                'sku' => 'SKU-ONLY',
                'days_to_cover' => 12,
                'ma_window_days' => 5,
                'min_avg_daily' => 2.0,
                'safety_days' => 1.0,
            ],
        ],
        "SHOW COLUMNS FROM `stock_snapshots` LIKE 'product_name'" => [[]],
        'SELECT warehouse_id, sku, quantity, snapshot_date, product_name FROM stock_snapshots WHERE warehouse_id = 1 ORDER BY warehouse_id, sku, snapshot_date DESC, id DESC' => [],
    ];
}

function basePreparedResults(): array
{
    return [
        'SELECT warehouse_id, sku, sale_date, SUM(quantity) AS quantity FROM sales WHERE sale_date >= ? AND warehouse_id = ? GROUP BY warehouse_id, sku, sale_date' => [],
    ];
}

$mysqli = new FakeMysqli(baseQueryResults(), basePreparedResults());


$config = [
    'lookback_days' => 7,
    'chart_max_days' => 3,
    'defaults' => [
        'days_to_cover' => 9,
        'ma_window_days' => 7,
        'min_avg_daily' => 1.0,
        'safety_days' => 2.0,
    ],
];

$result = calculateDashboardData($mysqli, $config, ['warehouse_id' => 1]);

assertSame(1, count($result['data']), 'Expected a single SKU row');
$row = $result['data'][0];
assertSame('SKU-ONLY', $row['sku'], 'SKU from sku_parameters should be present');
assertSame(1, $row['warehouse_id']);
assertSame('', $row['product_name']);
assertSame(1, $result['summary']['total_items']);
assertSame($row['reorder_qty'], $result['summary']['total_reorder_qty']);
assertSame(3, count($row['daily_series']), 'Daily series should respect chart_max_days limit');

$today = new \DateTimeImmutable('today');
$expectedDates = [
    $today->modify('-2 days')->format('Y-m-d'),
    $today->modify('-1 days')->format('Y-m-d'),
    $today->format('Y-m-d'),
];
assertSame($expectedDates, array_keys($row['daily_series']), 'Daily series should include the most recent dates');

$expandedConfig = $config;
$expandedConfig['chart_max_days'] = 10;
$expandedConfig['lookback_days'] = 30;

$expandedResult = calculateDashboardData($mysqli, $expandedConfig, ['warehouse_id' => 1]);
$expandedRow = $expandedResult['data'][0];
assertSame(10, count($expandedRow['daily_series']), 'Daily series should expand up to the chart_max_days value');

$expectedExpandedDates = [];
for ($i = 9; $i >= 0; $i--) {
    $expectedExpandedDates[] = $today->modify('-' . $i . ' days')->format('Y-m-d');
}
assertSame($expectedExpandedDates, array_keys($expandedRow['daily_series']), 'Expanded series should preserve chronological order');

$filteredQueryResults = baseQueryResults();
$filteredQueryResults['SELECT warehouse_id, sku, quantity, snapshot_date, product_name FROM stock_snapshots WHERE warehouse_id = 1 AND (sku LIKE \'%Widget%\' OR product_name LIKE \'%Widget%\') ORDER BY warehouse_id, sku, snapshot_date DESC, id DESC'] = [
    [
        'warehouse_id' => 1,
        'sku' => 'SKU-123',
        'quantity' => 15,
        'snapshot_date' => '2024-01-02',
        'product_name' => 'Widget Pro',
    ],
];

$filteredPreparedResults = [
    'SELECT warehouse_id, sku, sale_date, SUM(quantity) AS quantity FROM sales WHERE sale_date >= ? AND warehouse_id = ? AND sku IN (?) GROUP BY warehouse_id, sku, sale_date' => [
        [
            'warehouse_id' => 1,
            'sku' => 'SKU-123',
            'sale_date' => $today->format('Y-m-d'),
            'quantity' => 3,
        ],
        [
            'warehouse_id' => 1,
            'sku' => 'SKU-123',
            'sale_date' => $today->modify('-1 day')->format('Y-m-d'),
            'quantity' => 2,
        ],
    ],
];

$filteredMysqli = new FakeMysqli($filteredQueryResults, $filteredPreparedResults);
$filteredResult = calculateDashboardData($filteredMysqli, $config, ['warehouse_id' => 1, 'sku' => 'Widget']);

assertSame(1, count($filteredResult['data']), 'Filtered request should return matching SKU only');
$filteredRow = $filteredResult['data'][0];
assertSame('SKU-123', $filteredRow['sku']);
assertSame('Widget Pro', $filteredRow['product_name']);
assertSame(1, $filteredResult['summary']['total_items']);

$binding = $filteredMysqli->getBoundParams('SELECT warehouse_id, sku, sale_date, SUM(quantity) AS quantity FROM sales WHERE sale_date >= ? AND warehouse_id = ? AND sku IN (?) GROUP BY warehouse_id, sku, sale_date');
assertSame('sis', $binding['types'] ?? '', 'Expected sales query to bind start date, warehouse, and SKU list');
assertSame('SKU-123', $binding['params'][2] ?? null, 'Sales query should request only the filtered SKU');

$expectedStartDate = $today->modify('-' . $config['lookback_days'] . ' days')->format('Y-m-d');
assertSame($expectedStartDate, $binding['params'][0] ?? null, 'Start date parameter should respect lookback window');

echo "OK\n";
