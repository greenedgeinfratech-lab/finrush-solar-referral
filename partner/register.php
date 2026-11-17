<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
if (!is_installed()) { http_response_code(503); exit('Registration opens soon.'); }
if (current_partner()) redirect('/partner/');

$occupations = ['Electrician', 'Builder / Contractor', 'Property dealer', 'Architect / Engineer', 'Solar installer', 'Shop / Business owner', 'Sales professional', 'Other'];
$err = '';
$v = ['name' => '', 'mobile' => '', 'email' => '', 'city' => '', 'district' => '', 'occupation' => '', 'message' => ''];

if (is_post()) {
    check_csrf();
    foreach ($v as $k => $_) $v[$k] = p($k, $k === 'message' ? 500 : 120);
    $v['mobile'] = clean_mobile($v['mobile']);
    $pw = (string) ($_POST['password'] ?? ''); $pw2 = (string) ($_POST['password2'] ?? '');
    if (trim((string) ($_POST['website'] ?? '')) !== '') { redirect('/'); } // honeypot
    if (too_many('register', client_ip(), 6, 3600)) $err = 'Too many registrations from this network. Please try again later or WhatsApp us.';
    elseif ($v['name'] === '' || $v['city'] === '' || $v['district'] === '' || !in_array($v['occupation'], $occupations, true)) $err = 'Please fill all required fields.';
    elseif (!valid_mobile($v['mobile'])) $err = 'Please enter a valid 10-digit Indian mobile number.';
    elseif ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err = 'Please enter a valid email address or leave it blank.';
    elseif (($pe = password_problem($pw, $pw2)) !== null) $err = $pe;
    elseif (p('consent', 5) !== 'yes') $err = 'Please tick the consent box to continue.';
    elseif (one('SELECT id FROM partners WHERE mobile = ?', [$v['mobile']])) $err = 'This mobile number is already registered. Please log in instead.';
    else {
        note_attempt('register', client_ip());
        q('INSERT INTO partners (name, mobile, email, city, district, occupation, message, status, password_hash, consent_at, source, created_at)
           VALUES (?,?,?,?,?,?,?,\'pending\',?,NOW(),\'web\',NOW())',
          [$v['name'], $v['mobile'], $v['email'] ?: null, $v['city'], $v['district'], $v['occupation'], $v['message'] ?: null, password_hash($pw, PASSWORD_DEFAULT)]);
        $id = (int) db()->lastInsertId();
        q('UPDATE partners SET code = ? WHERE id = ?', [partner_code($id), $id]);
        session_regenerate_id(true);
        $_SESSION['partner_id'] = $id;
        send_mail(notify_emails(), 'Finrush: new referral partner ' . $v['name'] . ' (' . $v['city'] . ')',
            "A new partner registered and is waiting for approval.\n\nPartner ID: " . partner_code($id) . "\nName: {$v['name']}\nMobile: {$v['mobile']}\nEmail: {$v['email']}\nCity: {$v['city']}\nDistrict/State: {$v['district']}\nOccupation: {$v['occupation']}\nMessage: {$v['message']}\n\nApprove: " . site_origin() . url('/admin/partner.php?id=' . $id) . "\n");
        flash('Welcome! Your registration is received. Our team will verify your details and activate your account within 2 working days.');
        redirect('/partner/');
    }
}

page_start('Become a partner', 'public');
?>
<div class="auth" style="max-width:620px">
  <div class="card">
    <h1>Become a referral partner</h1>
    <p class="muted" style="margin-bottom:16px">Free to join. Earn 3% on every home solar system you refer that gets installed and paid. Already registered? <a href="<?= h(url('/partner/login.php')) ?>">Log in</a></p>
    <?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <?= csrf_field() ?>
      <div style="position:absolute;left:-9999px" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
      <div class="fgrid">
        <div><label>Full name *</label><input name="name" required maxlength="80" value="<?= h($v['name']) ?>" autocomplete="name"></div>
        <div><label>Mobile (WhatsApp) * — this is your login</label><input name="mobile" required inputmode="numeric" maxlength="13" value="<?= h($v['mobile']) ?>" placeholder="10-digit mobile" autocomplete="tel-national"></div>
        <div><label>Email</label><input name="email" type="email" maxlength="120" value="<?= h($v['email']) ?>" autocomplete="email"></div>
        <div><label>City / Town *</label><input name="city" required maxlength="60" value="<?= h($v['city']) ?>"></div>
        <div><label>District &amp; State *</label><input name="district" required maxlength="80" value="<?= h($v['district']) ?>" placeholder="e.g. Aligarh, Uttar Pradesh"></div>
        <div><label>Occupation *</label><select name="occupation" required><option value="">Select…</option><?= select_opts(array_combine($occupations, $occupations), $v['occupation']) ?></select></div>
        <div><label>Create password *</label><input name="password" type="password" required minlength="8" autocomplete="new-password" placeholder="8+ characters, letters &amp; numbers"></div>
        <div><label>Confirm password *</label><input name="password2" type="password" required minlength="8" autocomplete="new-password"></div>
        <div class="full"><label>Anything you'd like to tell us?</label><textarea name="message" rows="2" maxlength="500"><?= h($v['message']) ?></textarea></div>
        <div class="full"><label class="check"><input type="checkbox" name="consent" value="yes" required> <span>I am 18 or older. I agree to the <a href="<?= h(url('/terms.html')) ?>" target="_blank">Terms &amp; Conditions</a> and <a href="<?= h(url('/privacy.html')) ?>" target="_blank">Privacy Policy</a>, and I understand that income is not guaranteed and depends only on installed and paid solar projects. *</span></label></div>
      </div>
      <button class="btn" type="submit" style="margin-top:14px">Register as partner</button>
    </form>
  </div>
</div>
<?php page_end();
