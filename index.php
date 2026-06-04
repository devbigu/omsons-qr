<?php
declare(strict_types=1);

require __DIR__ . '/src/app.php';

try {
    init_app();
} catch (Throwable $exception) {
    render_setup_error($exception);
    exit;
}

$page = $_GET['page'] ?? (is_logged_in() ? 'dashboard' : 'login');

if ($page === 'logout') {
    logout();
    redirect_to('login');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post();
}

if (!is_logged_in() && $page !== 'login') {
    redirect_to('login');
}

if (is_logged_in() && $page === 'login') {
    redirect_to('dashboard');
}

switch ($page) {
    case 'login':
        render_login();
        break;

    case 'products':
        render_app_page('products', 'Product Master', 'render_products');
        break;

    case 'batches':
        render_app_page('batches', 'Batch History', 'render_batches');
        break;

    case 'labels':
        render_app_page('labels', 'Generated Labels', 'render_labels');
        break;

    case 'dashboard':
    default:
        render_app_page('dashboard', 'QR Label Generator', 'render_dashboard');
        break;
}
