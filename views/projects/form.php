<?php use Mailing\Editor\Brand; use Mailing\Editor\Document; $logo = Brand::logoUrl($p); ?>
<a class="crumb" href="/projectes">← Projectes</a>
<div class="page-head"><div><h1 class="page-title"><?= $p ? e($p['name']) : 'Projecte nou' ?></h1><p class="page-sub">Les dades de marca s'utilitzen als blocs nous de l'editor (logotip, colors, peu) i al peu legal de tots els correus.</p></div></div>
<form method="post" action="<?= $p ? '/projectes/' . (int) $p['id'] : '/projectes' ?>" enctype="multipart/form-data" class="grid two">
    <?= csrf_field() ?>
    <div class="card"><div class="card-head">Identitat</div><div class="card-body">
        <div class="row">
            <label class="field"><span>Nom</span><input class="input" name="name" required maxlength="120" value="<?= e($p['name'] ?? old('name')) ?>" placeholder="Emergencing"></label>
            <label class="field"><span>Identificador</span><input class="input" name="slug" maxlength="60" value="<?= e($p['slug'] ?? '') ?>" placeholder="es fa sol"></label>
        </div>
        <div class="row">
            <label class="field"><span>Web</span><input class="input" name="website" value="<?= e($p['website'] ?? '') ?>" placeholder="https://emergencing.com"></label>
            <label class="field"><span>Color de marca</span><input class="input" type="color" name="color" value="<?= e($p['color'] ?? '#ac1966') ?>" style="height:40px;padding:4px"></label>
        </div>
        <label class="field"><span>Idioma principal dels correus</span><select class="input" name="default_language"><?php foreach (Document::LANGS as $k => $v): ?><option value="<?= e($k) ?>" <?= ($p['default_language'] ?? 'ca') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
        <span class="field-label">Logotip</span>
        <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" style="max-height:60px;max-width:240px;display:block;margin:0 0 8px;background:#f6f7f9;border-radius:8px;padding:6px"><label class="check"><input type="checkbox" name="logo_remove" value="1"> Treu el logotip</label><?php endif; ?>
        <label class="field"><input class="input" type="file" name="logo" accept="image/png,image/jpeg,image/gif"><small>PNG o JPG (amb fons transparent o blanc). O l'adreça d'una imatge:</small></label>
        <label class="field"><input class="input" name="logo_url" placeholder="https://…/logo.png" value="<?= e(str_starts_with((string) ($p['logo_src'] ?? ''), 'https://') ? $p['logo_src'] : '') ?>"></label>
    </div></div>
    <div>
        <div class="card"><div class="card-head">Peu legal</div><div class="card-body">
            <label class="field"><span>Raó social</span><input class="input" name="company" maxlength="190" value="<?= e($p['company'] ?? '') ?>" placeholder="Soportic SL"></label>
            <span class="field-label">Text «per què reps aquest correu»</span>
            <p class="small muted" style="margin:0 0 8px">Primera línia del peu legal de tots els correus, en cada idioma. En blanc = el text per defecte. <code>{projecte}</code> es substitueix pel nom del projecte.</p>
            <?php $why = $p ? Document::customWhy((int) $p['id']) : []; foreach (Document::LANGS as $l => $lname): ?>
                <label class="field" style="margin-bottom:8px"><span class="small muted" style="font-weight:600"><?= e($lname) ?></span><input class="input" name="footer_why[<?= e($l) ?>]" maxlength="500" value="<?= e($why[$l] ?? '') ?>" placeholder="<?= e(sprintf(Document::TAIL[$l]['why'], $p['name'] ?? '{projecte}')) ?>"></label>
            <?php endforeach; ?>
            <label class="field" style="margin-top:12px"><span>Adreça postal</span><input class="input" name="postal_address" maxlength="500" value="<?= e($p['postal_address'] ?? '') ?>" placeholder="Carrer…, 08000 Barcelona"><small>Surt al peu de cada correu amb l'enllaç de baixa (ho demanen la normativa i els filtres antispam).</small></label>
        </div></div>
        <div class="card"><div class="card-head">Compte de Mailjet propi (opcional)</div><div class="card-body">
            <p class="small muted" style="margin-top:0">Si el projecte envia amb un altre compte de Mailjet. En blanc = el de Configuració.</p>
            <div class="row">
                <label class="field"><span>API Key</span><input class="input" name="mailjet_key" autocomplete="off" placeholder="<?= !empty($p['mailjet_key']) ? '•••••• desada («-» per treure-la)' : '' ?>"></label>
                <label class="field"><span>Secret Key</span><input class="input" type="password" name="mailjet_secret" autocomplete="new-password" placeholder="<?= !empty($p['mailjet_secret']) ? '•••••• desada' : '' ?>"></label>
            </div>
        </div></div>
        <button class="btn" type="submit" style="margin-top:16px"><?= $p ? 'Desa' : 'Crea el projecte' ?></button>
    </div>
</form>
