<div class="page-head"><div><h1 class="page-title">El meu perfil</h1><p class="page-sub"><?= e($user['email']) ?> · <?= e(\Mailing\Core\Auth::ROLES[$user['role']] ?? $user['role']) ?></p></div></div>
<div class="card" style="max-width:560px;margin-bottom:16px"><div class="card-body">
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

<?php
use Mailing\Services\Notify;
$prefs = Notify::prefs((int) $user['id']);
?>
<div class="card" style="max-width:560px" id="avisos">
    <div class="card-head">Avisos</div>
    <div class="card-body">
        <div class="push-device" data-push-toggle>
            <div class="main"><b>Avisos en aquest dispositiu</b><div class="small muted" data-push-status><?= Notify::enabled() ? 'Comprovant…' : 'Els avisos push no estan activats en aquesta plataforma' . (\Mailing\Core\Auth::isAdmin() ? ' (<a href="/configuracio#push">Configuració</a>).' : '.') ?></div></div>
            <?php if (Notify::enabled()): ?><button class="btn sm" type="button" data-push-button hidden>Activa</button><?php endif; ?>
        </div>
        <form method="post" action="/perfil/avisos" style="margin-top:14px">
            <?= csrf_field() ?>
            <span class="field-label">Quins avisos vull</span>
            <?php foreach (Notify::KINDS as $k => [$label, $help]): if ($k === 'system' && !\Mailing\Core\Auth::isAdmin()) continue; ?>
                <label class="check"><input type="checkbox" name="kinds[]" value="<?= e($k) ?>" <?= $prefs[$k] ? 'checked' : '' ?>> <span><b><?= e($label) ?></b><br><span class="small muted"><?= e($help) ?></span></span></label>
            <?php endforeach; ?>
            <p class="small muted">Arriben a la campaneta i, si els tens activats, a aquest dispositiu. Només dels projectes on tens accés.</p>
            <div class="btns"><button class="btn" type="submit">Desa</button>
            <?php if (Notify::enabled()): ?><button class="btn secondary" type="submit" formaction="/push/prova">Envia'm un avís de prova</button><?php endif; ?></div>
        </form>
    </div>
</div>
