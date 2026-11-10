<?php
use Mailing\Editor\Document;
use Mailing\Services\Campaigns;
$sent = max(0, $s['sent']);
$delivered = max($s['delivered'], 0);
// Timeline: up to the last hour with activity (min 24 h).
$last = 23;
foreach ($timeline['opens'] as $i => $v) { if ($v || $timeline['clicks'][$i]) { $last = max($last, $i); } }
$n = min(count($timeline['opens']), $last + 1);
$opens = array_slice($timeline['opens'], 0, $n);
$clicks = array_slice($timeline['clicks'], 0, $n);
$max = max(1, max($opens ?: [0]), max($clicks ?: [0]));
$W = 720; $H = 200; $bw = $n ? $W / $n : 0;
$filters = ['' => 'Tots', 'opened' => 'Han obert', 'notopened' => 'No han obert', 'clicked' => 'Han clicat', 'bounced' => 'Rebots', 'unsub' => 'Baixes', 'spam' => 'Brossa', 'failed' => 'No enviats', 'queued' => 'Pendents'];
?>
<a class="crumb" href="/campanyes/<?= (int) $c['id'] ?>">← <?= e($c['name']) ?></a>
<div class="page-head">
    <div><h1 class="page-title">Informe <span class="st <?= e($c['status']) ?>" style="vertical-align:4px"><?= e(Campaigns::STATUSES[$c['status']] ?? 'Automatització') ?></span></h1>
        <p class="page-sub"><b><?= e($c['subject']) ?></b> · <?= $c['status'] === 'auto' ? 'correu d\'una automatització' : ($c['started_at'] ? 'enviada el ' . e(fmt_dt($c['started_at'])) : 'encara no enviada') ?><?= $sender ? ' · des de ' . e($sender['email']) : '' ?></p></div>
    <div class="btns"><a class="btn secondary" href="/campanyes/<?= (int) $c['id'] ?>/previsualitza" target="_blank">Mira el correu</a><a class="btn secondary" href="/campanyes/<?= (int) $c['id'] ?>/destinataris.csv">Exporta CSV</a></div>
</div>
<div class="kpis" style="margin-bottom:16px">
    <div class="kpi"><span>Enviats</span><b><?= fmt_n($sent) ?></b><small>de <?= fmt_n($s['total']) ?> destinataris<?= $s['queued'] ? ' · ' . fmt_n($s['queued']) . ' pendents' : '' ?></small></div>
    <div class="kpi"><span>Lliurats</span><b><?= pct($delivered, $sent) ?></b><small><?= fmt_n($delivered) ?> correus</small></div>
    <div class="kpi"><span>Obertura</span><b><?= pct($s['opened'], $sent) ?></b><small><?= fmt_n($s['opened']) ?> persones · <?= fmt_n($s['opens']) ?> obertures</small></div>
    <div class="kpi"><span>Clics</span><b><?= pct($s['clicked'], $sent) ?></b><small><?= fmt_n($s['clicked']) ?> persones · CTOR <?= pct($s['clicked'], $s['opened']) ?></small></div>
    <div class="kpi"><span>Rebots</span><b><?= pct($s['bounced'], $sent) ?></b><small><?= fmt_n($s['hard']) ?> durs (exclosos)</small></div>
    <div class="kpi"><span>Baixes</span><b><?= fmt_n($s['unsub']) ?></b><small><?= fmt_n($s['spam']) ?> queixes de brossa</small></div>
