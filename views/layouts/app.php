<?php
use Mailing\Core\Auth;
use Mailing\Core\Context;
use Mailing\Core\Csrf;
use Mailing\Core\DB;
use Mailing\Core\Session;
use Mailing\Services\Notify;
use Mailing\Services\WebPush;

$user = Auth::user();
$project = Context::project();
$projects = $user ? Context::projects() : [];
$cur = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$on = static fn (string $prefix): bool => $prefix === '/' ? $cur === '/' : str_starts_with($cur, $prefix);
$active = static fn (string $prefix): string => $on($prefix) ? ' class="active"' : '';
$msg = Session::pull('msg');
$accent = $project['color'] ?? '#ac1966';
$unread = $user ? (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$user['id']]) : 0;
$pushKey = $user && Notify::enabled() ? (static function (): string { try { return WebPush::publicKey(); } catch (\Throwable) { return ''; } })() : '';

// Sections: [url, label, icon]. Phone tabs and tablet rail show the first ones; «Més» shows every group.
$sec = [
    'home' => ['/', 'Inici', 'home'], 'campaigns' => ['/campanyes', 'Campanyes', 'send'], 'auto' => ['/automatitzacions', 'Automatitzacions', 'bolt'],
    'calendar' => ['/calendari', 'Calendari', 'cal'], 'contacts' => ['/contactes', 'Contactes', 'users'], 'lists' => ['/llistes', 'Llistes', 'list'],
    'segments' => ['/segments', 'Segments', 'filter'], 'forms' => ['/formularis', 'Formularis', 'form'], 'templates' => ['/plantilles', 'Plantilles', 'tpl'],
    'senders' => ['/remitents', 'Remitents', 'at'], 'sync' => ['/sincronitzacio', 'Sincronització', 'sync'],
];
$groups = ['Enviar' => ['campaigns', 'auto', 'calendar', 'templates', 'senders'], 'Audiència' => ['contacts', 'lists', 'segments', 'forms', 'sync']];
$admin = [['/projectes', 'Projectes', 'mail'], ['/usuaris', 'Usuaris', 'users'], ['/configuracio', 'Configuració', 'gear'], ['/actualitzacio', 'Actualització', 'sync'], ['/activitat', 'Activitat', 'list']];
$tabs = ['home', 'campaigns', 'auto', 'contacts'];
$rail = ['home', 'campaigns', 'auto', 'calendar', '|', 'contacts', 'lists'];
$short = ['auto' => 'Automat.'];
$ico = static fn (string $n, int $s = 20): string => '<svg width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-' . $n . '"/></svg>';
$moreOn = !array_filter(array_merge($tabs), static fn ($k) => $on($sec[$k][0])) && $user && $project;
?><!DOCTYPE html>
<html lang="ca">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<?php if ($pushKey !== ''): ?><meta name="push-key" content="<?= e($pushKey) ?>"><?php endif; ?>
<meta name="robots" content="noindex">
<title><?= e(($title ?? 'Mailing') . ' · Mailing Soportic') ?></title>
<?= favicon_tag(true) ?>
<link rel="stylesheet" href="/assets/app.css?v=<?= e(app_version()) ?>">
<style>:root { --accent: <?= e($accent) ?>; --accent-dark: color-mix(in srgb, <?= e($accent) ?> 82%, #000); --accent-soft: color-mix(in srgb, <?= e($accent) ?> 12%, #fff); }</style>
<?= \Mailing\Core\View::get('head') ?>
</head>
<body class="<?= $user && $project ? 'has-nav' : '' ?>">
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
    <symbol id="i-home" viewBox="0 0 24 24"><path d="M5 12l-2 0l9 -9l9 9l-2 0"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7"/><path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6"/></symbol>
    <symbol id="i-send" viewBox="0 0 24 24"><path d="M10 14l11 -11"/><path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5"/></symbol>
    <symbol id="i-bolt" viewBox="0 0 24 24"><path d="M13 3l0 7l6 0l-8 11l0 -7l-6 0l8 -11"/></symbol>
    <symbol id="i-users" viewBox="0 0 24 24"><path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M21 21v-2a4 4 0 0 0 -3 -3.85"/></symbol>
    <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 6l16 0"/><path d="M4 12l16 0"/><path d="M4 18l16 0"/></symbol>
    <symbol id="i-bell" viewBox="0 0 24 24"><path d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6"/><path d="M9 17v1a3 3 0 0 0 6 0v-1"/></symbol>
    <symbol id="i-cal" viewBox="0 0 24 24"><path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z"/><path d="M16 3v4"/><path d="M8 3v4"/><path d="M4 11h16"/></symbol>
    <symbol id="i-tpl" viewBox="0 0 24 24"><path d="M4 4m0 1a1 1 0 0 1 1 -1h14a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-14a1 1 0 0 1 -1 -1z"/><path d="M4 12m0 1a1 1 0 0 1 1 -1h6a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-6a1 1 0 0 1 -1 -1z"/><path d="M14 12l6 0"/><path d="M14 16l6 0"/><path d="M14 20l6 0"/></symbol>
    <symbol id="i-at" viewBox="0 0 24 24"><path d="M12 12m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/><path d="M16 12v1.5a2.5 2.5 0 0 0 5 0v-1.5a9 9 0 1 0 -5.5 8.28"/></symbol>
    <symbol id="i-list" viewBox="0 0 24 24"><path d="M9 6l11 0"/><path d="M9 12l11 0"/><path d="M9 18l11 0"/><path d="M5 6l0 .01"/><path d="M5 12l0 .01"/><path d="M5 18l0 .01"/></symbol>
    <symbol id="i-filter" viewBox="0 0 24 24"><path d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.345v-2.227z"/></symbol>
    <symbol id="i-form" viewBox="0 0 24 24"><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2"/><path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z"/><path d="M9 12l.01 0"/><path d="M13 12l2 0"/><path d="M9 16l.01 0"/><path d="M13 16l2 0"/></symbol>
    <symbol id="i-sync" viewBox="0 0 24 24"><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/></symbol>
    <symbol id="i-gear" viewBox="0 0 24 24"><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"/><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"/></symbol>
    <symbol id="i-mail" viewBox="0 0 24 24"><path d="M13 19h-8a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v6"/><path d="M3 7l9 6l9 -6"/><path d="M16 22l5 -5"/><path d="M21 21.5v-4.5h-4.5"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"/><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/></symbol>
    <symbol id="i-out" viewBox="0 0 24 24"><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2"/><path d="M9 12h12l-3 -3"/><path d="M18 15l3 -3"/></symbol>
    <symbol id="i-share" viewBox="0 0 24 24"><path d="M8 9h-1a2 2 0 0 0 -2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-8a2 2 0 0 0 -2 -2h-1"/><path d="M12 14v-11"/><path d="M9 6l3 -3l3 3"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M4 4m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z"/><path d="M9 12l6 0"/><path d="M12 9l0 6"/></symbol>
</defs></svg>
<div class="app-top">
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="/"><span class="brand-mark" aria-hidden="true"><?= brand_icon() ?></span><span class="brand-name">Mailing<small>Soportic</small></span></a>
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
                <a class="bell<?= $on('/avisos') ? ' on' : '' ?>" href="/avisos" aria-label="Avisos<?= $unread ? ' (' . $unread . ' sense llegir)' : '' ?>" title="Avisos"><?= $ico('bell', 19) ?><?php if ($unread): ?><em><?= $unread > 99 ? '99+' : $unread ?></em><?php endif; ?></a>
                <span class="user-chip"><?= e($user['name']) ?></span>
                <details class="ml-menu">
                    <summary class="btn secondary sm" aria-label="Menú de l'usuari">☰</summary>
                    <div class="ml-menu-pop">
                        <a href="/perfil">El meu perfil<small>Nom, contrasenya i avisos</small></a>
                        <?php if (Auth::isAdmin()): ?>
                            <a href="/projectes">Projectes<small>Clients i marques que envien</small></a>
                            <a href="/usuaris">Usuaris<small>Accés i permisos</small></a>
                            <a href="/configuracio">Configuració<small>Mailjet, IA, avisos i enviament</small></a>
                            <a href="/actualitzacio">Actualització<small>Versió <?= e(app_version()) ?> · OTA i còpies</small></a>
                            <a href="/activitat">Activitat<small>Registre d'accions</small></a>
                        <?php endif; ?>
                        <form method="post" action="/logout" data-logout><?= Csrf::field() ?><button type="submit">Tanca la sessió</button></form>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </header>
    <?php if ($user && $project): ?>
    <nav class="tabs" aria-label="Seccions">
        <?php foreach (['home', 'campaigns', 'auto', 'calendar', 'contacts', 'lists', 'segments', 'forms', 'templates', 'senders', 'sync'] as $k): ?>
            <a href="<?= $sec[$k][0] ?>"<?= $active($sec[$k][0]) ?>><?= e($sec[$k][1]) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
</div>

<?php if ($user && $project): ?>
<nav class="rail" aria-label="Seccions">
    <a class="rail-brand" href="/" aria-label="Inici"><span class="brand-mark"><?= brand_icon(19) ?></span></a>
    <?php foreach ($rail as $k): if ($k === '|') { echo '<hr>'; continue; } ?>
        <a href="<?= $sec[$k][0] ?>"<?= $active($sec[$k][0]) ?>><?= $ico($sec[$k][2]) ?><span><?= e($short[$k] ?? $sec[$k][1]) ?></span></a>
    <?php endforeach; ?>
    <button type="button" data-sheet-open<?= $moreOn ? ' class="active"' : '' ?>><?= $ico('menu') ?><span>Més</span></button>
</nav>
<nav class="tabbar" aria-label="Seccions">
    <?php foreach ($tabs as $k): ?>
        <a href="<?= $sec[$k][0] ?>"<?= $active($sec[$k][0]) ?>><?= $ico($sec[$k][2], 22) ?><span><?= e($short[$k] ?? $sec[$k][1]) ?></span></a>
    <?php endforeach; ?>
    <button type="button" data-sheet-open<?= $moreOn ? ' class="active"' : '' ?>><?= $ico('menu', 22) ?><span>Més</span></button>
</nav>
<dialog class="sheet" id="nav-sheet" aria-label="Totes les seccions">
    <div class="sheet-in">
        <span class="sheet-grab" aria-hidden="true"></span>
        <?php foreach ($groups as $g => $keys): ?>
            <p class="sheet-g"><?= e($g) ?></p>
            <div class="sheet-items">
                <?php foreach ($keys as $k): ?><a href="<?= $sec[$k][0] ?>"<?= $active($sec[$k][0]) ?>><?= $ico($sec[$k][2]) ?><span><?= e($sec[$k][1]) ?></span></a><?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if (Auth::isAdmin()): ?>
            <p class="sheet-g">Administració</p>
            <div class="sheet-items">
                <?php foreach ($admin as [$u, $l, $i]): ?><a href="<?= $u ?>"<?= $active($u) ?>><?= $ico($i) ?><span><?= e($l) ?></span></a><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <a class="sheet-line" href="/perfil"><?= $ico('user', 18) ?>El meu perfil i avisos</a>
        <form method="post" action="/logout" data-logout><?= Csrf::field() ?><button type="submit" class="sheet-line out"><?= $ico('out', 18) ?>Tanca la sessió</button></form>
        <button type="button" class="btn secondary sheet-close" data-sheet-close>Tanca</button>
    </div>
</dialog>
<?php endif; ?>

<main<?= !empty($wide) ? ' class="wide"' : '' ?>>
<?php if ($user): ?><div id="install-card" class="install-card" hidden></div><?php endif; ?>
<?php if ($msg): ?><div id="flash-msg" data-type="<?= e($msg['type']) ?>" data-text="<?= e($msg['text']) ?>" hidden></div>
<?php if ($msg['type'] === 'err'): ?><div class="alert err"><?= e($msg['text']) ?></div><?php endif; ?><?php endif; ?>
<?= $content ?>
</main>
<script src="/assets/app.js?v=<?= e(app_version()) ?>"></script>
<?= \Mailing\Core\View::get('foot') ?>
</body>
</html>
