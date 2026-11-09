<?php use Mailing\Core\Settings; ?>
<div class="page-head"><div><h1 class="page-title">Configuració</h1><p class="page-sub">Paràmetres comuns a tots els projectes. Les claus es desen xifrades (AES-256-GCM).</p></div></div>
<div class="grid two">
<div>
    <form method="post" action="/configuracio" class="card" id="mailjet"><?= csrf_field() ?><input type="hidden" name="section" value="mailjet">
        <div class="card-head">Mailjet (enviament) <a href="https://app.mailjet.com/account/apikeys" target="_blank" rel="noopener">On són les claus? ↗</a></div>
        <div class="card-body">
            <div class="row">
                <label class="field"><span>API Key</span><input class="input" name="mailjet_key" autocomplete="off" placeholder="<?= e(Settings::masked('mailjet_key') ?: 'Clau pública') ?>"></label>
                <label class="field"><span>Secret Key</span><input class="input" type="password" name="mailjet_secret" autocomplete="new-password" placeholder="<?= Settings::has('mailjet_secret') ? '•••••• desada' : 'Clau secreta' ?>"></label>
            </div>
            <label class="field"><span>Ritme màxim d'enviament</span><input class="input" type="number" name="send_rate" min="50" max="10000" value="<?= e(Settings::get('send_rate', '600')) ?>"><small>Correus per minut (segons el pla de Mailjet). Una campanya de 300 correus surt en menys d'un minut.</small></label>
            <p class="small muted" style="margin-top:0">Deixa un camp en blanc per mantenir la clau; escriu «-» per treure-la. Cada projecte pot tenir un compte propi (a Projectes).</p>
            <div class="btns"><button class="btn" type="submit">Desa</button></div>
        </div>
    </form>
    <div class="card">
        <div class="card-head">Seguiment (webhooks de Mailjet)</div>
        <div class="card-body">
            <p class="small" style="margin-top:0;line-height:1.55">Mailjet avisa la plataforma de cada lliurament, obertura, clic, rebot, queixa i baixa. Els rebots durs i les queixes exclouen el contacte automàticament.</p>
            <label class="field"><span>Adreça per als webhooks</span><input class="input mono" id="wh" readonly value="<?= e($webhook) ?>"></label>
            <div class="btns">
                <button class="btn secondary" type="button" data-copy="wh">Copia</button>
                <form method="post" action="/configuracio/webhooks"><?= csrf_field() ?><button class="btn" type="submit" <?= Settings::has('mailjet_key') ? '' : 'disabled' ?>>Configura-ho a Mailjet automàticament</button></form>
                <form method="post" action="/configuracio/prova-mailjet"><?= csrf_field() ?><button class="btn secondary" type="submit" <?= Settings::has('mailjet_key') ? '' : 'disabled' ?>>Prova la connexió</button></form>
            </div>
            <p class="small muted" style="margin-bottom:0">O a mà: Mailjet › Account settings › Event notifications (webhooks): posa aquesta adreça a <i>sent, open, click, bounce, spam, blocked, unsub</i>.</p>
        </div>
    </div>
    <div class="card" id="cron">
        <div class="card-head">Tasca programada (cron) <?= $cronAge !== null && $cronAge < 600 ? '<span class="pill ok">Funciona · ' . e(ago(Settings::get('cron_last_real'))) . '</span>' : '<span class="pill warn">No detectada</span>' ?></div>
        <div class="card-body small" style="line-height:1.55">
            <p style="margin-top:0">Envia les campanyes programades, la cua i les sincronitzacions. A <b>CloudPanel › Sites › mailing.soportic.es › Cron Jobs</b>, afegeix (cada minut):</p>
            <input class="input mono" id="cronl" readonly value="<?= e($cronLine) ?>">
            <div class="btns" style="margin:8px 0"><button class="btn sm secondary" type="button" data-copy="cronl">Copia</button></div>
            <p>Si el servidor no permet cron, un servei extern (p. ex. cron-job.org) pot cridar cada minut: <code><?= e($cronUrl) ?></code></p>
            <p style="margin-bottom:0" class="muted">Sense cron, els enviaments avancen igualment quan algú té la plataforma oberta, però amb retard.</p>
        </div>
    </div>
</div>
<div>
    <form method="post" action="/configuracio" class="card" id="ai"><?= csrf_field() ?><input type="hidden" name="section" value="ai">
        <div class="card-head">IA a l'editor (textos, traducció i imatges)</div>
        <div class="card-body">
            <label class="check"><input type="checkbox" name="ai_enabled" value="1" <?= Settings::get('ai_enabled', '1') !== '0' ? 'checked' : '' ?>> Activa la IA a l'editor</label>
            <label class="field"><span>Clau d'Anthropic (Claude) — textos i traducció</span><input class="input" type="password" name="anthropic_key" autocomplete="new-password" placeholder="<?= e(Settings::masked('anthropic_key') ?: 'sk-ant-…') ?>"></label>
            <label class="field"><span>Clau d'OpenAI — imatges (i textos)</span><input class="input" type="password" name="openai_key" autocomplete="new-password" placeholder="<?= e(Settings::masked('openai_key') ?: 'sk-…') ?>"></label>
            <div class="row">
                <label class="field"><span>Servei per defecte per als textos</span><select class="input" name="ai_default_text"><option value="claude">Claude</option><option value="openai" <?= Settings::get('ai_default_text') === 'openai' ? 'selected' : '' ?>>OpenAI</option></select></label>
                <label class="field"><span>Model de Claude</span><input class="input" name="claude_model" value="<?= e(Settings::get('claude_model', 'claude-opus-5-5')) ?>"></label>
            </div>
            <div class="row">
                <label class="field"><span>Model de text d'OpenAI</span><input class="input" name="openai_text_model" value="<?= e(Settings::get('openai_text_model', 'gpt-5')) ?>"></label>
                <label class="field"><span>Model d'imatges d'OpenAI</span><input class="input" name="openai_image_model" value="<?= e(Settings::get('openai_image_model', 'gpt-image-1')) ?>"></label>
            </div>
            <div class="btns"><button class="btn" type="submit">Desa</button></div>
        </div>
    </form>
    <form method="post" action="/configuracio/prova-ia" class="btns" style="margin:-6px 0 16px"><?= csrf_field() ?><button class="btn secondary" type="submit" data-busy="Provant…">Prova els serveis d'IA</button></form>
    <form method="post" action="/configuracio" class="card" id="update"><?= csrf_field() ?><input type="hidden" name="section" value="update">
        <div class="card-head">Origen de les actualitzacions (OTA)</div>
        <div class="card-body">
            <div class="row">
                <label class="field"><span>Repositori de GitHub</span><input class="input" name="update_repo" value="<?= e(Settings::get('update_repo', 'soportic-orb/mailing')) ?>"></label>
                <label class="field"><span>Branca</span><input class="input" name="update_branch" value="<?= e(Settings::get('update_branch', 'main')) ?>"></label>
            </div>
            <label class="field"><span>Token (només si el repositori és privat)</span><input class="input" type="password" name="update_token" autocomplete="new-password" placeholder="<?= Settings::has('update_token') ? '•••••• desat («-» per treure\'l)' : 'No cal: el repositori és públic' ?>"></label>
            <button class="btn" type="submit">Desa</button>
        </div>
    </form>
</div>
</div>
