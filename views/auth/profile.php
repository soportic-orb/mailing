<div class="page-head"><div><h1 class="page-title">El meu perfil</h1><p class="page-sub"><?= e($user['email']) ?> · <?= e(\Mailing\Core\Auth::ROLES[$user['role']] ?? $user['role']) ?></p></div></div>
<div class="card" style="max-width:560px"><div class="card-body">
<form method="post" action="/perfil">
    <?= csrf_field() ?>
    <label class="field"><span>Nom</span><input class="input" name="name" value="<?= e($user['name']) ?>" required maxlength="120"></label>
    <h2 style="margin-top:20px">Canvia la contrasenya</h2>
    <label class="field"><span>Contrasenya actual</span><input class="input" type="password" name="current" autocomplete="current-password"></label>
    <div class="row">
        <label class="field"><span>Contrasenya nova</span><input class="input" type="password" name="password" minlength="10" autocomplete="new-password"><small>Mínim 10 caràcters. Deixa-ho en blanc per no canviar-la.</small></label>
        <label class="field"><span>Repeteix-la</span><input class="input" type="password" name="password2" autocomplete="new-password"></label>
    </div>
    <button class="btn" type="submit">Desa</button>
</form>
</div></div>
