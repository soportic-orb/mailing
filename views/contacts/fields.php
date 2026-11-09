<?php use Mailing\Core\Auth; ?>
<a class="crumb" href="/contactes">← Contactes</a>
<div class="page-head"><div><h1 class="page-title">Camps dels contactes</h1><p class="page-sub">Dades addicionals (empresa, població, data de renovació…). Es poden posar als correus amb <code>{{camp:clau}}</code> i fer servir als segments.</p></div></div>
<div class="grid side">
<div class="card">
    <?php if ($fields): ?>
    <table class="t"><thead><tr><th>Nom</th><th>Al correu</th><th>Tipus</th><th></th></tr></thead><tbody>
    <?php foreach ($fields as $fd): ?>
        <tr><td><b><?= e($fd['label']) ?></b></td><td><code>{{camp:<?= e($fd['key']) ?>}}</code></td><td class="small"><?= e(['text' => 'Text', 'number' => 'Número', 'date' => 'Data'][$fd['type']] ?? $fd['type']) ?></td>
        <td class="num"><?php if (Auth::canEdit()): ?><form method="post" action="/contactes/camps/<?= e($fd['key']) ?>/elimina" class="inline" data-confirm="Treure el camp «<?= e($fd['label']) ?>»?"><?= csrf_field() ?><button class="btn sm danger">Treu</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php else: ?><div class="empty">Encara no hi ha camps addicionals. També es creen sols en importar o sincronitzar columnes noves.</div><?php endif; ?>
</div>
<?php if (Auth::canEdit()): ?>
<form method="post" action="/contactes/camps" class="card"><div class="card-head">Camp nou</div><div class="card-body">
    <?= csrf_field() ?>
    <label class="field"><span>Nom</span><input class="input" name="label" required maxlength="120" placeholder="Ex.: Empresa"></label>
    <label class="field"><span>Tipus</span><select class="input" name="type"><option value="text">Text</option><option value="number">Número</option><option value="date">Data</option></select></label>
    <button class="btn" type="submit">Crea</button>
</div></form>
<?php endif; ?>
</div>
