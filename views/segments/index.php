<?php use Mailing\Core\Auth; ?>
<div class="page-head">
    <div><h1 class="page-title">Segments</h1><p class="page-sub">Grups dinàmics de contactes segons les seves dades o la seva activitat. Sempre al dia: es calculen en el moment d'enviar.</p></div>
    <?php if (Auth::canEdit()): ?><a class="btn" href="/segments/nou">+ Segment nou</a><?php endif; ?>
</div>
<div class="card">
    <?php if ($segments): ?>
    <table class="t"><thead><tr><th>Segment</th><th class="num">Contactes ara</th><th></th></tr></thead><tbody>
    <?php foreach ($segments as $s): ?>
        <tr><td><a class="t-main" href="/segments/<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a><div class="sub"><?= e($s['_desc']) ?></div></td>
        <td class="num"><b><?= fmt_n($s['_count']) ?></b></td>
        <td class="num"><?php if (Auth::canEdit()): ?><form method="post" action="/segments/<?= (int) $s['id'] ?>/elimina" class="inline" data-confirm="Eliminar el segment «<?= e($s['name']) ?>»?"><?= csrf_field() ?><button class="btn sm danger">Elimina</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php else: ?><div class="empty"><b>Encara no hi ha segments</b>Exemples: «han obert l'últim butlletí», «empresa conté Hospital», «no han obert res en 90 dies».</div><?php endif; ?>
</div>
