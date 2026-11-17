<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$u = require_user();
$byStage = [];
foreach (all('SELECT stage, COUNT(*) n FROM leads GROUP BY stage') as $r) $byStage[$r['stage']] = (int) $r['n'];
$pendingPartners = (int) val("SELECT COUNT(*) FROM partners WHERE status = 'pending'");
$activePartners = (int) val("SELECT COUNT(*) FROM partners WHERE status = 'active'");
$newThisMonth = (int) val("SELECT COUNT(*) FROM leads WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')");
$dueTotal = (float) val("SELECT COALESCE(SUM(commission_amount),0) FROM leads WHERE commission_status = 'pending'");
$salesMonth = (float) val("SELECT COALESCE(SUM(project_value),0) FROM leads WHERE commission_status IN ('pending','paid') AND payment_received_on >= DATE_FORMAT(NOW(),'%Y-%m-01')");
$mine = all("SELECT l.*, p.name pname FROM leads l LEFT JOIN partners p ON p.id = l.partner_id WHERE l.assigned_to = ? AND l.stage NOT IN ('lost','commission_paid','payment_received') ORDER BY l.updated_at ASC LIMIT 10", [$u['id']]);
$unassigned = all("SELECT l.*, p.name pname FROM leads l LEFT JOIN partners p ON p.id = l.partner_id WHERE l.assigned_to IS NULL AND l.stage = 'new' ORDER BY l.created_at ASC LIMIT 10");
$activity = all("SELECT e.*, l.customer_name, l.id lid, COALESCE(us.name, pa.name) actor_name FROM lead_events e JOIN leads l ON l.id = e.lead_id
                 LEFT JOIN users us ON e.actor_type = 'team' AND us.id = e.actor_id LEFT JOIN partners pa ON e.actor_type = 'partner' AND pa.id = e.actor_id
                 ORDER BY e.id DESC LIMIT 12");
page_start('Dashboard', 'admin', admin_nav($u), 'home');
?>
<div class="page-head"><div><h1>Dashboard</h1><div class="muted"><?= h(date('l, d F Y')) ?></div></div>
<div class="actions"><a class="btn o" href="<?= h(url('/admin/lead-new.php')) ?>">+ Add lead</a><a class="btn" href="<?= h(url('/admin/leads.php')) ?>">All leads</a></div></div>
<?php if ($pendingPartners): ?><div class="alert warn"><b><?= $pendingPartners ?> partner(s)</b> waiting for approval. <a href="<?= h(url('/admin/partners.php?status=pending')) ?>">Review now →</a></div><?php endif; ?>
<div class="grid g4" style="margin-bottom:16px">
  <div class="stat"><span>New leads this month</span><b><?= $newThisMonth ?></b></div>
  <div class="stat"><span>Active partners</span><b><?= $activePartners ?></b></div>
  <div class="stat"><span>Confirmed sales this month</span><b><?= inr($salesMonth) ?></b></div>
  <div class="stat hl"><span>Commission due to partners</span><b><?= inr($dueTotal) ?></b></div>
</div>
<div class="card"><h2>Pipeline</h2><div class="actions">
<?php foreach (STAGES as $k => [$label]): ?><a class="badge st-<?= h($k) ?>" style="padding:6px 12px;text-decoration:none" href="<?= h(url('/admin/leads.php?stage=' . $k)) ?>"><?= h($label) ?>: <?= $byStage[$k] ?? 0 ?></a><?php endforeach; ?>
</div></div>
<div class="cols">
<div>
  <div class="card"><h2>Unassigned new leads</h2>
  <?php if (!$unassigned): ?><p class="muted">None. 🎉</p><?php else: ?><table class="t"><tbody>
  <?php foreach ($unassigned as $l): ?><tr><td><a href="<?= h(url('/admin/lead.php?id=' . $l['id'])) ?>"><b><?= h($l['customer_name']) ?></b></a> · <?= h($l['customer_city']) ?><div class="small muted">from <?= h($l['pname'] ?: 'website') ?> · <?= h(dt($l['created_at'], true)) ?></div></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?></div>
  <div class="card"><h2>My open leads (oldest update first)</h2>
  <?php if (!$mine): ?><p class="muted">No open leads assigned to you.</p><?php else: ?><table class="t"><tbody>
  <?php foreach ($mine as $l): ?><tr><td><a href="<?= h(url('/admin/lead.php?id=' . $l['id'])) ?>"><b><?= h($l['customer_name']) ?></b></a> <?= stage_badge($l['stage']) ?><div class="small muted">last update <?= h(dt($l['updated_at'], true)) ?></div></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?></div>
</div>
<div class="card"><h2>Recent activity</h2><ol class="timeline">
<?php foreach ($activity as $e): ?><li class="ev<?= !$e['partner_visible'] ? ' ev-internal' : '' ?>"><div class="ev-head"><b><?= h($e['actor_name'] ?: ucfirst($e['actor_type'])) ?></b> on <a href="<?= h(url('/admin/lead.php?id=' . $e['lid'])) ?>"><?= h($e['customer_name']) ?></a> · <?= h(dt($e['created_at'], true)) ?></div>
<?php if ($e['kind'] === 'stage'): ?><div class="ev-stage"><?= stage_badge((string) $e['to_stage']) ?></div><?php endif; ?>
<?php if ($e['body']): ?><div class="ev-body small"><?= h(mb_strimwidth($e['body'], 0, 140, '…')) ?></div><?php endif; ?></li><?php endforeach; ?>
<?php if (!$activity): ?><p class="muted">No activity yet.</p><?php endif; ?>
</ol></div>
</div>
<?php page_end();
