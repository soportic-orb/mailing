<?php
use Mailing\Core\Auth;
use Mailing\Editor\Document;
use Mailing\Services\Contacts;
$vals = $c ? (json_decode((string) $c['fields'], true) ?: []) : [];
$evLabels = ['sent' => 'Lliurat', 'open' => 'Obert', 'click' => 'Clic', 'bounce' => 'Rebot', 'spam' => 'Marcat com a brossa', 'blocked' => 'Bloquejat', 'unsub' => 'Baixa', 'status' => 'Canvi d\'estat'];
?>
<a class="crumb" href="/contactes">← Contactes</a>
<div class="page-head">
    <div><h1 class="page-title"><?= $c ? e($c['email']) : 'Contacte nou' ?> <?php if ($c): ?><span class="st <?= e($c['status']) ?>" style="vertical-align:4px"><?= e(Contacts::STATUSES[$c['status']]) ?></span><?php endif; ?></h1>
    <?php if ($c): ?><p class="page-sub">Alta <?= e(fmt_dt($c['created_at'])) ?> · origen: <?= e($c['source'] ?: '—') ?><?= $c['external_id'] ? ' · ID extern ' . e($c['external_id']) : '' ?></p><?php endif; ?></div>
    <?php if ($c): ?><div class="btns">
        <a class="btn secondary" href="/contactes/<?= (int) $c['id'] ?>/dades">Exporta les dades (RGPD)</a>
        <?php if (Auth::canEdit()): ?><form method="post" action="/contactes/<?= (int) $c['id'] ?>/elimina" data-confirm="Eliminar definitivament aquest contacte i el seu historial (dret de supressió)?"><?= csrf_field() ?><button class="btn danger" type="submit">Elimina</button></form><?php endif; ?>
    </div><?php endif; ?>
