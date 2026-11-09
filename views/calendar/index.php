<?php
use Mailing\Core\Auth;
use Mailing\Services\Campaigns;
$today = (new DateTimeImmutable('now', app_tz()))->format('Y-m-d');
$month = (int) $first->format('n');
$can = Auth::canEdit();
?>
<div class="page-head">
    <div><h1 class="page-title"><?= e(ucfirst(MONTHS_CA[$month - 1]) . ' ' . $first->format('Y')) ?></h1><p class="page-sub">Calendari d'enviaments. Arrossega una campanya programada o un esborrany a un altre dia per canviar-ne la data.</p></div>
    <div class="btns">
        <a class="btn secondary" href="?mes=<?= e($first->modify('-1 month')->format('Y-m')) ?>">← <?= e(ucfirst(MONTHS_CA[(int) $first->modify('-1 month')->format('n') - 1])) ?></a>
        <a class="btn secondary" href="/calendari">Avui</a>
        <a class="btn secondary" href="?mes=<?= e($first->modify('+1 month')->format('Y-m')) ?>"><?= e(ucfirst(MONTHS_CA[(int) $first->modify('+1 month')->format('n') - 1])) ?> →</a>
    </div>
</div>
<div class="card" style="padding:6px;overflow-x:auto">
<table class="cal" id="cal">
    <thead><tr><?php foreach (['Dl', 'Dt', 'Dc', 'Dj', 'Dv', 'Ds', 'Dg'] as $d): ?><th><?= $d ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php for ($w = 0; $w < 6; $w++): ?><tr>
        <?php for ($d = 0; $d < 7; $d++): $day = $start->modify('+' . ($w * 7 + $d) . ' days'); $key = $day->format('Y-m-d'); ?>
        <td class="<?= (int) $day->format('n') !== $month ? 'out' : '' ?><?= $key === $today ? ' today' : '' ?>" data-date="<?= e($key) ?>">
            <div class="d"><span><?= (int) $day->format('j') ?></span><?php if ($can && $key >= $today): ?><a href="/campanyes/nova" title="Nova campanya" aria-label="Nova campanya">+</a><?php endif; ?></div>
            <?php foreach ($byDay[$key] ?? [] as $c): $drag = $can && in_array($c['status'], ['draft', 'scheduled'], true); ?>
                <a class="ev <?= e($c['status']) ?>" href="/campanyes/<?= (int) $c['id'] ?><?= $c['status'] === 'sent' ? '/informe' : '' ?>" <?= $drag ? 'draggable="true"' : '' ?> data-id="<?= (int) $c['id'] ?>" title="<?= e($c['name'] . ' · ' . (Campaigns::STATUSES[$c['status']] ?? '') . ($c['status'] === 'draft' ? ' (data prevista, no s\'enviarà sola)' : '')) ?>"><b><?= e($c['_time']) ?></b> <?= e($c['name']) ?></a>
            <?php endforeach; ?>
        </td>
        <?php endfor; ?>
    </tr><?php endfor; ?>
    </tbody>
</table>
</div>
<div class="legend" style="margin-top:12px;flex-wrap:wrap">
    <span><i style="background:#c58a00"></i>Programada (s'enviarà sola)</span>
    <span><i style="background:#9aa0aa"></i>Esborrany amb data prevista</span>
    <span><i style="background:#1f5fa8"></i>Enviant</span>
    <span><i style="background:#1a7f56"></i>Enviada</span>
    <span><i style="background:#b42318"></i>Pausada o fallida</span>
</div>
<?php if ($unscheduled && $can): ?>
<div class="card" style="margin-top:16px">
    <div class="card-head">Esborranys sense data <span class="muted small" style="font-weight:400">Arrossega'ls a un dia</span></div>
    <div class="card-body" style="display:flex;flex-wrap:wrap;gap:8px" id="pool">
        <?php foreach ($unscheduled as $c): ?><a class="ev draft" style="display:inline-block;max-width:260px;margin:0;border:1px solid var(--border);border-left:3px dashed #9aa0aa" href="/campanyes/<?= (int) $c['id'] ?>" draggable="true" data-id="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<script>
(function () {
    var dragId = null;
    document.addEventListener('dragstart', function (e) { var a = e.target.closest('.ev[draggable="true"]'); if (!a) return; dragId = a.dataset.id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', dragId); });
    document.addEventListener('dragend', function () { dragId = null; document.querySelectorAll('.cal td.over').forEach(function (td) { td.classList.remove('over'); }); });
    document.querySelectorAll('#cal td[data-date]').forEach(function (td) {
        td.addEventListener('dragover', function (e) { if (!dragId) return; e.preventDefault(); td.classList.add('over'); });
        td.addEventListener('dragleave', function () { td.classList.remove('over'); });
        td.addEventListener('drop', function (e) {
            e.preventDefault(); td.classList.remove('over');
            if (!dragId) return;
            mlApi('/campanyes/' + dragId + '/data', { method: 'POST', json: { date: td.dataset.date } }).then(function (r) { return r.json(); }).then(function (j) {
                mlToast(j.message, !j.ok);
                if (j.ok) setTimeout(function () { location.reload(); }, 700);
            }).catch(function () { mlToast('No s\'ha pogut canviar la data.', true); });
        });
    });
})();
</script>
