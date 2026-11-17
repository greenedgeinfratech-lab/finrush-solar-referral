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
echo "Usage: php inc/cli.php delete-partner FR0001\n"; exit(1);
