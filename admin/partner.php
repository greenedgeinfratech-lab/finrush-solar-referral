<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$u = require_user();
$id = (int) g('id');
$pt = one('SELECT * FROM partners WHERE id = ?', [$id]);
if (!$pt) { flash('Partner not found.', 'err'); redirect('/admin/partners.php'); }
$isAdmin = $u['role'] === 'admin';

if (is_post()) {
    check_csrf();
    $a = p('action', 20);
    if ($a === 'status') {
        $to = p('to', 12);
        $allowed = $isAdmin ? ['active', 'rejected', 'suspended', 'pending'] : ['active', 'rejected'];
        if (!in_array($to, $allowed, true)) flash('You cannot set that status.', 'err');
        else {
            q('UPDATE partners SET status = ?' . ($to === 'active' && !$pt['approved_at'] ? ', approved_by = ?, approved_at = NOW()' : '') . ' WHERE id = ?',
              $to === 'active' && !$pt['approved_at'] ? [$to, $u['id'], $id] : [$to, $id]);
            if ($to === 'active' && $pt['email']) send_mail([$pt['email']], 'Your Finrush partner account is active',
                "Namaste {$pt['name']},\n\nYour Finrush Solar Referral Programme account is now active. Your Partner ID is {$pt['code']}.\n\nLog in with your mobile number to add leads and share your referral link: " . site_origin() . url('/partner/login.php') . "\n\nFinrush Smart Services Pvt. Ltd.");
            flash('Partner status set to ' . $to . '.');
        }
    } elseif ($a === 'details') {
        $name = p('name', 80); $mobile = clean_mobile(p('mobile', 15)); $email = p('email', 120);
        $pan = strtoupper(p('pan', 10)); $last4 = preg_replace('/\D/', '', p('bank_last4', 4)); $ifsc = strtoupper(p('ifsc', 11));
        if ($name === '' || !valid_mobile($mobile)) flash('Name and a valid mobile are required.', 'err');
        elseif ($mobile !== $pt['mobile'] && one('SELECT id FROM partners WHERE mobile = ? AND id <> ?', [$mobile, $id])) flash('Another partner already uses this mobile.', 'err');
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Invalid email.', 'err');
        elseif ($pan !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) flash('PAN format looks wrong (e.g. ABCDE1234F).', 'err');
        elseif ($ifsc !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) flash('IFSC format looks wrong (e.g. SBIN0001234).', 'err');
        else {
            q('UPDATE partners SET name=?, mobile=?, email=?, city=?, district=?, occupation=?, pan=?, bank_name=?, bank_last4=?, ifsc=?, kyc_status=?, team_notes=? WHERE id=?',
              [$name, $mobile, $email ?: null, p('city', 60), p('district', 80), p('occupation', 40), $pan ?: null, p('bank_name', 80) ?: null, $last4 ?: null, $ifsc ?: null,
               p('kyc_status', 10) === 'verified' ? 'verified' : 'pending', p('team_notes', 2000) ?: null, $id]);
            flash('Partner details saved.');
        }
    } elseif ($a === 'delete') {
        $hasMoney = (int) val("SELECT COUNT(*) FROM leads WHERE partner_id = ? AND commission_status <> 'none'", [$id]) + (int) val('SELECT COUNT(*) FROM bonuses WHERE partner_id = ?', [$id]);
        if (!$isAdmin) flash('Only admins can delete partners.', 'err');
        elseif ($hasMoney) flash('This partner has commission due or paid, so they cannot be deleted — that is your payout and TDS record. Suspend them instead.', 'err');
        elseif (strtoupper(p('confirm_code', 12)) !== $pt['code']) flash('To delete, type the partner ID (' . $pt['code'] . ') in the box.', 'err');
        else {
            foreach (all('SELECT id FROM leads WHERE partner_id = ?', [$id]) as $lr) {
                add_event((int) $lr['id'], 'team', (int) $u['id'], 'update', 'Partner ' . $pt['name'] . ' (' . $pt['code'] . ') was deleted; this is now a direct lead.', false);
            }
            q('UPDATE leads SET partner_id = NULL WHERE partner_id = ?', [$id]);
            q('DELETE FROM partners WHERE id = ?', [$id]);
            clear_attempts('plogin', $pt['mobile']);
            flash('Partner ' . $pt['name'] . ' (' . $pt['code'] . ') was deleted.');
            redirect('/admin/partners.php');
        }
    } elseif ($a === 'reset') {
        $tmp = temp_password();
        q('UPDATE partners SET password_hash = ?, must_change = 1 WHERE id = ?', [password_hash($tmp, PASSWORD_DEFAULT), $id]);
        clear_attempts('plogin', $pt['mobile']);
        $_SESSION['temp_pw'] = [$id, $tmp];
        flash('Temporary password created. Share it with the partner privately; they must change it when they log in.');
    }
    redirect('/admin/partner.php?id=' . $id);
}

