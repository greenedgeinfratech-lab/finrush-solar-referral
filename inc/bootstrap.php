<?php
/**
 * Finrush Solar Referral Programme — shared bootstrap
 * Config, database, sessions, CSRF, auth and small helpers.
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');
mb_internal_encoding('UTF-8');

define('APP_ROOT', dirname(__DIR__));
define('APP_NAME', 'Finrush Solar Referral Programme');

// ---------- error handling: log privately, show a friendly page ----------
function error_log_path(): string { return private_dir() . '/finrush-errors.log'; }
function log_error_line(string $msg): string {
    $id = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $line = '[' . date('Y-m-d H:i:s') . "] #$id " . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . ' | ' . str_replace(["\r", "\n"], ' ', $msg) . "\n";
    @file_put_contents(error_log_path(), $line, FILE_APPEND | LOCK_EX);
    return $id;
}
function friendly_error(int $code, string $id): void {
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "Error #$id\n"); return; }
    if (!headers_sent()) { http_response_code($code); header('Content-Type: text/html; charset=utf-8'); header('Retry-After: 5'); header('Cache-Control: no-store'); }
    $msg = $code === 503 ? 'The server is busy for a moment. Please wait a few seconds and try again.' : 'Something went wrong on our side. Please try again — if it keeps happening, WhatsApp us on +91 98370 67681.';
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Please try again | Finrush</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#f5f8f6;color:#14211a;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}.c{background:#fff;border:1px solid #dfe7e2;border-radius:16px;padding:28px;max-width:420px;text-align:center}a.b{display:inline-block;margin-top:16px;background:#15803d;color:#fff;padding:10px 20px;border-radius:999px;text-decoration:none;font-weight:700}small{color:#5a6b61}</style></head>'
       . '<body><div class="c"><h1 style="font-size:1.3rem">Please try again</h1><p>' . $msg . '</p><a class="b" href="javascript:location.reload()">Try again</a><p><small>Error reference: ' . $id . '</small></p></div></body></html>';
}
set_exception_handler(function (Throwable $e): void {
    $isDb = $e instanceof PDOException && preg_match('/2002|2006|1040|1203|1226|HY000|Too many connections|refused|gone away/i', $e->getMessage() . ' ' . $e->getCode());
    $id = log_error_line(get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    friendly_error($isDb ? 503 : 500, $id);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $id = log_error_line('FATAL ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line']);
        friendly_error(500, $id);
    }
});

/** Folder that holds public_html (private storage lives here, never inside public_html). */
function private_dir(): string {
    $root = realpath(APP_ROOT) ?: APP_ROOT;
    $pos = strpos($root, '/public_html');
    if ($pos !== false) return substr($root, 0, $pos);
    return dirname($root); // local development
}
function config_path(): string {
    return getenv('FINRUSH_CONFIG') ?: private_dir() . '/finrush-crm-config.php';
}
function app_config(): ?array {
    static $cfg = false;
    if ($cfg === false) {
        $p = config_path();
        $cfg = is_file($p) ? (require $p) : null;
    }
    return $cfg;
}
function is_installed(): bool { return app_config() !== null; }

/** URL prefix of the app (works whether it sits at / or in a subfolder). */
function base_url(): string {
    static $b = null;
    if ($b === null) {
        $doc = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
        $root = realpath(APP_ROOT) ?: APP_ROOT;
        $b = ($doc !== '' && strpos($root, $doc) === 0) ? rtrim(str_replace('\\', '/', substr($root, strlen($doc))), '/') : '';
    }
    return $b;
}
function url(string $path = '/'): string { return base_url() . $path; }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = app_config();
    if (!$c) { http_response_code(503); exit('This site is not set up yet.'); }
    $d = $c['db'];
    // The hosting account allows ~20 new DB connections per second across ALL its sites,
    // so a refused connection is usually momentary: retry a few times before giving up.
    $wait = [0, 300000, 800000, 1500000];
    foreach ($wait as $i => $us) {
        if ($us) usleep($us);
        try {
            $pdo = new PDO(
                'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=utf8mb4',
                $d['user'], $d['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]
            );
            if ($i > 0) log_error_line('DB connected after ' . $i . ' retr' . ($i === 1 ? 'y' : 'ies'));
            break;
        } catch (PDOException $e) {
            if ($i === count($wait) - 1) throw $e;
        }
    }
    // Pin the connection collation so text comparisons never mix server defaults (Hostinger uses utf8mb4_unicode_ci).
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+05:30'");
    ensure_schema($pdo);
    return $pdo;
}

