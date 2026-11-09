<div class="card" style="max-width:640px;margin:40px auto"><div class="card-body" style="padding:30px">
    <h1 class="page-title">Benvingut/da a la plataforma de Mailing</h1>
    <?php if (\Mailing\Core\Auth::isAdmin()): ?>
        <p class="muted" style="line-height:1.6">Per començar, crea el primer <b>projecte</b> (per exemple «Emergencing» o «SysRevAI»). Cada projecte té els seus contactes, llistes, remitents i campanyes, separats de la resta.</p>
        <p class="btns"><a class="btn" href="/projectes/nou">Crea un projecte</a><a class="btn secondary" href="/configuracio">Configura Mailjet</a></p>
    <?php else: ?>
        <p class="muted" style="line-height:1.6">Encara no tens accés a cap projecte. Demana-ho a una persona administradora.</p>
    <?php endif; ?>
</div></div>