$tmp = null;
if (!empty($_SESSION['temp_pw']) && $_SESSION['temp_pw'][0] === $id) { $tmp = $_SESSION['temp_pw'][1]; unset($_SESSION['temp_pw']); }
$leads = all('SELECT * FROM leads WHERE partner_id = ? ORDER BY updated_at DESC', [$id]);
$due = 0; $paid = 0; foreach ($leads as $l) { if ($l['commission_status'] === 'pending') $due += (float) $l['commission_amount']; if ($l['commission_status'] === 'paid') $paid += (float) $l['commission_amount']; }
$paid += (float) val('SELECT COALESCE(SUM(amount),0) FROM bonuses WHERE partner_id = ?', [$id]);
$occ = ['Electrician', 'Builder / Contractor', 'Property dealer', 'Architect / Engineer', 'Solar installer', 'Shop / Business owner', 'Sales professional', 'Other'];
page_start($pt['name'], 'admin', admin_nav($u), 'partners');
?>
<div class="page-head"><div><a class="small" href="<?= h(url('/admin/partners.php')) ?>">← Partners</a><h1><?= h($pt['name']) ?> <span class="badge b-<?= h($pt['status']) ?>"><?= h(ucfirst($pt['status'])) ?></span></h1>
<div class="muted small"><span class="mono"><?= h($pt['code']) ?></span> · joined <?= h(dt($pt['created_at'])) ?> · last login <?= h(dt($pt['last_login_at'], true)) ?></div></div>
<div class="actions">
  <a class="btn wa s" target="_blank" rel="noopener" href="<?= h(wa_link($pt['mobile'], 'Namaste ' . $pt['name'] . ', this is the Finrush referral team.')) ?>">WhatsApp</a>
  <a class="btn o s" href="<?= h(url('/admin/lead-new.php?partner=' . $id)) ?>">+ Lead for this partner</a>
</div></div>
<?php if ($tmp): ?><div class="alert info">Temporary password for <?= h($pt['name']) ?>: <b class="mono" style="font-size:1.1rem"><?= h($tmp) ?></b> — shown only once. <a target="_blank" rel="noopener" href="<?= h(wa_link($pt['mobile'], "Your Finrush partner login:\nMobile: {$pt['mobile']}\nTemporary password: $tmp\nLog in at " . site_origin() . url('/partner/login.php') . " and set a new password.")) ?>">Send on WhatsApp</a></div><?php endif; ?>
<div class="grid g4" style="margin-bottom:16px">
  <div class="stat"><span>Leads</span><b><?= count($leads) ?></b></div>
  <div class="stat"><span>Converted</span><b><?= count(array_filter($leads, fn($l) => in_array($l['commission_status'], ['pending', 'paid'], true))) ?></b></div>
  <div class="stat"><span>Commission due</span><b><?= inr($due) ?></b></div>
  <div class="stat hl"><span>Total paid (incl. bonus)</span><b><?= inr($paid) ?></b></div>
