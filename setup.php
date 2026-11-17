<?php
/**
 * One-time setup: connects the database, creates tables, the first admin,
 * imports earlier form submissions, and writes the config file OUTSIDE public_html.
 * Locked forever once the config file exists.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (is_installed()) {
    if (PHP_SAPI === 'cli') { echo "Already set up.\n"; exit(0); }
    page_start('Setup', 'public');
    echo '<div class="auth"><div class="card"><h1>Already set up</h1><p>This site is configured. <a href="' . h(url('/admin/login.php')) . '">Team login</a></p></div></div>';
    page_end(); exit;
}

$err = ''; $ok = null;
$v = ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'admin_name' => '', 'admin_mobile' => '', 'notify' => 'finrushsmart@gmail.com, greenedgeinfratech@gmail.com'];

function import_csv(string $file): array {
    if (!is_readable($file) || !($h = fopen($file, 'r'))) return [];
    $hdr = fgetcsv($h); $rows = [];
    while ($hdr && ($r = fgetcsv($h)) !== false) if (count($r) === count($hdr)) $rows[] = array_map(fn($x) => ltrim((string) $x, "'"), array_combine($hdr, $r));
    fclose($h);
    return $rows;
}

function install_crm(array $v, string $dbPass, string $mobile, string $pw, bool $mustChange): array {
    $pdo = new PDO('mysql:host=' . $v['db_host'] . ';dbname=' . $v['db_name'] . ';charset=utf8mb4', $v['db_user'], $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '+05:30'");
    foreach (require __DIR__ . '/inc/schema.php' as $sql) $pdo->exec($sql);
    $defaults = [
        'rate_residential' => '3', 'rate_commercial' => '1.5', 'rate_industrial_max' => '1', 'tds_percent' => '2',
        'bonus_slabs' => json_encode([[1000000, 25000], [2500000, 75000], [5000000, 200000]]),
        'notify_emails' => implode(',', array_filter(array_map('trim', explode(',', $v['notify'])), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))),
        'whatsapp' => '919837067681', 'mail_from' => 'noreply@finrush.co.in',
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)');
    foreach ($defaults as $k => $val) $st->execute([$k, $val]);
    if (!(int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) {
        $pdo->prepare("INSERT INTO users (name, mobile, org, role, password_hash, must_change, active, created_at) VALUES (?,?,'Finrush','admin',?,?,1,NOW())")
            ->execute([$v['admin_name'], $mobile, password_hash($pw, PASSWORD_DEFAULT), $mustChange ? 1 : 0]);
    }

    // Import anything submitted through the earlier /solar-partner/ forms
    $imported = [0, 0];
    $dataDir = private_dir() . '/solar-partner-data';
    foreach (import_csv($dataDir . '/partners.csv') as $r) {
        $m = clean_mobile($r['phone'] ?? '');
        if (!valid_mobile($m) || $pdo->query('SELECT id FROM partners WHERE mobile = ' . $pdo->quote($m))->fetch()) continue;
        $pdo->prepare("INSERT INTO partners (name, mobile, email, city, district, occupation, message, status, source, consent_at, created_at) VALUES (?,?,?,?,?,?,?,'pending','import',?,?)")
            ->execute([$r['name'] ?? 'Partner', $m, ($r['email'] ?? '') ?: null, $r['city'] ?? null, $r['district'] ?? null, $r['occupation'] ?? null, ($r['message'] ?? '') ?: null, $r['submitted_at'] ?? date('Y-m-d H:i:s'), $r['submitted_at'] ?? date('Y-m-d H:i:s')]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE partners SET code = ? WHERE id = ?')->execute([partner_code($id), $id]);
        $imported[0]++;
    }
    foreach (import_csv($dataDir . '/referrals.csv') as $r) {
        $cm = clean_mobile($r['customer_phone'] ?? ''); $pm = clean_mobile($r['partner_phone'] ?? '');
        if (!valid_mobile($cm) || $pdo->query("SELECT id FROM leads WHERE customer_phone = " . $pdo->quote($cm) . " AND stage <> 'lost'")->fetch()) continue;
        $pid = null;
        if (valid_mobile($pm)) {
            $row = $pdo->query('SELECT id FROM partners WHERE mobile = ' . $pdo->quote($pm))->fetch();
            if ($row) $pid = (int) $row['id'];
            else {
                $pdo->prepare("INSERT INTO partners (name, mobile, status, source, created_at) VALUES (?,?,'pending','import',NOW())")->execute([$r['partner_name'] ?? 'Partner', $pm]);
                $pid = (int) $pdo->lastInsertId();
                $pdo->prepare('UPDATE partners SET code = ? WHERE id = ?')->execute([partner_code($pid), $pid]);
            }
        }
        $type = stripos($r['project_type'] ?? '', 'resid') !== false ? 'residential' : (stripos($r['project_type'] ?? '', 'commer') !== false ? 'commercial' : (stripos($r['project_type'] ?? '', 'indus') !== false ? 'industrial' : 'unknown'));
        $when = $r['submitted_at'] ?? date('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO leads (ref, partner_id, customer_name, customer_phone, customer_city, project_type, size_info, pm_surya_ghar, notes, stage, source, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,'new','import',?,?)")
            ->execute([new_lead_ref(), $pid, $r['customer_name'] ?? 'Customer', $cm, $r['customer_city'] ?? null, $type, ($r['size'] ?? '') ?: null, ($r['pm_surya_ghar'] ?? '') ?: null, ($r['message'] ?? '') ?: null, $when, $when]);
        $lid = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO lead_events (lead_id, actor_type, kind, body, to_stage, partner_visible, created_at) VALUES (?, 'system', 'stage', 'Imported from the earlier referral form', 'new', 1, NOW())")->execute([$lid]);
        $imported[1]++;
    }

    $cfg = ['db' => ['host' => $v['db_host'], 'name' => $v['db_name'], 'user' => $v['db_user'], 'pass' => $dbPass], 'installed_at' => date('c')];
    $php = "<?php\n// Finrush CRM configuration — keep private. Written by setup.php.\nreturn " . var_export($cfg, true) . ";\n";
    $path = config_path();
    if (@file_put_contents($path, $php, LOCK_EX) === false) throw new RuntimeException('Could not write the config file to ' . $path);
    @chmod($path, 0600);
    return $imported;
}

// ---- Command-line setup (run once from the hosting account) ----
if (PHP_SAPI === 'cli') {
    $o = getopt('', ['db-host::', 'db-name:', 'db-user:', 'db-pass:', 'admin-name:', 'admin-mobile:', 'admin-pass:', 'notify::']);
    foreach (['db-name', 'db-user', 'db-pass', 'admin-name', 'admin-mobile', 'admin-pass'] as $req) if (empty($o[$req])) { echo "Missing --$req\n"; exit(1); }
    $mob = clean_mobile($o['admin-mobile']);
    if (!valid_mobile($mob)) { echo "Invalid admin mobile\n"; exit(1); }
    try {
        $imp = install_crm(['db_host' => $o['db-host'] ?? 'localhost', 'db_name' => $o['db-name'], 'db_user' => $o['db-user'], 'admin_name' => $o['admin-name'], 'notify' => $o['notify'] ?? 'finrushsmart@gmail.com,greenedgeinfratech@gmail.com'], $o['db-pass'], $mob, $o['admin-pass'], true);
        echo "SETUP OK. Imported partners: {$imp[0]}, leads: {$imp[1]}. Config written outside public_html.\n";
    } catch (Throwable $e) { echo 'SETUP FAILED: ' . $e->getMessage() . "\n"; exit(1); }
    exit(0);
}

if (is_post()) {
    check_csrf();
    foreach ($v as $k => $_) $v[$k] = p($k, 200);
    $key = (string) ($_POST['setup_key'] ?? '');
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $pw = (string) ($_POST['admin_password'] ?? ''); $pw2 = (string) ($_POST['admin_password2'] ?? '');
    $mobile = clean_mobile($v['admin_mobile']);
    $hash = require __DIR__ . '/inc/setup-key.php';
    if (!password_verify($key, $hash)) { sleep(2); $err = 'Setup key is incorrect.'; }
    elseif ($v['db_name'] === '' || $v['db_user'] === '') $err = 'Database name and user are required.';
    elseif ($v['admin_name'] === '' || !valid_mobile($mobile)) $err = 'Admin name and a valid 10-digit mobile are required.';
    elseif (($pe = password_problem($pw, $pw2)) !== null) $err = $pe;
    else {
        try {
            $ok = install_crm($v, $dbPass, $mobile, $pw, false);
        } catch (Throwable $e) {
            $err = 'Setup failed: ' . $e->getMessage();
        }
    }
}

page_start('Setup', 'public');
?>
<div class="auth" style="max-width:640px"><div class="card">
<?php if ($ok !== null): ?>
  <h1>Setup complete ✓</h1>
  <p>Database tables are ready and your admin account is created.</p>
  <?php if ($ok[0] || $ok[1]): ?><p>Imported <?= (int) $ok[0] ?> partner(s) and <?= (int) $ok[1] ?> lead(s) from the earlier form. Imported partners have no password yet — reset one from their page to let them log in.</p><?php endif; ?>
  <p style="margin-top:14px"><a class="btn" href="<?= h(url('/admin/login.php')) ?>">Go to team login</a></p>
<?php else: ?>
  <h1>Set up the Finrush CRM</h1>
  <p class="muted small" style="margin-bottom:14px">One-time step. Create a MySQL database in hPanel (Databases → Management) first, then enter its details here. The password is saved in a private file outside the website folder.</p>
  <?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off"><?= csrf_field() ?>
    <div class="f"><label>Setup key</label><input name="setup_key" type="password" required></div>
    <h2 style="margin-top:16px">Database</h2>
    <div class="fgrid">
      <div><label>Host</label><input name="db_host" value="<?= h($v['db_host']) ?>" required></div>
      <div><label>Database name</label><input name="db_name" value="<?= h($v['db_name']) ?>" required placeholder="u414903541_..."></div>
      <div><label>Database user</label><input name="db_user" value="<?= h($v['db_user']) ?>" required placeholder="u414903541_..."></div>
      <div><label>Database password</label><input name="db_pass" type="password" required></div>
    </div>
    <h2 style="margin-top:16px">First admin account</h2>
    <div class="fgrid">
      <div><label>Your name</label><input name="admin_name" value="<?= h($v['admin_name']) ?>" required></div>
      <div><label>Mobile (login)</label><input name="admin_mobile" value="<?= h($v['admin_mobile']) ?>" required inputmode="numeric"></div>
      <div><label>Password</label><input name="admin_password" type="password" required minlength="8" autocomplete="new-password"></div>
      <div><label>Confirm password</label><input name="admin_password2" type="password" required minlength="8" autocomplete="new-password"></div>
      <div class="full"><label>Send notifications to (comma separated)</label><input name="notify" value="<?= h($v['notify']) ?>"></div>
    </div>
    <button class="btn" style="margin-top:14px">Finish setup</button>
  </form>
<?php endif; ?>
</div></div>
<?php page_end();
