<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$u = require_user();
$status = g('status'); $s = g('q', 80);
$where = []; $args = [];
if (in_array($status, ['pending', 'active', 'suspended', 'rejected'], true)) { $where[] = 'p.status = ?'; $args[] = $status; }
if ($s !== '') { $where[] = '(p.name LIKE ? OR p.mobile LIKE ? OR p.code LIKE ? OR p.city LIKE ?)'; $like = "%$s%"; array_push($args, $like, $like, $like, $like); }
$rows = all("SELECT p.*,
   (SELECT COUNT(*) FROM leads l WHERE l.partner_id = p.id) leads_total,
   (SELECT COUNT(*) FROM leads l WHERE l.partner_id = p.id AND l.commission_status IN ('pending','paid')) converted,
   (SELECT COALESCE(SUM(commission_amount),0) FROM leads l WHERE l.partner_id = p.id AND l.commission_status = 'pending') due
   FROM partners p" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY FIELD(p.status, "pending","active","suspended","rejected"), p.created_at DESC LIMIT 500', $args);
page_start('Partners', 'admin', admin_nav($u), 'partners');
?>
<div class="page-head"><div><h1>Partners</h1><div class="muted"><?= count($rows) ?> shown</div></div><a class="btn o s" href="<?= h(url('/admin/export.php?what=partners')) ?>">Export CSV</a></div>
<form class="filters" method="get"><input name="q" value="<?= h($s) ?>" placeholder="Search name, mobile, ID, city">
<select name="status"><option value="">All statuses</option><?= select_opts(['pending' => 'Pending approval', 'active' => 'Active', 'suspended' => 'Suspended', 'rejected' => 'Rejected'], $status) ?></select><button class="btn s">Filter</button></form>
<div class="tbl-wrap"><table class="t"><thead><tr><th>Partner</th><th>Mobile</th><th>City</th><th>Occupation</th><th>Status</th><th>KYC</th><th class="num">Leads</th><th class="num">Converted</th><th class="num">Due</th><th>Joined</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="10" class="empty">No partners yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr>
<td><a href="<?= h(url('/admin/partner.php?id=' . $r['id'])) ?>"><b><?= h($r['name']) ?></b></a><div class="small mono muted"><?= h($r['code']) ?></div></td>
<td class="mono small"><?= h($r['mobile']) ?></td><td><?= h($r['city']) ?></td><td class="small"><?= h($r['occupation']) ?></td>
<td><span class="badge b-<?= h($r['status']) ?>"><?= h(ucfirst($r['status'])) ?></span></td><td><span class="badge b-<?= h($r['kyc_status']) ?>"><?= h(ucfirst($r['kyc_status'])) ?></span></td>
<td class="num"><?= (int) $r['leads_total'] ?></td><td class="num"><?= (int) $r['converted'] ?></td><td class="num"><?= inr($r['due']) ?></td><td class="small"><?= h(dt($r['created_at'])) ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php page_end();
