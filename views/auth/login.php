<span class="brand" style="margin-bottom:18px"><span class="brand-mark" aria-hidden="true"><?= brand_icon() ?></span><span class="brand-name">Mailing<small>Soportic</small></span></span>
<h1>Inicia la sessió</h1>
<p class="muted" style="margin:0 0 18px">Plataforma d'enviaments de Soportic.</p>
<form method="post" action="/login">
    <?= csrf_field() ?>
    <label class="field"><span>Correu electrònic</span><input class="input" type="email" name="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="username"></label>
    <label class="field"><span>Contrasenya</span><input class="input" type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn" type="submit" style="width:100%">Entra</button>
</form>
