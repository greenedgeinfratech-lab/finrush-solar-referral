<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$u = require_user('admin');
$tdsDefault = (float) setting('tds_percent', '2');

if (is_post()) {
    check_csrf();
    $a = p('action', 20);
    $date = p('paid_on', 10); $ref = p('ref', 80); $tdsPct = (float) p('tds', 6);
    $okDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) <= time() + 86400;
    if (!$okDate) { flash('Enter a valid payment date.', 'err'); redirect('/admin/payouts.php'); }
    if ($tdsPct < 0 || $tdsPct > 30) { flash('TDS % looks wrong.', 'err'); redirect('/admin/payouts.php'); }

    if ($a === 'commissions') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $n = 0;
        foreach ($ids as $lid) {
            $l = one("SELECT * FROM leads WHERE id = ? AND commission_status = 'pending'", [$lid]);
            if (!$l) continue;
            $tds = round((float) $l['commission_amount'] * $tdsPct / 100, 2);
            $net = round((float) $l['commission_amount'] - $tds, 2);
            q("UPDATE leads SET commission_status = 'paid', commission_tds = ?, commission_net = ?, commission_paid_on = ?, commission_ref = ?, stage = 'commission_paid', updated_at = NOW() WHERE id = ?",
              [$tds, $net, $date, $ref ?: null, $lid]);
            add_event($lid, 'team', (int) $u['id'], 'stage', 'Commission paid: ' . inr($l['commission_amount']) . ' − TDS ' . inr($tds) . ' = ' . inr($net) . ' net' . ($ref ? ' (ref ' . $ref . ')' : ''), true, $l['stage'], 'commission_paid');
            $n++;
        }
        flash($n ? "$n commission(s) marked as paid." : 'Select at least one commission.', $n ? 'ok' : 'err');
        redirect('/admin/payouts.php');
    }
    if ($a === 'bonus') {
        $pid = (int) p('partner_id', 10); $month = p('month', 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) { flash('Invalid month.', 'err'); redirect('/admin/payouts.php'); }
        [$ms, $me2] = month_range($month);
        $sales = (float) val("SELECT COALESCE(SUM(project_value),0) FROM leads WHERE partner_id = ? AND commission_status IN ('pending','paid') AND payment_received_on >= ? AND payment_received_on < ?", [$pid, $ms, $me2]);
        $amtRaw = str_replace([',', '₹', ' '], '', p('amount', 20));
        $amount = $amtRaw !== '' ? (float) $amtRaw : bonus_for($sales);
        if ($amount <= 0) flash('No bonus amount for this partner and month.', 'err');
        elseif (one('SELECT id FROM bonuses WHERE partner_id = ? AND month = ?', [$pid, $month])) flash('A bonus is already recorded for this month.', 'err');
        else {
            $tds = round($amount * $tdsPct / 100, 2);
            q('INSERT INTO bonuses (partner_id, month, sales, amount, tds, net, paid_on, ref, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())',
              [$pid, $month, $sales, $amount, $tds, round($amount - $tds, 2), $date, $ref ?: null, $u['id']]);
            flash('Bonus of ' . inr($amount) . ' recorded.');
        }
        redirect('/admin/payouts.php?month=' . $month . '#bonus');
    }
}

