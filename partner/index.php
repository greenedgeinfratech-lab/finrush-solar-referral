<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$me = require_partner();
$pid = (int) $me['id'];

$counts = [];
foreach (all('SELECT stage, COUNT(*) n FROM leads WHERE partner_id = ? GROUP BY stage', [$pid]) as $r) $counts[$r['stage']] = (int) $r['n'];
$total = array_sum($counts);
$open = $total - ($counts['lost'] ?? 0) - ($counts['payment_received'] ?? 0) - ($counts['commission_paid'] ?? 0);
$earnedPending = (float) val("SELECT COALESCE(SUM(commission_amount),0) FROM leads WHERE partner_id = ? AND commission_status = 'pending'", [$pid]);
$paid = (float) val("SELECT COALESCE(SUM(commission_amount),0) FROM leads WHERE partner_id = ? AND commission_status = 'paid'", [$pid])
      + (float) val('SELECT COALESCE(SUM(amount),0) FROM bonuses WHERE partner_id = ?', [$pid]);
$month = date('Y-m');
[$ms, $me2] = month_range($month);
$monthSales = (float) val("SELECT COALESCE(SUM(project_value),0) FROM leads WHERE partner_id = ? AND commission_status IN ('pending','paid') AND payment_received_on >= ? AND payment_received_on < ?", [$pid, $ms, $me2]);
$next = null;
foreach (bonus_slabs() as [$min, $amt]) if ($monthSales < $min) { $next = [$min, $amt]; break; }

$recent = all("SELECT e.*, l.customer_name, l.id lid FROM lead_events e JOIN leads l ON l.id = e.lead_id
               WHERE l.partner_id = ? AND e.partner_visible = 1 ORDER BY e.id DESC LIMIT 8", [$pid]);
$link = site_origin() . url('/quote.php?ref=' . $me['code']);
$share = "Planning rooftop solar? Get a free site survey and quotation from Greenedge Infratech (with PM Surya Ghar subsidy help). Fill this short form: $link";

page_start('Dashboard', 'partner', partner_nav(), 'home');
?>
<div class="page-head">
  <div><h1>Namaste, <?= h(explode(' ', $me['name'])[0]) ?></h1><div class="muted">Partner ID <b class="mono"><?= h($me['code']) ?></b> · <span class="badge b-<?= h($me['status']) ?>"><?= h(ucfirst($me['status'])) ?></span></div></div>
  <?php if ($me['status'] === 'active'): ?><a class="btn" href="<?= h(url('/partner/lead-new.php')) ?>">+ Add a customer lead</a><?php endif; ?>
</div>

<?php if ($me['status'] === 'pending'): ?>
  <div class="alert warn"><b>Your account is awaiting approval.</b> The Finrush team will call or WhatsApp you to verify your details (usually within 2 working days). You can add leads once your account is active.</div>
<?php endif; ?>

<div class="grid g4" style="margin-bottom:16px">
  <div class="stat"><span>Total leads</span><b><?= $total ?></b></div>
  <div class="stat"><span>In progress</span><b><?= max(0, $open) ?></b></div>
  <div class="stat"><span>Commission due</span><b><?= inr($earnedPending) ?></b></div>
  <div class="stat hl"><span>Total paid to you</span><b><?= inr($paid) ?></b></div>
</div>

<div class="cols">
  <div>
    <div class="card">
      <h2>Recent updates on your leads</h2>
      <?php if (!$recent): ?><p class="muted">No updates yet. Add your first customer lead or share your referral link.</p>
      <?php else: ?><ol class="timeline">
        <?php foreach ($recent as $e): ?><li class="ev"><div class="ev-head"><a href="<?= h(url('/partner/lead.php?id=' . $e['lid'])) ?>"><b><?= h($e['customer_name']) ?></b></a> · <?= h(dt($e['created_at'], true)) ?></div>
          <?php if ($e['kind'] === 'stage'): ?><div class="ev-stage"><?= stage_badge((string) $e['to_stage']) ?></div><?php endif; ?>
          <?php if ($e['body']): ?><div class="ev-body"><?= nl2br(h($e['body'])) ?></div><?php endif; ?></li>
        <?php endforeach; ?></ol>
      <?php endif; ?>
    </div>
  </div>
  <div>
    <div class="card">
      <h2>This month's bonus progress</h2>
      <p class="small muted">Confirmed sales in <?= h(date('F Y')) ?> (installed &amp; fully paid)</p>
      <div style="font-size:1.4rem;font-weight:800;margin-top:4px"><?= inr($monthSales) ?></div>
      <?php if ($next): ?>
        <div class="progress"><i style="width:<?= min(100, round($monthSales / $next[0] * 100)) ?>%"></i></div>
        <p class="small"><?= inr($next[0] - $monthSales) ?> more to reach the <?= inr($next[0]) ?> slab and earn a <b><?= inr($next[1]) ?></b> bonus.</p>
      <?php else: ?><p class="small"><b>You've reached the top bonus slab this month.</b></p><?php endif; ?>
    </div>
    <?php if ($me['status'] === 'active'): ?>
    <div class="card">
      <h2>Your referral link</h2>
      <p class="small muted">Share it on WhatsApp. When a customer fills the form, the lead is added to your account automatically.</p>
      <div class="copy" style="margin:8px 0"><input id="reflink" readonly value="<?= h($link) ?>"><button class="btn o s" type="button" onclick="navigator.clipboard.writeText(document.getElementById('reflink').value);this.textContent='Copied'">Copy</button></div>
      <a class="btn wa s" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode($share) ?>">Share on WhatsApp</a>
    </div>
    <?php endif; ?>
    <div class="card small">
      <h2>How you earn</h2>
      <?php $tl = ['residential' => 'Homes', 'commercial' => 'Commercial / societies', 'industrial' => 'Industrial']; ?>
      <ul style="margin-left:16px"><?php foreach (commission_plans() as $pl): ?><li><?= h(($tl[$pl['type']] ?? $pl['type']) . ', ' . plan_range_label($pl) . ': ' . plan_value_label($pl)) ?></li><?php endforeach; ?></ul>
      <p style="margin-top:6px">Paid by Finrush after installation and full customer payment.</p>
    </div>
  </div>
</div>
<?php page_end();