</div>
<?php if ($s['failed'] || $s['skipped']): ?><div class="alert warn"><?= fmt_n($s['failed']) ?> correus rebutjats per Mailjet i <?= fmt_n($s['skipped']) ?> omesos (contactes donats de baixa abans de l'enviament). Filtra per «No enviats» per veure el motiu.</div><?php endif; ?>
<p class="small muted" style="margin:-4px 0 16px">Les obertures són orientatives: alguns programes (Apple Mail) les marquen soles i d'altres bloquegen les imatges. Els clics són la mesura fiable.</p>

<div class="grid side">
    <div class="card">
        <div class="card-head">Obertures i clics per hora</div>
        <div class="card-body">
        <?php if (array_sum($opens) + array_sum($clicks) > 0): ?>
            <svg class="chart" viewBox="0 0 <?= $W ?> <?= $H + 20 ?>" preserveAspectRatio="none" role="img" aria-label="Obertures i clics per hora des de l'enviament">
                <?php for ($g = 1; $g <= 4; $g++): $y = $H - $H * $g / 4; ?><line x1="0" x2="<?= $W ?>" y1="<?= $y ?>" y2="<?= $y ?>" stroke="#eef0f2"/><?php endfor; ?>
                <?php foreach ($opens as $i => $v): $h = $H * $v / $max; ?><rect x="<?= round($i * $bw + 1, 2) ?>" y="<?= round($H - $h, 2) ?>" width="<?= max(1, round($bw * .55, 2)) ?>" height="<?= round($h, 2) ?>" fill="var(--accent)" rx="1.5"><title><?= e($timeline['labels'][$i] . ': ' . $v . ' obertures') ?></title></rect><?php endforeach; ?>
                <?php foreach ($clicks as $i => $v): $h = $H * $v / $max; ?><rect x="<?= round($i * $bw + 1 + $bw * .55, 2) ?>" y="<?= round($H - $h, 2) ?>" width="<?= max(1, round($bw * .35, 2)) ?>" height="<?= round($h, 2) ?>" fill="#1f5fa8" rx="1.5"><title><?= e($timeline['labels'][$i] . ': ' . $v . ' clics') ?></title></rect><?php endforeach; ?>
                <text x="0" y="<?= $H + 16 ?>" font-size="11" fill="#6b7280"><?= e($timeline['labels'][0] ?? '') ?></text>
                <text x="<?= $W ?>" y="<?= $H + 16 ?>" font-size="11" fill="#6b7280" text-anchor="end"><?= e($timeline['labels'][$n - 1] ?? '') ?></text>
            </svg>
            <div class="legend"><span><i style="background:var(--accent)"></i>Obertures</span><span><i style="background:#1f5fa8"></i>Clics</span><span>Màxim: <?= fmt_n($max) ?>/hora</span></div>
        <?php else: ?><div class="empty">Encara no hi ha obertures ni clics. Arriben pocs minuts després de l'enviament (cal tenir els webhooks de Mailjet configurats).</div><?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-head">Enviament</div>
        <ul class="list">
            <li><div class="main"><b class="small">Destinataris</b><div class="sub"><?= e($audience) ?></div></div></li>
            <li><div class="main"><b class="small">Inici i final</b><div class="sub"><?= e(fmt_dt($c['started_at'])) ?> → <?= e(fmt_dt($c['finished_at'])) ?></div></div></li>
            <?php if (count($langs) > 1): ?><li><div class="main"><b class="small">Idiomes</b><div class="sub"><?= e(implode(' · ', array_map(static fn ($l) => (Document::LANGS[$l['language']] ?? $l['language']) . ': ' . $l['n'], $langs))) ?></div></div></li><?php endif; ?>
        </ul>
    </div>
</div>

<div class="card" style="margin-top:16px">
    <div class="card-head">Enllaços clicats</div>
    <?php if ($links): ?><div class="table-wrap"><table class="t"><thead><tr><th>Enllaç</th><th class="num">Clics</th><th class="num">Persones</th><th style="width:30%"></th></tr></thead><tbody>
        <?php $lmax = max(1, (int) $links[0]['clicks']); foreach ($links as $l): ?>
        <tr><td class="small" style="word-break:break-all"><a href="<?= e($l['url']) ?>" target="_blank" rel="noopener"><?= e(mb_strimwidth($l['url'], 0, 90, '…')) ?></a></td><td class="num"><b><?= fmt_n($l['clicks']) ?></b></td><td class="num"><?= fmt_n($l['people']) ?></td>
        <td><div class="bar"><i style="width:<?= round($l['clicks'] * 100 / $lmax) ?>%;background:#1f5fa8"></i></div></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php else: ?><div class="empty">Cap clic encara.</div><?php endif; ?>
</div>

<div class="card" style="margin-top:16px">
    <div class="toolbar"><b style="margin-right:8px">Destinataris</b>
        <div class="chips"><?php foreach ($filters as $k => $label): ?><a class="chip" style="text-decoration:none;<?= $filter === $k ? 'background:var(--accent-soft);color:var(--accent-dark);font-weight:700' : '' ?>" href="?filtre=<?= e($k) ?>"><?= e($label) ?></a><?php endforeach; ?></div></div>
    <?php if ($page['rows']): ?>
    <div class="table-wrap"><table class="t"><thead><tr><th>Contacte</th><th>Estat</th><th>Obert</th><th>Clic</th><th>Incidència</th></tr></thead><tbody>
    <?php foreach ($page['rows'] as $m): ?>
        <tr>
            <td><?= $m['contact_id'] ? '<a class="t-main" href="/contactes/' . (int) $m['contact_id'] . '">' . e($m['email']) . '</a>' : e($m['email']) ?><div class="sub"><?= e(trim($m['first_name'] . ' ' . $m['last_name'])) ?><?= $m['language'] ? ' · ' . e(strtoupper($m['language'])) : '' ?></div></td>
            <td class="small"><?= e(['queued' => 'Pendent', 'sent' => $m['delivered_at'] ? 'Lliurat' : 'Enviat', 'failed' => 'Rebutjat', 'skipped' => 'Omès', 'cancelled' => 'Cancel·lat'][$m['status']] ?? $m['status']) ?></td>
            <td class="small"><?= $m['opened_at'] ? e(fmt_short($m['opened_at'])) . ($m['open_count'] > 1 ? ' (' . (int) $m['open_count'] . '×)' : '') : '—' ?></td>
            <td class="small"><?= $m['clicked_at'] ? e(fmt_short($m['clicked_at'])) . ($m['click_count'] > 1 ? ' (' . (int) $m['click_count'] . '×)' : '') : '—' ?></td>
            <td class="small"><?= $m['bounced_at'] ? '<span class="pill err">Rebot ' . ($m['bounce_hard'] ? 'dur' : 'tou') . '</span> ' : '' ?><?= $m['spam_at'] ? '<span class="pill err">Brossa</span> ' : '' ?><?= $m['unsub_at'] ? '<span class="pill">Baixa</span> ' : '' ?><?= $m['blocked_at'] ? '<span class="pill err">Bloquejat</span> ' : '' ?><?= e($m['error'] ?? '') ?></td>
        </tr>
    <?php endforeach; ?></tbody></table></div>
    <?php include __DIR__ . '/../partials/pager.php'; ?>
    <?php else: ?><div class="empty">Cap destinatari en aquest filtre.</div><?php endif; ?>
</div>