/** Small in-place upgrades for databases created by earlier versions. Cheap: one indexed read per request. */
const SCHEMA_VERSION = 2;
function ensure_schema(PDO $pdo): void {
    $v = (int) ($pdo->query("SELECT v FROM settings WHERE k = 'schema_version'")->fetchColumn() ?: 1);
    if ($v >= SCHEMA_VERSION) return;
    $has = fn(string $col) => (bool) $pdo->query("SHOW COLUMNS FROM leads LIKE " . $pdo->quote($col))->fetch();
    if (!$has('system_kw')) $pdo->exec('ALTER TABLE leads ADD COLUMN system_kw DECIMAL(8,2) NULL AFTER size_info');
    if (!$has('commission_basis')) $pdo->exec('ALTER TABLE leads ADD COLUMN commission_basis VARCHAR(160) NULL AFTER commission_amount');
    if (!$has('commission_override')) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN commission_override DECIMAL(5,2) NULL AFTER commission_rate');
        // earlier versions stored the industrial per-lead rate in commission_rate before calculation
        $pdo->exec("UPDATE leads SET commission_override = commission_rate WHERE commission_status = 'none' AND commission_rate IS NOT NULL");
    }
    if (!$pdo->query("SELECT 1 FROM settings WHERE k = 'commission_plans'")->fetch()) {
        $get = function (string $k, string $d) use ($pdo) { $x = $pdo->query('SELECT v FROM settings WHERE k = ' . $pdo->quote($k))->fetchColumn(); return $x === false ? $d : (string) $x; };
        $plans = [
            ['type' => 'residential', 'min' => 0, 'max' => 10, 'mode' => 'percent', 'value' => (float) $get('rate_residential', '3')],
            ['type' => 'commercial', 'min' => 0, 'max' => 100, 'mode' => 'percent', 'value' => (float) $get('rate_commercial', '1.5')],
            ['type' => 'industrial', 'min' => 0, 'max' => null, 'mode' => 'percent', 'value' => (float) $get('rate_industrial_max', '1')],
        ];
        $pdo->prepare("INSERT INTO settings (k, v) VALUES ('commission_plans', ?)")->execute([json_encode($plans)]);
    }
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string) SCHEMA_VERSION]);
}
function q(string $sql, array $args = []): PDOStatement { $s = db()->prepare($sql); $s->execute($args); return $s; }
function one(string $sql, array $args = []): ?array { $r = q($sql, $args)->fetch(); return $r ?: null; }
function all(string $sql, array $args = []): array { return q($sql, $args)->fetchAll(); }
function val(string $sql, array $args = []) { $r = q($sql, $args)->fetchColumn(); return $r === false ? null : $r; }

// ---------- settings ----------
function setting(string $k, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (all('SELECT k, v FROM settings') as $r) $cache[$r['k']] = $r['v'];
    }
    return $cache[$k] ?? $default;
}
function set_setting(string $k, string $v): void {
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}
function bonus_slabs(): array {
    $s = json_decode((string) setting('bonus_slabs', '[]'), true) ?: [];
    usort($s, fn($a, $b) => $a[0] <=> $b[0]);
    return $s;
}
function bonus_for(float $sales): float {
    $b = 0;
    foreach (bonus_slabs() as [$min, $amt]) if ($sales >= $min) $b = (float) $amt;
    return $b;
}

