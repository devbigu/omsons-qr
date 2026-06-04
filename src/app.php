<?php
declare(strict_types=1);

const APP_NAME = 'OMSONS QR Label Generator';
const DATA_DIR = __DIR__ . '/../data';
const PRODUCTS_FILE = DATA_DIR . '/products.json';
const BATCHES_FILE = DATA_DIR . '/batches.json';
const DEMO_EMAIL = 'demo@omsons.local';
const DEMO_PASSWORD = 'demo123';

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/database.php';

function init_app(): void
{
    init_database();
    configure_session();

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

function is_vercel_runtime(): bool
{
    return (bool) getenv('VERCEL');
}

function should_use_database_sessions(): bool
{
    $driver = getenv('OMQR_SESSION_DRIVER') ?: '';

    return is_vercel_runtime() || strtolower($driver) === 'database';
}

function configure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');

    if (should_use_database_sessions()) {
        session_set_save_handler(new DatabaseSessionHandler(), true);
    }

    if (is_vercel_runtime() || getenv('RENDER')) {
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_samesite', 'Lax');
    }
}

function default_products(): array
{
    return [
        [
            'id' => 'puricap-pes',
            'product_name' => 'Puricap PES',
            'catalog_no' => 'OM553-02-02-045',
            'pore_size' => '0.45 + 0.2 um',
            'membrane_type' => 'PES',
            'lot_suffix' => 'E',
            'qr_url_template' => 'https://example.com/coa/{lot}/{serial}',
            'created_at' => date('c'),
        ],
    ];
}

function read_json(string $file, array $default): array
{
    if (!file_exists($file)) {
        return $default;
    }

    $json = file_get_contents($file);
    $data = json_decode((string) $json, true);
    return is_array($data) ? $data : $default;
}

function write_json(string $file, array $data): void
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function format_pore_size(?string $value): string
{
    return preg_replace('/\bum\b/i', '&micro;m', e($value)) ?? e($value);
}

function asset_url(string $path): string
{
    $assetBase = getenv('OMQR_ASSET_BASE');

    if ($assetBase !== false && $assetBase !== '') {
        return rtrim($assetBase, '/') . '/' . ltrim($path, '/');
    }

    if (is_vercel_runtime()) {
        return '/assets/' . ltrim($path, '/');
    }

    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return ($base === '' || $base === '.') ? 'public/assets/' . $path : $base . '/public/assets/' . $path;
}

function app_url(string $page, array $params = []): string
{
    $params = array_merge(['page' => $page], $params);
    $configuredEntry = getenv('OMQR_APP_ENTRY');
    $entry = $configuredEntry !== false && $configuredEntry !== ''
        ? $configuredEntry
        : (is_vercel_runtime() ? '/' : ($_SERVER['PHP_SELF'] ?? 'index.php'));

    return e($entry . '?' . http_build_query($params));
}

function redirect_to(string $page, array $params = []): void
{
    $params = array_merge(['page' => $page], $params);
    $configuredEntry = getenv('OMQR_APP_ENTRY');
    $entry = $configuredEntry !== false && $configuredEntry !== ''
        ? $configuredEntry
        : (is_vercel_runtime() ? '/' : ($_SERVER['PHP_SELF'] ?? 'index.php'));

    header('Location: ' . $entry . '?' . http_build_query($params));
    exit;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf'] ?? '') . '">';
}

