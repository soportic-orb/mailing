<?php
use Mailing\Core\Auth;
use Mailing\Editor\Document;
use Mailing\Services\Campaigns;

$editable = Campaigns::editable($c) && Auth::canEdit();
$sender = null;
foreach ($senders as $s) { if ((int) $s['id'] === (int) $c['sender_id']) { $sender = $s; } }
$blocking = Campaigns::blocking($checks);
$when = local_dt($c['scheduled_at']);
$tomorrow = (new DateTimeImmutable('tomorrow', app_tz()))->format('Y-m-d');
?>
<a class="crumb" href="/campanyes">← Campanyes</a>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e($c['name']) ?> <span class="st <?= e($c['status']) ?>" style="vertical-align:4px"><?= e(Campaigns::STATUSES[$c['status']]) ?></span></h1>
        <p class="page-sub"><?= e($c['subject'] ?: 'Sense assumpte') ?></p>
    </div>
    <div class="btns">
        <?php if (in_array($c['status'], ['sending', 'paused', 'sent'], true)): ?><a class="btn secondary" href="/campanyes/<?= (int) $c['id'] ?>/informe">Informe</a><?php endif; ?>
        <?php if ($editable): ?><a class="btn" href="/campanyes/<?= (int) $c['id'] ?>/editor">Edita el contingut</a><?php endif; ?>
        <?php if (Auth::canEdit()): ?>
        <details class="ml-menu">
            <summary class="btn secondary" aria-label="Més opcions">⋯</summary>
            <div class="ml-menu-pop">
                <a href="/campanyes/<?= (int) $c['id'] ?>/previsualitza" target="_blank">Previsualitza<small>S'obre en una pestanya nova</small></a>
                <form method="post" action="/campanyes/<?= (int) $c['id'] ?>/duplica"><?= csrf_field() ?><button type="submit">Duplica<small>Una campanya nova amb el mateix contingut</small></button></form>
                <form method="post" action="/campanyes/<?= (int) $c['id'] ?>/com-a-plantilla"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($c['name']) ?>"><button type="submit">Desa com a plantilla<small>Per reutilitzar-ne el disseny</small></button></form>
                <?php if (in_array($c['status'], ['draft', 'cancelled', 'failed'], true)): ?>
                <form method="post" action="/campanyes/<?= (int) $c['id'] ?>/elimina" data-confirm="Vols eliminar aquesta campanya? No es pot desfer."><?= csrf_field() ?><button type="submit" style="color:#b42318">Elimina</button></form>
                <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>
</div>
<?php if ($c['error']): ?><div class="alert err"><?= e($c['error']) ?></div><?php endif; ?>

