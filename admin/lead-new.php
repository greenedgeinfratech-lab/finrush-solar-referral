<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$u = require_user();
$partners = all("SELECT id, name, code FROM partners WHERE status = 'active' ORDER BY name");
$pOpts = ['0' => '— Direct lead (no partner) —']; foreach ($partners as $pt) $pOpts[$pt['id']] = $pt['name'] . ' (' . $pt['code'] . ')';
$team = all('SELECT id, name FROM users WHERE active = 1 ORDER BY name');
$tOpts = ['0' => '— Unassigned —']; foreach ($team as $t) $tOpts[$t['id']] = $t['name'];
$v = ['partner_id' => (string) (int) g('partner'), 'customer_name' => '', 'customer_phone' => '', 'customer_city' => '', 'project_type' => 'residential', 'size_info' => '', 'pm_surya_ghar' => 'Not sure', 'notes' => '', 'assigned_to' => (string) $u['id']];
$err = '';
if (is_post()) {
    check_csrf();
    foreach ($v as $k => $_) $v[$k] = p($k, $k === 'notes' ? 1000 : 80);
    $v['customer_phone'] = clean_mobile($v['customer_phone']);
    $pid = (int) $v['partner_id']; $aid = (int) $v['assigned_to'];
    if ($v['customer_name'] === '' || !valid_mobile($v['customer_phone']) || !isset(PROJECT_TYPES[$v['project_type']])) $err = 'Name, a valid 10-digit mobile and project type are required.';
    elseif ($pid && !isset($pOpts[$pid])) $err = 'Choose an active partner.';
    elseif ($d = open_lead_for_phone($v['customer_phone'])) $err = 'An open lead already exists for this mobile: ' . $d['ref'] . '.';
    else {
        $ref = new_lead_ref();
        q("INSERT INTO leads (ref, partner_id, customer_name, customer_phone, customer_city, project_type, size_info, pm_surya_ghar, notes, stage, assigned_to, source, created_at, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,'new',?,'team',NOW(),NOW())",
          [$ref, $pid ?: null, $v['customer_name'], $v['customer_phone'], $v['customer_city'], $v['project_type'], $v['size_info'] ?: null, $v['pm_surya_ghar'], $v['notes'] ?: null, isset($tOpts[$aid]) && $aid ? $aid : null]);
        $lid = (int) db()->lastInsertId();
        add_event($lid, 'team', (int) $u['id'], 'stage', 'Lead added by the Finrush team' . ($v['notes'] !== '' ? ': ' . $v['notes'] : ''), true, null, 'new');
        flash('Lead ' . $ref . ' created.');
        redirect('/admin/lead.php?id=' . $lid);
    }
}
page_start('Add lead', 'admin', admin_nav($u), 'leads');
?>
<div class="auth" style="max-width:680px;margin-top:0"><div class="card">
<h1>Add a lead</h1><p class="muted small" style="margin-bottom:12px">For leads received by phone, walk-in or WhatsApp. Choose a partner only if they referred this customer.</p>
<?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?><div class="fgrid">
  <div class="full"><label>Referred by partner</label><select name="partner_id"><?= select_opts($pOpts, $v['partner_id']) ?></select></div>
  <div><label>Customer name *</label><input name="customer_name" required value="<?= h($v['customer_name']) ?>"></div>
  <div><label>Customer mobile *</label><input name="customer_phone" required inputmode="numeric" value="<?= h($v['customer_phone']) ?>"></div>
  <div><label>City</label><input name="customer_city" value="<?= h($v['customer_city']) ?>"></div>
  <div><label>Project type *</label><select name="project_type"><?= select_opts(PROJECT_TYPES, $v['project_type']) ?></select></div>
  <div><label>Size / bill</label><input name="size_info" value="<?= h($v['size_info']) ?>"></div>
  <div><label>PM Surya Ghar</label><select name="pm_surya_ghar"><?= select_opts(['Not sure' => 'Not sure', 'Yes' => 'Yes', 'No' => 'No'], $v['pm_surya_ghar']) ?></select></div>
  <div class="full"><label>Assign to</label><select name="assigned_to"><?= select_opts($tOpts, $v['assigned_to']) ?></select></div>
  <div class="full"><label>Notes</label><textarea name="notes" rows="3"><?= h($v['notes']) ?></textarea></div>
</div><button class="btn" style="margin-top:12px">Create lead</button></form>
</div></div>
<?php page_end();
