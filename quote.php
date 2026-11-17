<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
if (!is_installed()) redirect('/');

$refCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', g('ref', 12) ?: p('ref', 12)));
$partner = $refCode !== '' ? one("SELECT * FROM partners WHERE code = ? AND status = 'active'", [$refCode]) : null;
$v = ['customer_name' => '', 'customer_phone' => '', 'customer_city' => '', 'project_type' => 'residential', 'size_info' => '', 'pm_surya_ghar' => 'Not sure', 'notes' => ''];
$err = ''; $done = false;
if (is_post()) {
    check_csrf();
    foreach ($v as $k => $_) $v[$k] = p($k, $k === 'notes' ? 800 : 80);
    $v['customer_phone'] = clean_mobile($v['customer_phone']);
    if (trim((string) ($_POST['website'] ?? '')) !== '') { $done = true; }
    elseif (too_many('quote', client_ip(), 5, 3600)) $err = 'Too many requests from this network. Please call or WhatsApp us instead.';
    elseif ($v['customer_name'] === '' || $v['customer_city'] === '' || !isset(PROJECT_TYPES[$v['project_type']])) $err = 'Please fill all required fields.';
    elseif (!valid_mobile($v['customer_phone'])) $err = 'Please enter a valid 10-digit mobile number.';
    elseif (p('consent', 5) !== 'yes') $err = 'Please tick the box so we can contact you.';
    else {
        note_attempt('quote', client_ip());
        if ($existing = open_lead_for_phone($v['customer_phone'])) {
            add_event((int) $existing['id'], 'system', null, 'note', 'Customer filled the quote form again' . ($partner ? ' (via link of ' . $partner['code'] . ')' : '') . ($v['notes'] !== '' ? ': ' . $v['notes'] : ''), false);
        } else {
            $ref = new_lead_ref();
            q("INSERT INTO leads (ref, partner_id, customer_name, customer_phone, customer_city, project_type, size_info, pm_surya_ghar, notes, stage, source, created_at, updated_at)
               VALUES (?,?,?,?,?,?,?,?,?,'new',?,NOW(),NOW())",
              [$ref, $partner['id'] ?? null, $v['customer_name'], $v['customer_phone'], $v['customer_city'], $v['project_type'], $v['size_info'] ?: null, $v['pm_surya_ghar'], $v['notes'] ?: null, $partner ? 'ref-link' : 'website']);
            $lid = (int) db()->lastInsertId();
            add_event($lid, 'system', null, 'stage', 'Customer submitted the quote form' . ($partner ? ' using your referral link' : ''), true, null, 'new');
            send_mail(notify_emails(), 'Finrush: new quote request ' . $v['customer_name'] . ' (' . $v['customer_city'] . ')' . ($partner ? ' via ' . $partner['name'] : ''),
                "Ref: $ref\nCustomer: {$v['customer_name']}\nMobile: {$v['customer_phone']}\nCity: {$v['customer_city']}\nType: " . PROJECT_TYPES[$v['project_type']] . "\nSize/bill: {$v['size_info']}\nPM Surya Ghar: {$v['pm_surya_ghar']}\nNotes: {$v['notes']}\nPartner: " . ($partner ? "{$partner['name']} ({$partner['code']})" : 'none (direct)') . "\n\nOpen: " . site_origin() . url('/admin/lead.php?id=' . $lid) . "\n");
        }
        $done = true;
    }
}
page_start('Get a free solar quote', 'public');
?>
<div class="auth" style="max-width:600px">
<div class="card">
<?php if ($done): ?>
  <h1>Thank you!</h1>
  <p>Our solar team will call you shortly to understand your needs and book a free site survey.</p>
  <p class="small muted" style="margin-top:10px">Solar systems are supplied and installed by Greenedge Infratech Pvt. Ltd., Aligarh.</p>
<?php else: ?>
  <h1>Get a free rooftop solar quote</h1>
  <p class="muted" style="margin-bottom:14px">Free site survey, quotation and help with PM Surya Ghar subsidy paperwork. Systems installed by Greenedge Infratech.<?= $partner ? ' Referred by <b>' . h($partner['name']) . '</b>.' : '' ?></p>
  <?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="ref" value="<?= h($refCode) ?>">
    <div style="position:absolute;left:-9999px" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
    <div class="fgrid">
      <div><label>Your name *</label><input name="customer_name" required value="<?= h($v['customer_name']) ?>" autocomplete="name"></div>
      <div><label>Mobile *</label><input name="customer_phone" required inputmode="numeric" value="<?= h($v['customer_phone']) ?>" autocomplete="tel-national"></div>
      <div><label>City / Town *</label><input name="customer_city" required value="<?= h($v['customer_city']) ?>"></div>
      <div><label>Where do you need solar? *</label><select name="project_type"><?= select_opts(['residential' => 'Home', 'commercial' => 'Shop / office / school / society', 'industrial' => 'Factory / industry', 'unknown' => 'Not sure'], $v['project_type']) ?></select></div>
      <div><label>Monthly electricity bill or size</label><input name="size_info" value="<?= h($v['size_info']) ?>" placeholder="e.g. ₹3,000 or 3 kW"></div>
      <div><label>Interested in PM Surya Ghar subsidy?</label><select name="pm_surya_ghar"><?= select_opts(['Not sure' => 'Not sure', 'Yes' => 'Yes', 'No' => 'No'], $v['pm_surya_ghar']) ?></select></div>
      <div class="full"><label>Anything else?</label><textarea name="notes" rows="2"><?= h($v['notes']) ?></textarea></div>
      <div class="full"><label class="check"><input type="checkbox" name="consent" value="yes" required> <span>I agree to be contacted by Finrush and Greenedge Infratech about rooftop solar. See our <a href="<?= h(url('/privacy.html')) ?>" target="_blank">Privacy Policy</a>.</span></label></div>
    </div>
    <button class="btn" style="margin-top:12px">Request free quote</button>
  </form>
<?php endif; ?>
</div></div>
<?php page_end();
