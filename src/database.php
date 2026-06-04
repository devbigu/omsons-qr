<?php
declare(strict_types=1);

function db_config(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/../config/database.php';
    }

    return $config;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = db_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset']
    );

    $pdo = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function db_server(): PDO
{
    $config = db_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['charset']
    );

    return new PDO($dsn, (string) $config['username'], (string) $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function init_database(): void
{
    if (db_config()['auto_create']) {
        ensure_database_exists();
    }

    migrate_database();
    seed_database();
    import_json_data();
}

function ensure_database_exists(): void
{
    $config = db_config();
    $database = str_replace('`', '``', (string) $config['database']);
    db_server()->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function migrate_database(): void
{
    $pdo = db();

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS labels (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            batch_id VARCHAR(64) NOT NULL,
            serial_no VARCHAR(40) NOT NULL,
            qr_payload TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_batch_serial (batch_id, serial_no),
            INDEX idx_labels_batch (batch_id),
            INDEX idx_labels_serial (serial_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(128) PRIMARY KEY,
            payload MEDIUMTEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_sessions_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function seed_database(): void
{
    $pdo = db();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();

    if ($count > 0) {
        return;
    }

    foreach (default_products() as $product) {
        insert_product($product);
    }
}

function import_json_data(): void
{
    if (!file_exists(PRODUCTS_FILE) && !file_exists(BATCHES_FILE)) {
        return;
    }

    foreach (read_json(PRODUCTS_FILE, []) as $product) {
        if (!is_array($product) || empty($product['id']) || find_product((string) $product['id'])) {
            continue;
        }
        insert_product($product);
    }

    foreach (read_json(BATCHES_FILE, []) as $batch) {
        if (!is_array($batch) || empty($batch['id']) || find_batch((string) $batch['id'])) {
            continue;
        }
        insert_batch($batch);
    }
}

function insert_product(array $product): void
{
    $stmt = db()->prepare('
        INSERT INTO products (
            id, product_name, catalog_no, pore_size, membrane_type, lot_suffix, qr_url_template, created_at
        ) VALUES (
            :id, :product_name, :catalog_no, :pore_size, :membrane_type, :lot_suffix, :qr_url_template, :created_at
        )
        ON DUPLICATE KEY UPDATE
            product_name = VALUES(product_name),
            catalog_no = VALUES(catalog_no),
            pore_size = VALUES(pore_size),
            membrane_type = VALUES(membrane_type),
            lot_suffix = VALUES(lot_suffix),
            qr_url_template = VALUES(qr_url_template)
    ');

    $stmt->execute([
        'id' => $product['id'],
        'product_name' => $product['product_name'],
        'catalog_no' => $product['catalog_no'],
        'pore_size' => $product['pore_size'],
        'membrane_type' => $product['membrane_type'],
        'lot_suffix' => $product['lot_suffix'] ?? 'E',
        'qr_url_template' => $product['qr_url_template'] ?? 'https://example.com/coa/{lot}/{serial}',
        'created_at' => db_datetime($product['created_at'] ?? null),
    ]);
}

function insert_batch(array $batch): void
{
    $pdo = db();
    $product = $batch['product'] ?? null;

    if (!is_array($product) || empty($product['id'])) {
        return;
    }

    if (!find_product((string) $product['id'])) {
        insert_product($product);
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('
            INSERT INTO batches (
                id, product_id, product_snapshot, lot_no, label_date, start_serial, end_serial, quantity, created_at
            ) VALUES (
                :id, :product_id, :product_snapshot, :lot_no, :label_date, :start_serial, :end_serial, :quantity, :created_at
            )
        ');

        $stmt->execute([
            'id' => $batch['id'],
            'product_id' => $product['id'],
            'product_snapshot' => json_encode($product, JSON_UNESCAPED_SLASHES),
            'lot_no' => $batch['lot_no'],
            'label_date' => normalize_date($batch['label_date'] ?? null),
            'start_serial' => $batch['start_serial'],
            'end_serial' => $batch['end_serial'],
            'quantity' => (int) $batch['quantity'],
            'created_at' => db_datetime($batch['created_at'] ?? null),
        ]);

        $labelStmt = $pdo->prepare('
            INSERT INTO labels (batch_id, serial_no, qr_payload, created_at)
            VALUES (:batch_id, :serial_no, :qr_payload, :created_at)
        ');

        foreach (($batch['labels'] ?? []) as $label) {
            if (!is_array($label)) {
                continue;
            }

            $labelStmt->execute([
                'batch_id' => $batch['id'],
                'serial_no' => $label['serial_no'],
                'qr_payload' => $label['qr_payload'],
                'created_at' => db_datetime($batch['created_at'] ?? null),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function db_datetime(?string $value = null): string
{
    $timestamp = $value ? strtotime($value) : time();
    return date('Y-m-d H:i:s', $timestamp ?: time());
}

function normalize_date(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

class DatabaseSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = db()->prepare('
            SELECT payload
            FROM sessions
            WHERE id = :id
              AND expires_at > NOW()
            LIMIT 1
        ');
        $stmt->execute(['id' => $id]);
        $payload = $stmt->fetchColumn();

        return is_string($payload) ? $payload : '';
    }

    public function write(string $id, string $data): bool
    {
        $lifetime = (int) ini_get('session.gc_maxlifetime');
        $expiresAt = date('Y-m-d H:i:s', time() + max($lifetime, 1440));

        $stmt = db()->prepare('
            INSERT INTO sessions (id, payload, expires_at, updated_at)
            VALUES (:id, :payload, :expires_at, NOW())
            ON DUPLICATE KEY UPDATE
                payload = VALUES(payload),
                expires_at = VALUES(expires_at),
                updated_at = NOW()
        ');

        return $stmt->execute([
            'id' => $id,
            'payload' => $data,
            'expires_at' => $expiresAt,
        ]);
    }

    public function destroy(string $id): bool
    {
        $stmt = db()->prepare('DELETE FROM sessions WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = db()->prepare('DELETE FROM sessions WHERE expires_at <= :expires_at');
        $stmt->execute(['expires_at' => date('Y-m-d H:i:s')]);

        return $stmt->rowCount();
    }
}
