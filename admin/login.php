<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
if (!is_installed()) redirect('/setup.php');
if (current_user()) redirect('/admin/');
$err = ''; $mobile = '';
if (is_post()) {
    check_csrf();
    $mobile = clean_mobile(p('mobile', 15));
    $pw = (string) ($_POST['password'] ?? '');
    if (too_many('ulogin', $mobile, 5, 900)) $err = 'Too many failed attempts. Please wait 15 minutes.';
    else {
        $u = valid_mobile($mobile) ? one('SELECT * FROM users WHERE mobile = ? AND active = 1', [$mobile]) : null;
        if ($u && password_verify($pw, $u['password_hash'])) {
            clear_attempts('ulogin', $mobile);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $u['id'];
            q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$u['id']]);
            redirect($u['must_change'] ? '/admin/profile.php?change=1' : '/admin/');
        }
        note_attempt('ulogin', $mobile ?: 'x');
        $err = 'Mobile number or password is incorrect.';
    }
}
page_start('Team login', 'public');
?>
<div class="auth"><div class="card">
  <h1>Team login</h1><p class="muted" style="margin-bottom:16px">Finrush &amp; Greenedge staff only.</p>
  <?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <div class="f"><label>Mobile number</label><input name="mobile" required inputmode="numeric" maxlength="13" value="<?= h($mobile) ?>" autocomplete="username" autofocus></div>
    <div class="f"><label>Password</label><input name="password" type="password" required autocomplete="current-password"></div>
    <button class="btn" style="width:100%;justify-content:center">Log in</button>
  </form>
  <p class="small muted" style="margin-top:12px">Forgot your password? Ask an admin to reset it from the Team page.</p>
</div></div>
<?php page_end();
