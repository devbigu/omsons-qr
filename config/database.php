<?php
declare(strict_types=1);

$url = getenv('DATABASE_URL') ?: getenv('MYSQL_URL') ?: '';
$parsed = $url ? parse_url($url) : [];
$path = isset($parsed['path']) ? rawurldecode(ltrim((string) $parsed['path'], '/')) : '';
$autoCreate = getenv('OMQR_DB_AUTO_CREATE');

return [
    'host' => getenv('OMQR_DB_HOST') ?: ($parsed['host'] ?? '127.0.0.1'),
    'port' => getenv('OMQR_DB_PORT') ?: (string) ($parsed['port'] ?? '3306'),
    'database' => getenv('OMQR_DB_NAME') ?: ($path !== '' ? $path : 'omqr'),
    'username' => getenv('OMQR_DB_USER') ?: rawurldecode((string) ($parsed['user'] ?? 'root')),
    'password' => getenv('OMQR_DB_PASS') ?: rawurldecode((string) ($parsed['pass'] ?? '')),
    'charset' => 'utf8mb4',
    'auto_create' => $autoCreate === false ? !getenv('VERCEL') : filter_var($autoCreate, FILTER_VALIDATE_BOOL),
];