function verify_csrf(): void
{
    $posted = $_POST['csrf'] ?? '';
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) $posted)) {
        flash('Session expired. Please try again.', 'error');
        redirect_to(is_logged_in() ? 'dashboard' : 'login');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function consume_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function handle_post(): void
{
    $action = $_POST['_action'] ?? '';

    if ($action === 'login') {
        handle_login();
        return;
    }

    verify_csrf();

    if (!is_logged_in()) {
        redirect_to('login');
    }

    if ($action === 'add_product') {
        add_product();
    }

    if ($action === 'generate_batch') {
        generate_batch();
    }

    redirect_to('dashboard');
}

function handle_login(): void
{
    verify_csrf();

    $useDemo = ($_POST['use_demo'] ?? '') === '1';
    $email = $useDemo ? DEMO_EMAIL : trim((string) ($_POST['email'] ?? ''));
    $password = $useDemo ? DEMO_PASSWORD : (string) ($_POST['password'] ?? '');

    if ($email === DEMO_EMAIL && $password === DEMO_PASSWORD) {
        $_SESSION['user'] = [
            'name' => 'Demo Admin',
            'email' => DEMO_EMAIL,
        ];
        flash('Logged in with dummy access.');
        redirect_to('dashboard');
    }

    flash('Invalid email or password.', 'error');
    redirect_to('login');
}

function add_product(): void
{
    $name = trim((string) ($_POST['product_name'] ?? ''));
    $catalog = trim((string) ($_POST['catalog_no'] ?? ''));
    $pore = trim((string) ($_POST['pore_size'] ?? ''));
    $membrane = trim((string) ($_POST['membrane_type'] ?? ''));
    $suffix = strtoupper(substr(trim((string) ($_POST['lot_suffix'] ?? '')), 0, 3));
    $template = trim((string) ($_POST['qr_url_template'] ?? ''));

    if ($name === '' || $catalog === '' || $pore === '' || $membrane === '') {
        flash('Product name, catalog number, pore size, and membrane are required.', 'error');
        redirect_to('products');
    }

    insert_product([
        'id' => 'prod-' . date('YmdHis') . '-' . random_int(100, 999),
        'product_name' => $name,
        'catalog_no' => $catalog,
        'pore_size' => $pore,
        'membrane_type' => $membrane,
        'lot_suffix' => $suffix !== '' ? $suffix : 'E',
        'qr_url_template' => $template !== '' ? $template : 'https://example.com/coa/{lot}/{serial}',
        'created_at' => date('c'),
    ]);

    flash('Product added.');
    redirect_to('products');
}

function generate_batch(): void
{
    $product = find_product((string) ($_POST['product_id'] ?? ''));
    if (!$product) {
        flash('Please select a valid product.', 'error');
        redirect_to('dashboard');
    }

    $lotPrefix = strtoupper(substr(trim((string) ($_POST['lot_prefix'] ?? 'S')), 0, 4));
    $lotCode = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', (string) ($_POST['lot_code'] ?? '5516')), 0, 12));
    $lotSuffix = strtoupper(substr(trim((string) ($_POST['lot_suffix'] ?? ($product['lot_suffix'] ?? 'E'))), 0, 4));
    $manualLot = strtoupper(substr(trim((string) ($_POST['lot_no'] ?? '')), 0, 24));
    $lotNo = $manualLot !== '' ? $manualLot : $lotPrefix . $lotCode . $lotSuffix;

    $startRaw = trim((string) ($_POST['start_serial'] ?? '101'));
    $start = max(0, (int) $startRaw);
    $quantity = min(500, max(1, (int) ($_POST['quantity'] ?? 1)));
    $width = max(strlen($startRaw), 1);
    $labels = [];

    for ($i = 0; $i < $quantity; $i++) {
        $serial = str_pad((string) ($start + $i), $width, '0', STR_PAD_LEFT);
        $labels[] = [
            'serial_no' => $serial,
            'qr_payload' => build_qr_payload($product, $lotNo, $serial),
        ];
    }

    $batch = [
        'id' => 'batch-' . date('YmdHis') . '-' . random_int(100, 999),
        'product' => $product,
        'lot_no' => $lotNo,
        'label_date' => trim((string) ($_POST['label_date'] ?? date('Y-m-d'))),
        'start_serial' => $labels[0]['serial_no'],
        'end_serial' => $labels[count($labels) - 1]['serial_no'],
        'quantity' => $quantity,
        'labels' => $labels,
        'created_at' => date('c'),
    ];

    insert_batch($batch);

    flash('Labels generated.');
    redirect_to('labels', ['id' => $batch['id']]);
}

function build_qr_payload(array $product, string $lotNo, string $serial): string
{
    $template = trim((string) ($product['qr_url_template'] ?? ''));

    if ($template !== '') {
        return strtr($template, [
            '{product}' => rawurlencode((string) ($product['product_name'] ?? '')),
            '{catalog}' => rawurlencode((string) ($product['catalog_no'] ?? '')),
            '{lot}' => rawurlencode($lotNo),
            '{serial}' => rawurlencode($serial),
            '{membrane}' => rawurlencode((string) ($product['membrane_type'] ?? '')),
        ]);
    }

    return json_encode([
        'product' => $product['product_name'] ?? '',
        'catalog' => $product['catalog_no'] ?? '',
        'lot' => $lotNo,
        'serial' => $serial,
    ], JSON_UNESCAPED_SLASHES);
}

function get_products(): array
{
    $stmt = db()->query('
        SELECT id, product_name, catalog_no, pore_size, membrane_type, lot_suffix, qr_url_template, created_at
        FROM products
        ORDER BY created_at ASC, product_name ASC
    ');

    return $stmt->fetchAll();
}

function get_batches(): array
{
    $stmt = db()->query('
        SELECT b.*, p.product_name
        FROM batches b
        LEFT JOIN products p ON p.id = b.product_id
        ORDER BY b.created_at DESC
    ');

    $batches = [];

    foreach ($stmt->fetchAll() as $row) {
        $batches[] = hydrate_batch($row);
    }

    return $batches;
}

function find_product(string $id): ?array
{
    $stmt = db()->prepare('
        SELECT id, product_name, catalog_no, pore_size, membrane_type, lot_suffix, qr_url_template, created_at
        FROM products
        WHERE id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id]);
    $product = $stmt->fetch();

    if (!$product) {
        return null;
    }

    return $product;
}

function find_batch(string $id): ?array
{
    $stmt = db()->prepare('
        SELECT b.*, p.product_name
        FROM batches b
        LEFT JOIN products p ON p.id = b.product_id
        WHERE b.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id]);
    $batch = $stmt->fetch();

    if (!$batch) {
        return null;
    }

    return hydrate_batch($batch);
}

function hydrate_batch(array $row): array
{
    $product = json_decode((string) $row['product_snapshot'], true);
    $labelStmt = db()->prepare('
        SELECT serial_no, qr_payload
        FROM labels
        WHERE batch_id = :batch_id
        ORDER BY id ASC
    ');
    $labelStmt->execute(['batch_id' => $row['id']]);

    return [
        'id' => $row['id'],
        'product' => is_array($product) ? $product : [],
        'lot_no' => $row['lot_no'],
        'label_date' => $row['label_date'],
        'start_serial' => $row['start_serial'],
        'end_serial' => $row['end_serial'],
        'quantity' => (int) $row['quantity'],
        'labels' => $labelStmt->fetchAll(),
        'created_at' => $row['created_at'],
    ];
}

function render_login(): void
{
    $flash = consume_flash();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Login | <?= e(APP_NAME) ?></title>
        <link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
    </head>
    <body class="login-view">
        <main class="login-shell single-panel">
            <section class="login-panel">
                <div class="login-brand">
                    
                    <div>
                        <strong>OMSONS</strong>
                        <span>QR Label Console</span>
                    </div>
                </div>
                <h1>Sign in</h1>
                <?php render_flash($flash); ?>
                <form method="post" class="stack-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="login">
                    <label>
                        Email
                        <input type="email" name="email" value="<?= e(DEMO_EMAIL) ?>" autocomplete="username">
                    </label>
                    <label>
                        Password
                        <input type="password" name="password" value="<?= e(DEMO_PASSWORD) ?>" autocomplete="current-password">
                    </label>
                    <div class="login-actions">
                        <button type="submit" class="primary-btn">Login</button>
                        <button type="submit" name="use_demo" value="1" class="secondary-btn">Dummy Login</button>
                    </div>
                </form>
            </section>
        </main>
        <script src="<?= e(asset_url('qrcode.min.js')) ?>"></script>
        <script src="<?= e(asset_url('app.js')) ?>"></script>
    </body>
    </html>
    <?php
}

function render_setup_error(Throwable $exception): void
{
    $config = db_config();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Database Setup | <?= e(APP_NAME) ?></title>
        <link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
    </head>
    <body class="login-view">
        <main class="login-shell single-panel">
            <section class="login-panel">
                <div class="login-brand">
                    <span class="brand-mark">OM</span>
                    <div>
                        <strong>OMSONS</strong>
                        <span>Database Setup</span>
                    </div>
                </div>
                <h1>Database not connected</h1>
                <div class="flash error"><?= e($exception->getMessage()) ?></div>
                <div class="setup-list">
                    <p>Start MySQL or check the hosted database environment variables, then refresh this page.</p>
                    <p>Current connection: <?= e($config['username'] . '@' . $config['host'] . ':' . $config['port'] . '/' . $config['database']) ?></p>
                    <p>Settings file: <?= e('config/database.php') ?></p>
                </div>
            </section>
        </main>
    </body>
    </html>
    <?php
}

function render_app_page(string $page, string $title, string $callback): void
{
    $flash = consume_flash();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> | <?= e(APP_NAME) ?></title>
        <link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
    </head>
    <body class="app-view">
        <div class="app-shell">
            <aside class="sidebar">
                <div class="sidebar-brand">
                    
                    <div>
                        <strong>OMSONS</strong>
                        <span>Label Generator</span>
                    </div>
                </div>
                <nav class="side-nav" aria-label="Main navigation">
                    <?= nav_link('dashboard', 'Generator', $page) ?>
                    <?= nav_link('products', 'Products', $page) ?>
                    <?= nav_link('batches', 'Batches', $page) ?>
                </nav>
            </aside>
            <div class="main-area">
                <header class="topbar">
                    <div>
                        <p><?= e(date('d M Y')) ?></p>
                        <h1><?= e($title) ?></h1>
                    </div>
                    <div class="user-chip">
                        <span><?= e($_SESSION['user']['name'] ?? 'Demo Admin') ?></span>
                        <a href="<?= app_url('logout') ?>">Logout</a>
                    </div>
                </header>
                <?php render_flash($flash); ?>
                <?php $callback(); ?>
            </div>
        </div>
        <script src="<?= e(asset_url('qrcode.min.js')) ?>"></script>
        <script src="<?= e(asset_url('app.js')) ?>"></script>
    </body>
    </html>
    <?php
}

function nav_link(string $target, string $label, string $current): string
{
    $class = $target === $current ? 'active' : '';
    return '<a class="' . $class . '" href="' . app_url($target) . '">' . e($label) . '</a>';
}

function render_flash(?array $flash): void
{
    if (!$flash) {
        return;
    }
    $type = $flash['type'] ?? 'success';
    echo '<div class="flash ' . e($type) . '">' . e($flash['message'] ?? '') . '</div>';
}

function render_dashboard(): void
{
    $products = get_products();
    $selected = $products[0] ?? default_products()[0];
    $batches = array_slice(get_batches(), 0, 5);
    ?>
    <main class="generator-layout">
        <section class="work-panel">
            <div class="panel-title">
                <h2>Create Label Batch</h2>
                <span><?= count($products) ?> products</span>
            </div>
            <form method="post" class="generator-form" id="generatorForm">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="generate_batch">
                <div class="form-grid">
                    <label>
                        Product
                        <select name="product_id" id="productSelect" required>
                            <?php foreach ($products as $product): ?>
                                <option
                                    value="<?= e($product['id']) ?>"
                                    data-name="<?= e($product['product_name']) ?>"
                                    data-catalog="<?= e($product['catalog_no']) ?>"
                                    data-pore="<?= e($product['pore_size']) ?>"
                                    data-membrane="<?= e($product['membrane_type']) ?>"
                                    data-suffix="<?= e($product['lot_suffix']) ?>"
                                    data-template="<?= e($product['qr_url_template']) ?>"
                                >
                                    <?= e($product['product_name']) ?> / <?= e($product['catalog_no']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Label Date
                        <input type="date" name="label_date" value="<?= e(date('Y-m-d')) ?>">
                    </label>
                    <label>
                        Lot Prefix
                        <input type="text" name="lot_prefix" value="S" maxlength="4">
                    </label>
                    <label>
                        Lot Code
                        <input type="text" name="lot_code" value="5516" maxlength="12">
                    </label>
                    <label>
                        Lot Suffix
                        <input type="text" name="lot_suffix" value="<?= e($selected['lot_suffix'] ?? 'E') ?>" maxlength="4" id="lotSuffix">
                    </label>
                    <label>
                        Final Lot No.
                        <input type="text" name="lot_no" placeholder="Auto" maxlength="24" id="manualLot">
                    </label>
                    <label>
                        Start Serial
                        <input type="text" name="start_serial" value="101" inputmode="numeric">
                    </label>
                    <label>
                        Quantity
                        <input type="number" name="quantity" value="1" min="1" max="500">
                    </label>
                </div>
                <button type="submit" class="primary-btn">Generate Labels</button>
            </form>
        </section>

        <section class="preview-panel">
            <div class="panel-title">
                <h2>Live Label</h2>
                <span id="previewLotText">S5516E</span>
            </div>
            <div class="preview-stage">
                <?= render_label_card($selected, 'S5516E', '101', build_qr_payload($selected, 'S5516E', '101'), true) ?>
            </div>
        </section>
    </main>

    <section class="table-panel">
        <div class="panel-title">
            <h2>Recent Batches</h2>
            <a href="<?= app_url('batches') ?>">View all</a>
        </div>
        <?php render_batch_table($batches); ?>
    </section>
    <?php
}

function render_products(): void
{
    $products = get_products();
    ?>
    <main class="split-layout">
        <section class="work-panel">
            <div class="panel-title">
                <h2>Add Product</h2>
            </div>
            <form method="post" class="stack-form">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="add_product">
                <label>
                    Product Name
                    <input type="text" name="product_name" placeholder="Puricap PES" required>
                </label>
                <label>
                    Catalog No.
                    <input type="text" name="catalog_no" placeholder="OM553-02-02-045" required>
                </label>
                <label>
                    PES / Pore Size
                    <input type="text" name="pore_size" placeholder="0.45 + 0.2 um" required>
                </label>
                <label>
                    Membrane Type
                    <input type="text" name="membrane_type" placeholder="PES" required>
                </label>
                <label>
                    Default Lot Suffix
                    <input type="text" name="lot_suffix" placeholder="E" maxlength="3">
                </label>
                <label>
                    QR URL Template
                    <input type="url" name="qr_url_template" placeholder="https://example.com/coa/{lot}/{serial}">
                </label>
                <button type="submit" class="primary-btn">Add Product</button>
            </form>
        </section>

        <section class="table-panel">
            <div class="panel-title">
                <h2>Products</h2>
                <span><?= count($products) ?> saved</span>
            </div>
            <div class="responsive-table">
                <table>
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th>Catalog</th>
                        <th>PES</th>
                        <th>Membrane</th>
                        <th>Lot Suffix</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?= e($product['product_name']) ?></td>
                            <td><?= e($product['catalog_no']) ?></td>
                            <td><?= format_pore_size($product['pore_size']) ?></td>
                            <td><?= e($product['membrane_type']) ?></td>
                            <td><?= e($product['lot_suffix']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <?php
}

function render_batches(): void
{
    ?>
    <main class="table-panel">
        <div class="panel-title">
            <h2>Generated Batches</h2>
            <a href="<?= app_url('dashboard') ?>">New batch</a>
        </div>
        <?php render_batch_table(get_batches()); ?>
    </main>
    <?php
}

function render_batch_table(array $batches): void
{
    if (!$batches) {
        echo '<div class="empty-state">No batches generated yet.</div>';
        return;
    }
    ?>
    <div class="responsive-table">
        <table>
            <thead>
            <tr>
                <th>Lot No.</th>
                <th>Product</th>
                <th>Serials</th>
                <th>Qty</th>
                <th>Created</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $batch): ?>
                <tr>
                    <td><?= e($batch['lot_no']) ?></td>
                    <td><?= e($batch['product']['product_name'] ?? '') ?></td>
                    <td><?= e(($batch['start_serial'] ?? '') . ' - ' . ($batch['end_serial'] ?? '')) ?></td>
                    <td><?= e((string) ($batch['quantity'] ?? 0)) ?></td>
                    <td><?= e(date('d M Y, h:i A', strtotime((string) ($batch['created_at'] ?? 'now')))) ?></td>
                    <td><a class="table-action" href="<?= app_url('labels', ['id' => $batch['id']]) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function render_labels(): void
{
    $batch = find_batch((string) ($_GET['id'] ?? ''));
    if (!$batch) {
        echo '<main class="empty-state">Batch not found.</main>';
        return;
    }

    $product = $batch['product'] ?? [];
    ?>
    <main class="labels-page">
        <div class="print-toolbar">
            <div>
                <h2><?= e($batch['lot_no']) ?></h2>
                <p><?= e($product['product_name'] ?? '') ?> / <?= e($batch['quantity'] . ' labels') ?></p>
            </div>
            <div class="toolbar-actions">
                <a class="secondary-btn" href="<?= app_url('dashboard') ?>">Back</a>
                <button type="button" class="primary-btn" onclick="window.print()">Print</button>
            </div>
        </div>
        <section class="label-sheet">
            <?php foreach ($batch['labels'] as $label): ?>
                <?= render_label_card($product, (string) $batch['lot_no'], (string) $label['serial_no'], (string) $label['qr_payload']) ?>
            <?php endforeach; ?>
        </section>
    </main>
    <?php
}

function render_label_card(array $product, string $lotNo, string $serial, string $qrPayload, bool $preview = false): string
{
    ob_start();
    ?>
    <article class="label-card<?= $preview ? ' preview-label' : '' ?>">
        <div class="label-branding">
            <div class="omsons-logo">OMSONS</div>
            <span>GERMANY</span>
        </div>
        <div class="label-content">
            <div class="label-copy">
                <h3 data-preview="product"><?= e($product['product_name'] ?? '') ?></h3>
                <p><strong>Cat. No.:</strong> <span data-preview="catalog"><?= e($product['catalog_no'] ?? '') ?></span></p>
                <p><strong>Pes:</strong> <span data-preview="pore"><?= format_pore_size($product['pore_size'] ?? '') ?></span></p>
                <div class="label-gap"></div>
                <p><strong>Lot. No.:</strong> <span data-preview="lot"><?= e($lotNo) ?></span></p>
                <p><strong>Membranes :</strong> <span data-preview="membrane"><?= e($product['membrane_type'] ?? '') ?></span></p>
                <p><strong>Serial No.:</strong> <span data-preview="serial"><?= e($serial) ?></span></p>
            </div>
            <div class="label-qr">
                <div class="qr-code" data-qr="<?= e($qrPayload) ?>"></div>
                <span>Scan for COA</span>
            </div>
        </div>
    </article>
    <?php
    return (string) ob_get_clean();
}
