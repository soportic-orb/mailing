<?php
$c = $stats['contacts'];
$s = $stats['sent90'];
$max = max(1, max($growth));
?>
<div class="page-head">
    <div><h1 class="page-title"><?= e($project['name']) ?></h1><p class="page-sub">Resum del projecte</p></div>
    <?php if (\Mailing\Core\Auth::canEdit()): ?><div class="btns"><a class="btn secondary" href="/contactes/importa">Importa contactes</a><a class="btn" href="/campanyes/nova">+ Nova campanya</a></div><?php endif; ?>
</div>
<?php foreach ($warnings as [$text, $link]): ?>
    <div class="alert warn"><?= e($text) ?><?php if ($link): ?> <a href="<?= e($link) ?>">Resol-ho</a><?php endif; ?></div>
<?php endforeach; ?>

<div class="kpis" style="margin-bottom:18px">
    <div class="kpi"><span>Contactes subscrits</span><b><?= fmt_n($c['subscribed'] ?? 0) ?></b><small><?= fmt_n($c['new30'] ?? 0) ?> nous en 30 dies</small></div>
    <div class="kpi"><span>De baixa o exclosos</span><b><?= fmt_n(($c['unsubscribed'] ?? 0) + ($c['suppressed'] ?? 0)) ?></b><small><?= fmt_n($c['suppressed'] ?? 0) ?> per rebot o queixa</small></div>
    <div class="kpi"><span>Correus enviats (90 dies)</span><b><?= fmt_n($s['sent'] ?? 0) ?></b><small><?= fmt_n($s['bounced'] ?? 0) ?> rebots</small></div>
    <div class="kpi"><span>Taxa d'obertura</span><b><?= pct($s['opened'] ?? 0, $s['sent'] ?? 0) ?></b><small>Orientativa (Apple Mail obre sol)</small></div>
    <div class="kpi"><span>Taxa de clics</span><b><?= pct($s['clicked'] ?? 0, $s['sent'] ?? 0) ?></b><small><?= fmt_n($s['unsub'] ?? 0) ?> baixes</small></div>
</div>

<div class="grid two">
    <div class="card">
        <div class="card-head">Properes i en curs <a href="/calendari">Calendari →</a></div>
        <?php if ($upcoming): ?><ul class="list"><?php foreach ($upcoming as $c) { include __DIR__ . '/../partials/campaign_row.php'; } ?></ul>
        <?php else: ?><div class="empty">No hi ha cap campanya programada.</div><?php endif; ?>
    </div>
    <div class="card">
        <div class="card-head">Esborranys <a href="/campanyes?estat=draft">Tots →</a></div>
        <?php if ($drafts): ?><ul class="list"><?php foreach ($drafts as $c) { include __DIR__ . '/../partials/campaign_row.php'; } ?></ul>
        <?php else: ?><div class="empty">Cap esborrany.</div><?php endif; ?>
    </div>
</div>

<div class="grid side" style="margin-top:16px">
    <div class="card">
        <div class="card-head">Últimes campanyes enviades <a href="/campanyes?estat=sent">Totes →</a></div>
        <?php if ($recent): ?>
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Campanya</th><th class="num">Enviats</th><th class="num">Obertura</th><th class="num">Clics</th><th></th></tr></thead>
            <tbody><?php foreach ($recent as $r): ?>
                <tr>
                    <td><a class="t-main" href="/campanyes/<?= (int) $r['id'] ?>/informe"><?= e($r['name']) ?></a><div class="sub"><?= e(fmt_dt($r['finished_at'])) ?></div></td>
                    <td class="num"><?= fmt_n($r['n_sent']) ?></td>
                    <td class="num"><?= pct($r['n_open'], $r['n_sent']) ?></td>
                    <td class="num"><?= pct($r['n_click'], $r['n_sent']) ?></td>
                    <td class="num"><a class="btn sm secondary" href="/campanyes/<?= (int) $r['id'] ?>/informe">Informe</a></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php else: ?><div class="empty"><b>Encara no s'ha enviat cap campanya</b>Quan n'enviïs, aquí en veuràs els resultats.</div><?php endif; ?>
    </div>
    <div class="card">
        <div class="card-head">Altes de contactes (12 mesos)</div>
        <div class="card-body">
            <div style="display:flex;align-items:flex-end;gap:4px;height:140px">
                <?php foreach ($growth as $ym => $n): ?>
                    <div title="<?= e($ym . ': ' . $n) ?>" style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%">
                        <div style="height:<?= max(2, round($n * 100 / $max)) ?>%;background:var(--accent);border-radius:4px 4px 0 0;opacity:<?= $n ? 1 : .2 ?>"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:6px"><span><?= e(substr((string) array_key_first($growth), 0, 7)) ?></span><span><?= e((string) array_key_last($growth)) ?></span></div>
        </div>
    </div>
</div>
