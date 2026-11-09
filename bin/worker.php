<?php

declare(strict_types=1);

/*
 * Background worker: run it every minute from CloudPanel's cron.
 *   * * * * * php /home/<user>/htdocs/mailing.soportic.es/bin/worker.php >/dev/null 2>&1
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$base = dirname(__DIR__);
if (!is_file($base . '/config/installed.lock')) {
    exit(0);
}
require $base . '/src/bootstrap.php';
$r = Mailing\Services\Cron::run('cron');
if (in_array('-v', $argv ?? [], true)) {
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