</div>
<div class="grid side">
<form method="post" action="<?= $c ? '/contactes/' . (int) $c['id'] : '/contactes' ?>" class="card"><div class="card-body">
    <?= csrf_field() ?>
    <fieldset <?= Auth::canEdit() ? '' : 'disabled' ?> style="border:0;margin:0;padding:0">
    <label class="field"><span>Correu electrònic</span><input class="input" type="email" name="email" required value="<?= e(old('email', $c['email'] ?? '')) ?>"></label>
    <div class="row">
        <label class="field"><span>Nom</span><input class="input" name="first_name" value="<?= e(old('first_name', $c['first_name'] ?? '')) ?>"></label>
        <label class="field"><span>Cognoms</span><input class="input" name="last_name" value="<?= e(old('last_name', $c['last_name'] ?? '')) ?>"></label>
    </div>
    <div class="row">
        <label class="field"><span>Idioma</span><select class="input" name="language"><option value="">— Sense idioma (rep el principal) —</option><?php foreach (Document::LANGS as $k => $v): ?><option value="<?= e($k) ?>" <?= ($c['language'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Estat</span><select class="input" name="status"><?php foreach (Contacts::STATUSES as $k => $v): ?><option value="<?= e($k) ?>" <?= ($c['status'] ?? 'subscribed') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select><small>Només els «Subscrits» reben campanyes.</small></label>
    </div>
    <?php if ($fields): ?>
        <div class="row">
        <?php foreach ($fields as $fd): ?>
            <label class="field"><span><?= e($fd['label']) ?> <code>{{camp:<?= e($fd['key']) ?>}}</code></span><input class="input" name="f_<?= e($fd['key']) ?>" type="<?= $fd['type'] === 'date' ? 'date' : ($fd['type'] === 'number' ? 'number' : 'text') ?>" value="<?= e($vals[$fd['key']] ?? '') ?>"></label>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($lists): ?>
        <span class="field-label">Llistes</span>
        <div class="chips" style="margin-bottom:16px"><?php foreach ($lists as $l): ?><label class="chip"><input type="checkbox" name="lists[]" value="<?= (int) $l['id'] ?>" <?= in_array((int) $l['id'], $inLists, true) ? 'checked' : '' ?>> <?= e($l['name']) ?></label><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (!$c): ?><label class="check"><input type="checkbox" name="consent" value="1" required> Aquesta persona ha donat el consentiment per rebre correus d'aquest projecte.</label><?php endif; ?>
    <?php if (Auth::canEdit()): ?><button class="btn" type="submit"><?= $c ? 'Desa' : 'Crea el contacte' ?></button><?php endif; ?>
    </fieldset>
</div></form>

<?php if ($c): ?>
<div>
    <?php if (!empty($runs)): ?>
    <div class="card" style="margin-bottom:16px">
        <div class="card-head">Automatitzacions</div>
        <ul class="list"><?php foreach ($runs as $r): $fl = \Mailing\Services\Automations::cleanFlow($r['flow']); $st = array_column($fl['steps'], null, 'id')[$r['step_id']] ?? null; ?>
            <li><div class="main"><a href="/automatitzacions/<?= (int) $r['automation_id'] ?>"><?= e($r['automation']) ?></a>
                <div class="sub"><?= $r['status'] === 'active' ? 'Dins: ' . e($st ? \Mailing\Services\Automations::describeStep($st, $fl, (int) $c['project_id']) : '—') . ($r['waiting'] && $r['next_at'] ? ' (fins al ' . e(fmt_short($r['next_at'])) . ')' : '') : ($r['status'] === 'done' ? 'Ha acabat' : 'Ha sortit') . ($r['exit_reason'] ? ' · ' . e($r['exit_reason']) : '') ?> · entrada <?= e(fmt_short($r['entered_at'])) ?></div></div>
                <?php if ($r['status'] === 'active' && Auth::canEdit()): ?><form method="post" action="/automatitzacions/<?= (int) $r['automation_id'] ?>/inscripcions/<?= (int) $r['id'] ?>/treu" data-confirm="Treure el contacte d'aquesta automatització?"><?= csrf_field() ?><button class="btn sm ghost" type="submit">Treu</button></form><?php endif; ?></li>
        <?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <div class="card" style="margin-bottom:16px">
        <div class="card-head">Correus rebuts</div>
        <?php if ($messages): ?><ul class="list"><?php foreach ($messages as $m): ?>
            <li><div class="main"><a href="/campanyes/<?= (int) $m['campaign_id'] ?>/informe"><?= e($m['campaign']) ?></a>
                <div class="sub"><?= e($m['sent_at'] ? fmt_short($m['sent_at']) : ($m['status'] === 'queued' ? 'Pendent' : $m['status'])) ?>
                <?= $m['opened_at'] ? ' · obert' : '' ?><?= $m['clicked_at'] ? ' · clic' : '' ?><?= $m['bounced_at'] ? ' · rebot' : '' ?><?= $m['unsub_at'] ? ' · baixa' : '' ?></div></div></li>
        <?php endforeach; ?></ul><?php else: ?><div class="empty">Encara no n'ha rebut cap.</div><?php endif; ?>
    </div>
    <div class="card">
        <div class="card-head">Activitat</div>
        <?php if ($events): ?><ul class="list"><?php foreach ($events as $ev): ?>
            <li><div class="main"><b class="small"><?= e($evLabels[$ev['type']] ?? $ev['type']) ?></b> <span class="small muted"><?= e($ev['campaign'] ?? '') ?></span>
                <div class="sub"><?= e(fmt_short($ev['created_at'])) ?><?= $ev['url'] ? ' · ' . e(mb_strimwidth($ev['url'], 0, 60, '…')) : '' ?><?= $ev['detail'] ? ' · ' . e($ev['detail']) : '' ?></div></div></li>
        <?php endforeach; ?></ul><?php else: ?><div class="empty">Sense activitat.</div><?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>
