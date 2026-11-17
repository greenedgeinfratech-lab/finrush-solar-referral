<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$me = require_partner();
if ($me['status'] !== 'active') { flash('You can add leads once your account is approved.', 'err'); redirect('/partner/'); }

$v = ['customer_name' => '', 'customer_phone' => '', 'customer_city' => '', 'project_type' => 'residential', 'size_info' => '', 'pm_surya_ghar' => 'Not sure', 'notes' => ''];
$err = '';
if (is_post()) {
    check_csrf();
    foreach ($v as $k => $_) $v[$k] = p($k, $k === 'notes' ? 1000 : 80);
    $v['customer_phone'] = clean_mobile($v['customer_phone']);
    if ($v['customer_name'] === '' || $v['customer_city'] === '' || !isset(PROJECT_TYPES[$v['project_type']])) $err = 'Please fill all required fields.';
    elseif (!valid_mobile($v['customer_phone'])) $err = "Please enter the customer's valid 10-digit mobile number.";
    elseif ($v['customer_phone'] === $me['mobile']) $err = 'You cannot refer yourself.';
    elseif (p('consent', 5) !== 'yes') $err = 'Please confirm the customer has agreed to be contacted.';
    elseif (too_many('plead', (string) $me['id'], 30, 3600)) $err = 'Too many leads added in the last hour. Please try again later.';
    elseif ($dup = open_lead_for_phone($v['customer_phone'])) $err = ((int) $dup['partner_id'] === (int) $me['id']) ? 'You have already referred this customer (' . $dup['ref'] . ').' : 'This customer has already been referred or is already in touch with us, so it cannot be credited as a new referral.';
    else {
        note_attempt('plead', (string) $me['id']);
        $ref = new_lead_ref();
        q("INSERT INTO leads (ref, partner_id, customer_name, customer_phone, customer_city, project_type, size_info, pm_surya_ghar, notes, stage, source, created_at, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,'new','partner',NOW(),NOW())",
          [$ref, $me['id'], $v['customer_name'], $v['customer_phone'], $v['customer_city'], $v['project_type'], $v['size_info'] ?: null, $v['pm_surya_ghar'], $v['notes'] ?: null]);
        $lid = (int) db()->lastInsertId();
        add_event($lid, 'partner', (int) $me['id'], 'stage', $v['notes'] !== '' ? 'Lead added: ' . $v['notes'] : 'Lead added by partner', true, null, 'new');
        send_mail(notify_emails(), 'Finrush: new lead ' . $v['customer_name'] . ' (' . $v['customer_city'] . ') from ' . $me['name'],
            "New lead from partner {$me['name']} ({$me['code']}, {$me['mobile']}).\n\nRef: $ref\nCustomer: {$v['customer_name']}\nMobile: {$v['customer_phone']}\nCity: {$v['customer_city']}\nType: " . PROJECT_TYPES[$v['project_type']] . "\nSize/bill: {$v['size_info']}\nPM Surya Ghar: {$v['pm_surya_ghar']}\nNotes: {$v['notes']}\n\nOpen: " . site_origin() . url('/admin/lead.php?id=' . $lid) . "\n");
        flash('Lead added (' . $ref . '). Our team will contact the customer soon and you will see every update here.');
        redirect('/partner/lead.php?id=' . $lid);
    }
}
page_start('Add lead', 'partner', partner_nav(), 'new');
?>
<div class="auth" style="max-width:640px;margin-top:0">
<div class="card">
  <h1>Add a customer lead</h1>
  <p class="muted small" style="margin-bottom:14px">Only add customers who have agreed to be contacted. The first valid referral for each customer mobile number gets the credit.</p>
  <?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="fgrid">
      <div><label>Customer name *</label><input name="customer_name" required maxlength="80" value="<?= h($v['customer_name']) ?>"></div>
      <div><label>Customer mobile *</label><input name="customer_phone" required inputmode="numeric" maxlength="13" value="<?= h($v['customer_phone']) ?>"></div>
      <div><label>City / Town *</label><input name="customer_city" required maxlength="80" value="<?= h($v['customer_city']) ?>"></div>
      <div><label>Project type *</label><select name="project_type"><?= select_opts(PROJECT_TYPES, $v['project_type']) ?></select></div>
      <div><label>Approx. size (kW) or monthly bill</label><input name="size_info" maxlength="60" value="<?= h($v['size_info']) ?>" placeholder="e.g. 3 kW or ₹2,500 bill"></div>
      <div><label>Interested in PM Surya Ghar subsidy?</label><select name="pm_surya_ghar"><?= select_opts(['Not sure' => 'Not sure', 'Yes' => 'Yes', 'No' => 'No'], $v['pm_surya_ghar']) ?></select></div>
      <div class="full"><label>Notes for our team</label><textarea name="notes" rows="3" maxlength="1000" placeholder="Best time to call, roof type, anything useful"><?= h($v['notes']) ?></textarea></div>
      <div class="full"><label class="check"><input type="checkbox" name="consent" value="yes" required> <span>The customer has agreed to be contacted by Finrush and Greenedge Infratech about solar, and I have not promised any subsidy, price or savings. *</span></label></div>
    </div>
    <button class="btn" style="margin-top:14px">Submit lead</button>
  </form>
</div></div>
<?php page_end();
