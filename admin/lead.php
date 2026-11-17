<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$u = require_user();
$id = (int) g('id');
$l = one('SELECT * FROM leads WHERE id = ?', [$id]);
if (!$l) { flash('Lead not found.', 'err'); redirect('/admin/leads.php'); }
$partner = $l['partner_id'] ? one('SELECT * FROM partners WHERE id = ?', [$l['partner_id']]) : null;
$isAdmin = $u['role'] === 'admin';
$locked = $l['commission_status'] === 'paid';

function notify_partner(?array $partner, array $lead, string $text): void {
    if ($partner && $partner['email']) {
        send_mail([$partner['email']], 'Update on your referral: ' . $lead['customer_name'],
            "Namaste {$partner['name']},\n\n$text\n\nSee details: " . site_origin() . url('/partner/lead.php?id=' . $lead['id']) . "\n\nFinrush Solar Referral Programme");
    }
}

if (is_post()) {
    check_csrf();
    $action = p('action', 20);
    $err = '';

    if ($action === 'details') {
        $name = p('customer_name', 80); $phone = clean_mobile(p('customer_phone', 15)); $city = p('customer_city', 80);
        $type = p('project_type', 20); $size = p('size_info', 60); $pm = p('pm_surya_ghar', 10);
        $assigned = (int) p('assigned_to', 10);
        $valueRaw = str_replace([',', '₹', ' '], '', p('project_value', 20));
        $value = $valueRaw === '' ? null : (float) $valueRaw;
        $rateRaw = p('commission_rate', 6);
        $rate = $rateRaw === '' ? null : (float) $rateRaw;
        if ($name === '' || !valid_mobile($phone) || !isset(PROJECT_TYPES[$type])) $err = 'Name, a valid mobile and project type are required.';
        elseif ($value !== null && ($value < 0 || $value > 1e11)) $err = 'Project value looks wrong.';
        elseif ($type === 'industrial' && $rate !== null && $rate > (float) setting('rate_industrial_max', '1')) $err = 'Industrial rate cannot exceed ' . setting('rate_industrial_max', '1') . '%.';
        elseif ($phone !== $l['customer_phone'] && ($d = open_lead_for_phone($phone, $id))) $err = 'Another open lead already has this mobile (' . $d['ref'] . ').';
        elseif ($locked && ((string) $value !== (string) (float) $l['project_value'] || $type !== $l['project_type'])) $err = 'Commission is already paid, so project value and type are locked.';
        else {
            $assignedId = $assigned > 0 && one('SELECT id FROM users WHERE id = ? AND active = 1', [$assigned]) ? $assigned : null;
            q('UPDATE leads SET customer_name=?, customer_phone=?, customer_city=?, project_type=?, size_info=?, pm_surya_ghar=?, assigned_to=?, project_value=?, commission_rate=?, updated_at=NOW() WHERE id=?',
              [$name, $phone, $city, $type, $size ?: null, $pm ?: null, $assignedId, $value, $type === 'industrial' ? $rate : ($l['commission_status'] === 'none' ? null : $l['commission_rate']), $id]);
            $changes = [];
            if ((int) $l['assigned_to'] !== (int) $assignedId) $changes[] = 'Assigned to ' . ($assignedId ? val('SELECT name FROM users WHERE id = ?', [$assignedId]) : 'nobody');
            if ((string) $l['project_value'] !== (string) ($value === null ? null : number_format($value, 2, '.', ''))) $changes[] = 'Project value set to ' . inr($value);
            if ($type !== $l['project_type']) $changes[] = 'Type changed to ' . PROJECT_TYPES[$type];
            // Keep a due commission in step with the new value/type
            if ($l['commission_status'] === 'pending') {
                $fresh = one('SELECT * FROM leads WHERE id = ?', [$id]);
                $r = commission_rate_for($fresh);
                if ($r !== null && $value) { q('UPDATE leads SET commission_rate=?, commission_amount=? WHERE id=?', [$r, round($value * $r / 100, 2), $id]); $changes[] = 'Commission recalculated: ' . inr(round($value * $r / 100, 2)); }
            }
            if ($changes) add_event($id, 'team', (int) $u['id'], 'update', implode("\n", $changes), false);
            flash('Lead details saved.');
        }
    }

    elseif ($action === 'stage') {
        $to = p('to_stage', 30); $note = p('note', 1500); $visible = p('partner_visible', 3) === '1';
        $from = $l['stage'];
        if (!isset(STAGES[$to]) || $to === 'commission_paid') $err = 'Choose a valid stage. Commission is marked paid from the Payouts page.';
        elseif ($to === $from) $err = 'The lead is already at this stage.';
        elseif ($from === 'commission_paid' && !($isAdmin && $to === 'lost')) $err = 'Commission is already paid. Only an admin can close this lead (which marks the commission for recovery).';
        elseif ($to === 'lost' && p('lost_reason', 200) === '') $err = 'Please give a reason for closing the lead.';
        else {
            $sets = ['stage = ?']; $vals = [$to];
            if ($to === 'payment_received') {
                $fresh = $l;
                $rate = commission_rate_for($fresh);
                $pdate = p('payment_date', 10) ?: date('Y-m-d');
                if (!$l['project_value'] || (float) $l['project_value'] <= 0) $err = 'Enter the project value (excluding GST) in Lead details first.';
                elseif ($l['project_type'] === 'unknown') $err = 'Set the project type in Lead details first.';
                elseif ($rate === null) $err = 'Enter the commission rate for this industrial project in Lead details first.';
                elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pdate) || strtotime($pdate) > time() + 86400) $err = 'Enter a valid payment date.';
                elseif (!$l['partner_id']) { array_push($sets, 'payment_received_on = ?'); $vals[] = $pdate; }
                else {
                    $amt = round((float) $l['project_value'] * $rate / 100, 2);
                    array_push($sets, 'commission_rate = ?', 'commission_amount = ?', "commission_status = 'pending'", 'payment_received_on = ?');
                    array_push($vals, $rate, $amt, $pdate);
                    $note = trim(($note !== '' ? $note . "\n" : '') . 'Commission due: ' . inr($amt) . ' (' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '% of ' . inr($l['project_value']) . ')');
                }
            } elseif ($to === 'lost') {
                $reason = p('lost_reason', 200);
                array_push($sets, 'lost_reason = ?'); $vals[] = $reason;
                $note = trim('Reason: ' . $reason . ($note !== '' ? "\n" . $note : ''));
                if ($l['commission_status'] === 'pending') array_push($sets, "commission_status = 'none'", 'commission_amount = NULL', 'payment_received_on = NULL');
                if ($l['commission_status'] === 'paid') { array_push($sets, "commission_status = 'reversed'"); $note .= "\nCommission of " . inr($l['commission_amount']) . ' was already paid and is marked for recovery.'; }
            } elseif (in_array($from, ['payment_received'], true)) {
                array_push($sets, "commission_status = 'none'", 'commission_amount = NULL', 'payment_received_on = NULL');
                $note = trim(($note !== '' ? $note . "\n" : '') . 'Commission cleared because the lead moved back.');
            }
            if ($from === 'lost') { array_push($sets, 'lost_reason = NULL'); }
            if (!$err) {
                $vals[] = $id;
                q('UPDATE leads SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?', $vals);
                add_event($id, 'team', (int) $u['id'], 'stage', $note, $visible || $to === 'payment_received', $from, $to);
                if ($visible) notify_partner($partner, $l, 'Your referral ' . $l['customer_name'] . ' has moved to: ' . stage_label($to) . ($note !== '' ? "\n\n" . $note : ''));
                flash('Stage updated to ' . stage_label($to) . '.');
            }
        }
    }

    elseif ($action === 'note') {
        $note = p('note', 2000); $visible = p('partner_visible', 3) === '1';
        if ($note === '') $err = 'Write a note first.';
        else {
            add_event($id, 'team', (int) $u['id'], 'note', $note, $visible);
            if ($visible) notify_partner($partner, $l, "Update on your referral {$l['customer_name']}:\n\n$note");
            flash($visible ? 'Note added and shared with the partner.' : 'Internal note added.');
        }
    }

    if ($err) flash($err, 'err');
    redirect('/admin/lead.php?id=' . $id);
}

