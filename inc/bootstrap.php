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
    $pdo = new PDO(
        'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=utf8mb4',
        $d['user'], $d['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET time_zone = '+05:30'");
    return $pdo;
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
    'residential' => 'Residential (home, up to 10 kW)',
    'commercial'  => 'Commercial / society / home above 10 kW (up to 100 kW)',
    'industrial'  => 'Industrial (above 100 kW)',
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

/** Commission rate for a lead, in percent. */
function commission_rate_for(array $lead, ?float $industrialRate = null): ?float {
    switch ($lead['project_type']) {
        case 'residential': return (float) setting('rate_residential', '3');
        case 'commercial':  return (float) setting('rate_commercial', '1.5');
        case 'industrial':
            $max = (float) setting('rate_industrial_max', '1');
            $r = $industrialRate ?? ($lead['commission_rate'] !== null ? (float) $lead['commission_rate'] : null);
            return $r === null ? null : min(max($r, 0), $max);
    }
    return null;
}

if (PHP_SAPI !== 'cli') start_session();
