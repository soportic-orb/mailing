<?php
use Mailing\Core\Auth;
use Mailing\Services\Contacts;
$qs = http_build_query(array_filter($f));
?>
<div class="page-head">
    <div><h1 class="page-title">Contactes</h1><p class="page-sub"><?= fmt_n(array_sum($counts)) ?> contactes · <?= fmt_n($counts['subscribed'] ?? 0) ?> subscrits</p></div>
    <div class="btns">
        <a class="btn secondary" href="/contactes/camps">Camps</a>
        <a class="btn secondary" href="/contactes/exporta<?= $qs ? '?' . e($qs) : '' ?>">Exporta CSV</a>
        <?php if (Auth::canEdit()): ?><a class="btn secondary" href="/contactes/importa">Importa</a><a class="btn" href="/contactes/nou<?= $f['llista'] ? '?llista=' . (int) $f['llista'] : '' ?>">+ Contacte</a><?php endif; ?>
    </div>
</div>
<div class="card">
    <form class="toolbar" method="get">
        <input class="input grow" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Cerca per correu o nom">
        <select class="input" name="llista" onchange="this.form.submit()"><option value="">Totes les llistes</option><?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) $f['llista'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
        <select class="input" name="estat" onchange="this.form.submit()"><option value="">Tots els estats</option><?php foreach (Contacts::STATUSES as $k => $v): ?><option value="<?= e($k) ?>" <?= $f['estat'] === $k ? 'selected' : '' ?>><?= e($v) ?> (<?= (int) ($counts[$k] ?? 0) ?>)</option><?php endforeach; ?></select>
        <button class="btn secondary" type="submit">Filtra</button>
    </form>
    <?php if ($page['rows']): ?>
    <form method="post" action="/contactes/accio" id="bulk">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($qs) ?>">
        <?php if (Auth::canEdit()): ?>
        <div class="toolbar" style="background:#fafbfc">
            <select class="input" name="action" required>
                <option value="">Amb els seleccionats…</option>
                <option value="add_list">Afegeix a la llista</option>
                <option value="remove_list">Treu de la llista</option>
                <option value="unsubscribe">Dona de baixa</option>
                <option value="resubscribe">Torna a subscriure (els de baixa)</option>
                <option value="delete">Elimina definitivament</option>
            </select>
            <select class="input" name="list_id"><option value="">Llista…</option><?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select>
            <label class="check" style="margin:0"><input type="checkbox" name="scope" value="filtered"> Tots els <?= fmt_n($page['total']) ?> del filtre</label>
            <button class="btn secondary" type="submit" onclick="var a=this.form.action.value; if(a==='delete' && !confirm('Eliminar definitivament els contactes seleccionats i el seu historial?')) return false;">Aplica</button>
        </div>
        <?php endif; ?>
        <div class="table-wrap"><table class="t">
            <thead><tr><?php if (Auth::canEdit()): ?><th style="width:30px"><input type="checkbox" data-check-all="ids[]" aria-label="Selecciona'ls tots"></th><?php endif; ?><th>Contacte</th><th>Estat</th><th>Llistes</th><th>Idioma</th><th>Alta</th></tr></thead>
            <tbody>
            <?php foreach ($page['rows'] as $c): ?>
                <tr>
                    <?php if (Auth::canEdit()): ?><td><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" aria-label="Selecciona <?= e($c['email']) ?>"></td><?php endif; ?>
                    <td><a class="t-main" href="/contactes/<?= (int) $c['id'] ?>"><?= e($c['email']) ?></a><div class="sub"><?= e(trim($c['first_name'] . ' ' . $c['last_name']) ?: '—') ?></div></td>
                    <td><span class="st <?= e($c['status']) ?>"><?= e(Contacts::STATUSES[$c['status']] ?? $c['status']) ?></span></td>
                    <td class="small"><?= e($c['list_names'] ?: '—') ?></td>
                    <td class="small"><?= e(strtoupper($c['language']) ?: '—') ?></td>
                    <td class="small muted"><?= e(fmt_dt($c['created_at'], false)) ?><div class="sub"><?= e($c['source'] ?? '') ?></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </form>
    <?php include __DIR__ . '/../partials/pager.php'; ?>
    <?php else: ?>
        <div class="empty"><b><?= array_filter($f) ? 'Cap contacte amb aquests filtres' : 'Encara no hi ha contactes' ?></b><?= array_filter($f) ? '' : 'Importa\'ls des d\'un CSV o Excel, o configura la sincronització amb una plataforma.' ?></div>
    <?php endif; ?>
</div>
