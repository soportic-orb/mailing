<?php

declare(strict_types=1);

/*
 * Maintenance from the command line (CloudPanel SSH):
 *   php bin/console.php migrate            apply pending migrations
 *   php bin/console.php status             version, migrations, cron
 *   php bin/console.php cron               run the worker once (verbose)
 *   php bin/console.php backup             database backup to storage/backups
 *   php bin/console.php update             OTA update from GitHub
 *   php bin/console.php user <email> <pw>  create / reset an administrator
 *   php bin/console.php template-format   template format guide (Markdown)
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Mailing\Core\DB;
use Mailing\Services\Backup;
use Mailing\Services\Cron;
use Mailing\Services\Migrations;
use Mailing\Services\Updater;

$cmd = $argv[1] ?? 'status';
switch ($cmd) {
    case 'migrate':
        $r = Migrations::run();
        echo $r['ok'] ? 'OK ' . implode(', ', $r['ran']) . "\n" : 'ERROR ' . ($r['failed'] ?? '') . ': ' . ($r['error'] ?? '') . "\n";
        exit($r['ok'] ? 0 : 1);
    case 'cron':
        echo json_encode(Cron::run('cron'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        break;
    case 'backup':
        echo Backup::create('console') . "\n";
        break;
    case 'update':
        $r = (new Updater())->apply();
        echo $r['log'] . "\n";
        exit($r['ok'] ? 0 : 1);
    case 'template-format':
        echo Mailing\Editor\TemplateFormat::spec();
        break;
    case 'user':
        [$email, $pw] = [strtolower((string) ($argv[2] ?? '')), (string) ($argv[3] ?? '')];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 10) {
            fwrite(STDERR, "Ús: php bin/console.php user <email> <contrasenya de 10+ caràcters>\n");
            exit(1);
        }
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        if (DB::value('SELECT id FROM users WHERE email = ?', [$email])) {
            DB::update('users', ['password_hash' => $hash, 'role' => 'admin', 'active' => 1], 'email = ?', [$email]);
            echo "Contrasenya restablerta (administrador/a).\n";
        } else {
            DB::insert('users', ['name' => explode('@', $email)[0], 'email' => $email, 'password_hash' => $hash, 'role' => 'admin', 'active' => 1, 'created_at' => now_utc()]);
            echo "Administrador/a creat.\n";
        }
        break;
    default:
        $m = Migrations::status();
        echo 'Versió ' . app_version() . "\n" . count($m['applied']) . ' migracions aplicades, ' . count($m['pending']) . " pendents\n"
            . 'Cron: ' . (Cron::age() === null ? 'mai' : 'fa ' . Cron::age() . ' s') . "\n";
}
