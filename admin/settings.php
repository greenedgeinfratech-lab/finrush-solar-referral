<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$u = require_user('admin');
$types = ['residential' => 'Residential', 'commercial' => 'Commercial / society', 'industrial' => 'Industrial'];
$err = '';
$plans = commission_plans();

if (is_post()) {
    check_csrf();
    $num = fn($s) => str_replace([',', ' ', '₹', '%'], '', (string) $s);
    // ---- commission plans ----
    $rows = $_POST['plan'] ?? [];
    $new = [];
    foreach (is_array($rows) ? $rows : [] as $i => $row) {
        if (!is_array($row)) continue;
        $type = (string) ($row['type'] ?? ''); $mode = (string) ($row['mode'] ?? '');
        $min = $num($row['min'] ?? ''); $max = $num($row['max'] ?? ''); $val = $num($row['value'] ?? '');
        if ($val === '' && $min === '' && $max === '') continue; // empty row
        $n = count($new) + 1;
        if (!isset($types[$type]) || !isset(PLAN_MODES[$mode])) { $err = "Plan row $n: choose a project type and a commission type."; break; }
        if ($min === '') $min = '0';
        if (!is_numeric($min) || (float) $min < 0) { $err = "Plan row $n: 'More than (kW)' must be 0 or a positive number."; break; }
        if ($max !== '' && (!is_numeric($max) || (float) $max <= (float) $min)) { $err = "Plan row $n: 'Up to (kW)' must be bigger than 'More than (kW)', or left blank for no limit."; break; }
        if (!is_numeric($val) || (float) $val <= 0) { $err = "Plan row $n: enter the commission value."; break; }
        if ($mode === 'percent' && (float) $val > 20) { $err = "Plan row $n: a percentage above 20% looks wrong."; break; }
        $new[] = ['type' => $type, 'min' => (float) $min, 'max' => $max === '' ? null : (float) $max, 'mode' => $mode, 'value' => (float) $val];
    }
    // overlapping ranges for the same type would make the plan ambiguous
    if (!$err) {
        foreach ($types as $t => $label) {
            $tp = array_values(array_filter($new, fn($p) => $p['type'] === $t));
            usort($tp, fn($a, $b) => $a['min'] <=> $b['min']);
            for ($i = 1; $i < count($tp); $i++) {
                $prevMax = $tp[$i - 1]['max'];
                if ($prevMax === null || $prevMax > $tp[$i]['min']) { $err = "$label plans overlap: " . plan_range_label($tp[$i - 1]) . ' and ' . plan_range_label($tp[$i]) . '. Adjust the kW ranges so they follow each other (e.g. up to 3, then above 3 up to 5).'; break 2; }
            }
        }
    }
    // ---- other settings ----
    $slabs = [];
    for ($i = 0; $i < 6; $i++) {
        $m = $num(p("slab_min_$i", 15)); $a = $num(p("slab_amt_$i", 15));
        if ($m !== '' && $a !== '' && is_numeric($m) && is_numeric($a) && $m > 0 && $a > 0) $slabs[] = [(float) $m, (float) $a];
    }
    $emails = array_filter(array_map('trim', explode(',', p('notify_emails', 500))));
    $badEmail = array_filter($emails, fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL));
    $tds = $num(p('tds_percent', 10));
    if (!$err && (!is_numeric($tds) || $tds < 0 || $tds > 30)) $err = 'TDS % must be between 0 and 30.';
    if (!$err && $badEmail) $err = 'Invalid email: ' . implode(', ', $badEmail);
    if (!$err && !$new) $err = 'Keep at least one commission plan.';
    if (!$err) {
        set_setting('commission_plans', json_encode($new));
        set_setting('tds_percent', (string) (float) $tds);
        set_setting('bonus_slabs', json_encode($slabs));
        set_setting('notify_emails', implode(',', $emails));
        set_setting('whatsapp', preg_replace('/\D/', '', p('whatsapp', 15)) ?: '919837067681');
        flash('Settings saved. New plans apply to commissions calculated from now on; commissions already due or paid are not changed.');
        redirect('/admin/settings.php');
    }
    // show what was submitted so nothing typed is lost
    $plans = [];
    foreach (is_array($rows) ? $rows : [] as $row) if (is_array($row) && (($row['value'] ?? '') !== '' || ($row['min'] ?? '') !== '' || ($row['max'] ?? '') !== '')) $plans[] = ['type' => $row['type'] ?? '', 'min' => $row['min'] ?? '', 'max' => ($row['max'] ?? '') === '' ? null : $row['max'], 'mode' => $row['mode'] ?? 'percent', 'value' => $row['value'] ?? ''];
}
$slabs = bonus_slabs();
$rowsToShow = max(count($plans) + 3, 8);
$fmt = fn($n) => $n === null || $n === '' ? '' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
page_start('Settings', 'admin', admin_nav($u), 'settings');
?>
<div class="page-head"><h1>Settings</h1></div>
<?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<div class="card">
  <h2>Commission plans by kW</h2>
  <p class="small muted" style="margin-bottom:10px">When a lead reaches <b>Payment received</b>, the commission comes from the plan matching its project type and final system size (kW). A row covers sizes <b>more than</b> the first number <b>up to and including</b> the second. Leave "Up to" blank for no upper limit. Blank rows are ignored.</p>
  <div class="tbl-wrap"><table class="t" id="plans">
    <thead><tr><th>Project type</th><th>More than (kW)</th><th>Up to (kW)</th><th>Commission type</th><th>Value</th><th>Example</th><th></th></tr></thead>
    <tbody>
    <?php for ($i = 0; $i < $rowsToShow; $i++): $p = $plans[$i] ?? ['type' => '', 'min' => '', 'max' => null, 'mode' => 'percent', 'value' => '']; ?>
      <tr>
        <td><select name="plan[<?= $i ?>][type]" style="min-width:150px"><option value="">—</option><?= select_opts($types, $p['type']) ?></select></td>
        <td><input name="plan[<?= $i ?>][min]" value="<?= h($fmt($p['min'])) ?>" inputmode="decimal" style="width:90px" placeholder="0"></td>
        <td><input name="plan[<?= $i ?>][max]" value="<?= h($fmt($p['max'])) ?>" inputmode="decimal" style="width:90px" placeholder="no limit"></td>
        <td><select name="plan[<?= $i ?>][mode]" style="min-width:170px"><?= select_opts(PLAN_MODES, $p['mode']) ?></select></td>
        <td><input name="plan[<?= $i ?>][value]" value="<?= h($fmt($p['value'])) ?>" inputmode="decimal" style="width:100px"></td>
        <td class="small muted"><?= $p['type'] !== '' && $p['value'] !== '' && isset(PLAN_MODES[$p['mode']]) ? h(plan_range_label($p) . ' → ' . plan_value_label($p)) : '' ?></td>
        <td><button type="button" class="btn o s" onclick="this.closest('tr').querySelectorAll('input').forEach(i=>i.value='');this.closest('tr').querySelector('select').value=''">Clear</button></td>
      </tr>
    <?php endfor; ?>
    </tbody>
  </table></div>
  <p class="small muted" style="margin-top:8px">Examples: Residential, more than 0 up to 3, Fixed ₹ per project, 6000 → ₹6,000 for any home system up to 3 kW. Commercial, more than 10 up to 100, ₹ per kW, 500 → ₹25,000 for a 50 kW plant. Need more rows? Save, and 3 empty rows appear again. A special % rate can also be set on an individual lead by an admin.</p>
