<?php $local = app_version(); $newer = $remote && version_compare($remote, $local, '>'); ?>
<div class="page-head"><div><h1 class="page-title">Actualització</h1><p class="page-sub">Versió instal·lada: <b><?= e($local) ?></b> · PHP <?= e(PHP_VERSION) ?></p></div>
    <form method="post" action="/actualitzacio/comprova"><?= csrf_field() ?><button class="btn secondary" type="submit" data-busy="Comprovant…">Comprova si n'hi ha</button></form></div>
<?php if ($result): unset($_SESSION['update_result']); ?><div class="card" style="margin-bottom:16px"><div class="card-head">Registre de l'última actualització</div><div class="card-body"><pre class="log"><?= e($result['log']) ?></pre></div></div><?php endif; ?>
<div class="grid two">
<div class="card">
    <div class="card-head">Actualització OTA <?= $newer ? '<span class="pill accent">Nova: ' . e($remote) . '</span>' : '' ?></div>
    <div class="card-body">
        <p class="small" style="margin-top:0;line-height:1.55">Es descarrega la versió nova de GitHub i s'instal·la sola: <b>mode manteniment → còpia de la base de dades → substitució de fitxers</b> (es conserven <code>.env</code> i <code>storage/</code>) <b>→ migracions → fet</b>. Si falla, es tornen a posar els fitxers anteriors.</p>
        <p class="small muted"><?= $lastCheck ? 'Última comprovació: ' . e(ago($lastCheck)) . ($remote ? ' · versió remota ' . e($remote) : '') : 'Encara no s\'ha comprovat.' ?></p>
        <ul class="checks" style="margin-bottom:14px"><?php foreach ($preflight as $c): ?><li class="<?= $c['ok'] ? '' : 'block' ?>"><?= e($c['label']) ?><?= $c['detail'] ? ' <span class="muted">(' . e($c['detail']) . ')</span>' : '' ?></li><?php endforeach; ?></ul>
        <form method="post" action="/actualitzacio/aplica" data-confirm="Actualitzar ara? Durant un minut la plataforma estarà en manteniment."><?= csrf_field() ?><button class="btn" type="submit" data-busy="Actualitzant… no tanquis la pàgina"><?= $newer ? 'Actualitza a la ' . e($remote) : 'Reinstal·la la darrera versió' ?></button></form>
    </div>
</div>
<div>
    <div class="card">
        <div class="card-head">Base de dades</div>
        <div class="card-body">
            <p class="small" style="margin-top:0"><?= count($migrations['applied']) ?> migracions aplicades<?= $migrations['pending'] ? ' · <b>' . count($migrations['pending']) . ' pendents</b>: ' . e(implode(', ', $migrations['pending'])) : '' ?>.</p>
            <div class="btns">
                <?php if ($migrations['pending']): ?><form method="post" action="/actualitzacio/migra"><?= csrf_field() ?><button class="btn">Aplica les migracions</button></form><?php endif; ?>
                <form method="post" action="/actualitzacio/copia"><?= csrf_field() ?><button class="btn secondary" data-busy="Fent la còpia…">Fes una còpia de seguretat</button></form>
            </div>
            <?php if ($backups): ?><ul class="list" style="padding:10px 0 0"><?php foreach (array_slice($backups, 0, 6) as $b): ?>
                <li><div class="main"><a href="/actualitzacio/copia/<?= e($b['name']) ?>"><?= e($b['name']) ?></a><div class="sub"><?= e(round($b['size'] / 1024)) ?> KB · <?= e(fmt_short(gmdate('Y-m-d H:i:s', $b['time']))) ?></div></div></li>
            <?php endforeach; ?></ul><?php endif; ?>
        </div>
    </div>
    <?php if ($history): ?><div class="card"><div class="card-head">Historial</div><ul class="list"><?php foreach ($history as $h): ?>
        <li><div class="main"><b class="small"><?= e($h['from'] . ' → ' . $h['to']) ?></b> <span class="pill <?= $h['ok'] ? 'ok' : 'err' ?>"><?= $h['ok'] ? 'Correcta' : 'Fallida' ?></span><div class="sub"><?= e(fmt_short($h['at'])) ?></div></div></li>
    <?php endforeach; ?></ul></div><?php endif; ?>
</div>
</div>