</div>
<div class="cols">
<div>
  <div class="card"><h2>Account</h2>
    <div class="actions">
    <?php foreach (($isAdmin ? ['active' => 'Approve / activate', 'rejected' => 'Reject', 'suspended' => 'Suspend', 'pending' => 'Set pending'] : ['active' => 'Approve / activate', 'rejected' => 'Reject']) as $st => $label): if ($st === $pt['status']) continue; ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="to" value="<?= h($st) ?>"><button class="btn s <?= in_array($st, ['rejected', 'suspended'], true) ? 'red o' : ($st === 'active' ? '' : 'o') ?>"><?= h($label) ?></button></form>
    <?php endforeach; ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><button class="btn o s">Reset password</button></form>
    </div>

    <?php if ($pt['message']): ?><p class="small" style="margin-top:12px"><b>Their message:</b> <?= nl2br(h($pt['message'])) ?></p><?php endif; ?>
  </div>
  <?php if ($isAdmin): $money = (int) val("SELECT COUNT(*) FROM leads WHERE partner_id = ? AND commission_status <> 'none'", [$id]) + (int) val('SELECT COUNT(*) FROM bonuses WHERE partner_id = ?', [$id]); ?>
  <div class="card" id="delete" style="border-color:#f3c2c2"><h2 style="color:#b42318">Delete partner</h2>
    <?php if ($money): ?>
      <p class="small">This partner has commission due or paid, so they can't be deleted — that's your payout and TDS record. Use <b>Suspend</b> above to block their login instead.</p>
    <?php else: ?>
      <p class="small muted">Permanently removes <?= h($pt['name']) ?>'s account and login. <?= $leads ? count($leads) . ' lead(s) stay in the CRM as direct leads.' : '' ?> This cannot be undone.</p>
      <form method="post" class="actions" style="margin-top:8px" onsubmit="return confirm('Permanently delete this partner? This cannot be undone.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <input name="confirm_code" placeholder="Type <?= h($pt['code']) ?> to confirm" style="max-width:220px" autocomplete="off"><button class="btn red s">Delete partner</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="card"><h2>Leads</h2>
    <div class="tbl-wrap"><table class="t"><thead><tr><th>Customer</th><th>Stage</th><th class="num">Commission</th><th>Updated</th></tr></thead><tbody>
    <?php if (!$leads): ?><tr><td colspan="4" class="empty">No leads yet.</td></tr><?php endif; ?>
    <?php foreach ($leads as $l): ?><tr><td><a href="<?= h(url('/admin/lead.php?id=' . $l['id'])) ?>"><?= h($l['customer_name']) ?></a> <span class="small muted"><?= h($l['customer_city']) ?></span></td><td><?= stage_badge($l['stage']) ?></td><td class="num"><?= $l['commission_status'] !== 'none' ? inr($l['commission_amount']) . ' <span class="badge b-' . h($l['commission_status']) . '">' . h($l['commission_status']) . '</span>' : '—' ?></td><td class="small"><?= h(dt($l['updated_at'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<div class="card"><h2>Details &amp; KYC</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="details">
    <div class="f"><label>Name</label><input name="name" value="<?= h($pt['name']) ?>" required></div>
    <div class="f"><label>Mobile (login)</label><input name="mobile" value="<?= h($pt['mobile']) ?>" required></div>
    <div class="f"><label>Email</label><input name="email" type="email" value="<?= h($pt['email']) ?>"></div>
    <div class="fgrid"><div class="f"><label>City</label><input name="city" value="<?= h($pt['city']) ?>"></div><div class="f"><label>District &amp; State</label><input name="district" value="<?= h($pt['district']) ?>"></div></div>
    <div class="f"><label>Occupation</label><select name="occupation"><?= select_opts(array_combine($occ, $occ), $pt['occupation']) ?></select></div>
    <hr style="border:0;border-top:1px solid var(--line);margin:8px 0 12px">
    <div class="f"><label>KYC status</label><select name="kyc_status"><?= select_opts(['pending' => 'Pending', 'verified' => 'Verified'], $pt['kyc_status']) ?></select></div>
    <div class="f"><label>PAN (for TDS)</label><input name="pan" value="<?= h($pt['pan']) ?>" maxlength="10" style="text-transform:uppercase"></div>
    <div class="fgrid"><div class="f"><label>Bank name</label><input name="bank_name" value="<?= h($pt['bank_name']) ?>"></div><div class="f"><label>Account no. — last 4 digits only</label><input name="bank_last4" value="<?= h($pt['bank_last4']) ?>" maxlength="4" inputmode="numeric"></div></div>
    <div class="f"><label>IFSC</label><input name="ifsc" value="<?= h($pt['ifsc']) ?>" maxlength="11" style="text-transform:uppercase"></div>
    <div class="f"><label>Team notes (internal)</label><textarea name="team_notes" rows="3"><?= h($pt['team_notes']) ?></textarea></div>
    <button class="btn s">Save</button>
    <p class="small muted" style="margin-top:8px">Keep full bank account numbers and Aadhaar copies in your accounts system, not here.</p>
  </form>
</div>
</div>
<?php page_end();