// ---------- session & security ----------
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('FRSESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => base_url() . '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400); exit('Your session expired. Please go back, refresh the page and try again.');
    }
}
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }
function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function p(string $k, int $max = 500): string {
    $v = isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $v));
    return mb_substr($v, 0, $max);
}
function g(string $k, int $max = 200): string {
    $v = isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : '';
    return mb_substr(trim($v), 0, $max);
}
function redirect(string $path): void { header('Location: ' . url($path)); exit; }
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function take_flash(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function client_ip(): string { return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45); }

function clean_mobile(string $m): string {
    $d = preg_replace('/\D/', '', $m);
    if (strlen($d) === 12 && strpos($d, '91') === 0) $d = substr($d, 2);
    if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
    return $d;
}
function valid_mobile(string $m): bool { return (bool) preg_match('/^[6-9][0-9]{9}$/', $m); }
function password_problem(string $pw, string $confirm): ?string {
    if (mb_strlen($pw) < 8) return 'Password must be at least 8 characters.';
    if ($pw !== $confirm) return 'The two passwords do not match.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) return 'Password must contain letters and numbers.';
    return null;
}
function temp_password(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
    return $s . random_int(10, 99);
}

/** Simple rate limiter backed by the login_attempts table. */
function too_many(string $scope, string $ident, int $max, int $seconds): bool {
    q('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    $n = (int) val('SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND (ident = ? OR ip = ?) AND created_at > (NOW() - INTERVAL ? SECOND)', [$scope, $ident, client_ip(), $seconds]);
    return $n >= $max;
}
function note_attempt(string $scope, string $ident): void {
    q('INSERT INTO login_attempts (scope, ident, ip, created_at) VALUES (?, ?, ?, NOW())', [$scope, $ident, client_ip()]);
}
function clear_attempts(string $scope, string $ident): void { q('DELETE FROM login_attempts WHERE scope = ? AND ident = ?', [$scope, $ident]); }

// ---------- auth ----------
function current_partner(): ?array {
    if (empty($_SESSION['partner_id'])) return null;
    static $p = false;
    if ($p === false) $p = one('SELECT * FROM partners WHERE id = ?', [$_SESSION['partner_id']]);
    if (!$p || $p['status'] === 'suspended' || $p['status'] === 'rejected') { unset($_SESSION['partner_id']); return null; }
    return $p;
}
function require_partner(bool $allowMustChange = false): array {
    $p = current_partner();
    if (!$p) redirect('/partner/login.php');
    if ($p['must_change'] && !$allowMustChange) redirect('/partner/profile.php?change=1');
    return $p;
}
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    static $u = false;
    if ($u === false) $u = one('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['user_id']]);
    if (!$u) { unset($_SESSION['user_id']); return null; }
    return $u;
}
function require_user(?string $role = null, bool $allowMustChange = false): array {
    $u = current_user();
    if (!$u) redirect('/admin/login.php');
    if ($u['must_change'] && !$allowMustChange) redirect('/admin/profile.php?change=1');
    if ($role === 'admin' && $u['role'] !== 'admin') { http_response_code(403); exit('Only admins can open this page.'); }
    return $u;
}

// ---------- domain ----------
const STAGES = [
    'new'              => ['New lead', 'Received — our team will call the customer soon'],
    'contacted'        => ['Customer contacted', 'Our team has spoken to the customer'],
    'survey'           => ['Site survey done', 'Engineers have visited the site'],
    'quotation'        => ['Quotation sent', 'The customer has received a quotation'],
    'order'            => ['Order confirmed', 'The customer has confirmed the order'],
    'installed'        => ['Installed', 'The solar system is installed'],
    'payment_received' => ['Payment received', 'Customer has paid in full — your commission is due'],
    'commission_paid'  => ['Commission paid', 'Your commission has been paid'],
    'lost'             => ['Closed', 'This lead did not convert'],
];
const PROJECT_TYPES = [
    'residential' => 'Residential (home)',
    'commercial'  => 'Commercial / shop / school / society',
    'industrial'  => 'Industrial / factory',
    'unknown'     => 'Not sure yet',
];
function stage_label(string $s): string { return STAGES[$s][0] ?? $s; }
function stage_badge(string $s): string { return '<span class="badge st-' . h($s) . '">' . h(stage_label($s)) . '</span>'; }
function inr($n): string {
    if ($n === null || $n === '') return '—';
    $n = round((float) $n, 2);
    $neg = $n < 0; $n = abs($n);
    $int = (string) floor($n);
    $dec = $n - floor($n);
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int = $rest . ',' . $last3;
    }
    return ($neg ? '-' : '') . '₹' . $int . ($dec >= 0.005 ? sprintf('.%02d', round($dec * 100)) : '');
}
function dt($s, bool $time = false): string {
    if (!$s) return '—';
    $t = strtotime((string) $s);
    return $t ? date($time ? 'd M Y, h:i A' : 'd M Y', $t) : '—';
}
/** [first day of month, first day of next month] for a 'YYYY-MM' string — compare dates, not formatted text. */
function month_range(string $ym): array {
    $start = $ym . '-01';
    return [$start, date('Y-m-d', strtotime($start . ' +1 month'))];
}
function partner_code(int $id): string { return 'FR' . str_pad((string) $id, 4, '0', STR_PAD_LEFT); }
function new_lead_ref(): string { return 'L' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2))); }
function wa_link(string $mobile, string $text = ''): string {
    return 'https://wa.me/91' . rawurlencode(clean_mobile($mobile)) . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/** Find an open (not closed) lead for this customer mobile. */
function open_lead_for_phone(string $phone, ?int $exceptId = null): ?array {
    $sql = "SELECT * FROM leads WHERE customer_phone = ? AND stage <> 'lost'" . ($exceptId ? ' AND id <> ' . (int) $exceptId : '') . ' ORDER BY id LIMIT 1';
    return one($sql, [$phone]);
}

function add_event(int $leadId, string $actorType, ?int $actorId, string $kind, string $body, bool $partnerVisible, ?string $from = null, ?string $to = null): void {
    q('INSERT INTO lead_events (lead_id, actor_type, actor_id, kind, body, from_stage, to_stage, partner_visible, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
      [$leadId, $actorType, $actorId, $kind, $body, $from, $to, $partnerVisible ? 1 : 0]);
    q('UPDATE leads SET updated_at = NOW() WHERE id = ?', [$leadId]);
}

function notify_emails(): array {
    return array_values(array_filter(array_map('trim', explode(',', (string) setting('notify_emails', '')))));
}
function send_mail(array $to, string $subject, string $body): void {
    $from = (string) setting('mail_from', 'noreply@finrush.co.in');
    $headers = "From: Finrush Referral <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    foreach ($to as $addr) if (filter_var($addr, FILTER_VALIDATE_EMAIL)) @mail($addr, $subj, $body, $headers, '-f' . $from);
}
function site_origin(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'finrush.co.in');
}

