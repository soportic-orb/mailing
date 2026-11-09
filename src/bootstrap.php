<?php

declare(strict_types=1);

/*
 * Application bootstrap: autoloader, .env, configuration, error handling.
 * No Composer dependencies: the platform runs on any PHP 8.1+ shared host.
 */

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Mailing\\')) {
        return;
    }
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/src/functions.php';

Mailing\Core\Env::load(BASE_PATH . '/.env');
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
Mailing\Core\ErrorHandler::register();
