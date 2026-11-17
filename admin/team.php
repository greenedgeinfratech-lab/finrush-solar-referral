<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$u = require_user('admin');
if (is_post()) {
    check_csrf();
    $a = p('action', 20);
    if ($a === 'add') {
        $name = p('name', 80); $mobile = clean_mobile(p('mobile', 15)); $email = p('email', 120);
        $role = p('role', 10) === 'admin' ? 'admin' : 'staff'; $org = p('org', 40) === 'Greenedge' ? 'Greenedge' : 'Finrush';
        if ($name === '' || !valid_mobile($mobile)) flash('Name and a valid mobile are required.', 'err');
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Invalid email.', 'err');
        elseif (one('SELECT id FROM users WHERE mobile = ?', [$mobile])) flash('A team member with this mobile already exists.', 'err');
        else {
            $tmp = temp_password();
            q('INSERT INTO users (name, mobile, email, org, role, password_hash, must_change, active, created_at) VALUES (?,?,?,?,?,?,1,1,NOW())',
              [$name, $mobile, $email ?: null, $org, $role, password_hash($tmp, PASSWORD_DEFAULT)]);
            $_SESSION['team_tmp'] = [$name, $mobile, $tmp];
            flash("$name added.");
        }
    } elseif (in_array($a, ['toggle', 'reset', 'role'], true)) {
        $tid = (int) p('id', 10);
        $t = one('SELECT * FROM users WHERE id = ?', [$tid]);
        if (!$t) flash('Not found.', 'err');
        elseif ($tid === (int) $u['id'] && $a !== 'reset') flash('You cannot change your own access here.', 'err');
        elseif ($a === 'toggle') { q('UPDATE users SET active = 1 - active WHERE id = ?', [$tid]); flash($t['name'] . ($t['active'] ? ' deactivated.' : ' reactivated.')); }
        elseif ($a === 'role') { q('UPDATE users SET role = ? WHERE id = ?', [$t['role'] === 'admin' ? 'staff' : 'admin', $tid]); flash('Role updated.'); }
        else { $tmp = temp_password(); q('UPDATE users SET password_hash = ?, must_change = 1 WHERE id = ?', [password_hash($tmp, PASSWORD_DEFAULT), $tid]); clear_attempts('ulogin', $t['mobile']); $_SESSION['team_tmp'] = [$t['name'], $t['mobile'], $tmp]; flash('Password reset.'); }
    }
    redirect('/admin/team.php');
}
$tmp = $_SESSION['team_tmp'] ?? null; unset($_SESSION['team_tmp']);
$rows = all('SELECT * FROM users ORDER BY active DESC, name');
page_start('Team', 'admin', admin_nav($u), 'team');
?>
<div class="page-head"><div><h1>Team</h1><div class="muted">Finrush and Greenedge staff who can work on leads. Admins also manage payouts, team and settings.</div></div></div>
<?php if ($tmp): ?><div class="alert info">Login for <?= h($tmp[0]) ?>: mobile <b><?= h($tmp[1]) ?></b>, temporary password <b class="mono" style="font-size:1.1rem"><?= h($tmp[2]) ?></b> — shown only once. <a target="_blank" rel="noopener" href="<?= h(wa_link($tmp[1], "Your Finrush team login:\nMobile: {$tmp[1]}\nTemporary password: {$tmp[2]}\nLog in at " . site_origin() . url('/admin/login.php') . ' and set a new password.')) ?>">Send on WhatsApp</a></div><?php endif; ?>
<div class="cols">
<div class="tbl-wrap"><table class="t"><thead><tr><th>Name</th><th>Mobile</th><th>Company</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><b><?= h($r['name']) ?></b><div class="small muted"><?= h($r['email']) ?></div></td><td class="mono small"><?= h($r['mobile']) ?></td><td><?= h($r['org']) ?></td><td><?= h(ucfirst($r['role'])) ?></td>
<td><span class="badge <?= $r['active'] ? 'b-active' : 'b-suspended' ?>"><?= $r['active'] ? 'Active' : 'Inactive' ?></span></td><td class="small"><?= h(dt($r['last_login_at'], true)) ?></td>
<td class="actions"><?php if ((int) $r['id'] !== (int) $u['id']): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn o s"><?= $r['role'] === 'admin' ? 'Make staff' : 'Make admin' ?></button></form>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn o s <?= $r['active'] ? 'red' : '' ?>"><?= $r['active'] ? 'Deactivate' : 'Reactivate' ?></button></form>
<?php endif; ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn o s">Reset password</button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="card"><h2>Add team member</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
  <div class="f"><label>Name</label><input name="name" required></div>
  <div class="f"><label>Mobile (login)</label><input name="mobile" required inputmode="numeric"></div>
  <div class="f"><label>Email</label><input name="email" type="email"></div>
  <div class="f"><label>Company</label><select name="org"><option>Finrush</option><option>Greenedge</option></select></div>
  <div class="f"><label>Role</label><select name="role"><option value="staff">Staff — leads &amp; partners</option><option value="admin">Admin — everything</option></select></div>
  <button class="btn s">Add &amp; create temporary password</button>
</form></div>
</div>
<?php page_end();