$events = all("SELECT e.*, COALESCE(us.name, pa.name) actor_name FROM lead_events e LEFT JOIN users us ON e.actor_type = 'team' AND us.id = e.actor_id LEFT JOIN partners pa ON e.actor_type = 'partner' AND pa.id = e.actor_id WHERE e.lead_id = ? ORDER BY e.id DESC", [$id]);
$team = all('SELECT id, name, org FROM users WHERE active = 1 ORDER BY name');
$teamOpts = ['0' => '— Unassigned —']; foreach ($team as $t) $teamOpts[$t['id']] = $t['name'] . ' (' . $t['org'] . ')';
$stageOpts = array_map(fn($s) => $s[0], STAGES); unset($stageOpts['commission_paid']);
$dupes = all("SELECT id, ref, stage FROM leads WHERE customer_phone = ? AND id <> ?", [$l['customer_phone'], $id]);
page_start($l['customer_name'], 'admin', admin_nav($u), 'leads');
?>
<div class="page-head"><div><a class="small" href="<?= h(url('/admin/leads.php')) ?>">← Leads</a><h1><?= h($l['customer_name']) ?> <?= stage_badge($l['stage']) ?></h1>
<div class="muted small mono"><?= h($l['ref']) ?> · source: <?= h($l['source']) ?> · created <?= h(dt($l['created_at'], true)) ?></div></div>
<div class="actions">
  <a class="btn wa s" target="_blank" rel="noopener" href="<?= h(wa_link($l['customer_phone'], 'Namaste ' . $l['customer_name'] . ', this is the Greenedge solar team regarding your rooftop solar enquiry.')) ?>">WhatsApp customer</a>
  <a class="btn o s" href="tel:+91<?= h($l['customer_phone']) ?>">Call</a>
  <?php if ($partner): ?><a class="btn wa s" target="_blank" rel="noopener" href="<?= h(wa_link($partner['mobile'], 'Namaste ' . $partner['name'] . ', update on your referral ' . $l['customer_name'] . ': ' . stage_label($l['stage']) . '.')) ?>">WhatsApp partner</a><?php endif; ?>
