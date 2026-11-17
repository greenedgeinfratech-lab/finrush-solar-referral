<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
if (!is_installed()) { http_response_code(503); exit('Partner login opens soon.'); }
if (current_partner()) redirect('/partner/');

$err = '';
$mobile = '';
if (is_post()) {
    check_csrf();
    $mobile = clean_mobile(p('mobile', 15));
    $pw = (string) ($_POST['password'] ?? '');
    if (too_many('plogin', $mobile, 5, 900)) {
        $err = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $pt = valid_mobile($mobile) ? one('SELECT * FROM partners WHERE mobile = ?', [$mobile]) : null;
        if ($pt && $pt['password_hash'] && password_verify($pw, $pt['password_hash'])) {
            if (in_array($pt['status'], ['suspended', 'rejected'], true)) {
                $err = 'Your partner account is not active. Please contact the Finrush team.';
            } else {
                clear_attempts('plogin', $mobile);
                session_regenerate_id(true);
                $_SESSION['partner_id'] = (int) $pt['id'];
                if (password_needs_rehash($pt['password_hash'], PASSWORD_DEFAULT)) q('UPDATE partners SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $pt['id']]);
                q('UPDATE partners SET last_login_at = NOW() WHERE id = ?', [$pt['id']]);
                redirect($pt['must_change'] ? '/partner/profile.php?change=1' : '/partner/');
            }
        } else {
            note_attempt('plogin', $mobile ?: 'x');
            $err = 'Mobile number or password is incorrect.';
        }
    }
}
page_start('Partner login', 'public');
$wa = preg_replace('/\D/', '', (string) setting('whatsapp', '919837067681'));
?>
<div class="auth">
  <div class="card">
    <h1>Partner login</h1>
    <p class="muted" style="margin-bottom:16px">Track your referrals and earnings.</p>
    <?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="f"><label>Mobile number</label><input name="mobile" required inputmode="numeric" maxlength="13" value="<?= h($mobile) ?>" autocomplete="username" autofocus></div>
      <div class="f"><label>Password</label><input name="password" type="password" required autocomplete="current-password"></div>
      <button class="btn" type="submit" style="width:100%;justify-content:center">Log in</button>
    </form>
    <p class="small muted" style="margin-top:14px">Forgot your password? <a href="https://wa.me/<?= h($wa) ?>?text=<?= rawurlencode('Hi, I forgot my Finrush partner password. My registered mobile is ') ?>" target="_blank" rel="noopener">WhatsApp the Finrush team</a> and we'll reset it.</p>
    <p class="small" style="margin-top:8px">New here? <a href="<?= h(url('/partner/register.php')) ?>">Become a partner — it's free</a></p>
  </div>
</div>
<?php page_end();