$pending = all("SELECT l.*, p.name pname, p.code pcode, p.pan, p.bank_name, p.bank_last4, p.ifsc, p.kyc_status FROM leads l JOIN partners p ON p.id = l.partner_id WHERE l.commission_status = 'pending' ORDER BY p.name, l.payment_received_on");
$reversed = all("SELECT l.*, p.name pname FROM leads l JOIN partners p ON p.id = l.partner_id WHERE l.commission_status = 'reversed' ORDER BY l.updated_at DESC");
$month = g('month', 7);
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m', strtotime('first day of last month'));
$bonusRows = all("SELECT p.id, p.name, p.code, COALESCE(SUM(l.project_value),0) sales, b.amount paid_amount, b.paid_on, b.ref
   FROM partners p JOIN leads l ON l.partner_id = p.id AND l.commission_status IN ('pending','paid') AND l.payment_received_on >= ? AND l.payment_received_on < ?
   LEFT JOIN bonuses b ON b.partner_id = p.id AND b.month = ? GROUP BY p.id, p.name, p.code, b.amount, b.paid_on, b.ref ORDER BY sales DESC", [...month_range($month), $month]);
$history = all('SELECT b.*, p.name pname FROM bonuses b JOIN partners p ON p.id = b.partner_id ORDER BY b.paid_on DESC LIMIT 50');
$totalDue = array_sum(array_map(fn($r) => (float) $r['commission_amount'], $pending));
page_start('Payouts', 'admin', admin_nav($u), 'payouts');
?>
<div class="page-head"><div><h1>Payouts</h1><div class="muted">Finrush pays partners from its own account. Record each bank transfer here so partners see it in their portal.</div></div>
<a class="btn o s" href="<?= h(url('/admin/export.php?what=payouts')) ?>">Export payout history</a></div>

<div class="card"><h2>Commissions due — <?= inr($totalDue) ?></h2>
<?php if (!$pending): ?><p class="muted">Nothing due. Commissions appear here when a lead is moved to "Payment received".</p><?php else: ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="commissions">
<div class="tbl-wrap"><table class="t"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.pc').forEach(c=>c.checked=this.checked)"></th><th>Partner</th><th>Customer</th><th>Payment received</th><th class="num">Value</th><th class="num">Rate</th><th class="num">Commission</th><th class="num">Net after TDS</th><th>Bank / PAN</th></tr></thead><tbody>
<?php foreach ($pending as $r): ?><tr>
<td><input type="checkbox" class="pc" name="ids[]" value="<?= (int) $r['id'] ?>"></td>
<td><a href="<?= h(url('/admin/partner.php?id=' . $r['partner_id'])) ?>"><?= h($r['pname']) ?></a><div class="small mono muted"><?= h($r['pcode']) ?></div></td>
<td><a href="<?= h(url('/admin/lead.php?id=' . $r['id'])) ?>"><?= h($r['customer_name']) ?></a></td><td class="small"><?= h(dt($r['payment_received_on'])) ?></td>
<td class="num"><?= inr($r['project_value']) ?></td><td class="num" title="<?= h($r['commission_basis']) ?>"><?= h(commission_rate_text($r)) ?></td><td class="num"><b><?= inr($r['commission_amount']) ?></b></td>
<td class="num"><?= inr(round($r['commission_amount'] * (1 - $tdsDefault / 100), 2)) ?></td>
<td class="small"><?= $r['bank_last4'] ? h($r['bank_name']) . ' ••' . h($r['bank_last4']) . '<br>' . h($r['ifsc']) : '<span class="badge b-pending">No bank on file</span>' ?><br><?= $r['pan'] ? '<span class="mono">' . h($r['pan']) . '</span>' : '<span class="badge b-pending">No PAN</span>' ?> <?= $r['kyc_status'] !== 'verified' ? '<span class="badge b-pending">KYC pending</span>' : '' ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="fgrid" style="margin-top:12px;max-width:640px">
  <div><label>Paid on</label><input type="date" name="paid_on" value="<?= h(date('Y-m-d')) ?>" max="<?= h(date('Y-m-d')) ?>" required></div>
  <div><label>TDS %</label><input name="tds" inputmode="decimal" value="<?= h((string) $tdsDefault) ?>"></div>
  <div class="full"><label>Bank reference / UTR</label><input name="ref" maxlength="80" placeholder="e.g. NEFT UTR number"></div>
</div>
<button class="btn" style="margin-top:10px" onclick="return confirm('Mark the selected commissions as paid?')">Mark selected as paid</button>
</form><?php endif; ?>
</div>

<div class="card" id="bonus"><h2>Monthly performance bonus</h2>
<form class="filters" method="get"><label style="align-self:center;margin:0">Month</label><input type="month" name="month" value="<?= h($month) ?>"><button class="btn s o">Show</button></form>
<p class="small muted">Sales = project value of each partner's leads with payment received in <?= h(date('F Y', strtotime($month . '-01'))) ?>. Slabs: <?php $sl = []; foreach (bonus_slabs() as [$m, $a]) $sl[] = inr($m) . '+ → ' . inr($a); echo h(implode(' · ', $sl)); ?> (highest slab only).</p>
<div class="tbl-wrap"><table class="t"><thead><tr><th>Partner</th><th class="num">Sales</th><th class="num">Bonus due</th><th>Status / record payment</th></tr></thead><tbody>
<?php if (!$bonusRows): ?><tr><td colspan="4" class="empty">No confirmed sales in this month.</td></tr><?php endif; ?>
<?php foreach ($bonusRows as $b): $due = bonus_for((float) $b['sales']); ?><tr>
<td><?= h($b['name']) ?> <span class="small mono muted"><?= h($b['code']) ?></span></td><td class="num"><?= inr($b['sales']) ?></td><td class="num"><b><?= $due ? inr($due) : '—' ?></b><?= (float) $b['sales'] >= 10000000 ? '<div class="small">+ special incentive?</div>' : '' ?></td>
<td><?php if ($b['paid_amount'] !== null): ?><span class="badge b-paid">Paid <?= inr($b['paid_amount']) ?></span> <span class="small"><?= h(dt($b['paid_on'])) ?> <?= h($b['ref']) ?></span>
<?php elseif ($due > 0 || (float) $b['sales'] >= 10000000): ?>
<form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="action" value="bonus"><input type="hidden" name="partner_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="month" value="<?= h($month) ?>">
<input name="amount" value="<?= h((string) $due) ?>" style="width:110px" title="Bonus amount"><input type="date" name="paid_on" value="<?= h(date('Y-m-d')) ?>" style="width:150px"><input name="tds" value="<?= h((string) $tdsDefault) ?>" style="width:60px" title="TDS %"><input name="ref" placeholder="UTR" style="width:120px"><button class="btn s">Record paid</button></form>
<?php else: ?><span class="muted small">Below first slab</span><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>

<?php if ($reversed): ?><div class="card"><h2>Commissions to recover</h2><p class="small muted">These were paid, but the lead was later closed (cancelled or refunded). Adjust them against the partner's next payout.</p>
<table class="t"><tbody><?php foreach ($reversed as $r): ?><tr><td><a href="<?= h(url('/admin/lead.php?id=' . $r['id'])) ?>"><?= h($r['customer_name']) ?></a> — <?= h($r['pname']) ?></td><td class="num"><?= inr($r['commission_amount']) ?></td><td class="small"><?= h($r['lost_reason']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

<div class="card"><h2>Recent bonus payments</h2>
<table class="t"><thead><tr><th>Partner</th><th>Month</th><th class="num">Sales</th><th class="num">Bonus</th><th class="num">Net</th><th>Paid</th></tr></thead><tbody>
<?php if (!$history): ?><tr><td colspan="6" class="empty">None yet.</td></tr><?php endif; ?>
<?php foreach ($history as $b): ?><tr><td><?= h($b['pname']) ?></td><td><?= h($b['month']) ?></td><td class="num"><?= inr($b['sales']) ?></td><td class="num"><?= inr($b['amount']) ?></td><td class="num"><?= inr($b['net']) ?></td><td class="small"><?= h(dt($b['paid_on'])) ?> <?= h($b['ref']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php page_end();