</div></div>
<?php if ($dupes): ?><div class="alert warn">Same customer mobile appears in: <?php foreach ($dupes as $d): ?><a href="<?= h(url('/admin/lead.php?id=' . $d['id'])) ?>" class="mono"><?= h($d['ref']) ?></a> (<?= h(stage_label($d['stage'])) ?>) <?php endforeach; ?></div><?php endif; ?>
<?php if ($l['commission_status'] === 'reversed'): ?><div class="alert err">Commission <?= inr($l['commission_amount']) ?> was paid on <?= h(dt($l['commission_paid_on'])) ?> but this lead is now closed — recover it from the partner's next payout.</div><?php endif; ?>
<div class="cols">
<div>
  <div class="card"><h2>Move stage</h2>
    <form method="post" id="stageform"><?= csrf_field() ?><input type="hidden" name="action" value="stage">
      <div class="fgrid">
        <div><label>New stage</label><select name="to_stage" id="to_stage"><?= select_opts($stageOpts, $l['stage']) ?></select></div>
        <div id="pdate" hidden><label>Customer payment completed on</label><input type="date" name="payment_date" value="<?= h(date('Y-m-d')) ?>" max="<?= h(date('Y-m-d')) ?>"></div>
        <div id="lreason" hidden><label>Reason for closing *</label><input name="lost_reason" maxlength="200" placeholder="e.g. Customer chose another vendor"></div>
        <div class="full"><label>Note (optional)</label><textarea name="note" rows="2" maxlength="1500"></textarea></div>
        <div class="full"><label class="check"><input type="checkbox" name="partner_visible" value="1" checked> <span>Show this update to the partner</span></label></div>
      </div>
      <button class="btn s" style="margin-top:10px">Update stage</button>
    </form>
    <p class="small muted" style="margin-top:8px">Moving to <b>Payment received</b> calculates the partner's commission from the project value. Mark commissions paid on the <a href="<?= h(url('/admin/payouts.php')) ?>">Payouts</a> page.</p>
  </div>
  <div class="card"><h2>Add note</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="note">
      <textarea name="note" rows="3" maxlength="2000" placeholder="Call notes, survey findings, follow-up date…"></textarea>
      <label class="check" style="margin-top:8px"><input type="checkbox" name="partner_visible" value="1"> <span>Share with partner (leave unticked for an internal note)</span></label>
      <button class="btn s" style="margin-top:8px">Save note</button>
    </form>
  </div>
  <div class="card"><h2>History</h2><?= event_list($events, true) ?></div>
