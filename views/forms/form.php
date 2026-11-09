<?php
use Mailing\Core\Auth;
use Mailing\Editor\Document;
use Mailing\Services\Forms;
$u = $f ? url('f/' . $f['slug']) : '';
$base = ['first_name' => 'Nom', 'last_name' => 'Cognoms', 'language' => 'Idioma'];
?>
<a class="crumb" href="/formularis">← Formularis d'alta</a>
<div class="page-head">
    <div><h1 class="page-title"><?= $f ? e($f['title']) : 'Formulari nou' ?></h1><?php if ($f): ?><p class="page-sub"><a href="<?= e($u) ?>" target="_blank" rel="noopener"><?= e($u) ?> ↗</a></p><?php endif; ?></div>
    <?php if ($f && Auth::canEdit()): ?><form method="post" action="/formularis/<?= (int) $f['id'] ?>/elimina" data-confirm="Eliminar aquest formulari? L'adreça deixarà de funcionar (els contactes es conserven)."><?= csrf_field() ?><button class="btn danger">Elimina</button></form><?php endif; ?>
</div>
<div class="grid side">
<form method="post" action="<?= $f ? '/formularis/' . (int) $f['id'] : '/formularis' ?>" class="card"><div class="card-body">
    <?= csrf_field() ?>
    <fieldset <?= Auth::canEdit() ? '' : 'disabled' ?> style="border:0;margin:0;padding:0">
    <div class="row">
        <label class="field"><span>Títol de la pàgina</span><input class="input" name="title" required maxlength="190" value="<?= e($f['title'] ?? old('title')) ?>" placeholder="Subscriu-te al butlletí"></label>
        <label class="field"><span>Adreça</span><input class="input" name="slug" maxlength="80" value="<?= e($f['slug'] ?? '') ?>" placeholder="es fa sola a partir del títol"><small><?= e(url('f/')) ?>…</small></label>
    </div>
    <label class="field"><span>Text de presentació (opcional)</span><textarea class="input" name="intro" rows="4" maxlength="5000" placeholder="Explica què rebran i cada quan."><?= e($f['intro'] ?? '') ?></textarea><small>Text pla; una línia en blanc separa els paràgrafs.</small></label>
    <div class="row">
        <label class="field"><span>Idioma de la pàgina</span><select class="input" name="language"><?php foreach (Document::LANGS as $k => $v): ?><option value="<?= e($k) ?>" <?= ($f['language'] ?? $project['default_language']) === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select><small>També és l'idioma que es desa als contactes que no el triïn.</small></label>
        <label class="field"><span>Text del botó</span><input class="input" name="button_text" maxlength="80" value="<?= e($f['button_text'] ?? '') ?>" placeholder="Subscriu-me"></label>
    </div>

    <span class="field-label">Llistes on s'apunten</span>
    <div class="chips" style="margin-bottom:10px"><?php foreach ($lists as $l): ?><label class="chip"><input type="checkbox" name="lists[]" value="<?= (int) $l['id'] ?>" <?= in_array((int) $l['id'], $chosen, true) ? 'checked' : '' ?>> <?= e($l['name']) ?></label><?php endforeach; ?></div>
    <label class="field"><input class="input" name="new_list" maxlength="120" placeholder="…o crea una llista nova amb aquest nom"></label>
    <label class="check"><input type="checkbox" name="choose_lists" value="1" <?= !empty($f['choose_lists']) ? 'checked' : '' ?>> Si n'hi ha més d'una, que cada persona triï a quines s'apunta</label>

    <span class="field-label" style="margin-top:14px">Camps del formulari</span>
    <p class="small muted" style="margin:0 0 8px">El correu electrònic sempre hi és i és obligatori.</p>
    <table class="t" style="margin-bottom:14px"><thead><tr><th>Camp</th><th>Al formulari</th><th>Obligatori</th></tr></thead><tbody>
    <?php foreach ($base + array_combine(array_map(static fn ($c) => 'camp:' . $c['key'], $custom), array_column($custom, 'label')) as $k => $label): ?>
        <tr><td><?= e($label) ?></td><td><input type="checkbox" name="fields[]" value="<?= e($k) ?>" <?= array_key_exists($k, $fields) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--accent)"></td>
        <td><input type="checkbox" name="required[]" value="<?= e($k) ?>" <?= !empty($fields[$k]) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--accent)"></td></tr>
    <?php endforeach; ?>
    </tbody></table>

    <label class="field"><span>Text legal addicional (opcional)</span><textarea class="input" name="consent_text" rows="2" maxlength="1000" placeholder="Responsable: … Finalitat: … Drets: …"><?= e($f['consent_text'] ?? '') ?></textarea><small>S'afegeix a la casella de consentiment, que sempre és obligatòria.</small></label>
    <div class="row">
        <label class="field"><span>Enllaç a la política de privacitat</span><input class="input" name="privacy_url" value="<?= e($f['privacy_url'] ?? '') ?>" placeholder="https://…"></label>
        <label class="field"><span>Després d'apuntar-se, anar a (opcional)</span><input class="input" name="redirect_url" value="<?= e($f['redirect_url'] ?? '') ?>" placeholder="https://… (si no, es mostra el missatge)"></label>
    </div>
    <label class="field"><span>Missatge d'agraïment</span><input class="input" name="success_text" maxlength="1000" value="<?= e($f['success_text'] ?? '') ?>" placeholder="Gràcies! T'has subscrit correctament."></label>
    <label class="check"><input type="checkbox" name="double_optin" value="1" <?= ($f['double_optin'] ?? 1) ? 'checked' : '' ?>> <span><b>Doble confirmació</b>: s'envia un correu i la persona no queda apuntada fins que hi clica (recomanat: evita adreces falses i és la millor prova de consentiment).<?php if (!$hasSender): ?> <span style="color:#b42318">Cal un remitent per defecte al projecte.</span><?php endif; ?></span></label>
    <label class="check"><input type="checkbox" name="active" value="1" <?= ($f['active'] ?? 1) ? 'checked' : '' ?>> Formulari obert (accepta inscripcions)</label>
    <?php if (Auth::canEdit()): ?><button class="btn" type="submit"><?= $f ? 'Desa' : 'Crea el formulari' ?></button><?php endif; ?>
    </fieldset>
