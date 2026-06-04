CREATE DATABASE IF NOT EXISTS omqr
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE omqr;

CREATE TABLE IF NOT EXISTS products (
    id VARCHAR(64) PRIMARY KEY,
    product_name VARCHAR(160) NOT NULL,
    catalog_no VARCHAR(120) NOT NULL,
    pore_size VARCHAR(80) NOT NULL,
    membrane_type VARCHAR(80) NOT NULL,
    lot_suffix VARCHAR(12) NOT NULL DEFAULT 'E',
    qr_url_template VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_products_name (product_name),
    INDEX idx_products_catalog (catalog_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batches (
    id VARCHAR(64) PRIMARY KEY,
    product_id VARCHAR(64) NOT NULL,
    product_snapshot JSON NOT NULL,
    lot_no VARCHAR(64) NOT NULL,
    label_date DATE NULL,
    start_serial VARCHAR(40) NOT NULL,
    end_serial VARCHAR(40) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_batches_lot (lot_no),
    INDEX idx_batches_product (product_id),
    INDEX idx_batches_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS labels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id VARCHAR(64) NOT NULL,
    serial_no VARCHAR(40) NOT NULL,
    qr_payload TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_batch_serial (batch_id, serial_no),
    INDEX idx_labels_batch (batch_id),
    INDEX idx_labels_serial (serial_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    payload MEDIUMTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
