<?php use Mailing\Services\Campaigns; ?>
<div class="page-head">
    <div><h1 class="page-title">Campanyes</h1><p class="page-sub">Correus massius d'aquest projecte</p></div>
    <?php if (\Mailing\Core\Auth::canEdit()): ?><a class="btn" href="/campanyes/nova">+ Nova campanya</a><?php endif; ?>
</div>
<div class="card">
    <form class="toolbar" method="get">
        <div class="chips">
            <a class="chip" href="/campanyes" style="<?= $status === '' ? 'background:var(--accent-soft);color:var(--accent-dark);font-weight:700' : '' ?>;text-decoration:none">Totes (<?= array_sum($counts) ?>)</a>
            <?php foreach (Campaigns::STATUSES as $k => $label): if (empty($counts[$k])) continue; ?>
                <a class="chip" href="/campanyes?estat=<?= e($k) ?>" style="<?= $status === $k ? 'background:var(--accent-soft);color:var(--accent-dark);font-weight:700' : '' ?>;text-decoration:none"><?= e($label) ?> (<?= (int) $counts[$k] ?>)</a>
            <?php endforeach; ?>
        </div>
        <span style="flex:1"></span>
        <?php if ($status !== ''): ?><input type="hidden" name="estat" value="<?= e($status) ?>"><?php endif; ?>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Cerca per nom o assumpte">
    </form>
    <?php if ($page['rows']): ?>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Campanya</th><th>Estat</th><th>Data</th><th class="num">Destinataris</th><th class="num">Obertura</th><th class="num">Clics</th></tr></thead>
        <tbody>
        <?php foreach ($page['rows'] as $c): $sent = in_array($c['status'], ['sent', 'sending', 'paused'], true); ?>
            <tr>
                <td><a class="t-main" href="/campanyes/<?= (int) $c['id'] ?><?= $c['status'] === 'sent' ? '/informe' : '' ?>"><?= e($c['name']) ?></a><div class="sub"><?= e($c['subject'] ?: 'Sense assumpte') ?></div></td>
                <td><span class="st <?= e($c['status']) ?>"><?= e(Campaigns::STATUSES[$c['status']] ?? $c['status']) ?></span></td>
                <td class="small"><?php if ($c['status'] === 'sent'): ?><?= e(fmt_short($c['finished_at'])) ?><?php elseif ($c['scheduled_at']): ?><?= e(fmt_short($c['scheduled_at'])) ?><?= $c['status'] === 'draft' ? ' <span class="muted">(prevista)</span>' : '' ?><?php else: ?><span class="muted">Editat <?= e(ago($c['updated_at'])) ?></span><?php endif; ?></td>
                <td class="num"><?= $sent ? fmt_n($c['recipients']) : '—' ?></td>
                <td class="num"><?= $sent ? pct($c['n_open'], $c['n_sent']) : '—' ?></td>
                <td class="num"><?= $sent ? pct($c['n_click'], $c['n_sent']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php include __DIR__ . '/../partials/pager.php'; ?>
    <?php else: ?>
        <div class="empty"><b><?= $q !== '' || $status !== '' ? 'Cap resultat' : 'Encara no hi ha cap campanya' ?></b><?= $q !== '' || $status !== '' ? 'Prova amb uns altres filtres.' : 'Crea la primera amb el botó «Nova campanya».' ?></div>
    <?php endif; ?>
</div>
