<div class="page-head">
    <div><h1 class="page-title">Projectes</h1><p class="page-sub">Cada projecte (Emergencing, SysRevAI…) té els seus contactes, llistes, remitents, plantilles i campanyes, separats.</p></div>
    <a class="btn" href="/projectes/nou">+ Projecte nou</a>
</div>
<div class="card">
<?php if ($projects): ?>
    <table class="t"><thead><tr><th>Projecte</th><th class="num">Contactes</th><th class="num">Campanyes enviades</th><th class="num">Remitents</th><th>Mailjet</th><th></th></tr></thead><tbody>
    <?php foreach ($projects as $p): ?>
        <tr style="<?= $p['archived'] ? 'opacity:.55' : '' ?>">
            <td><span class="dot" style="background:<?= e($p['color']) ?>;width:10px;height:10px"></span> <a class="t-main" href="/projectes/<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a><?= $p['archived'] ? ' <span class="pill">Arxivat</span>' : '' ?><div class="sub"><?= e($p['website'] ?: '') ?></div></td>
            <td class="num"><?= fmt_n($p['n_contacts']) ?></td>
            <td class="num"><?= fmt_n($p['n_sent']) ?></td>
            <td class="num"><?= fmt_n($p['n_senders']) ?></td>
            <td class="small"><?= $p['mailjet_key'] ? '<span class="pill info">Compte propi</span>' : '<span class="muted">El general</span>' ?></td>
            <td class="num"><form method="post" action="/projectes/<?= (int) $p['id'] ?>/arxiva" class="inline" data-confirm="<?= $p['archived'] ? 'Recuperar' : 'Arxivar' ?> el projecte?"><?= csrf_field() ?><button class="btn sm secondary"><?= $p['archived'] ? 'Recupera' : 'Arxiva' ?></button></form></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
<?php else: ?><div class="empty"><b>Encara no hi ha cap projecte</b>Crea'n el primer.</div><?php endif; ?>
</div>
