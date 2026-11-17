<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$me = require_partner(true);
$err = '';
if (is_post()) {
    check_csrf();
    $action = p('action', 20);
    if ($action === 'password') {
        $cur = (string) ($_POST['current'] ?? ''); $pw = (string) ($_POST['password'] ?? ''); $pw2 = (string) ($_POST['password2'] ?? '');
        if (!password_verify($cur, (string) $me['password_hash'])) $err = 'Your current password is incorrect.';
        elseif (($pe = password_problem($pw, $pw2)) !== null) $err = $pe;
        elseif ($pw === $cur) $err = 'Please choose a new password different from the current one.';
        else {
            q('UPDATE partners SET password_hash = ?, must_change = 0 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $me['id']]);
            session_regenerate_id(true);
            flash('Password changed.');
            redirect('/partner/');
        }
    } elseif ($action === 'details') {
        $email = p('email', 120); $city = p('city', 60); $district = p('district', 80);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Please enter a valid email address.';
        elseif ($city === '' || $district === '') $err = 'City and district are required.';
        else { q('UPDATE partners SET email = ?, city = ?, district = ? WHERE id = ?', [$email ?: null, $city, $district, $me['id']]); flash('Details updated.'); redirect('/partner/profile.php'); }
    }
}
page_start('Profile', 'partner', $me['must_change'] ? [] : partner_nav(), 'profile');
?>
<div class="page-head"><div><h1>Profile</h1></div></div>
<?php if ($me['must_change']): ?><div class="alert warn">Please set a new password to continue. You logged in with a temporary password.</div><?php endif; ?>
<?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
<div class="grid g2">
  <div class="card"><h2>Your details</h2>
    <dl class="kv" style="margin-bottom:14px"><dt>Partner ID</dt><dd class="mono"><?= h($me['code']) ?></dd><dt>Name</dt><dd><?= h($me['name']) ?></dd><dt>Mobile (login)</dt><dd><?= h($me['mobile']) ?></dd><dt>Status</dt><dd><span class="badge b-<?= h($me['status']) ?>"><?= h(ucfirst($me['status'])) ?></span></dd><dt>KYC</dt><dd><span class="badge b-<?= h($me['kyc_status']) ?>"><?= h(ucfirst($me['kyc_status'])) ?></span></dd>
    <?php if ($me['bank_last4']): ?><dt>Payout bank</dt><dd><?= h($me['bank_name']) ?> ••••<?= h($me['bank_last4']) ?></dd><?php endif; ?></dl>
    <?php if (!$me['must_change']): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="details">
      <div class="f"><label>Email</label><input name="email" type="email" value="<?= h($me['email']) ?>"></div>
      <div class="f"><label>City</label><input name="city" value="<?= h($me['city']) ?>" required></div>
      <div class="f"><label>District &amp; State</label><input name="district" value="<?= h($me['district']) ?>" required></div>
      <button class="btn s">Save details</button>
    </form>
    <p class="small muted" style="margin-top:10px">To change your name, mobile number, PAN or bank account, WhatsApp the Finrush team. These are verified during KYC.</p>
    <?php endif; ?>
  </div>
  <div class="card"><h2><?= $me['must_change'] ? 'Set a new password' : 'Change password' ?></h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
      <div class="f"><label><?= $me['must_change'] ? 'Temporary password' : 'Current password' ?></label><input name="current" type="password" required autocomplete="current-password"></div>
      <div class="f"><label>New password</label><input name="password" type="password" required minlength="8" autocomplete="new-password"></div>
      <div class="f"><label>Confirm new password</label><input name="password2" type="password" required minlength="8" autocomplete="new-password"></div>
      <button class="btn s">Change password</button>
    </form>
  </div>
</div>
<?php page_end();
