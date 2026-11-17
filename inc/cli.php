<?php
/**
 * Command-line maintenance (run from the hosting account only; blocked on the web by inc/.htaccess).
 *   php inc/cli.php delete-partner FR0001   — deletes a partner who has no leads or payouts
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
if (!is_installed()) { echo "Not set up.\n"; exit(1); }
[$cmd, $arg] = [$argv[1] ?? '', strtoupper($argv[2] ?? '')];
if ($cmd === 'delete-partner' && preg_match('/^FR\d{4,}$/', $arg)) {
    $pt = one('SELECT * FROM partners WHERE code = ?', [$arg]);
    if (!$pt) { echo "No partner $arg.\n"; exit(1); }
    $n = (int) val('SELECT COUNT(*) FROM leads WHERE partner_id = ?', [$pt['id']]) + (int) val('SELECT COUNT(*) FROM bonuses WHERE partner_id = ?', [$pt['id']]);
    if ($n) { echo "$arg has leads or payouts; not deleted.\n"; exit(1); }
    q('DELETE FROM partners WHERE id = ?', [$pt['id']]);
    echo "Deleted partner $arg ({$pt['name']}, joined {$pt['created_at']}). Remaining partners: " . val('SELECT COUNT(*) FROM partners') . "\n";
    exit(0);
}
if ($cmd === 'errors') {
    $f = error_log_path();
    if (!is_file($f)) { echo "No errors logged yet.\n"; exit(0); }
    $l = file($f); echo count($l) . " line(s) in log; last 40:\n" . implode('', array_slice($l, -40)); exit(0);
}
if ($cmd === 'diag') {
    echo 'PHP ' . PHP_VERSION . ' | log_errors=' . ini_get('log_errors') . ' error_log=' . ini_get('error_log') . "\n";
    $urls = ['https://finrush.co.in/', 'https://finrush.co.in/partner/login.php', 'https://finrush.co.in/partner/register.php', 'https://finrush.co.in/admin/login.php', 'https://finrush.co.in/quote.php', 'https://www.finrush.co.in/', 'http://finrush.co.in/partner/login.php'];
    $summary = [];
    for ($i = 0; $i < 4; $i++) foreach ($urls as $u) {
        $c = curl_init($u);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 8, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => false, CURLOPT_USERAGENT => 'Mozilla/5.0 FinrushDiag']);
        $body = curl_exec($c);
        $code = curl_getinfo($c, CURLINFO_RESPONSE_CODE); $n = curl_getinfo($c, CURLINFO_REDIRECT_COUNT); $err = curl_error($c);
        $t = round(curl_getinfo($c, CURLINFO_TOTAL_TIME), 2);
        curl_close($c);
        $key = "$u => $code (redirects $n)" . ($err ? " ERR: $err" : '');
        $summary[$key] = ($summary[$key] ?? 0) + 1;
        if ($code >= 500 || $err) echo "  FAIL $u code=$code t={$t}s err=$err body=" . substr(preg_replace('/\s+/', ' ', strip_tags((string) $body)), 0, 200) . "\n";
    }
    foreach ($summary as $k => $n) echo "$n x $k\n";
    $home = getenv('HOME') ?: '/home/' . get_current_user();
    $globs = [private_dir() . '/public_html/error_log', private_dir() . '/public_html/*/error_log', private_dir() . '/logs/*', $home . '/logs/*', $home . '/.logs/*', $home . '/.logs/*/*', (string) ini_get('error_log')];
    foreach ($globs as $gp) foreach (glob($gp) ?: [] as $f) {
        if (!is_file($f) || !is_readable($f)) continue;
        $lines = @file($f) ?: [];
        $hits = array_values(array_filter($lines, fn($l) => stripos($l, 'finrush') !== false || stripos($l, 'PHP ') !== false));
        echo "LOG $f (" . filesize($f) . " bytes, " . count($hits) . " relevant)\n";
        foreach (array_slice($hits, -12) as $l) echo '  ' . substr(trim($l), 0, 300) . "\n";
    }
    exit(0);
}
echo "Usage: php inc/cli.php delete-partner FR0001\n"; exit(1);
