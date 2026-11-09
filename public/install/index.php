<?php

declare(strict_types=1);

/*
 * Web installer: requirements → database → administrator → Mailjet (optional) → done.
 * Writes .env (with a new APP_KEY), creates the tables, the first user and
 * config/installed.lock. Once installed it refuses to run again.
 */

$base = dirname(__DIR__, 2);
session_name('mailing_install');
session_start();
// Installed: only the final page (once, right after installing) is still shown.
if (is_file($base . '/config/installed.lock') && !(($_GET['pas'] ?? '') === 'fet' && !empty($_SESSION['done']))) {
    header('Location: /');
    exit;
}
require $base . '/src/bootstrap.php';

use Mailing\Core\Crypto;
use Mailing\Core\DB;
use Mailing\Core\Env;
use Mailing\Core\Settings;
use Mailing\Services\Cron;
use Mailing\Services\Migrations;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$S = &$_SESSION;
$step = (string) ($_GET['pas'] ?? 'requisits');
$error = '';

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function reqs(string $base): array
{
    $w = static fn (string $p): bool => is_dir($base . $p) ? is_writable($base . $p) : is_writable(dirname($base . $p));
    return [
        ['PHP 8.1 o superior', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION, true],
        ['Extensió pdo_mysql', extension_loaded('pdo_mysql'), '', true],
        ['Extensió curl (Mailjet, IA, actualitzacions)', extension_loaded('curl'), '', true],
        ['Extensió mbstring', extension_loaded('mbstring'), '', true],
        ['Extensió openssl (claus xifrades)', extension_loaded('openssl'), '', true],
        ['Extensió dom (editor de text)', extension_loaded('dom'), '', true],
        ['Extensió zip (Excel i actualitzacions OTA)', class_exists(ZipArchive::class), '', false],
        ['Extensió gd (imatges)', extension_loaded('gd'), '', false],
        ['Es pot escriure el fitxer .env', is_writable($base), $base, true],
        ['Es pot escriure a storage/', $w('/storage') && $w('/storage/images') && $w('/storage/logs'), '', true],
        ['Es pot escriure a config/', $w('/config'), '', true],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($S['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'La sessió ha caducat: torna-ho a provar.';
    } elseif ($step === 'bd') {
        $db = ['host' => trim($_POST['host'] ?? 'localhost'), 'port' => (int) ($_POST['port'] ?? 3306) ?: 3306, 'database' => trim($_POST['database'] ?? ''), 'username' => trim($_POST['username'] ?? ''), 'password' => (string) ($_POST['password'] ?? '')];
        try {
            $pdo = DB::connect($db['host'], $db['port'], $db['database'], $db['username'], $db['password']);
            $v = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $S['db'] = $db;
            $S['db_version'] = $v;
            header('Location: ?pas=admin');
            exit;
        } catch (Throwable $e) {
            $error = 'No es pot connectar amb la base de dades: ' . $e->getMessage();
        }
    } elseif ($step === 'admin') {
        $a = ['name' => trim($_POST['name'] ?? ''), 'email' => strtolower(trim($_POST['email'] ?? '')), 'password' => (string) ($_POST['password'] ?? ''), 'url' => rtrim(trim($_POST['url'] ?? ''), '/'), 'tz' => trim($_POST['tz'] ?? 'Europe/Madrid')];
        if ($a['name'] === '' || !filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Cal el nom i un correu vàlid.';
        } elseif (mb_strlen($a['password']) < 10) {
            $error = 'La contrasenya ha de tenir almenys 10 caràcters.';
        } elseif ($a['password'] !== ($_POST['password2'] ?? '')) {
            $error = 'Les contrasenyes no coincideixen.';
        } elseif (!preg_match('#^https?://#', $a['url'])) {
            $error = 'L\'adreça ha de començar per https://';
        } elseif (!in_array($a['tz'], DateTimeZone::listIdentifiers(), true)) {
            $error = 'Zona horària no vàlida.';
        } else {
            $S['admin'] = $a;
            header('Location: ?pas=mailjet');
            exit;
        }
    } elseif ($step === 'mailjet') {
        $S['mailjet'] = ['key' => trim($_POST['mj_key'] ?? ''), 'secret' => trim($_POST['mj_secret'] ?? '')];
        // --- Install -------------------------------------------------------
        try {
            if (empty($S['db']) || empty($S['admin'])) {
                throw new RuntimeException('Falten dades: torna a començar.');
            }
            $db = $S['db'];
            $a = $S['admin'];
            $env = [
                'APP_URL' => $a['url'], 'APP_KEY' => Crypto::newKey(), 'APP_TIMEZONE' => $a['tz'], 'APP_DEBUG' => 'false',
                'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'],
            ];
            if (!Env::write($base . '/.env', $env)) {
                throw new RuntimeException('No s\'ha pogut escriure el fitxer .env.');
            }
            @chmod($base . '/.env', 0640);
            Env::load($base . '/.env');
            $pdo = DB::connect($db['host'], $db['port'], $db['database'], $db['username'], $db['password']);
            DB::setConnection($pdo);
            $m = Migrations::run($pdo);
            if (!$m['ok']) {
                throw new RuntimeException('Error creant les taules (' . ($m['failed'] ?? '') . '): ' . ($m['error'] ?? ''));
            }
            $now = gmdate('Y-m-d H:i:s');
            if (DB::value('SELECT id FROM users WHERE email = ?', [$a['email']])) {
                DB::update('users', ['name' => $a['name'], 'password_hash' => password_hash($a['password'], PASSWORD_DEFAULT), 'role' => 'admin', 'active' => 1], 'email = ?', [$a['email']]);
            } else {
                DB::insert('users', ['name' => $a['name'], 'email' => $a['email'], 'password_hash' => password_hash($a['password'], PASSWORD_DEFAULT), 'role' => 'admin', 'active' => 1, 'created_at' => $now]);
            }
            Settings::set('webhook_key', random_token(32));
            Settings::set('cron_key', random_token(32));
            Settings::set('installed_at', $now);
            if ($S['mailjet']['key'] !== '' && $S['mailjet']['secret'] !== '') {
                Settings::set('mailjet_key', $S['mailjet']['key'], true);
                Settings::set('mailjet_secret', $S['mailjet']['secret'], true);
            }
            file_put_contents($base . '/config/installed.lock', json_encode(['at' => $now, 'version' => app_version()]));
            $S['done'] = ['url' => $a['url'], 'email' => $a['email'], 'webhook' => $a['url'] . '/webhook/mailjet?key=' . Settings::get('webhook_key'), 'cron' => Cron::line()];
            unset($S['db'], $S['admin'], $S['mailjet']);
            header('Location: ?pas=fet');
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

if (($step === 'admin' && empty($S['db'])) || ($step === 'mailjet' && empty($S['admin']))) {
    header('Location: ?pas=' . ($step === 'admin' ? 'bd' : 'admin'));
    exit;
}
$guessUrl = ((($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'mailing.soportic.es');
$steps = ['requisits' => 'Requisits', 'bd' => 'Base de dades', 'admin' => 'Administrador/a', 'mailjet' => 'Mailjet', 'fet' => 'Fet'];
$csrf = '<input type="hidden" name="csrf" value="' . h($S['csrf']) . '">';
?><!DOCTYPE html>
<html lang="ca"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Instal·lació · Mailing Soportic</title><?= favicon_tag() ?><link rel="stylesheet" href="/assets/app.css">
<style>.steps{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 20px}.steps span{font-size:12px;font-weight:700;padding:5px 10px;border-radius:999px;background:var(--fill);color:var(--muted)}.steps span.on{background:var(--accent);color:#fff}.bare-card{width:min(620px,100%)}</style>
</head><body><div class="bare"><div class="bare-card">
<span class="brand" style="margin-bottom:16px"><span class="brand-mark" aria-hidden="true"><?= brand_icon() ?></span><span class="brand-name">Mailing<small>Instal·lació · versió <?= h(app_version()) ?></small></span></span>
<div class="steps"><?php foreach ($steps as $k => $l): ?><span class="<?= $k === $step ? 'on' : '' ?>"><?= h($l) ?></span><?php endforeach; ?></div>
<?php if ($error): ?><div class="alert err"><?= h($error) ?></div><?php endif; ?>

<?php if ($step === 'requisits'): $r = reqs($base); $ok = !array_filter($r, static fn ($x) => $x[3] && !$x[1]); ?>
    <h1>Comprovació del servidor</h1>
    <ul class="checks" style="margin:14px 0 18px"><?php foreach ($r as [$label, $pass, $detail, $required]): ?><li class="<?= $pass ? '' : ($required ? 'block' : 'warn') ?>"><?= h($label) ?><?= $detail ? ' <span class="muted">(' . h($detail) . ')</span>' : '' ?><?= !$pass && !$required ? ' — recomanat' : '' ?></li><?php endforeach; ?></ul>
    <?php if ($ok): ?><a class="btn" href="?pas=bd">Continua</a><?php else: ?><p class="muted">Corregeix els punts en vermell (a CloudPanel: versió i extensions de PHP del lloc, i permisos de la carpeta) i torna a carregar la pàgina.</p><?php endif; ?>

<?php elseif ($step === 'bd'): $d = $S['db'] ?? []; ?>
    <h1>Base de dades MySQL</h1>
    <p class="muted">Crea-la abans a CloudPanel (Sites › el lloc › Databases) i escriu-ne les dades.</p>
    <form method="post"><?= $csrf ?>
        <div class="row"><label class="field"><span>Servidor</span><input class="input" name="host" value="<?= h($d['host'] ?? '127.0.0.1') ?>" required></label><label class="field"><span>Port</span><input class="input" name="port" type="number" value="<?= h((string) ($d['port'] ?? 3306)) ?>"></label></div>
        <label class="field"><span>Nom de la base de dades</span><input class="input" name="database" value="<?= h($d['database'] ?? '') ?>" required></label>
        <div class="row"><label class="field"><span>Usuari</span><input class="input" name="username" value="<?= h($d['username'] ?? '') ?>" required autocomplete="off"></label><label class="field"><span>Contrasenya</span><input class="input" type="password" name="password" autocomplete="new-password"></label></div>
        <button class="btn" type="submit">Comprova i continua</button>
    </form>

<?php elseif ($step === 'admin'): $a = $S['admin'] ?? []; ?>
    <h1>Primer usuari (administrador/a)</h1>
    <p class="muted">Connectat a <?= h($S['db_version'] ?? 'MySQL') ?>.</p>
    <form method="post"><?= $csrf ?>
        <div class="row"><label class="field"><span>Nom</span><input class="input" name="name" value="<?= h($a['name'] ?? '') ?>" required></label><label class="field"><span>Correu</span><input class="input" type="email" name="email" value="<?= h($a['email'] ?? '') ?>" required></label></div>
        <div class="row"><label class="field"><span>Contrasenya</span><input class="input" type="password" name="password" minlength="10" required autocomplete="new-password"><small>Mínim 10 caràcters.</small></label><label class="field"><span>Repeteix-la</span><input class="input" type="password" name="password2" required autocomplete="new-password"></label></div>
        <div class="row"><label class="field"><span>Adreça de la plataforma</span><input class="input" name="url" value="<?= h($a['url'] ?? $guessUrl) ?>" required><small>Es fa servir als enllaços dels correus (baixa, imatges).</small></label><label class="field"><span>Zona horària</span><input class="input" name="tz" value="<?= h($a['tz'] ?? 'Europe/Madrid') ?>" required></label></div>
        <button class="btn" type="submit">Continua</button>
    </form>

<?php elseif ($step === 'mailjet'): ?>
    <h1>Mailjet (opcional)</h1>
    <p class="muted" style="line-height:1.5">Les claus API del compte de Mailjet (Account settings › API keys). Es poden posar o canviar després a Configuració; es desen xifrades.</p>
    <form method="post"><?= $csrf ?>
        <label class="field"><span>API Key</span><input class="input" name="mj_key" autocomplete="off"></label>
        <label class="field"><span>Secret Key</span><input class="input" type="password" name="mj_secret" autocomplete="new-password"></label>
        <button class="btn" type="submit" data-busy="Instal·lant…">Instal·la</button>
    </form>

<?php elseif ($step === 'fet' && !empty($S['done'])): $d = $S['done']; ?>
    <h1>Instal·lació completada</h1>
    <p>Ja pots entrar amb <b><?= h($d['email']) ?></b>.</p>
    <div class="alert info" style="line-height:1.55"><b>Dos passos més a CloudPanel i Mailjet:</b><br>
        1. <b>Cron Jobs</b> del lloc, cada minut:<br><code><?= h($d['cron']) ?></code><br>
        2. <b>Webhooks de Mailjet</b> (o el botó automàtic de Configuració):<br><code><?= h($d['webhook']) ?></code></div>
    <a class="btn" href="/login">Entra a la plataforma</a>
<?php else: ?>
    <p><a href="?pas=requisits">Comença</a></p>
<?php endif; ?>
</div></div></body></html>
