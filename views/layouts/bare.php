<?php
$msg = \Mailing\Core\Session::pull('msg');
$accent = $accent ?? '#ac1966';
?><!DOCTYPE html>
<html lang="<?= e($lang ?? 'ca') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title ?? 'Mailing') ?></title>
<?= favicon_tag() ?>
<link rel="stylesheet" href="/assets/app.css?v=<?= e(app_version()) ?>">
<style>:root { --accent: <?= e($accent) ?>; --accent-dark: color-mix(in srgb, <?= e($accent) ?> 82%, #000); --accent-soft: color-mix(in srgb, <?= e($accent) ?> 12%, #fff); }</style>
</head>
<body>
<div class="bare">
    <div class="bare-card">
        <?php if ($msg): ?><div class="alert <?= e($msg['type'] === 'err' ? 'err' : 'ok') ?>"><?= e($msg['text']) ?></div><?php endif; ?>
        <?= $content ?>
    </div>
</div>
</body>
</html>
