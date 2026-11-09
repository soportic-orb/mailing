<?php use Mailing\Core\Auth; use Mailing\Editor\Document; ?>
<div class="page-head">
    <div><h1 class="page-title">Plantilles</h1><p class="page-sub">Dissenys per començar campanyes noves. Les «comunes» les veuen tots els projectes.</p></div>
    <?php if (Auth::canEdit()): ?>
    <form method="post" action="/plantilles" class="btns">
        <?= csrf_field() ?>
        <input class="input" name="name" placeholder="Nom de la plantilla" style="width:240px">
        <?php if (Auth::isAdmin()): ?><label class="check" style="margin:0"><input type="checkbox" name="global" value="1"> Comuna</label><?php endif; ?>
        <button class="btn" type="submit">+ Nova plantilla</button>
    </form>
    <?php endif; ?>
</div>
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