<div class="grid side">
<div>
    <?php if ($stats): $done = $stats['sent'] + $stats['failed'] + $stats['skipped']; ?>
    <div class="card" style="margin-bottom:16px">
        <div class="card-head">Progrés de l'enviament <?php if ($c['status'] !== 'sent'): ?><a href="">Actualitza</a><?php endif; ?></div>
        <div class="card-body">
            <div class="bar" style="height:12px;margin-bottom:10px"><i style="width:<?= $stats['total'] ? round($done * 100 / $stats['total']) : 0 ?>%;background:var(--accent)"></i></div>
            <p style="margin:0" class="small"><b><?= fmt_n($stats['sent']) ?></b> enviats de <?= fmt_n($stats['total']) ?><?= $stats['queued'] ? ' · ' . fmt_n($stats['queued']) . ' pendents' : '' ?><?= $stats['failed'] ? ' · <span style="color:#b42318">' . fmt_n($stats['failed']) . ' rebutjats</span>' : '' ?><?= $stats['skipped'] ? ' · ' . fmt_n($stats['skipped']) . ' omesos' : '' ?></p>
            <?php if ($c['status'] === 'sending'): ?><p class="small muted" style="margin:8px 0 0">L'enviament avança en segon pla (cada minut). Pots tancar aquesta pàgina.</p><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <form method="post" action="/campanyes/<?= (int) $c['id'] ?>" class="card" id="cfg">
        <?= csrf_field() ?>
        <div class="card-head">Configuració</div>
        <div class="card-body">
            <fieldset <?= $editable ? '' : 'disabled' ?> style="border:0;margin:0;padding:0">
            <div class="row">
                <label class="field"><span>Nom intern</span><input class="input" name="name" value="<?= e($c['name']) ?>" maxlength="190"></label>
                <label class="field"><span>Adreça d'emissió (remitent)</span>
                    <select class="input" name="sender_id">
                        <option value="">— Tria'n una —</option>
                        <?php foreach ($senders as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === (int) $c['sender_id'] ? 'selected' : '' ?>><?= e(($s['name'] ?: $s['email']) . ' <' . $s['email'] . '>') ?><?= strtolower((string) $s['mailjet_status']) === 'active' ? '' : ' (no validat)' ?></option><?php endforeach; ?>
                    </select>
                    <small><a href="/remitents">Gestiona els remitents</a></small>
                </label>
            </div>
            <label class="field"><span>Respondre a (opcional)</span><input class="input" type="email" name="reply_to" value="<?= e($c['reply_to']) ?>" placeholder="<?= e($sender['reply_to'] ?? 'La del remitent') ?>"><small>Si les respostes han d'anar a una altra adreça.</small></label>

            <span class="field-label">Destinataris</span>
            <label class="check"><input type="checkbox" name="all" value="1" <?= $audience['all'] ? 'checked' : '' ?> data-aud> Tots els contactes subscrits del projecte</label>
            <div id="aud-pick" <?= $audience['all'] ? 'hidden' : '' ?>>
                <?php if ($lists): ?>
                    <p class="small muted" style="margin:4px 0 8px">Llistes</p>
                    <div class="chips" style="margin-bottom:12px">
                        <?php foreach ($lists as $l): ?><label class="chip"><input type="checkbox" name="lists[]" value="<?= (int) $l['id'] ?>" <?= in_array((int) $l['id'], $audience['lists'], true) ? 'checked' : '' ?> data-aud> <?= e($l['name']) ?> <span class="muted">(<?= fmt_n($l['n']) ?>)</span></label><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($segments): ?>
                    <p class="small muted" style="margin:4px 0 8px">Segments</p>
                    <div class="chips" style="margin-bottom:12px">
                        <?php foreach ($segments as $sg): ?><label class="chip"><input type="checkbox" name="segments[]" value="<?= (int) $sg['id'] ?>" <?= in_array((int) $sg['id'], $audience['segments'], true) ? 'checked' : '' ?> data-aud> <?= e($sg['name']) ?></label><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!$lists && !$segments): ?><p class="small muted">Encara no hi ha llistes ni segments. <a href="/llistes">Crea una llista</a> o marca «Tots els contactes».</p><?php endif; ?>
            </div>
            <?php if ($lists): ?>
                <details style="margin:4px 0 12px" <?= $audience['exclude_lists'] ? 'open' : '' ?>><summary class="small" style="cursor:pointer;font-weight:600">Excloure llistes</summary>
                    <div class="chips" style="margin-top:8px"><?php foreach ($lists as $l): ?><label class="chip"><input type="checkbox" name="exclude_lists[]" value="<?= (int) $l['id'] ?>" <?= in_array((int) $l['id'], $audience['exclude_lists'], true) ? 'checked' : '' ?> data-aud> <?= e($l['name']) ?></label><?php endforeach; ?></div>
                </details>
            <?php endif; ?>
            <p class="alert info" style="margin:6px 0 14px"><b id="aud-count"><?= fmt_n($count) ?></b> destinataris. Només els contactes subscrits; cadascú rep un sol correu encara que sigui a diverses llistes.</p>
            <?php if ($editable): ?><button class="btn" type="submit">Desa la configuració</button><?php endif; ?>
            </fieldset>
        </div>
    </form>

    <div class="card">
        <div class="card-head">Contingut <?php if ($editable): ?><a href="/campanyes/<?= (int) $c['id'] ?>/editor">Obre l'editor →</a><?php endif; ?></div>
        <div class="card-body" style="display:grid;grid-template-columns:150px minmax(0,1fr);gap:18px;align-items:start">
            <?php include __DIR__ . '/../partials/thumb.php'; ?>
            <div>
                <?php foreach ($doc['langs'] as $code => $l): ?>
                    <p style="margin:0 0 10px"><?php if (count($doc['langs']) > 1): ?><span class="pill"><?= e(Document::LANGS[$code]) ?></span> <?php endif; ?><b><?= e($l['subject'] ?: 'Sense assumpte') ?></b><br><span class="small muted"><?= e($l['preheader'] ?: 'Sense text de previsualització') ?></span></p>
                <?php endforeach; ?>
                <p class="small muted" style="margin:0">Versió <?= (int) $c['version'] ?> · editada <?= e(ago($c['updated_at'])) ?> · <a href="/campanyes/<?= (int) $c['id'] ?>/previsualitza" target="_blank">Previsualitza</a></p>
                <?php if (count($doc['langs']) > 1): ?><p class="small muted" style="margin:8px 0 0">Cada contacte rep l'idioma de la seva fitxa; si no el té o no hi és, el <?= e(Document::LANGS[$doc['primary']]) ?>.</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div>
    <div class="card" style="margin-bottom:16px">
        <div class="card-head">Enviament</div>
        <div class="card-body">
        <?php if ($c['status'] === 'scheduled'): ?>
            <p style="margin:0 0 12px">Programada per al <b><?= e(fmt_dt($c['scheduled_at'])) ?></b>.</p>
            <?php if (Auth::canEdit()): ?><form method="post" action="/campanyes/<?= (int) $c['id'] ?>/desprograma"><?= csrf_field() ?><button class="btn secondary" type="submit">Anul·la la programació</button></form><?php endif; ?>
        <?php elseif ($c['status'] === 'sending'): ?>
            <p style="margin:0 0 12px">S'està enviant des del <?= e(fmt_dt($c['started_at'])) ?>.</p>
            <?php if (Auth::canEdit()): ?><form method="post" action="/campanyes/<?= (int) $c['id'] ?>/pausa"><?= csrf_field() ?><button class="btn secondary" type="submit">Pausa l'enviament</button></form><?php endif; ?>
        <?php elseif ($c['status'] === 'paused'): ?>
            <p style="margin:0 0 12px">Enviament pausat. Els correus pendents s'enviaran quan el reprenguis.</p>
            <?php if (Auth::canEdit()): ?><div class="btns">
                <form method="post" action="/campanyes/<?= (int) $c['id'] ?>/repren"><?= csrf_field() ?><button class="btn" type="submit">Reprèn</button></form>
                <form method="post" action="/campanyes/<?= (int) $c['id'] ?>/atura" data-confirm="Aturar definitivament? Els correus pendents no s'enviaran."><?= csrf_field() ?><button class="btn danger" type="submit">Atura</button></form>
            </div><?php endif; ?>
        <?php elseif ($c['status'] === 'sent'): ?>
            <p style="margin:0 0 12px">Enviada el <b><?= e(fmt_dt($c['finished_at'])) ?></b> a <?= fmt_n($c['recipients']) ?> destinataris.</p>
            <a class="btn" href="/campanyes/<?= (int) $c['id'] ?>/informe">Mira l'informe</a>
        <?php elseif ($editable): ?>
            <ul class="checks" style="margin-bottom:16px">
                <?php foreach ($checks as $ch): ?><li class="<?= $ch['ok'] ? '' : ($ch['block'] ? 'block' : 'warn') ?>"><?= e($ch['label']) ?></li><?php endforeach; ?>
            </ul>
            <form method="post" action="/campanyes/<?= (int) $c['id'] ?>/programa" data-confirm="Programar l'enviament a <?= e(fmt_n($count)) ?> destinataris?">
                <?= csrf_field() ?>
                <div class="row">
                    <label class="field"><span>Data</span><input class="input" type="date" name="date" value="<?= e($when ? $when->format('Y-m-d') : $tomorrow) ?>" min="<?= e((new DateTimeImmutable('now', app_tz()))->format('Y-m-d')) ?>" required></label>
                    <label class="field"><span>Hora</span><input class="input" type="time" name="time" value="<?= e($when ? $when->format('H:i') : '09:00') ?>" required></label>
                </div>
                <button class="btn" type="submit" style="width:100%" <?= $blocking ? 'disabled' : '' ?>>Programa l'enviament</button>
            </form>
            <form method="post" action="/campanyes/<?= (int) $c['id'] ?>/programa" style="margin-top:8px" data-confirm="Enviar ARA a <?= e(fmt_n($count)) ?> destinataris? No es pot desfer.">
                <?= csrf_field() ?><input type="hidden" name="when" value="now">
                <button class="btn secondary" type="submit" style="width:100%" <?= $blocking ? 'disabled' : '' ?> data-busy="Enviant…">Envia ara</button>
            </form>
            <p class="small muted" style="margin:10px 0 0">Hora de <?= e(app_tz()->getName()) ?>. La data també es pot canviar arrossegant la campanya al <a href="/calendari">calendari</a>.</p>
        <?php else: ?>
            <p class="muted" style="margin:0">Sense permís per enviar.</p>
        <?php endif; ?>
        </div>
    </div>

    <?php if ($editable): ?>
    <form method="post" action="/campanyes/<?= (int) $c['id'] ?>/prova" class="card">
        <?= csrf_field() ?>
        <div class="card-head">Envia una prova</div>
        <div class="card-body">
            <label class="field"><span>A les adreces</span><input class="input" name="emails" value="<?= e($me['email'] ?? '') ?>" placeholder="una@exemple.cat, altra@exemple.cat"><small>Fins a 10, separades per comes. Els camps ({{nom}}…) es posen amb les dades del contacte si existeix.</small></label>
            <?php if (count($doc['langs']) > 1): ?>
                <label class="field"><span>Idioma</span><select class="input" name="lang"><?php foreach ($doc['langs'] as $code => $_): ?><option value="<?= e($code) ?>"><?= e(Document::LANGS[$code]) ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
            <button class="btn secondary" type="submit" data-busy="Enviant…">Envia la prova</button>
            <?php if ($c['test_sent_at']): ?><p class="small muted" style="margin:8px 0 0">Última prova: <?= e(ago($c['test_sent_at'])) ?></p><?php endif; ?>
        </div>
    </form>
    <?php endif; ?>
</div>
</div>

<script>
(function () {
    var form = document.getElementById('cfg'), timer = null;
    if (!form) return;
    function recount() {
        var fd = new FormData(form), qs = new URLSearchParams();
        fd.forEach(function (v, k) { if (k !== '_csrf') qs.append(k, v); });
        document.getElementById('aud-pick').hidden = !!fd.get('all');
        clearTimeout(timer);
        timer = setTimeout(function () {
            mlApi('/campanyes/<?= (int) $c['id'] ?>/destinataris?' + qs.toString()).then(function (r) { return r.json(); }).then(function (j) {
                if (j.ok) document.getElementById('aud-count').textContent = j.count.toLocaleString('ca');
            });
        }, 250);
    }
    form.querySelectorAll('[data-aud]').forEach(function (i) { i.addEventListener('change', recount); });
    <?php if ($c['status'] === 'sending'): ?>setTimeout(function () { location.reload(); }, 20000);<?php endif; ?>
})();
</script>