</div></form>

<div>
<?php if ($f): ?>
    <div class="card" style="margin-bottom:16px"><div class="card-head">Compartir-lo</div><div class="card-body">
        <label class="field"><span>Enllaç</span><input class="input mono" id="flink" readonly value="<?= e($u) ?>"></label>
        <div class="btns" style="margin-bottom:14px"><button class="btn sm secondary" type="button" data-copy="flink">Copia</button><a class="btn sm secondary" href="<?= e($u) ?>" target="_blank" rel="noopener">Obre-la</a></div>
        <label class="field"><span>Codi per incrustar-lo en una web</span><textarea class="input code" id="fembed" rows="3" readonly><?= e('<iframe src="' . $u . '?embed=1" style="width:100%;max-width:560px;height:640px;border:0" title="' . $f['title'] . '"></iframe>') ?></textarea></label>
        <button class="btn sm secondary" type="button" data-copy="fembed">Copia el codi</button>
    </div></div>
    <div class="card"><div class="card-head">Últimes inscripcions <span class="muted small" style="font-weight:400"><?= fmt_n($f['views']) ?> visites</span></div>
        <?php if ($recent): ?><ul class="list"><?php foreach ($recent as $s): ?>
            <li><div class="main"><?= $s['cid'] ? '<a href="/contactes/' . (int) $s['cid'] . '">' . e($s['email']) . '</a>' : '<b class="small">' . e($s['email']) . '</b>' ?>
                <div class="sub"><?= e(fmt_short($s['created_at'])) ?> · <?= $s['confirmed_at'] ? 'apuntada' : 'pendent de confirmar' ?></div></div></li>
        <?php endforeach; ?></ul><?php else: ?><div class="empty">Encara no s'hi ha apuntat ningú.</div><?php endif; ?>
    </div>
<?php else: ?>
    <div class="card"><div class="card-body small" style="line-height:1.6">Quan el desis tindràs una adreça pública (<code><?= e(url('f/…')) ?></code>) per compartir i un codi per incrustar-lo en qualsevol web. El logotip i el color són els del projecte.</div></div>
<?php endif; ?>
</div>
</div>
