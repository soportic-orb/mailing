<?php use Mailing\Services\Campaigns; ?>
<li>
    <div class="main">
        <a href="/campanyes/<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a>
        <div class="sub"><?= e($c['subject'] ?: 'Sense assumpte') ?><?= $c['scheduled_at'] && in_array($c['status'], ['scheduled', 'draft'], true) ? ' · ' . e(fmt_short($c['scheduled_at'])) : '' ?></div>
    </div>
    <span class="st <?= e($c['status']) ?>"><?= e(Campaigns::STATUSES[$c['status']] ?? $c['status']) ?></span>
</li>
