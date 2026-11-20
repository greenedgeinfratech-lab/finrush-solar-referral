<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';
$u = require_user();
$occ = ['Electrician', 'Builder / Contractor', 'Property dealer', 'Architect / Engineer', 'Solar installer', 'Shop / Business owner', 'Sales professional', 'Other'];
$nv = ['name' => '', 'mobile' => '', 'email' => '', 'city' => '', 'district' => '', 'occupation' => 'Other', 'status' => 'active'];
$addErr = '';
if (is_post() && p('action', 10) === 'add') {
    check_csrf();
    foreach ($nv as $k => $_) $nv[$k] = p($k, 120);
    $nv['mobile'] = clean_mobile($nv['mobile']);
    if ($nv['name'] === '' || $nv['city'] === '') $addErr = 'Name and city are required.';
    elseif (!valid_mobile($nv['mobile'])) $addErr = 'Enter a valid 10-digit mobile number.';
    elseif ($nv['email'] !== '' && !filter_var($nv['email'], FILTER_VALIDATE_EMAIL)) $addErr = 'Enter a valid email or leave it blank.';
    elseif (!in_array($nv['occupation'], $occ, true)) $addErr = 'Choose an occupation.';
    elseif ($dup = one('SELECT id, code FROM partners WHERE mobile = ?', [$nv['mobile']])) $addErr = 'This mobile is already registered as partner ' . $dup['code'] . '.';
    elseif (p('consent', 5) !== 'yes') $addErr = 'Confirm that the partner has agreed to join and to the Terms.';
    else {
        $active = $nv['status'] === 'active';
        $tmp = temp_password();
        q("INSERT INTO partners (name, mobile, email, city, district, occupation, status, password_hash, must_change, consent_at, source, approved_by, approved_at, created_at)
           VALUES (?,?,?,?,?,?,?,?,1,NOW(),'team',?,?,NOW())",
          [$nv['name'], $nv['mobile'], $nv['email'] ?: null, $nv['city'], $nv['district'] ?: null, $nv['occupation'], $active ? 'active' : 'pending', password_hash($tmp, PASSWORD_DEFAULT), $active ? $u['id'] : null, $active ? date('Y-m-d H:i:s') : null]);
        $pid = (int) db()->lastInsertId();
        q('UPDATE partners SET code = ? WHERE id = ?', [partner_code($pid), $pid]);
        $_SESSION['temp_pw'] = [$pid, $tmp];
        flash('Partner ' . $nv['name'] . ' (' . partner_code($pid) . ') added. Share the temporary password below with them.');
        redirect('/admin/partner.php?id=' . $pid);
    }
}
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
<div class="page-head"><div><h1>Partners</h1><div class="muted"><?= count($rows) ?> shown</div></div><div class="actions"><a class="btn o s" href="<?= h(url('/admin/export.php?what=partners')) ?>">Export CSV</a><a class="btn s" href="#add" onclick="document.getElementById('add').open=true">+ Add partner</a></div></div>
<details class="card" id="add"<?= $addErr ? ' open' : '' ?>><summary style="cursor:pointer;font-weight:700">+ Add a partner</summary>
  <p class="small muted" style="margin:8px 0 12px">For partners who joined by phone, WhatsApp or in person. A temporary password is created — they log in with their mobile number and must set their own password.</p>
  <?php if ($addErr): ?><div class="alert err"><?= h($addErr) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="fgrid">
      <div><label>Full name *</label><input name="name" required value="<?= h($nv['name']) ?>"></div>
      <div><label>Mobile (login) *</label><input name="mobile" required inputmode="numeric" value="<?= h($nv['mobile']) ?>"></div>
      <div><label>Email</label><input name="email" type="email" value="<?= h($nv['email']) ?>"></div>
      <div><label>City / Town *</label><input name="city" required value="<?= h($nv['city']) ?>"></div>
      <div><label>District &amp; State</label><input name="district" value="<?= h($nv['district']) ?>"></div>
      <div><label>Occupation</label><select name="occupation"><?= select_opts(array_combine($occ, $occ), $nv['occupation']) ?></select></div>
      <div><label>Status</label><select name="status"><?= select_opts(['active' => 'Active — can add leads now', 'pending' => 'Pending — approve later'], $nv['status']) ?></select></div>
      <div class="full"><label class="check"><input type="checkbox" name="consent" value="yes" required> <span>The partner has agreed to join the Finrush Solar Referral Programme and to its Terms &amp; Conditions.</span></label></div>
    </div>
    <button class="btn s" style="margin-top:10px">Add partner</button>
  </form>
</details>
<form class="filters" method="get"><input name="q" value="<?= h($s) ?>" placeholder="Search name, mobile, ID, city">
<select name="status"><option value="">All statuses</option><?= select_opts(['pending' => 'Pending approval', 'active' => 'Active', 'suspended' => 'Suspended', 'rejected' => 'Rejected'], $status) ?></select><button class="btn s">Filter</button></form>
<div class="tbl-wrap"><table class="t"><thead><tr><th>Partner</th><th>Mobile</th><th>City</th><th>Occupation</th><th>Status</th><th>KYC</th><th class="num">Leads</th><th class="num">Converted</th><th class="num">Due</th><th>Joined</th><?php if ($u['role'] === 'admin'): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="11" class="empty">No partners yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr>
<td><a href="<?= h(url('/admin/partner.php?id=' . $r['id'])) ?>"><b><?= h($r['name']) ?></b></a><div class="small mono muted"><?= h($r['code']) ?></div></td>
<td class="mono small"><?= h($r['mobile']) ?></td><td><?= h($r['city']) ?></td><td class="small"><?= h($r['occupation']) ?></td>
<td><span class="badge b-<?= h($r['status']) ?>"><?= h(ucfirst($r['status'])) ?></span></td><td><span class="badge b-<?= h($r['kyc_status']) ?>"><?= h(ucfirst($r['kyc_status'])) ?></span></td>
<td class="num"><?= (int) $r['leads_total'] ?></td><td class="num"><?= (int) $r['converted'] ?></td><td class="num"><?= inr($r['due']) ?></td><td class="small"><?= h(dt($r['created_at'])) ?></td><?php if ($u['role'] === 'admin'): ?><td><a class="small" style="color:#b42318" href="<?= h(url('/admin/partner.php?id=' . $r['id'] . '#delete')) ?>">Delete</a></td><?php endif; ?></tr>
<?php endforeach; ?></tbody></table></div>
<?php page_end();
