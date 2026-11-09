<?php

declare(strict_types=1);

/*
 * Front controller. Not installed yet (no config/installed.lock) → the web
 * installer. Otherwise: boot and dispatch.
 */
if (PHP_SAPI === 'cli-server') {
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($p !== '/' && is_file(__DIR__ . $p)) {
        return false;
    }
    if (str_starts_with($p, '/install')) {
        require __DIR__ . '/install/index.php';
        return true;
    }
}

$base = dirname(__DIR__);
if (!is_file($base . '/config/installed.lock')) {
    header('Location: /install/');
    exit;
}

require $base . '/src/bootstrap.php';
require $base . '/src/routes.php';
