<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$me = require_partner();
$id = (int) g('id');
$l = one('SELECT * FROM leads WHERE id = ? AND partner_id = ?', [$id, $me['id']]);
if (!$l) { http_response_code(404); flash('Lead not found.', 'err'); redirect('/partner/leads.php'); }

if (is_post()) {
    check_csrf();
    $note = p('note', 1500);
    if ($note === '') flash('Please write an update before saving.', 'err');
    elseif ($l['stage'] === 'commission_paid' || $l['stage'] === 'lost') flash('This lead is closed. Please WhatsApp the team for any changes.', 'err');
    else {
        add_event($id, 'partner', (int) $me['id'], 'note', $note, true);
        send_mail(notify_emails(), 'Finrush: partner update on ' . $l['customer_name'] . ' (' . $l['ref'] . ')',
            "{$me['name']} ({$me['code']}) added an update:\n\n$note\n\nOpen: " . site_origin() . url('/admin/lead.php?id=' . $id) . "\n");
        flash('Update added. Our team has been notified.');
    }
    redirect('/partner/lead.php?id=' . $id);
}

$events = all('SELECT * FROM lead_events WHERE lead_id = ? AND partner_visible = 1 ORDER BY id DESC', [$id]);
$order = array_keys(STAGES);
$pos = array_search($l['stage'], $order, true);
page_start($l['customer_name'], 'partner', partner_nav(), 'leads');
?>
<div class="page-head"><div><a class="small" href="<?= h(url('/partner/leads.php')) ?>">← My leads</a><h1><?= h($l['customer_name']) ?></h1><div class="muted mono small"><?= h($l['ref']) ?> · added <?= h(dt($l['created_at'])) ?></div></div><div><?= stage_badge($l['stage']) ?></div></div>
<div class="cols">
<div>
  <div class="card">
    <h2>Progress</h2>
    <?php if ($l['stage'] === 'lost'): ?>
      <div class="alert warn">This lead was closed<?= $l['lost_reason'] ? ': ' . h($l['lost_reason']) : '' ?>.</div>
    <?php else: ?>
      <div class="stepper"><?php foreach (array_slice($order, 0, 8) as $i => $s): ?><span class="<?= $i <= $pos ? 'done' : '' ?>" title="<?= h(stage_label($s)) ?>"></span><?php endforeach; ?></div>
      <p><b><?= h(stage_label($l['stage'])) ?></b> — <?= h(STAGES[$l['stage']][1] ?? '') ?></p>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Add an update</h2>
    <form method="post"><?= csrf_field() ?>
      <textarea name="note" rows="3" maxlength="1500" placeholder="e.g. Customer is free on Sunday after 11 am. Wants 5 kW."></textarea>
      <button class="btn s" style="margin-top:8px">Save update</button>
    </form>
    <p class="small muted" style="margin-top:8px">Only the Finrush team can change the stage. Your updates go to them straight away.</p>
  </div>
  <div class="card"><h2>History</h2><?= event_list($events, false) ?></div>
</div>
<div>
  <div class="card"><h2>Customer</h2><dl class="kv">
    <dt>Mobile</dt><dd><?= h($l['customer_phone']) ?></dd>
    <dt>City</dt><dd><?= h($l['customer_city']) ?></dd>
    <dt>Type</dt><dd><?= h(PROJECT_TYPES[$l['project_type']] ?? $l['project_type']) ?></dd>
    <dt>Size / bill</dt><dd><?= h($l['size_info'] ?: '—') ?></dd>
    <dt>PM Surya Ghar</dt><dd><?= h($l['pm_surya_ghar'] ?: '—') ?></dd>
  </dl></div>
  <div class="card"><h2>Your commission</h2><dl class="kv">
    <dt>Project value</dt><dd><?= $l['project_value'] !== null && in_array($l['stage'], ['payment_received', 'commission_paid'], true) ? inr($l['project_value']) . ' <span class="small muted">excl. GST</span>' : '<span class="muted">After installation</span>' ?></dd>
    <dt>Rate</dt><dd><?= $l['commission_rate'] !== null && $l['commission_status'] !== 'none' ? h(rtrim(rtrim((string) $l['commission_rate'], '0'), '.')) . '%' : '—' ?></dd>
    <dt>Commission</dt><dd><b><?= $l['commission_status'] !== 'none' ? inr($l['commission_amount']) : '—' ?></b></dd>
    <dt>Status</dt><dd><?php $cs = ['none' => 'Not yet earned', 'pending' => 'Due — will be paid soon', 'paid' => 'Paid', 'reversed' => 'Reversed']; ?><span class="badge b-<?= h($l['commission_status']) ?>"><?= h($cs[$l['commission_status']]) ?></span></dd>
    <?php if ($l['commission_status'] === 'paid'): ?><dt>Paid on</dt><dd><?= h(dt($l['commission_paid_on'])) ?></dd><dt>TDS</dt><dd><?= inr($l['commission_tds']) ?></dd><dt>Net paid</dt><dd><?= inr($l['commission_net']) ?></dd><dt>Reference</dt><dd class="mono small"><?= h($l['commission_ref'] ?: '—') ?></dd><?php endif; ?>
  </dl></div>
</div>
</div>
<?php page_end();
