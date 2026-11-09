<?php use Mailing\Core\Auth; use Mailing\Editor\Document; ?>
<div class="page-head">
    <div><h1 class="page-title">Plantilles</h1><p class="page-sub">Dissenys per començar campanyes noves. Les «comunes» les veuen tots els projectes.</p></div>
    <?php if (Auth::canEdit()): ?>
    <div class="btns">
    <button class="btn secondary" type="button" onclick="document.getElementById('imp').showModal()">Importa</button>
    <form method="post" action="/plantilles" class="btns">
        <?= csrf_field() ?>
        <input class="input" name="name" placeholder="Nom de la plantilla" style="width:240px">
        <?php if (Auth::isAdmin()): ?><label class="check" style="margin:0"><input type="checkbox" name="global" value="1"> Comuna</label><?php endif; ?>
        <button class="btn" type="submit">+ Nova plantilla</button>
    </form>
    </div>
    <?php endif; ?>
</div>
<?php if (Auth::canEdit()): ?>
<dialog class="dlg" id="imp" style="width:min(680px, calc(100vw - 24px))">
    <form method="post" action="/plantilles/importa" enctype="multipart/form-data" class="in">
        <?= csrf_field() ?>
        <div style="display:flex;align-items:center;gap:10px"><h2 style="flex:1">Importa una plantilla</h2><button class="btn secondary sm" type="button" onclick="this.closest('dialog').close()" aria-label="Tanca">×</button></div>
        <div class="alert info small" style="margin:0;line-height:1.55">
            <b>Fer-la dissenyar a Claude:</b> descarrega la <a href="/plantilles/format?baixa=1">guia del format</a> (o <button type="button" class="btn ghost sm" style="padding:0 4px" id="copy-spec">copia-la</button>), dona-la a Claude amb el que vols («un butlletí mensual per a clients d'Emergencing amb tres notícies i un botó…») i demana-li que respongui amb el JSON.
            Després enganxa la resposta aquí o desa-la en un fitxer <code>.json</code>. També serveix per a les plantilles exportades des d'aquesta plataforma.
        </div>
        <label class="field" style="margin:0"><span>Fitxer (.json)</span><input class="input" type="file" name="file" accept=".json,application/json,text/plain"></label>
        <label class="field" style="margin:0"><span>…o enganxa el JSON</span><textarea class="input code" name="json" rows="8" placeholder='{"format": "soportic-mailing-template", "version": 1, "name": "…", "langs": {…}}'></textarea></label>
        <div class="row">
            <label class="field" style="margin:0"><span>Nom (opcional)</span><input class="input" name="name" maxlength="190" placeholder="El del fitxer"></label>
            <?php if (Auth::isAdmin()): ?><label class="check" style="align-self:end"><input type="checkbox" name="global" value="1"> Comuna a tots els projectes</label><?php endif; ?>
        </div>
        <p class="small muted" style="margin:0">Les imatges han de ser adreces https:// públiques. Després de l'importació s'obre l'editor per revisar-la.</p>
        <div class="btns" style="justify-content:flex-end"><button class="btn" type="submit" data-busy="Important…">Importa</button></div>
    </form>
</dialog>
<script>
document.getElementById('copy-spec').addEventListener('click', function () {
    fetch('/plantilles/format', { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (t) { return navigator.clipboard.writeText(t); })
        .then(function () { mlToast('Guia copiada: enganxa-la a Claude.'); }).catch(function () { mlToast('No s\'ha pogut copiar: descarrega-la.', true); });
});
</script>
<?php endif; ?>
<?php if ($templates): ?>
<div class="tpls" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr))">
    <?php foreach ($templates as $t): $doc = Document::decode($t['doc']); $canEdit = Auth::canEdit() && ($t['project_id'] !== null || Auth::isAdmin()); ?>
    <div class="tpl" style="cursor:default">
        <?php include __DIR__ . '/../partials/thumb.php'; ?>
        <b><?= e($t['name']) ?></b>
        <span class="small muted"><?= $t['project_id'] === null ? '<span class="pill info">Comuna</span> ' : '' ?><?= count($doc['langs']) > 1 ? e(implode(' · ', array_map('strtoupper', array_keys($doc['langs'])))) . ' · ' : '' ?>editada <?= e(ago($t['updated_at'])) ?></span>
        <div class="btns">
            <?php if ($canEdit): ?><a class="btn sm" href="/plantilles/<?= (int) $t['id'] ?>/editor">Edita</a><?php endif; ?>
            <a class="btn sm secondary" href="/plantilles/<?= (int) $t['id'] ?>/previsualitza" target="_blank">Mira-la</a>
            <?php if (Auth::canEdit()): ?>
            <details class="ml-menu"><summary class="btn sm secondary" aria-label="Més">⋯</summary><div class="ml-menu-pop">
                <a href="/plantilles/<?= (int) $t['id'] ?>/exporta">Exporta (.json)<small>Per a una altra instal·lació o per donar-la a Claude</small></a>
                <form method="post" action="/plantilles/<?= (int) $t['id'] ?>/duplica"><?= csrf_field() ?><button type="submit">Duplica al projecte</button></form>
                <?php if ($canEdit): ?><form method="post" action="/plantilles/<?= (int) $t['id'] ?>/elimina" data-confirm="Eliminar la plantilla «<?= e($t['name']) ?>»?"><?= csrf_field() ?><button type="submit" style="color:#b42318">Elimina</button></form><?php endif; ?>
            </div></details>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card"><div class="empty"><b>Encara no hi ha cap plantilla</b>Crea'n una, o des de l'editor d'una campanya fes «Desa com a plantilla».</div></div>
<?php endif; ?>
