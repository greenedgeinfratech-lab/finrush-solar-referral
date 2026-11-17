<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$u = require_user('admin');
if (is_post()) {
    check_csrf();
    $num = fn($k, $min, $max) => is_numeric(p($k, 10)) && (float) p($k, 10) >= $min && (float) p($k, 10) <= $max;
    $slabs = [];
    for ($i = 0; $i < 6; $i++) {
        $m = str_replace([',', ' '], '', p("slab_min_$i", 15)); $a = str_replace([',', ' '], '', p("slab_amt_$i", 15));
        if ($m !== '' && $a !== '' && is_numeric($m) && is_numeric($a) && $m > 0 && $a > 0) $slabs[] = [(float) $m, (float) $a];
    }
    $emails = array_filter(array_map('trim', explode(',', p('notify_emails', 500))));
    $badEmail = array_filter($emails, fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL));
    if (!$num('rate_residential', 0, 20) || !$num('rate_commercial', 0, 20) || !$num('rate_industrial_max', 0, 20) || !$num('tds_percent', 0, 30)) flash('Check the rates — they must be numbers between 0 and 20 (TDS up to 30).', 'err');
    elseif ($badEmail) flash('Invalid email: ' . implode(', ', $badEmail), 'err');
    else {
        foreach (['rate_residential', 'rate_commercial', 'rate_industrial_max', 'tds_percent'] as $k) set_setting($k, (string) (float) p($k, 10));
        set_setting('bonus_slabs', json_encode($slabs));
        set_setting('notify_emails', implode(',', $emails));
        set_setting('whatsapp', preg_replace('/\D/', '', p('whatsapp', 15)) ?: '919837067681');
        flash('Settings saved. New rates apply to commissions calculated from now on.');
    }
    redirect('/admin/settings.php');
}
$slabs = bonus_slabs();
page_start('Settings', 'admin', admin_nav($u), 'settings');
?>
<div class="page-head"><h1>Settings</h1></div>
<form method="post"><?= csrf_field() ?>
<div class="grid g2">
<div class="card"><h2>Commission rates (% of project value excl. GST)</h2>
  <div class="f"><label>Residential, up to 10 kW</label><input name="rate_residential" value="<?= h(setting('rate_residential', '3')) ?>"></div>
  <div class="f"><label>Commercial / society / home above 10 kW, up to 100 kW</label><input name="rate_commercial" value="<?= h(setting('rate_commercial', '1.5')) ?>"></div>
  <div class="f"><label>Industrial — maximum rate (actual rate set per lead)</label><input name="rate_industrial_max" value="<?= h(setting('rate_industrial_max', '1')) ?>"></div>
  <div class="f"><label>Default TDS % (Section 194H)</label><input name="tds_percent" value="<?= h(setting('tds_percent', '2')) ?>"></div>
  <p class="small muted">Confirm the current 194H rate and threshold with your CA. Rate changes don't alter commissions already calculated.</p>
</div>
<div class="card"><h2>Monthly bonus slabs</h2>
  <p class="small muted" style="margin-bottom:8px">Partner's confirmed sales in a month (₹) → bonus (₹). Only the highest slab reached is paid.</p>
  <?php for ($i = 0; $i < 6; $i++): ?><div class="fgrid" style="margin-bottom:6px"><input name="slab_min_<?= $i ?>" placeholder="Sales from" value="<?= h(isset($slabs[$i]) ? (string) $slabs[$i][0] : '') ?>"><input name="slab_amt_<?= $i ?>" placeholder="Bonus" value="<?= h(isset($slabs[$i]) ? (string) $slabs[$i][1] : '') ?>"></div><?php endfor; ?>
</div>
<div class="card"><h2>Notifications</h2>
  <div class="f"><label>Email new registrations, leads and partner updates to (comma separated)</label><input name="notify_emails" value="<?= h(setting('notify_emails', '')) ?>"></div>
  <div class="f"><label>Programme WhatsApp number (with 91)</label><input name="whatsapp" value="<?= h(setting('whatsapp', '919837067681')) ?>"></div>
</div>
</div>
<button class="btn">Save settings</button>
</form>
<?php page_end();
