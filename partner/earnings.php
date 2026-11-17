<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$me = require_partner();
$pid = (int) $me['id'];
$rows = all("SELECT * FROM leads WHERE partner_id = ? AND commission_status <> 'none' ORDER BY payment_received_on DESC, id DESC", [$pid]);
$bon = all('SELECT * FROM bonuses WHERE partner_id = ? ORDER BY month DESC', [$pid]);
$due = 0; $paidGross = 0; $paidNet = 0; $tds = 0;
foreach ($rows as $r) {
    if ($r['commission_status'] === 'pending') $due += (float) $r['commission_amount'];
    if ($r['commission_status'] === 'paid') { $paidGross += (float) $r['commission_amount']; $paidNet += (float) $r['commission_net']; $tds += (float) $r['commission_tds']; }
}
foreach ($bon as $b) { $paidGross += (float) $b['amount']; $paidNet += (float) $b['net']; $tds += (float) $b['tds']; }
page_start('Earnings', 'partner', partner_nav(), 'earnings');
?>
<div class="page-head"><div><h1>Earnings</h1><div class="muted">Commissions and bonuses paid by Finrush Smart Services Pvt. Ltd.</div></div></div>
<div class="grid g4" style="margin-bottom:16px">
  <div class="stat"><span>Commission due</span><b><?= inr($due) ?></b></div>
  <div class="stat"><span>Total earned (gross)</span><b><?= inr($paidGross) ?></b></div>
  <div class="stat"><span>TDS deducted</span><b><?= inr($tds) ?></b></div>
  <div class="stat hl"><span>Net paid to you</span><b><?= inr($paidNet) ?></b></div>
</div>
<div class="card"><h2>Commissions</h2>
<div class="tbl-wrap"><table class="t"><thead><tr><th>Customer</th><th>Payment received</th><th class="num">Project value</th><th class="num">Rate</th><th class="num">Commission</th><th>Status</th><th class="num">TDS</th><th class="num">Net paid</th><th>Paid on / ref</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="empty">No commissions yet. Commission is earned when a referred project is installed and fully paid.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr>
<td><a href="<?= h(url('/partner/lead.php?id=' . $r['id'])) ?>"><?= h($r['customer_name']) ?></a></td><td><?= h(dt($r['payment_received_on'])) ?></td>
<td class="num"><?= inr($r['project_value']) ?></td><td class="num"><?= h(rtrim(rtrim((string) $r['commission_rate'], '0'), '.')) ?>%</td><td class="num"><b><?= inr($r['commission_amount']) ?></b></td>
<td><span class="badge b-<?= h($r['commission_status']) ?>"><?= h(ucfirst($r['commission_status'])) ?></span></td>
<td class="num"><?= $r['commission_status'] === 'paid' ? inr($r['commission_tds']) : '—' ?></td><td class="num"><?= $r['commission_status'] === 'paid' ? inr($r['commission_net']) : '—' ?></td>
<td class="small"><?= $r['commission_status'] === 'paid' ? h(dt($r['commission_paid_on'])) . '<br><span class="mono">' . h($r['commission_ref']) . '</span>' : '—' ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="card"><h2>Monthly performance bonuses</h2>
<div class="tbl-wrap"><table class="t"><thead><tr><th>Month</th><th class="num">Confirmed sales</th><th class="num">Bonus</th><th class="num">TDS</th><th class="num">Net paid</th><th>Paid on / ref</th></tr></thead><tbody>
<?php if (!$bon): ?><tr><td colspan="6" class="empty">No bonuses yet.</td></tr><?php endif; ?>
<?php foreach ($bon as $b): ?><tr><td><?= h(date('F Y', strtotime($b['month'] . '-01'))) ?></td><td class="num"><?= inr($b['sales']) ?></td><td class="num"><b><?= inr($b['amount']) ?></b></td><td class="num"><?= inr($b['tds']) ?></td><td class="num"><?= inr($b['net']) ?></td><td class="small"><?= h(dt($b['paid_on'])) ?> <span class="mono"><?= h($b['ref']) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="small muted" style="margin-top:10px">Bonus slabs: <?php $s = []; foreach (bonus_slabs() as [$min, $amt]) $s[] = inr($min) . '+ → ' . inr($amt); echo h(implode(' · ', $s)); ?>. Only the highest slab reached in a month is paid. TDS is deducted under Section 194H; Finrush issues TDS certificates.</p>
</div>
<?php page_end();
