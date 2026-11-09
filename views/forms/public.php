<?php
use Mailing\Services\Forms;
$old = $state['old'] ?? [];
$accent = preg_match('/^#[0-9a-f]{6}$/i', (string) $p['color']) ? $p['color'] : '#ac1966';
$v = static fn (string $k): string => e(is_scalar($old[$k] ?? null) ? (string) $old[$k] : '');
?><!DOCTYPE html>
<html lang="<?= e($f['language']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($f['title'] . ' · ' . $p['name']) ?></title>
<?= favicon_tag() ?>
<link rel="stylesheet" href="/assets/app.css?v=<?= e(app_version()) ?>">
<style>
:root { --accent: <?= e($accent) ?>; --accent-dark: color-mix(in srgb, <?= e($accent) ?> 82%, #000); --accent-soft: color-mix(in srgb, <?= e($accent) ?> 12%, #fff); }
.pf { min-height: 100vh; display: grid; place-items: center; padding: 28px 16px; }
.pf-card { width: min(520px, 100%); background: #fff; border-radius: 20px; box-shadow: 0 10px 40px rgba(31, 36, 48, .1); padding: 30px 28px; }
.pf-card h1 { font-size: 22px; margin: 0 0 10px; letter-spacing: -.01em; }
.pf-intro p { margin: 0 0 10px; line-height: 1.55; color: #3d4352; }
.pf-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
.pf-foot { margin-top: 18px; font-size: 12px; color: var(--muted); text-align: center; }
body.embed { background: transparent; }
body.embed .pf { min-height: 0; padding: 0; display: block; }
body.embed .pf-card { box-shadow: none; padding: 4px 2px; width: 100%; }
</style>
</head>
<body class="<?= $embed ? 'embed' : '' ?>">
<div class="pf"><div class="pf-card">
    <?php if (!$embed): ?>
        <?php if ($logo): ?><img class="bare-logo" src="<?= e($logo) ?>" alt="<?= e($p['name']) ?>"><?php else: ?><p style="font-weight:800;font-size:17px;margin:0 0 14px"><?= e($p['name']) ?></p><?php endif; ?>
    <?php endif; ?>
    <h1><?= e($f['title']) ?></h1>
    <?php if (!empty($state['done'])): ?>
        <div class="alert <?= $state['ok'] ? 'ok' : 'warn' ?>" role="status" style="margin:10px 0 0"><?= e($state['message']) ?></div>
    <?php else: ?>
        <?php if ($f['intro']): ?><div class="pf-intro"><?php foreach (preg_split('/\R{2,}/', trim((string) $f['intro'])) as $par): ?><p><?= nl2br(e($par)) ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php if (!empty($state['error'])): ?><div class="alert err" role="alert"><?= e($state['error']) ?></div><?php endif; ?>
        <form method="post" action="/f/<?= e($f['slug']) ?><?= $embed ? '?embed=1' : '' ?>" novalidate>
            <input type="hidden" name="_t" value="<?= e($stamp) ?>">
            <div class="pf-hp" aria-hidden="true"><label>Web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            <label class="field"><span><?= e(Forms::t($f, 'email')) ?> *</span><input class="input" type="email" name="email" required autocomplete="email" value="<?= $v('email') ?>"></label>
            <?php foreach ($fields as $fd): $name = str_replace(':', '_', $fd['key']); $label = Forms::label($f, $fd['key'], $custom); ?>
                <?php if ($fd['key'] === 'language'): ?>
                    <label class="field"><span><?= e($label) ?><?= $fd['required'] ? ' *' : '' ?></span><select class="input" name="language"><?php foreach (Forms::languages() as $k => $lname): ?><option value="<?= e($k) ?>" <?= ($old['language'] ?? $f['language']) === $k ? 'selected' : '' ?>><?= e($lname) ?></option><?php endforeach; ?></select></label>
                <?php else: ?>
                    <label class="field"><span><?= e($label) ?><?= $fd['required'] ? ' *' : '' ?></span><input class="input" name="<?= e($name) ?>" <?= $fd['required'] ? 'required' : '' ?> autocomplete="<?= $fd['key'] === 'first_name' ? 'given-name' : ($fd['key'] === 'last_name' ? 'family-name' : 'off') ?>" value="<?= $v($name) ?>"></label>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($f['choose_lists'] && count($lists) > 1): $sel = array_map('intval', (array) ($old['lists'] ?? array_column($lists, 'id'))); ?>
                <span class="field-label"><?= e(Forms::t($f, 'lists')) ?></span>
                <?php foreach ($lists as $l): ?><label class="check"><input type="checkbox" name="lists[]" value="<?= (int) $l['id'] ?>" <?= in_array((int) $l['id'], $sel, true) ? 'checked' : '' ?>> <span><?= e($l['name']) ?><?= $l['description'] ? '<br><span class="small muted">' . e($l['description']) . '</span>' : '' ?></span></label><?php endforeach; ?>
            <?php endif; ?>
            <label class="check" style="margin-top:6px"><input type="checkbox" name="consent" value="1" required <?= !empty($old['consent']) ? 'checked' : '' ?>> <span class="small" style="line-height:1.5"><?= e(sprintf(Forms::t($f, 'consent'), $p['name'])) ?><?= $f['consent_text'] ? ' ' . e($f['consent_text']) : '' ?><?php if ($f['privacy_url']): ?> <a href="<?= e($f['privacy_url']) ?>" target="_blank" rel="noopener"><?= e(Forms::t($f, 'privacy')) ?></a><?php endif; ?></span></label>
            <button class="btn" type="submit" style="width:100%;margin-top:8px"><?= e($f['button_text'] ?: Forms::t($f, 'button')) ?></button>
        </form>
    <?php endif; ?>
    <?php if (!$embed && $p['website']): ?><p class="pf-foot"><a href="<?= e($p['website']) ?>" style="color:inherit"><?= e(parse_url((string) $p['website'], PHP_URL_HOST) ?: $p['website']) ?></a></p><?php endif; ?>
</div></div>
</body>
</html>
