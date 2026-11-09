<?php use Mailing\Core\Auth; ?>
<div class="page-head"><div><h1 class="page-title">Usuaris</h1><p class="page-sub">Les persones administradores veuen tots els projectes; la resta, els que se'ls assignin.</p></div><a class="btn" href="/usuaris/nou">+ Usuari nou</a></div>
<div class="card"><table class="t"><thead><tr><th>Usuari</th><th>Rol</th><th>Projectes</th><th>Últim accés</th></tr></thead><tbody>
<?php foreach ($users as $u): ?>
    <tr style="<?= $u['active'] ? '' : 'opacity:.55' ?>"><td><a class="t-main" href="/usuaris/<?= (int) $u['id'] ?>"><?= e($u['name']) ?></a><?= $u['active'] ? '' : ' <span class="pill">Desactivat</span>' ?><div class="sub"><?= e($u['email']) ?></div></td>
    <td><span class="pill <?= $u['role'] === 'admin' ? 'accent' : '' ?>"><?= e(Auth::ROLES[$u['role']] ?? $u['role']) ?></span></td>
    <td class="small"><?= $u['role'] === 'admin' ? 'Tots' : e($u['project_names'] ?: '—') ?></td>
    <td class="small muted"><?= e(ago($u['last_login_at'])) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
