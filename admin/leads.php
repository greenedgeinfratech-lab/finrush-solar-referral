<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$u = require_user();
$stage = g('stage'); $assigned = g('assigned'); $partner = (int) g('partner'); $s = g('q', 80); $type = g('type');
$where = []; $args = [];
if ($stage === 'open') $where[] = "l.stage NOT IN ('lost','commission_paid')";
elseif ($stage !== '' && isset(STAGES[$stage])) { $where[] = 'l.stage = ?'; $args[] = $stage; }
if ($assigned === 'me') { $where[] = 'l.assigned_to = ?'; $args[] = $u['id']; }
elseif ($assigned === 'none') $where[] = 'l.assigned_to IS NULL';
elseif (ctype_digit($assigned)) { $where[] = 'l.assigned_to = ?'; $args[] = (int) $assigned; }
if ($partner) { $where[] = 'l.partner_id = ?'; $args[] = $partner; }
if ($type !== '' && isset(PROJECT_TYPES[$type])) { $where[] = 'l.project_type = ?'; $args[] = $type; }
if ($s !== '') { $where[] = '(l.customer_name LIKE ? OR l.customer_phone LIKE ? OR l.customer_city LIKE ? OR l.ref LIKE ? OR p.name LIKE ?)'; $like = '%' . $s . '%'; array_push($args, $like, $like, $like, $like, $like); }
$sql = 'SELECT l.*, p.name pname, p.code pcode, us.name uname FROM leads l LEFT JOIN partners p ON p.id = l.partner_id LEFT JOIN users us ON us.id = l.assigned_to'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY l.updated_at DESC LIMIT 500';
$leads = all($sql, $args);
$team = all('SELECT id, name FROM users WHERE active = 1 ORDER BY name');
$qs = http_build_query(array_filter(['stage' => $stage, 'assigned' => $assigned, 'partner' => $partner ?: '', 'q' => $s, 'type' => $type]));
page_start('Leads', 'admin', admin_nav($u), 'leads');
?>
<div class="page-head"><div><h1>Leads</h1><div class="muted"><?= count($leads) ?> shown</div></div>
<div class="actions"><a class="btn o s" href="<?= h(url('/admin/export.php?what=leads&' . $qs)) ?>">Export CSV</a><a class="btn" href="<?= h(url('/admin/lead-new.php')) ?>">+ Add lead</a></div></div>
<form class="filters" method="get">
  <input name="q" value="<?= h($s) ?>" placeholder="Search name, mobile, city, ref, partner">
  <select name="stage"><option value="">All stages</option><option value="open"<?= $stage === 'open' ? ' selected' : '' ?>>All open</option><?= select_opts(array_map(fn($x) => $x[0], STAGES), $stage) ?></select>
  <select name="type"><option value="">All types</option><?= select_opts(PROJECT_TYPES, $type) ?></select>
  <select name="assigned"><option value="">Anyone</option><option value="me"<?= $assigned === 'me' ? ' selected' : '' ?>>Assigned to me</option><option value="none"<?= $assigned === 'none' ? ' selected' : '' ?>>Unassigned</option><?php foreach ($team as $t): ?><option value="<?= (int) $t['id'] ?>"<?= $assigned === (string) $t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?></select>
  <?php if ($partner): ?><input type="hidden" name="partner" value="<?= $partner ?>"><?php endif; ?>
  <button class="btn s">Filter</button> <a class="small" href="<?= h(url('/admin/leads.php')) ?>">Clear</a>
</form>
<div class="tbl-wrap"><table class="t">
<thead><tr><th>Customer</th><th>Mobile</th><th>City</th><th>Type</th><th>Partner</th><th>Stage</th><th>Assigned</th><th class="num">Value</th><th>Updated</th></tr></thead><tbody>
<?php if (!$leads): ?><tr><td colspan="9" class="empty">No leads match.</td></tr><?php endif; ?>
<?php foreach ($leads as $l): ?><tr>
<td><a href="<?= h(url('/admin/lead.php?id=' . $l['id'])) ?>"><b><?= h($l['customer_name']) ?></b></a><div class="small muted mono"><?= h($l['ref']) ?></div></td>
<td class="mono small"><?= h($l['customer_phone']) ?></td><td><?= h($l['customer_city']) ?></td><td class="small"><?= h(ucfirst($l['project_type'])) ?></td>
<td class="small"><?= $l['pname'] ? '<a href="' . h(url('/admin/partner.php?id=' . $l['partner_id'])) . '">' . h($l['pname']) . '</a><br><span class="mono muted">' . h($l['pcode']) . '</span>' : '<span class="muted">Direct</span>' ?></td>
<td><?= stage_badge($l['stage']) ?></td><td class="small"><?= h($l['uname'] ?: '—') ?></td><td class="num"><?= inr($l['project_value']) ?></td><td class="small"><?= h(dt($l['updated_at'])) ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php page_end();
