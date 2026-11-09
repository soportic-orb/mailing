<?php use Mailing\Core\Auth; ?>
<a class="crumb" href="/usuaris">← Usuaris</a>
<div class="page-head"><div><h1 class="page-title"><?= $u ? e($u['name']) : 'Usuari nou' ?></h1></div></div>
<form method="post" action="<?= $u ? '/usuaris/' . (int) $u['id'] : '/usuaris' ?>" class="card" style="max-width:720px"><div class="card-body">
    <?= csrf_field() ?>
    <div class="row">
        <label class="field"><span>Nom</span><input class="input" name="name" required value="<?= e($u['name'] ?? old('name')) ?>"></label>
        <label class="field"><span>Correu (per entrar)</span><input class="input" type="email" name="email" required value="<?= e($u['email'] ?? old('email')) ?>"></label>
    </div>
    <div class="row">
        <label class="field"><span>Rol</span><select class="input" name="role"><?php foreach (Auth::ROLES as $k => $v): ?><option value="<?= e($k) ?>" <?= ($u['role'] ?? 'editor') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
            <small>Editor/a: crea i envia campanyes, gestiona contactes. Només consulta: veu campanyes i estadístiques.</small></label>
        <label class="field"><span><?= $u ? 'Contrasenya nova (opcional)' : 'Contrasenya' ?></span><input class="input" type="password" name="password" minlength="10" autocomplete="new-password" <?= $u ? '' : 'required' ?>><small>Mínim 10 caràcters. Comunica-la per un canal segur; l'usuari la pot canviar al seu perfil.</small></label>
    </div>
    <span class="field-label">Projectes als quals té accés (si no és administrador/a)</span>
    <div class="chips" style="margin-bottom:16px"><?php foreach ($projects as $p): ?><label class="chip"><input type="checkbox" name="projects[]" value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $mine, true) ? 'checked' : '' ?>> <?= e($p['name']) ?></label><?php endforeach; ?></div>
    <label class="check"><input type="checkbox" name="active" value="1" <?= ($u['active'] ?? 1) ? 'checked' : '' ?>> Actiu (pot entrar)</label>
    <button class="btn" type="submit">Desa</button>
</div></form>
