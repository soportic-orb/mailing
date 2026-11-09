<?php use Mailing\Core\Auth; use Mailing\Services\Forms; ?>
<div class="page-head">
    <div><h1 class="page-title">Formularis d'alta</h1><p class="page-sub">Pàgines públiques amb un formulari perquè les persones s'apuntin a les llistes. Es poden enllaçar o incrustar en una web.</p></div>
    <?php if (Auth::canEdit()): ?><a class="btn" href="/formularis/nou">+ Formulari nou</a><?php endif; ?>
</div>
<div class="card">
<?php if ($forms): ?>
    <table class="t"><thead><tr><th>Pàgina</th><th>Llistes</th><th class="num">Visites</th><th class="num">Altes</th><th></th></tr></thead><tbody>
    <?php foreach ($forms as $f): $u = url('f/' . $f['slug']); ?>
        <tr>
            <td><a class="t-main" href="/formularis/<?= (int) $f['id'] ?>"><?= e($f['title']) ?></a> <?= $f['active'] ? '' : '<span class="pill">Tancat</span>' ?><?= $f['double_optin'] ? ' <span class="pill info">Doble confirmació</span>' : '' ?>
                <div class="sub"><a href="<?= e($u) ?>" target="_blank" rel="noopener"><?= e($u) ?></a></div></td>
            <td class="small"><?= e(implode(', ', array_map(static fn ($id) => $lists[$id] ?? '#' . $id, Forms::listIds($f)))) ?></td>
            <td class="num"><?= fmt_n($f['views']) ?></td>
            <td class="num"><b><?= fmt_n($f['signups']) ?></b><?= $f['pending'] ? '<div class="sub">' . fmt_n($f['pending']) . ' per confirmar</div>' : '' ?></td>
            <td class="num"><button class="btn sm secondary" type="button" data-copy="<?= e($u) ?>">Copia l'enllaç</button></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
<?php else: ?><div class="empty"><b>Encara no hi ha cap formulari</b>Crea'n un per tenir una adreça pública on la gent es pugui apuntar a una llista.</div><?php endif; ?>
</div>
