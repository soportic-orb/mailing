<?php
use Mailing\Services\Contacts;
?>
<a class="crumb" href="/contactes">← Contactes</a>
<div class="page-head"><div><h1 class="page-title">Importa contactes</h1><p class="page-sub">Des d'un fitxer CSV o Excel (.xlsx). Els que ja hi són s'actualitzen; mai es torna a subscriure qui s'havia donat de baixa o havia rebotat.</p></div></div>
<?php if ($step === 'upload'): ?>
<div class="grid side">
<form method="post" action="/contactes/importa" enctype="multipart/form-data" class="card"><div class="card-body">
    <?= csrf_field() ?>
    <label class="field"><span>Fitxer</span><input class="input" type="file" name="file" accept=".csv,.txt,.xlsx" required><small>La primera fila pot ser la capçalera (Email, Nom, Cognoms…). Separador , ; o tabulació; UTF-8 o Windows. Una columna ha de tenir el correu.</small></label>
    <label class="field"><span>Afegeix-los a la llista</span><select class="input" name="list_id"><option value="">— Cap (només contactes) —</option><?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select><small>Al pas següent també en pots crear una de nova.</small></label>
    <button class="btn" type="submit" data-busy="Llegint…">Continua</button>
</div></form>
<div class="card">
    <div class="card-head">Últimes importacions</div>
    <?php if ($history): ?><ul class="list"><?php foreach ($history as $h): ?>
        <li><div class="main"><b class="small"><?= e($h['filename'] ?: 'Importació') ?></b><div class="sub"><?= e(fmt_short($h['created_at'])) ?><?= $h['list_name'] ? ' · ' . e($h['list_name']) : '' ?> · <?= (int) $h['created'] ?> nous, <?= (int) $h['updated'] ?> act., <?= (int) $h['skipped'] ?> desc.<?= $h['removed'] ? ', ' . (int) $h['removed'] . ' trets' : '' ?></div></div></li>
    <?php endforeach; ?></ul><?php else: ?><div class="empty">Cap importació encara.</div><?php endif; ?>
</div>
</div>
<?php else: ?>
<form method="post" action="/contactes/importa/executa" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="filename" value="<?= e($filename) ?>">
    <div class="card-head"><?= e($filename) ?> · <?= fmt_n($preview['total']) ?> files</div>
    <div class="card-body">
        <p class="small muted" style="margin:0 0 12px">Indica què és cada columna. Les que deixis a «No importis» s'ignoren. Una columna nova es desa com a camp personalitzat.</p>
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Columna</th><th>És…</th><th>Exemples</th></tr></thead>
            <tbody>
            <?php foreach ($preview['header'] as $i => $h): $g = $preview['guess'][$i] ?? ''; ?>
                <tr>
                    <td><b><?= e($h) ?></b></td>
                    <td style="min-width:240px">
                        <select class="input" name="map[<?= (int) $i ?>]" onchange="this.nextElementSibling.hidden = this.value !== 'new'">
                            <option value="">No importis</option>
                            <?php foreach (Contacts::BASE_FIELDS as $k => $label): ?><option value="<?= e($k) ?>" <?= $g === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                            <?php foreach ($fields as $fd): ?><option value="camp:<?= e($fd['key']) ?>" <?= Contacts::fieldKey($h) === $fd['key'] && $g === '' ? 'selected' : '' ?>>Camp: <?= e($fd['label']) ?></option><?php endforeach; ?>
                            <option value="new">+ Camp nou…</option>
                        </select>
                        <input class="input" name="newname[<?= (int) $i ?>]" value="<?= e(str_starts_with($h, 'Columna ') ? '' : $h) ?>" placeholder="Nom del camp nou" hidden style="margin-top:6px">
                    </td>
                    <td class="small muted"><?= e(implode(' · ', array_filter(array_map(static fn ($r) => mb_strimwidth((string) ($r[$i] ?? ''), 0, 40, '…'), $preview['rows'])))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="row" style="margin-top:16px">
            <label class="field"><span>Llista</span><select class="input" name="list_id"><option value="">— Cap —</option><?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) $listId === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>…o crea'n una de nova</span><input class="input" name="new_list" maxlength="120" placeholder="Nom de la llista nova"></label>
        </div>
        <label class="check"><input type="checkbox" name="overwrite" value="1" checked> Actualitza les dades dels contactes que ja existeixen (si no, només s'omplen els camps buits)</label>
        <label class="check"><input type="checkbox" name="as_unsub" value="1"> Són persones que <b>no</b> volen rebre correus (s'importen com a «De baixa», per excloure-les)</label>
        <label class="check"><input type="checkbox" name="consent" value="1" required> Confirmo que aquests contactes han donat el consentiment per rebre comunicacions d'aquest projecte (RGPD).</label>
        <div class="btns"><button class="btn" type="submit" data-busy="Important…">Importa <?= fmt_n($preview['total']) ?> contactes</button><a class="btn secondary" href="/contactes/importa">Cancel·la</a></div>
    </div>
</form>
<?php endif; ?>
