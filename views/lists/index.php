<?php use Mailing\Core\Auth; ?>
<div class="page-head"><div><h1 class="page-title">Llistes</h1><p class="page-sub">Grups de contactes per enviar campanyes. Un contacte pot ser a diverses llistes.</p></div></div>
<div class="grid side">
<div class="card">
    <?php if ($lists): ?>
    <table class="t"><thead><tr><th>Llista</th><th class="num">Subscrits</th><th class="num">Total</th><th></th></tr></thead><tbody>
    <?php foreach ($lists as $l): ?>
        <tr>
            <td><a class="t-main" href="/contactes?llista=<?= (int) $l['id'] ?>"><?= e($l['name']) ?></a><div class="sub"><?= e($l['description'] ?: '') ?><?= $l['syncs'] ? ' <span class="pill info">Sincronitzada</span>' : '' ?></div></td>
            <td class="num"><b><?= fmt_n($l['active']) ?></b></td>
            <td class="num muted"><?= fmt_n($l['total']) ?></td>
            <td class="num"><?php if (Auth::canEdit()): ?>
                <details class="ml-menu" style="display:inline-block"><summary class="btn sm secondary">Edita</summary><div class="ml-menu-pop" style="min-width:300px;padding:12px">
                    <form method="post" action="/llistes/<?= (int) $l['id'] ?>"><?= csrf_field() ?>
                        <label class="field"><span>Nom</span><input class="input" name="name" value="<?= e($l['name']) ?>"></label>
                        <label class="field"><span>Descripció</span><input class="input" name="description" value="<?= e($l['description']) ?>"></label>
                        <button class="btn sm" type="submit">Desa</button></form>
                    <form method="post" action="/llistes/<?= (int) $l['id'] ?>/elimina" data-confirm="Eliminar la llista «<?= e($l['name']) ?>»? Els contactes no s'eliminen." style="margin-top:8px"><?= csrf_field() ?><button type="submit" style="color:#b42318">Elimina la llista</button></form>
                </div></details>
            <?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php else: ?><div class="empty"><b>Encara no hi ha cap llista</b>Crea'n una aquí, en importar contactes o en configurar una sincronització.</div><?php endif; ?>
</div>
<?php if (Auth::canEdit()): ?>
<form method="post" action="/llistes" class="card"><div class="card-head">Llista nova</div><div class="card-body">
    <?= csrf_field() ?>
    <label class="field"><span>Nom</span><input class="input" name="name" required maxlength="120" placeholder="Ex.: Clients actius"></label>
    <label class="field"><span>Descripció (opcional)</span><input class="input" name="description" maxlength="500"></label>
    <button class="btn" type="submit">Crea la llista</button>
</div></form>
<?php endif; ?>
</div>
