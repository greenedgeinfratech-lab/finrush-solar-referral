<?php
/**
 * One-time: back up the old WordPress site on finrush.co.in and clear public_html
 * so the Finrush CRM can be deployed at the root.
 *  1. Exports the WordPress database to  domains/finrush.co.in/backups/wordpress-db-DATE.sql.gz
 *  2. MOVES (never deletes) every file in public_html to  domains/finrush.co.in/wordpress-old-DATE
 * Works only from public_html/solar-partner/, needs the setup key, and runs once.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$here = realpath(__DIR__) ?: __DIR__;
$pub  = dirname($here);
$base = dirname($pub);
$lock = $base . '/.finrush-migrated';
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$CLI = PHP_SAPI === 'cli';
function page(string $body): void {
    if (PHP_SAPI === 'cli') { echo trim(html_entity_decode(strip_tags(str_replace(['</p>', '</h1>', '<br>', '</li>'], "\n", $body)))), "\n"; exit; }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Finrush — move WordPress</title>'
       . '<style>body{font-family:system-ui,sans-serif;max-width:720px;margin:30px auto;padding:0 16px;color:#14211a}h1{font-size:1.4rem}.box{border:1px solid #dfe7e2;border-radius:12px;padding:18px;margin:14px 0}'
       . 'input{padding:10px;border:1px solid #c9d6ce;border-radius:8px;width:100%;box-sizing:border-box}button{background:#15803d;color:#fff;border:0;border-radius:999px;padding:11px 20px;font-weight:700;cursor:pointer;margin-top:12px}'
       . '.ok{color:#166534}.err{color:#b42318}pre{background:#f5f8f6;padding:10px;border-radius:8px;overflow:auto;font-size:12px}</style></head><body>' . $body . '</body></html>';
    exit;
}

if (basename($here) !== 'solar-partner' || basename($pub) !== 'public_html') page('<h1>Not available</h1><p>This tool only runs from the old /solar-partner/ folder.</p>');
if (is_file($lock)) page('<h1>Already done</h1><p>WordPress was already moved on ' . $e(trim((string) file_get_contents($lock))) . '.</p>');

if ($CLI) {
    // Run from the hosting account's command line / cron: account access is the authorisation.
    if (!in_array('--run', $argv ?? [], true)) page('Usage: php migrate.php --run');
} else {
session_name('FRMIG'); session_start();
if (empty($_SESSION['t'])) $_SESSION['t'] = bin2hex(random_bytes(16));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    page('<h1>Back up &amp; move the WordPress site</h1>
    <div class="box"><p>This will:</p><ol><li>Export the WordPress database to a private <b>backups</b> folder.</li>
    <li>Move every file in <b>public_html</b> into a private <b>wordpress-old</b> folder (nothing is deleted).</li></ol>
    <p>finrush.co.in will show an empty page for a few minutes until the new site is deployed.</p></div>
    <form method="post" class="box"><input type="hidden" name="t" value="' . $e($_SESSION['t']) . '"><label>Setup key<br><input type="password" name="key" required></label>
    <p><label><input type="checkbox" name="confirm" value="yes" required style="width:auto"> I understand the current site will be replaced.</label></p><button>Back up and move WordPress</button></form>');
}

if (!hash_equals($_SESSION['t'], (string) ($_POST['t'] ?? ''))) page('<p class="err">Session expired. Go back and reload the page.</p>');
$hash = require __DIR__ . '/inc/setup-key.php';
if (!password_verify((string) ($_POST['key'] ?? ''), $hash)) { sleep(2); page('<p class="err">Setup key is incorrect.</p><p><a href="">Try again</a></p>'); }
if (($_POST['confirm'] ?? '') !== 'yes') page('<p class="err">Please tick the confirmation box.</p>');
}

$log = [];
$stamp = date('Ymd-His');
$backups = $base . '/backups';
if (!is_dir($backups) && !mkdir($backups, 0750, true)) page('<p class="err">Could not create the backups folder.</p>');
@file_put_contents($backups . '/.htaccess', "Require all denied\n");

// ---- 1. Database export ----
$wpc = $pub . '/wp-config.php';
if (is_file($wpc)) {
    $src = (string) file_get_contents($wpc);
    $get = function (string $name) use ($src): ?string {
        return preg_match("/define\(\s*['\"]" . $name . "['\"]\s*,\s*(['\"])(.*?)\\1\s*\)/s", $src, $m) ? stripcslashes($m[2]) : null;
    };
    $dbName = $get('DB_NAME'); $dbUser = $get('DB_USER'); $dbPass = $get('DB_PASSWORD'); $dbHost = $get('DB_HOST') ?: 'localhost';
    if (!$dbName || !$dbUser) page('<p class="err">Could not read database details from wp-config.php. Nothing was changed.</p>');
    $port = null;
    if (strpos($dbHost, ':') !== false) { [$dbHost, $port] = explode(':', $dbHost, 2); }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $db = new mysqli($dbHost, $dbUser, (string) $dbPass, $dbName, $port ? (int) $port : 3306);
        $db->set_charset('utf8mb4');
        $file = $backups . "/wordpress-db-$stamp.sql.gz";
        $gz = gzopen($file, 'wb6');
        gzwrite($gz, "-- WordPress database export of $dbName, " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tables = 0; $rowsTotal = 0;
        $res = $db->query('SHOW FULL TABLES');
        $list = [];
        while ($r = $res->fetch_row()) if (($r[1] ?? '') === 'BASE TABLE') $list[] = $r[0];
        foreach ($list as $t) {
            $create = $db->query('SHOW CREATE TABLE `' . str_replace('`', '``', $t) . '`')->fetch_row()[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
            $q = $db->query('SELECT * FROM `' . str_replace('`', '``', $t) . '`', MYSQLI_USE_RESULT);
            $batch = [];
            while ($row = $q->fetch_row()) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'", $row);
                $batch[] = '(' . implode(',', $vals) . ')';
                $rowsTotal++;
                if (count($batch) >= 200) { gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n"); $batch = []; }
            }
            if ($batch) gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
            $q->free();
            gzwrite($gz, "\n");
            $tables++;
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        $log[] = "Database $dbName exported: $tables tables, $rowsTotal rows → backups/" . basename($file) . ' (' . round(filesize($file) / 1048576, 2) . ' MB)';
        $log[] = 'The WordPress database itself is left untouched in MySQL.';
    } catch (Throwable $ex) {
        page('<p class="err">Database export failed: ' . $e($ex->getMessage()) . '</p><p>Nothing was moved. Please tell Claude about this error.</p>');
    }
} else {
    $log[] = 'No wp-config.php found — skipped the database export.';
}

// ---- 2. Move files out of public_html ----
$dest = $base . "/wordpress-old-$stamp";
if (!mkdir($dest, 0750)) page('<p class="err">Could not create ' . $e($dest) . '. Database backup is done; files were not moved.</p><pre>' . $e(implode("\n", $log)) . '</pre>');
$moved = 0; $failed = [];
$items = array_diff(scandir($pub) ?: [], ['.', '..']);
usort($items, fn($a, $b) => ($a === 'solar-partner') <=> ($b === 'solar-partner')); // move ourselves last
foreach ($items as $it) {
    if (@rename($pub . '/' . $it, $dest . '/' . $it)) $moved++; else $failed[] = $it;
}
$log[] = "Moved $moved item(s) from public_html to " . basename($dest) . '/';
if ($failed) $log[] = 'Could NOT move: ' . implode(', ', $failed);
file_put_contents($lock, date('c') . ' — files in ' . basename($dest) . "\n");
file_put_contents($dest . '/_MOVED_BY_FINRUSH.txt', "Moved from public_html on " . date('c') . " to make room for the Finrush CRM.\nTo restore WordPress, move these files back into public_html.\n" . implode("\n", $log) . "\n");

page('<h1 class="ok">Done ✓</h1><div class="box"><pre>' . $e(implode("\n", $log)) . '</pre></div><p><b>public_html is now ' . ($failed ? 'almost' : '') . ' empty.</b> Tell Claude "WordPress moved" so the new site can be deployed.</p>');
