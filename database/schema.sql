CREATE TABLE IF NOT EXISTS warehouses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id INT UNSIGNED NOT NULL,
    sku VARCHAR(120) NOT NULL,
    sale_date DATE NOT NULL,
    quantity DECIMAL(15, 3) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sales_warehouse_sku_date (warehouse_id, sku, sale_date),
    CONSTRAINT fk_sales_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id INT UNSIGNED NOT NULL,
    sku VARCHAR(120) NOT NULL,
    snapshot_date DATE NOT NULL,
    quantity DECIMAL(15, 3) NOT NULL DEFAULT 0,
    product_name VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_warehouse_sku_date (warehouse_id, sku, snapshot_date),
    CONSTRAINT fk_stock_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouse_parameters (
    warehouse_id INT UNSIGNED NOT NULL PRIMARY KEY,
    days_to_cover INT NOT NULL,
    ma_window_days INT NOT NULL,
    min_avg_daily DECIMAL(12, 3) NOT NULL,
    safety_days DECIMAL(12, 3) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_warehouse_params FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sku_parameters (
    warehouse_id INT UNSIGNED NOT NULL,
    sku VARCHAR(120) NOT NULL,
    days_to_cover INT NOT NULL,
    ma_window_days INT NOT NULL,
    min_avg_daily DECIMAL(12, 3) NOT NULL,
    safety_days DECIMAL(12, 3) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (warehouse_id, sku),
    CONSTRAINT fk_sku_params_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
