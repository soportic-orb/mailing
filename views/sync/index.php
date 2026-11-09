<?php use Mailing\Core\Auth; use Mailing\Services\Sync; ?>
<div class="page-head">
    <div><h1 class="page-title">Sincronització</h1><p class="page-sub">Contactes que arriben sols des de les altres plataformes (Emergencing, SysRevAI…): per API, llegint-ne la base de dades o una adreça JSON.</p></div>
    <?php if (Auth::canEdit()): ?><div class="btns"><a class="btn secondary" href="/sincronitzacio/nou?tipus=mysql">+ Base de dades</a><a class="btn secondary" href="/sincronitzacio/nou?tipus=json">+ Adreça JSON</a><a class="btn" href="/sincronitzacio/nou?tipus=api">+ API</a></div><?php endif; ?>
</div>
<?php if ($token): unset($_SESSION['new_token']); ?>
<div class="alert ok">
    <b>Clau de l'API (copia-la ara: no es tornarà a mostrar)</b><br>
    <code id="tok" style="font-size:14px"><?= e($token['token']) ?></code> <button class="btn sm secondary" type="button" data-copy="tok">Copia</button>
    <p style="margin:10px 0 0" class="small">Des de la plataforma: <code>POST <?= e(url('api/v1/contacts')) ?></code> amb la capçalera <code>Authorization: Bearer &lt;clau&gt;</code>. Exemples a la fitxa de la connexió.</p>
</div>
<?php endif; ?>
<div class="card">
    <?php if ($connectors): ?>
    <table class="t"><thead><tr><th>Connexió</th><th>Tipus</th><th>Llista</th><th>Última execució</th><th></th></tr></thead><tbody>
    <?php foreach ($connectors as $c): ?>
        <tr>
            <td><a class="t-main" href="/sincronitzacio/<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a><?php if (!$c['active']): ?> <span class="pill">Aturada</span><?php endif; ?><div class="sub"><?= $c['mode'] === 'mirror' ? 'Mirall (treu de la llista els que ja no hi són)' : 'Afegeix i actualitza' ?></div></td>
            <td class="small"><?= e(explode(' (', Sync::TYPES[$c['type']])[0]) ?><?= $c['type'] !== 'api' ? '<div class="sub">cada ' . ((int) $c['interval_minutes'] >= 60 ? round($c['interval_minutes'] / 60, 1) . ' h' : (int) $c['interval_minutes'] . ' min') . '</div>' : '' ?></td>
            <td class="small"><?= e($c['list_name'] ?: '—') ?></td>
            <td class="small"><?php if ($c['last_run_at']): ?><span class="pill <?= $c['last_status'] === 'ok' ? 'ok' : 'err' ?>"><?= e($c['last_status'] === 'ok' ? 'Correcta' : 'Error') ?></span> <?= e(ago($c['last_run_at'])) ?><div class="sub"><?= e(mb_strimwidth((string) $c['last_message'], 0, 90, '…')) ?></div><?php else: ?><span class="muted"><?= $c['type'] === 'api' ? 'Esperant dades' : 'Mai' ?></span><?php endif; ?></td>
            <td class="num"><?php if (Auth::canEdit() && $c['type'] !== 'api'): ?><form method="post" action="/sincronitzacio/<?= (int) $c['id'] ?>/executa" class="inline"><?= csrf_field() ?><button class="btn sm secondary" data-busy="…">Executa ara</button></form><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php else: ?><div class="empty"><b>Encara no hi ha cap connexió</b>Tria com han d'arribar els contactes de cada plataforma amb els botons de dalt.</div><?php endif; ?>
</div>
