<?php
use Mailing\Core\Auth;
use Mailing\Services\Sync;
$api = url('api/v1/contacts');
?>
<a class="crumb" href="/sincronitzacio">← Sincronització</a>
<div class="page-head">
    <div><h1 class="page-title"><?= $c ? e($c['name']) : 'Connexió nova' ?></h1><p class="page-sub"><?= e(Sync::TYPES[$type]) ?></p></div>
    <?php if ($c): ?><div class="btns">
        <?php if ($type === 'api'): ?><form method="post" action="/sincronitzacio/<?= (int) $c['id'] ?>/token" data-confirm="Generar una clau nova? L'actual deixarà de funcionar."><?= csrf_field() ?><button class="btn secondary">Clau nova</button></form>
        <?php else: ?><form method="post" action="/sincronitzacio/<?= (int) $c['id'] ?>/executa"><?= csrf_field() ?><button class="btn secondary" data-busy="Sincronitzant…">Executa ara</button></form><?php endif; ?>
        <form method="post" action="/sincronitzacio/<?= (int) $c['id'] ?>/elimina" data-confirm="Eliminar aquesta connexió?"><?= csrf_field() ?><button class="btn danger">Elimina</button></form>
    </div><?php endif; ?>
</div>
<?php if ($c && $c['last_status'] === 'error'): ?><div class="alert err">Última execució (<?= e(ago($c['last_run_at'])) ?>): <?= e($c['last_message']) ?></div><?php endif; ?>
<div class="grid side">
<form method="post" action="<?= $c ? '/sincronitzacio/' . (int) $c['id'] : '/sincronitzacio' ?>" class="card"><div class="card-body">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <div class="row">
        <label class="field"><span>Nom</span><input class="input" name="name" required maxlength="120" value="<?= e($c['name'] ?? '') ?>" placeholder="Ex.: Clients d'Emergencing"></label>
        <label class="field"><span>Llista on van els contactes</span><select class="input" name="list_id"><option value="">— Cap (només contactes) —</option><?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) ($c['list_id'] ?? 0) === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></label>
    </div>
    <?php if (!$c): ?><label class="field"><span>…o crea una llista nova</span><input class="input" name="new_list" maxlength="120" placeholder="Nom de la llista nova"></label><?php endif; ?>

    <?php if ($type === 'mysql'): ?>
        <h2 style="margin-top:8px">Base de dades de la plataforma</h2>
        <div class="row three">
            <label class="field"><span>Servidor</span><input class="input" name="host" value="<?= e($cfg['host'] ?? 'localhost') ?>"></label>
            <label class="field"><span>Port</span><input class="input" name="port" type="number" value="<?= e($cfg['port'] ?? 3306) ?>"></label>
            <label class="field"><span>Base de dades</span><input class="input" name="database" value="<?= e($cfg['database'] ?? '') ?>" required></label>
        </div>
        <div class="row">
            <label class="field"><span>Usuari (recomanat: només lectura)</span><input class="input" name="username" value="<?= e($cfg['username'] ?? '') ?>" autocomplete="off"></label>
            <label class="field"><span>Contrasenya</span><input class="input" type="password" name="password" autocomplete="new-password" placeholder="<?= !empty($cfg['password']) ? '•••••• (desada; deixa-ho en blanc per mantenir-la)' : '' ?>"></label>
        </div>
        <label class="field"><span>Consulta SQL</span><textarea class="input code" name="query" rows="6" required placeholder="SELECT email, first_name, last_name, 'ca' AS language, id AS external_id, company AS empresa FROM users WHERE newsletter = 1"><?= e($cfg['query'] ?? '') ?></textarea>
            <small>Ha de tornar una columna <code>email</code>. Opcionals: <code>first_name</code>, <code>last_name</code>, <code>language</code> (ca/es/en/fr), <code>external_id</code>, <code>status</code> (subscribed/unsubscribed). Qualsevol altra columna es desa com a camp personalitzat. Només SELECT. Les contrasenyes es desen xifrades.</small></label>
    <?php elseif ($type === 'json'): ?>
        <h2 style="margin-top:8px">Adreça JSON</h2>
        <label class="field"><span>URL</span><input class="input" name="url" type="url" required value="<?= e($cfg['url'] ?? '') ?>" placeholder="https://plataforma.exemple.com/api/newsletter-contacts"></label>
        <div class="row three">
            <label class="field"><span>Camí a la llista (opcional)</span><input class="input" name="path" value="<?= e($cfg['path'] ?? '') ?>" placeholder="data"></label>
            <label class="field"><span>Capçalera d'autenticació</span><input class="input" name="header_name" value="<?= e($cfg['header_name'] ?? '') ?>" placeholder="Authorization"></label>
            <label class="field"><span>Valor</span><input class="input" type="password" name="header_value" autocomplete="off" placeholder="<?= !empty($cfg['header_value']) ? '•••••• (desat)' : 'Bearer …' ?>"></label>
        </div>
        <p class="small muted">Ha de tornar una llista d'objectes amb <code>email</code> (i opcionalment <code>first_name</code>, <code>last_name</code>, <code>language</code>, <code>external_id</code>, <code>fields</code>{…} o altres claus).</p>
    <?php endif; ?>

    <?php if ($type !== 'api'): ?>
        <div class="row">
            <label class="field"><span>Freqüència</span><select class="input" name="interval"><?php foreach ([60 => 'Cada hora', 180 => 'Cada 3 hores', 360 => 'Cada 6 hores', 720 => 'Cada 12 hores', 1440 => 'Un cop al dia', 10080 => 'Un cop a la setmana'] as $k => $v): ?><option value="<?= $k ?>" <?= (int) ($c['interval_minutes'] ?? 360) === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Mode</span><select class="input" name="mode"><option value="add">Afegeix i actualitza</option><option value="mirror" <?= ($c['mode'] ?? '') === 'mirror' ? 'selected' : '' ?>>Mirall: treu de la llista qui ja no hi és</option></select></label>
        </div>
    <?php endif; ?>
    <input type="hidden" name="active" value="0">
    <label class="check"><input type="checkbox" name="active" value="1" <?= ($c['active'] ?? 1) ? 'checked' : '' ?>> Activa</label>
    <div class="btns">
        <button class="btn" type="submit"><?= $c ? 'Desa' : 'Crea la connexió' ?></button>
        <?php if ($type !== 'api'): ?><button class="btn secondary" type="submit" name="test" value="1" data-busy="Sincronitzant…">Desa i executa ara</button><?php endif; ?>
    </div>
