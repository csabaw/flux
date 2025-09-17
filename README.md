# Multi-Warehouse Demand Planning Dashboard

A PHP web dashboard for multi-warehouse demand planning. The app ingests daily sales and stock snapshot CSV files, applies configurable replenishment parameters, and surfaces demand metrics via a responsive interface powered by Bootstrap 5, DataTables, and Chart.js.

## Features

- Secure admin login (credentials stored in `.env`).
- CSV importers for daily sales and stock snapshots with automatic warehouse creation.
- Configurable planning parameters per warehouse and optional SKU overrides.
- Moving-average demand, days of cover, safety stock, and replenishment suggestions.
- Interactive dashboard table with filters, top reorder chart, and demand trend visualization.
- JSON API (`/public/api.php`) used by the frontend and available for integrations.

## Tech Stack

- PHP 7.2+
- MySQL 8+ (`utf8mb4`)
- Bootstrap 5, DataTables, Chart.js

## Getting Started

1. **Install dependencies**

   Ensure PHP 7.2+ and MySQL 8 are available. Enable the `mysqli` extension in PHP.

2. **Database setup**

   ```sql
   SOURCE database/schema.sql;
   ```

3. **Environment configuration**

   Copy the example environment file and edit credentials:

   ```bash
   cp .env.example .env
   ```

   Update database access, admin credentials, and planning defaults as needed.

4. **Serve the application**

   Point your web server to the `public/` directory (e.g., with Apache or nginx + PHP-FPM). For local testing you can run:

   ```bash
   php -S localhost:8000 -t public/
   ```

5. **Log in**

   Visit `http://localhost:8000` and authenticate with the admin credentials from `.env`.

## CSV Formats

### Daily Sales

Columns (header row required):

| Column          | Description                          |
|-----------------|--------------------------------------|
| `warehouse_code`| Warehouse identifier (auto-created). |
| `sku`           | SKU identifier.                      |
| `sale_date`     | Date (YYYY-MM-DD).                   |
| `quantity`      | Units sold for the day.              |

### Stock Snapshot

Columns (header row required):

| Column           | Description                              |
|------------------|------------------------------------------|
| `warehouse_code` | Warehouse identifier (auto-created).     |
| `sku`            | SKU identifier.                          |
| `snapshot_date`  | Date of the inventory snapshot.          |
| `quantity`       | On-hand quantity at the snapshot date.   |

## Planning Logic

- Moving-average demand is computed over the configured `ma_window_days` (default 7) ending today.
- `days_to_cover` determines the stock target horizon. Target stock = `effective_avg_daily * days_to_cover + safety_stock`.
- `min_avg_daily` acts as a floor for average demand; values below the threshold use the threshold for replenishment calculations.
- `safety_stock` adds a buffer on top of the target.
- Reorder suggestions are the positive difference between target stock and the latest snapshot quantity.

Warehouse-level parameters can be overridden per SKU from the Parameters page. Removing an override reverts to warehouse defaults (or global defaults when no warehouse-specific values exist).

## API

Authenticated requests to `/public/api.php` return the dashboard dataset. Query parameters:

- `warehouse_id` (optional, integer)
- `sku` (optional, exact match)

Response structure:

```json
{
  "data": [
    {
      "warehouse_id": 1,
      "warehouse_code": "ROMA",
      "warehouse_name": "Rome",
      "sku": "SKU123",
      "current_stock": 120,
      "snapshot_date": "2024-05-20",
      "moving_average": 15.5,
      "effective_avg": 15.5,
      "days_of_cover": 7.74,
      "target_stock": 232.5,
      "reorder_qty": 112.5,
      "safety_stock": 5,
      "days_to_cover": 14,
      "ma_window_days": 7,
      "min_avg_daily": 1,
      "daily_series": { "2024-05-14": 10, ... }
    }
  ],
  "summary": {
    "total_items": 5,
    "total_reorder_qty": 480
  }
}
```

## Timezone

The application operates in the `Europe/Rome` timezone.

## Security Notes

- Replace the default admin password in `.env` before deploying.
- Serve the application over HTTPS in production.
- Uploaded CSV files are processed immediately and not stored on disk.

## License

MIT
