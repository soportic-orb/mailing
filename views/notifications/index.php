<?php use Mailing\Services\Notify; ?>
<div class="page-head">
    <div><h1 class="page-title">Avisos</h1><p class="page-sub">Campanyes enviades o fallides, automatitzacions i el sistema. Tria quins vols al <a href="/perfil#avisos">teu perfil</a>.</p></div>
    <?php if ($page['rows']): ?><form method="post" action="/avisos/llegits"><?= csrf_field() ?><button class="btn secondary" type="submit">Marca'ls tots com a llegits</button></form><?php endif; ?>
</div>
<div class="card">
<?php if ($page['rows']): ?>
    <ul class="list notif-list">
    <?php foreach ($page['rows'] as $n): ?>
        <li class="<?= $n['read_at'] ? '' : 'unread' ?>"><span class="notif-dot" aria-hidden="true"></span>
            <div class="main"><a href="/avisos/<?= (int) $n['id'] ?>"><?= e($n['title']) ?></a>
                <div class="sub"><?= e($n['body']) ?></div>
                <div class="sub"><?= e(Notify::KINDS[$n['kind']][0] ?? $n['kind']) ?> · <?= e(ago($n['created_at'])) ?><?= $n['read_at'] ? '' : ' · <b>nou</b>' ?></div></div></li>
    <?php endforeach; ?>
    </ul>
    <?php include __DIR__ . '/../partials/pager.php'; ?>
<?php else: ?>
    <div class="empty"><b>Cap avís encara</b>Quan una campanya s'enviï o falli, ho veuràs aquí.</div>
<?php endif; ?>
</div>
