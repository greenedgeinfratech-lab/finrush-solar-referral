<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$me = require_partner();
$stage = g('stage');
$args = [(int) $me['id']];
$sql = 'SELECT * FROM leads WHERE partner_id = ?';
if ($stage !== '' && isset(STAGES[$stage])) { $sql .= ' AND stage = ?'; $args[] = $stage; }
$leads = all($sql . ' ORDER BY updated_at DESC', $args);
page_start('My leads', 'partner', partner_nav(), 'leads');
?>
<div class="page-head"><div><h1>My leads</h1><div class="muted"><?= count($leads) ?> lead(s)</div></div>
<?php if ($me['status'] === 'active'): ?><a class="btn" href="<?= h(url('/partner/lead-new.php')) ?>">+ Add lead</a><?php endif; ?></div>
<form class="filters" method="get"><select name="stage" onchange="this.form.submit()"><option value="">All stages</option><?= select_opts(array_map(fn($s) => $s[0], STAGES), $stage) ?></select></form>
<div class="tbl-wrap"><table class="t">
<thead><tr><th>Customer</th><th>City</th><th>Type</th><th>Stage</th><th class="num">Commission</th><th>Updated</th></tr></thead>
<tbody>
<?php if (!$leads): ?><tr><td colspan="6" class="empty">No leads yet.</td></tr><?php endif; ?>
<?php foreach ($leads as $l): ?>
<tr><td><a href="<?= h(url('/partner/lead.php?id=' . $l['id'])) ?>"><b><?= h($l['customer_name']) ?></b></a><div class="small muted mono"><?= h($l['ref']) ?></div></td>
<td><?= h($l['customer_city']) ?></td><td class="small"><?= h(ucfirst($l['project_type'])) ?></td><td><?= stage_badge($l['stage']) ?></td>
<td class="num"><?= $l['commission_amount'] !== null ? inr($l['commission_amount']) : '—' ?></td><td class="small"><?= h(dt($l['updated_at'])) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php page_end();
