<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$u = require_user(null, true);
$err = '';
if (is_post()) {
    check_csrf();
    $cur = (string) ($_POST['current'] ?? ''); $pw = (string) ($_POST['password'] ?? ''); $pw2 = (string) ($_POST['password2'] ?? '');
    if (!password_verify($cur, $u['password_hash'])) $err = 'Your current password is incorrect.';
    elseif (($pe = password_problem($pw, $pw2)) !== null) $err = $pe;
    elseif ($pw === $cur) $err = 'Please choose a new password different from the current one.';
    else {
        q('UPDATE users SET password_hash = ?, must_change = 0 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        flash('Password changed.');
        redirect('/admin/');
    }
}
page_start('My account', 'admin', $u['must_change'] ? [] : admin_nav($u), 'profile');
?>
<div class="page-head"><h1>My account</h1></div>
<?php if ($u['must_change']): ?><div class="alert warn">Please set a new password to continue.</div><?php endif; ?>
<?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
<div class="grid g2">
<div class="card"><h2>Details</h2><dl class="kv"><dt>Name</dt><dd><?= h($u['name']) ?></dd><dt>Mobile (login)</dt><dd><?= h($u['mobile']) ?></dd><dt>Email</dt><dd><?= h($u['email'] ?: '—') ?></dd><dt>Company</dt><dd><?= h($u['org']) ?></dd><dt>Role</dt><dd><?= h(ucfirst($u['role'])) ?></dd></dl></div>
<div class="card"><h2>Change password</h2>
<form method="post"><?= csrf_field() ?>
  <div class="f"><label><?= $u['must_change'] ? 'Temporary password' : 'Current password' ?></label><input type="password" name="current" required autocomplete="current-password"></div>
  <div class="f"><label>New password</label><input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
  <div class="f"><label>Confirm new password</label><input type="password" name="password2" required minlength="8" autocomplete="new-password"></div>
  <button class="btn s">Change password</button>
</form></div></div>
<?php page_end();