</div>
<div class="grid g2">
<div class="card"><h2>Monthly bonus slabs</h2>
  <p class="small muted" style="margin-bottom:8px">Partner's confirmed sales in a month (₹) → bonus (₹). Only the highest slab reached is paid.</p>
  <?php for ($i = 0; $i < 6; $i++): ?><div class="fgrid" style="margin-bottom:6px"><input name="slab_min_<?= $i ?>" placeholder="Sales from" value="<?= h(isset($slabs[$i]) ? (string) $slabs[$i][0] : '') ?>"><input name="slab_amt_<?= $i ?>" placeholder="Bonus" value="<?= h(isset($slabs[$i]) ? (string) $slabs[$i][1] : '') ?>"></div><?php endfor; ?>
</div>
<div class="card"><h2>Tax &amp; notifications</h2>
  <div class="f"><label>Default TDS % (Section 194H)</label><input name="tds_percent" value="<?= h(setting('tds_percent', '2')) ?>"></div>
  <p class="small muted" style="margin:-4px 0 12px">Confirm the current 194H rate and threshold with your CA.</p>
  <div class="f"><label>Email new registrations, leads and partner updates to (comma separated)</label><input name="notify_emails" value="<?= h(setting('notify_emails', '')) ?>"></div>
  <div class="f"><label>Programme WhatsApp number (with 91)</label><input name="whatsapp" value="<?= h(setting('whatsapp', '919837067681')) ?>"></div>
</div>
</div>
<button class="btn">Save settings</button>
</form>
<?php page_end();
