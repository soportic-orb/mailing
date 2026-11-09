<?php
use Mailing\Core\Auth;
use Mailing\Core\Context;
use Mailing\Core\Csrf;
use Mailing\Core\Session;

$user = Auth::user();
$project = Context::project();
$projects = $user ? Context::projects() : [];
$cur = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$active = static fn (string $prefix): string => ($prefix === '/' ? $cur === '/' : str_starts_with($cur, $prefix)) ? ' class="active"' : '';
$msg = Session::pull('msg');
$accent = $project['color'] ?? '#ac1966';
?><!DOCTYPE html>
<html lang="ca">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<meta name="robots" content="noindex">
<title><?= e(($title ?? 'Mailing') . ' · Mailing Soportic') ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23ac1966'/><path d='M7 11l9 6 9-6M7 10h18v13H7z' fill='none' stroke='white' stroke-width='2' stroke-linejoin='round'/></svg>">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(app_version()) ?>">
<style>:root { --accent: <?= e($accent) ?>; --accent-dark: color-mix(in srgb, <?= e($accent) ?> 82%, #000); --accent-soft: color-mix(in srgb, <?= e($accent) ?> 12%, #fff); }</style>
<?= \Mailing\Core\View::get('head') ?>
</head>
<body>
<div class="app-top">
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">✉</span><span class="brand-name">Mailing<small>Soportic</small></span></a>
            <?php if ($user && $projects): ?>
                <form method="post" action="/projecte" class="proj-switch" title="Projecte amb què treballes">
                    <?= Csrf::field() ?>
                    <span class="dot" style="background:<?= e($accent) ?>"></span>
                    <label class="sr" for="proj-select" hidden>Projecte</label>
                    <select id="proj-select" name="project_id" aria-label="Projecte">
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= $project && (int) $project['id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn sm secondary">Canvia</button></noscript>
                </form>
            <?php endif; ?>
            <span class="spacer"></span>
            <?php if ($user): ?>
                <span class="user-chip"><?= e($user['name']) ?></span>
                <details class="ml-menu">
                    <summary class="btn secondary sm" aria-label="Menú de l'usuari">☰</summary>
                    <div class="ml-menu-pop">
                        <a href="/perfil">El meu perfil<small>Nom i contrasenya</small></a>
                        <?php if (Auth::isAdmin()): ?>
                            <a href="/projectes">Projectes<small>Clients i marques que envien</small></a>
                            <a href="/usuaris">Usuaris<small>Accés i permisos</small></a>
                            <a href="/configuracio">Configuració<small>Mailjet, IA i enviament</small></a>
                            <a href="/actualitzacio">Actualització<small>Versió <?= e(app_version()) ?> · OTA i còpies</small></a>
                            <a href="/activitat">Activitat<small>Registre d'accions</small></a>
                        <?php endif; ?>
                        <form method="post" action="/logout"><?= Csrf::field() ?><button type="submit">Tanca la sessió</button></form>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </header>
    <?php if ($user && $project): ?>
    <nav class="tabs" aria-label="Seccions">
        <a href="/"<?= $active('/') ?>>Inici</a>
        <a href="/campanyes"<?= $active('/campanyes') ?>>Campanyes</a>
        <a href="/calendari"<?= $active('/calendari') ?>>Calendari</a>
        <a href="/contactes"<?= $active('/contactes') ?>>Contactes</a>
        <a href="/llistes"<?= $active('/llistes') ?>>Llistes</a>
        <a href="/segments"<?= $active('/segments') ?>>Segments</a>
        <a href="/plantilles"<?= $active('/plantilles') ?>>Plantilles</a>
        <a href="/remitents"<?= $active('/remitents') ?>>Remitents</a>
        <a href="/sincronitzacio"<?= $active('/sincronitzacio') ?>>Sincronització</a>
    </nav>
    <?php endif; ?>
</div>
<main<?= !empty($wide) ? ' class="wide"' : '' ?>>
<?php if ($msg): ?><div id="flash-msg" data-type="<?= e($msg['type']) ?>" data-text="<?= e($msg['text']) ?>" hidden></div>
<?php if ($msg['type'] === 'err'): ?><div class="alert err"><?= e($msg['text']) ?></div><?php endif; ?><?php endif; ?>
<?= $content ?>
</main>
<script src="/assets/app.js?v=<?= e(app_version()) ?>"></script>
<?= \Mailing\Core\View::get('foot') ?>
</body>
</html>