// ---------- commission plans (editable in Settings) ----------
const PLAN_MODES = ['percent' => '% of project value', 'fixed' => 'Fixed ₹ per project', 'per_kw' => '₹ per kW'];
/** Plans: [{type, min, max|null, mode, value}]. A plan covers kW MORE THAN min (0 = from zero) UP TO AND INCLUDING max (null = no limit). */
function commission_plans(): array {
    $p = json_decode((string) setting('commission_plans', '[]'), true) ?: [];
    $ord = ['residential' => 1, 'commercial' => 2, 'industrial' => 3];
    usort($p, fn($a, $b) => [$ord[$a['type']] ?? 9, (float) $a['min']] <=> [$ord[$b['type']] ?? 9, (float) $b['min']]);
    return $p;
}
function plan_range_label(array $pl): string {
    $min = (float) $pl['min']; $max = $pl['max'] === null || $pl['max'] === '' ? null : (float) $pl['max'];
    $f = fn($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    if ($min <= 0 && $max === null) return 'any size';
    if ($min <= 0) return 'up to ' . $f($max) . ' kW';
    if ($max === null) return 'above ' . $f($min) . ' kW';
    return 'above ' . $f($min) . ' up to ' . $f($max) . ' kW';
}
function plan_value_label(array $pl): string {
    $v = (float) $pl['value'];
    if ($pl['mode'] === 'fixed') return inr($v) . ' per project';
    if ($pl['mode'] === 'per_kw') return inr($v) . ' per kW';
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . '% of project value';
}
function find_plan(string $type, float $kw): ?array {
    foreach (commission_plans() as $pl) {
        if ($pl['type'] !== $type) continue;
        $min = (float) $pl['min']; $max = $pl['max'] === null || $pl['max'] === '' ? null : (float) $pl['max'];
        if (($min <= 0 ? $kw >= 0 : $kw > $min) && ($max === null || $kw <= $max)) return $pl;
    }
    return null;
}
/**
 * Work out a lead's commission. Uses the per-lead override % if one is set, otherwise the matching kW plan.
 * Returns ['amount' => float, 'rate' => ?float (percent, null for ₹ plans), 'basis' => string] or ['error' => string].
 */
function commission_for(array $lead): array {
    $type = $lead['project_type'];
    $value = (float) ($lead['project_value'] ?? 0);
    $kw = $lead['system_kw'] !== null && $lead['system_kw'] !== '' ? (float) $lead['system_kw'] : null;
    $types = ['residential' => 'Residential', 'commercial' => 'Commercial', 'industrial' => 'Industrial'];
    if (!isset($types[$type])) return ['error' => 'Set the project type in Lead details first.'];
    if (($lead['commission_override'] ?? null) !== null && $lead['commission_override'] !== '') {
        $r = (float) $lead['commission_override'];
        if ($value <= 0) return ['error' => 'Enter the project value (excluding GST) in Lead details first.'];
        return ['amount' => round($value * $r / 100, 2), 'rate' => $r, 'basis' => 'Special rate ' . rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.') . '% of ' . inr($value)];
    }
    if ($kw === null || $kw <= 0) return ['error' => 'Enter the final system size (kW) in Lead details first.'];
    $pl = find_plan($type, $kw);
    if (!$pl) return ['error' => 'No commission plan covers a ' . $kw . ' kW ' . strtolower($types[$type]) . ' project. Add one in Settings, or enter a special rate on this lead.'];
    $label = $types[$type] . ' ' . plan_range_label($pl) . ' plan';
    if ($pl['mode'] === 'fixed') return ['amount' => round((float) $pl['value'], 2), 'rate' => null, 'basis' => $label . ': ' . inr($pl['value']) . ' fixed'];
    if ($pl['mode'] === 'per_kw') return ['amount' => round((float) $pl['value'] * $kw, 2), 'rate' => null, 'basis' => $label . ': ' . inr($pl['value']) . ' × ' . rtrim(rtrim(number_format($kw, 2, '.', ''), '0'), '.') . ' kW'];
    if ($value <= 0) return ['error' => 'Enter the project value (excluding GST) in Lead details first.'];
    return ['amount' => round($value * (float) $pl['value'] / 100, 2), 'rate' => (float) $pl['value'], 'basis' => $label . ': ' . rtrim(rtrim(number_format((float) $pl['value'], 2, '.', ''), '0'), '.') . '% of ' . inr($value)];
}
/** Short text for showing how a commission was worked out. */
function commission_rate_text(array $l): string {
    if ($l['commission_status'] === 'none') return '—';
    if ($l['commission_rate'] !== null && $l['commission_rate'] !== '') return rtrim(rtrim((string) (float) $l['commission_rate'], '0'), '.') . '%';
    return 'Fixed';
}

if (PHP_SAPI !== 'cli') start_session();