</div></form>

<div>
<?php if ($type === 'api'): ?>
    <div class="card"><div class="card-head">Com s'envien els contactes</div><div class="card-body small" style="line-height:1.6">
        <?php if ($c): ?><p style="margin-top:0">Clau actual: <code><?= e($c['token_hint']) ?>…</code> (completa només es mostra en crear-la).</p><?php endif; ?>
        <p><b>Alta o actualització</b> (un contacte o una llista de fins a 1.000):</p>
<pre class="log">curl -X POST <?= e($api) ?> \
  -H "Authorization: Bearer mlg_…" \
  -H "Content-Type: application/json" \
  -d '{"contacts":[{"email":"anna@exemple.cat","first_name":"Anna",
       "last_name":"Puig","language":"ca","external_id":"123",
       "fields":{"empresa":"Hospital X"}}]}'</pre>
        <p><b>Llistes per contacte</b>: afegeix <code>"lists": ["Compte de prova"]</code> i <code>"remove_lists": ["Compte de pagament"]</code> a cada contacte (noms o ids; les llistes que no existeixen es creen). Qui s'havia donat de baixa d'una llista no s'hi torna a subscriure.</p>
        <p><b>Esdeveniment</b> (automatitzacions): <code>POST <?= e(url('api/v1/events')) ?></code> amb <code>{"event":"registre","email":"…"}</code>.</p>
        <p><b>Baixa</b> (no rebrà més correus): <code>POST <?= e(url('api/v1/contacts/unsubscribe')) ?></code> amb <code>{"email":"…"}</code></p>
        <p><b>Treure de la llista</b>: <code>POST <?= e(url('api/v1/contacts/remove')) ?></code> amb <code>{"email":"…"}</code></p>
        <p style="margin-bottom:0">Comprovar la clau: <code>GET <?= e(url('api/v1/ping')) ?></code>. Resposta JSON amb el resultat de cada contacte.</p>
    </div></div>
<?php endif; ?>
<?php if ($history): ?>
    <div class="card"><div class="card-head">Últimes execucions</div><ul class="list"><?php foreach ($history as $h): ?>
        <li><div class="main"><b class="small"><?= e(fmt_short($h['created_at'])) ?></b><div class="sub"><?= (int) $h['total'] ?> registres · <?= (int) $h['created'] ?> nous · <?= (int) $h['updated'] ?> act. · <?= (int) $h['skipped'] ?> desc.<?= $h['removed'] ? ' · ' . (int) $h['removed'] . ' trets' : '' ?></div></div></li>
    <?php endforeach; ?></ul></div>
<?php endif; ?>
</div>
</div>
