<?php use Mailing\Core\Auth; ?>
<div class="page-head">
    <div><h1 class="page-title">Remitents</h1><p class="page-sub">Adreces des de les quals s'envien les campanyes de <?= e($project['name']) ?>. A cada campanya es tria quina.</p></div>
    <?php if (Auth::canEdit() && $configured): ?><div class="btns">
        <a class="btn secondary" href="/remitents?dns=1">Comprova els dominis (SPF/DKIM)</a>
        <form method="post" action="/remitents/sincronitza"><?= csrf_field() ?><button class="btn secondary" type="submit">Sincronitza amb Mailjet</button></form>
    </div><?php endif; ?>
</div>
<?php if (!$configured): ?><div class="alert warn">Mailjet no està configurat: els remitents no es poden validar. <?= Auth::isAdmin() ? '<a href="/configuracio">Configura\'l</a>.' : '' ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
<div class="alert info">Mailjet només envia des d'adreces <b>validades</b> (o de dominis validats). Perquè els correus no vagin a brossa, el domini ha de tenir els registres <b>SPF</b> i <b>DKIM</b> de Mailjet al DNS.</div>
<?php if ($domains): ?>
<div class="card" style="margin-bottom:16px"><div class="card-head">Dominis al compte de Mailjet</div>
    <table class="t"><thead><tr><th>Domini</th><th>SPF</th><th>DKIM</th></tr></thead><tbody>
    <?php foreach ($domains as $d): $ok = static fn ($v) => in_array(strtoupper($v), ['OK', 'VALID'], true); ?>
        <tr><td><b><?= e($d['domain']) ?></b></td><td><span class="pill <?= $ok($d['spf']) ? 'ok' : 'warn' ?>"><?= e($d['spf'] ?: '—') ?></span></td><td><span class="pill <?= $ok($d['dkim']) ? 'ok' : 'warn' ?>"><?= e($d['dkim'] ?: '—') ?></span></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
<div class="grid side">
<div class="card">
    <?php if ($senders): ?>
    <table class="t"><thead><tr><th>Remitent</th><th>Respostes a</th><th>Mailjet</th><th></th></tr></thead><tbody>
    <?php foreach ($senders as $s): $active = strtolower((string) $s['mailjet_status']) === 'active'; ?>
        <tr>
            <td><b><?= e($s['name'] ?: '—') ?></b> <?= (int) $project['default_sender_id'] === (int) $s['id'] ? '<span class="pill accent">Per defecte</span>' : '' ?><div class="sub"><?= e($s['email']) ?></div></td>
            <td class="small"><?= e($s['reply_to'] ?: '—') ?></td>
            <td><span class="pill <?= $active ? 'ok' : 'warn' ?>"><?= e($active ? 'Validat' : ($s['mailjet_status'] ?: 'Sense comprovar')) ?></span><?php if ($s['checked_at']): ?><div class="sub"><?= e(ago($s['checked_at'])) ?></div><?php endif; ?></td>
            <td class="num"><?php if (Auth::canEdit()): ?>
                <details class="ml-menu" style="display:inline-block"><summary class="btn sm secondary">Edita</summary><div class="ml-menu-pop" style="min-width:300px;padding:12px">
                    <form method="post" action="/remitents/<?= (int) $s['id'] ?>"><?= csrf_field() ?>
                        <label class="field"><span>Nom visible</span><input class="input" name="name" value="<?= e($s['name']) ?>"></label>
                        <label class="field"><span>Respostes a</span><input class="input" type="email" name="reply_to" value="<?= e($s['reply_to']) ?>"></label>
                        <button class="btn sm" type="submit">Desa</button></form>
                    <?php if ((int) $project['default_sender_id'] !== (int) $s['id']): ?><form method="post" action="/remitents/<?= (int) $s['id'] ?>/defecte" style="margin-top:8px"><?= csrf_field() ?><button type="submit">Fes-lo el remitent per defecte</button></form><?php endif; ?>
                    <form method="post" action="/remitents/<?= (int) $s['id'] ?>/elimina" data-confirm="Eliminar el remitent <?= e($s['email']) ?>?"><?= csrf_field() ?><button type="submit" style="color:#b42318">Elimina</button></form>
                </div></details>
            <?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php else: ?><div class="empty"><b>Encara no hi ha cap remitent</b>Afegeix l'adreça des de la qual vols enviar (per exemple info@<?= e(parse_url((string) $project['website'], PHP_URL_HOST) ?: 'domini.com') ?>).</div><?php endif; ?>
</div>
<?php if (Auth::canEdit()): ?>
<form method="post" action="/remitents" class="card"><div class="card-head">Remitent nou</div><div class="card-body">
    <?= csrf_field() ?>
    <label class="field"><span>Adreça</span><input class="input" type="email" name="email" required placeholder="info@exemple.com"></label>
    <label class="field"><span>Nom visible</span><input class="input" name="name" maxlength="120" value="<?= e($project['name']) ?>"></label>
    <label class="field"><span>Respostes a (opcional)</span><input class="input" type="email" name="reply_to"></label>
    <?php if ($configured): ?><label class="check"><input type="checkbox" name="register" value="1" checked> Si no existeix a Mailjet, registra-la (Mailjet hi enviarà un correu de validació)</label><?php endif; ?>
    <button class="btn" type="submit">Afegeix</button>
</div></form>
<?php endif; ?>
</div>