</div>
<div>
  <div class="card"><h2>Lead details</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="details">
      <div class="f"><label>Customer name</label><input name="customer_name" value="<?= h($l['customer_name']) ?>" required maxlength="80"></div>
      <div class="f"><label>Mobile</label><input name="customer_phone" value="<?= h($l['customer_phone']) ?>" required maxlength="13"></div>
      <div class="f"><label>City</label><input name="customer_city" value="<?= h($l['customer_city']) ?>" maxlength="80"></div>
      <div class="f"><label>Project type</label><select name="project_type" id="ptype"<?= $locked ? ' disabled' : '' ?>><?= select_opts(PROJECT_TYPES, $l['project_type']) ?></select><?php if ($locked): ?><input type="hidden" name="project_type" value="<?= h($l['project_type']) ?>"><?php endif; ?></div>
      <div class="f"><label>Size / bill</label><input name="size_info" value="<?= h($l['size_info']) ?>" maxlength="60"></div>
      <div class="f"><label>PM Surya Ghar</label><select name="pm_surya_ghar"><?= select_opts(['Not sure' => 'Not sure', 'Yes' => 'Yes', 'No' => 'No'], $l['pm_surya_ghar']) ?></select></div>
      <div class="f"><label>Assigned to</label><select name="assigned_to"><?= select_opts($teamOpts, (string) (int) $l['assigned_to']) ?></select></div>
      <div class="f"><label>Project value, excl. GST (₹)</label><input name="project_value" inputmode="decimal" value="<?= h($l['project_value'] !== null ? rtrim(rtrim($l['project_value'], '0'), '.') : '') ?>"<?= $locked ? ' readonly' : '' ?> placeholder="Final invoice value"></div>
      <div class="f" id="irate"><label>Industrial commission rate (%) — max <?= h(setting('rate_industrial_max', '1')) ?>%</label><input name="commission_rate" inputmode="decimal" value="<?= h($l['project_type'] === 'industrial' && $l['commission_rate'] !== null ? (string) (float) $l['commission_rate'] : '') ?>"<?= $locked ? ' readonly' : '' ?>></div>
      <button class="btn s">Save details</button>
    </form>
  </div>
  <div class="card"><h2>Partner</h2>
  <?php if ($partner): ?><dl class="kv"><dt>Name</dt><dd><a href="<?= h(url('/admin/partner.php?id=' . $partner['id'])) ?>"><?= h($partner['name']) ?></a></dd><dt>ID</dt><dd class="mono"><?= h($partner['code']) ?></dd><dt>Mobile</dt><dd><?= h($partner['mobile']) ?></dd><dt>Status</dt><dd><span class="badge b-<?= h($partner['status']) ?>"><?= h($partner['status']) ?></span></dd></dl>
  <?php else: ?><p class="muted">Direct lead (no partner commission).</p><?php endif; ?></div>
  <div class="card"><h2>Commission</h2><dl class="kv">
    <dt>Status</dt><dd><span class="badge b-<?= h($l['commission_status']) ?>"><?= h(ucfirst($l['commission_status'])) ?></span></dd>
    <dt>Rate</dt><dd><?= $l['commission_status'] !== 'none' ? h((string) (float) $l['commission_rate']) . '%' : '—' ?></dd>
    <dt>Amount</dt><dd><b><?= $l['commission_status'] !== 'none' ? inr($l['commission_amount']) : '—' ?></b></dd>
    <dt>Payment received</dt><dd><?= h(dt($l['payment_received_on'])) ?></dd>
    <?php if ($l['commission_paid_on']): ?><dt>Paid on</dt><dd><?= h(dt($l['commission_paid_on'])) ?></dd><dt>TDS / Net</dt><dd><?= inr($l['commission_tds']) ?> / <?= inr($l['commission_net']) ?></dd><dt>Reference</dt><dd class="mono small"><?= h($l['commission_ref']) ?></dd><?php endif; ?>
  </dl></div>
  <?php if ($l['notes']): ?><div class="card"><h2>Original notes</h2><p><?= nl2br(h($l['notes'])) ?></p></div><?php endif; ?>
</div></div>
<script>
(function(){var s=document.getElementById('to_stage'),pd=document.getElementById('pdate'),lr=document.getElementById('lreason');
function t(){pd.hidden=s.value!=='payment_received';lr.hidden=s.value!=='lost';}s.addEventListener('change',t);t();
var pt=document.getElementById('ptype'),ir=document.getElementById('irate');function r(){ir.hidden=pt.value!=='industrial';}pt.addEventListener('change',r);r();})();
</script>
<?php page_end();
