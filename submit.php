<?php
/**
 * Finrush Solar Referral Programme — form handler
 * Saves partner registrations and customer referrals to CSV (outside public_html)
 * and sends an email notification.
 */

// ---------- Settings ----------
$NOTIFY_TO   = ['finrushsmart@gmail.com', 'greenedgeinfratech@gmail.com'];
$MAIL_FROM   = 'noreply@finrush.co.in';
$RATE_MAX    = 5;     // submissions allowed per IP...
$RATE_WINDOW = 600;   // ...per this many seconds
$MIN_SECONDS = 3;     // reject forms submitted faster than this (bots)
// ------------------------------

date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($ok, $text, $code = 200) {
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true, 'message' => $text] : ['ok' => false, 'error' => $text]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(false, 'Method not allowed.', 405);

// Storage: prefer a folder outside public_html; fall back to a protected local folder.
$dataDir = dirname(__DIR__, 2) . '/solar-partner-data';
if (!is_dir($dataDir) && !@mkdir($dataDir, 0750, true)) {
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) @mkdir($dataDir, 0750, true);
    if (!file_exists($dataDir . '/.htaccess')) @file_put_contents($dataDir . '/.htaccess', "Require all denied\nDeny from all\n");
}
if (!is_dir($dataDir) || !is_writable($dataDir)) out(false, 'Server storage error. Please WhatsApp us on +91 98370 67681.', 500);

$clean = function ($key, $max = 200) {
    $v = isset($_POST[$key]) ? (string) $_POST[$key] : '';
    $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));
    return mb_substr($v, 0, $max);
};

// Spam checks
if ($clean('website') !== '') out(true, 'Thank you!'); // honeypot: pretend success
$ts = (int) $clean('ts', 20);
if ($ts <= 0 || (time() - $ts) < $MIN_SECONDS) out(false, 'Please take a moment to review the form and submit again.', 400);

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateFile = $dataDir . '/.rate.json';
$fh = @fopen($rateFile, 'c+');
if ($fh && flock($fh, LOCK_EX)) {
    $raw = stream_get_contents($fh);
    $rate = json_decode($raw ?: '{}', true) ?: [];
    $now = time();
    foreach ($rate as $k => $list) {
        $rate[$k] = array_values(array_filter($list, function ($t) use ($now, $RATE_WINDOW) { return $t > $now - $RATE_WINDOW; }));
        if (!$rate[$k]) unset($rate[$k]);
    }
    $key = hash('sha256', $ip);
    if (count($rate[$key] ?? []) >= $RATE_MAX) { flock($fh, LOCK_UN); fclose($fh); out(false, 'Too many submissions. Please try again in a few minutes.', 429); }
    $rate[$key][] = $now;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($rate));
    flock($fh, LOCK_UN); fclose($fh);
}

$type = $clean('form_type', 20);
$isPhone = function ($p) { return (bool) preg_match('/^[6-9][0-9]{9}$/', $p); };
if ($clean('consent', 5) !== 'yes') out(false, 'Please tick the consent box to continue.', 400);

if ($type === 'partner') {
    $row = [
        'name'       => $clean('name', 80),
        'phone'      => preg_replace('/\D/', '', $clean('phone', 15)),
        'email'      => $clean('email', 100),
        'city'       => $clean('city', 60),
        'district'   => $clean('district', 80),
        'occupation' => $clean('occupation', 40),
        'message'    => $clean('message', 500),
    ];
    if ($row['name'] === '' || $row['city'] === '' || $row['district'] === '' || $row['occupation'] === '') out(false, 'Please fill all required fields.', 400);
    if (!$isPhone($row['phone'])) out(false, 'Please enter a valid 10-digit Indian mobile number.', 400);
    if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) out(false, 'Please enter a valid email address or leave it blank.', 400);
    $file = 'partners.csv';
    $subject = 'Finrush: new referral partner: ' . $row['name'] . ' (' . $row['city'] . ')';
    $thanks = 'Thank you for registering! The Finrush team will call or WhatsApp you within 2 working days to complete verification and share your Partner ID.';
} elseif ($type === 'referral') {
    $row = [
        'partner_name'   => $clean('partner_name', 80),
        'partner_phone'  => preg_replace('/\D/', '', $clean('partner_phone', 15)),
        'partner_id'     => $clean('partner_id', 30),
        'customer_name'  => $clean('customer_name', 80),
        'customer_phone' => preg_replace('/\D/', '', $clean('customer_phone', 15)),
        'customer_city'  => $clean('customer_city', 80),
        'project_type'   => $clean('project_type', 60),
        'size'           => $clean('size', 40),
        'pm_surya_ghar'  => $clean('pm_surya_ghar', 10),
        'message'        => $clean('message', 500),
    ];
    if ($row['partner_name'] === '' || $row['customer_name'] === '' || $row['customer_city'] === '' || $row['project_type'] === '') out(false, 'Please fill all required fields.', 400);
    if (!$isPhone($row['partner_phone']) || !$isPhone($row['customer_phone'])) out(false, 'Please enter valid 10-digit Indian mobile numbers for you and the customer.', 400);
    if ($row['partner_phone'] === $row['customer_phone']) out(false, 'The customer mobile number must be different from yours.', 400);
    $file = 'referrals.csv';
    $subject = 'Finrush: new solar referral: ' . $row['customer_name'] . ' (' . $row['customer_city'] . ') from ' . $row['partner_name'];
    $thanks = 'Referral received. Our solar team will contact the customer soon, and Finrush will update you on WhatsApp. Referrals are credited to the first valid submission for each customer.';
} else {
    out(false, 'Invalid form.', 400);
}

$ref = strtoupper(($type === 'partner' ? 'P' : 'R') . date('ymd') . '-' . bin2hex(random_bytes(3)));
$record = array_merge(['ref' => $ref, 'submitted_at' => date('Y-m-d H:i:s'), 'ip' => $ip], $row);

// Duplicate referral check (same customer mobile already referred)
$path = $dataDir . '/' . $file;
$dupe = '';
if ($type === 'referral' && is_readable($path) && ($r = fopen($path, 'r'))) {
    $hdr = fgetcsv($r);
    $col = $hdr ? array_search('customer_phone', $hdr) : false;
    while ($col !== false && ($line = fgetcsv($r)) !== false) {
        if (isset($line[$col]) && ltrim($line[$col], "'") === $row['customer_phone']) { $dupe = 'POSSIBLE DUPLICATE: this customer mobile was referred before (first: ' . $line[0] . ').'; break; }
    }
    fclose($r);
}
$record['note'] = $dupe;

// Write CSV (guard against spreadsheet formula injection)
$safe = array_map(function ($v) { $v = (string) $v; return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v; }, $record);
$new = !file_exists($path);
$w = fopen($path, 'a');
if (!$w || !flock($w, LOCK_EX)) out(false, 'Server storage error. Please WhatsApp us on +91 98370 67681.', 500);
if ($new) fputcsv($w, array_keys($record));
fputcsv($w, $safe);
flock($w, LOCK_UN); fclose($w);

// Email notification (best effort; the CSV is the record of truth)
$body = "Reference: $ref\n";
foreach ($record as $k => $v) { if ($k !== 'ref') $body .= str_pad(ucwords(str_replace('_', ' ', $k)) . ':', 18) . $v . "\n"; }
$body .= "\nSubmitted via finrush.co.in/solar-partner/ (Finrush Solar Referral Programme; execution partner Greenedge Infratech)\n";
$headers = "From: Finrush Solar Referral <$MAIL_FROM>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
if (!empty($row['email'])) $headers .= 'Reply-To: ' . $row['email'] . "\r\n";
$encSubject = '=?UTF-8?B?' . base64_encode(($dupe ? '[DUPLICATE?] ' : '') . $subject) . '?=';
foreach ($NOTIFY_TO as $to) { @mail($to, $encSubject, $body, $headers, '-f' . $MAIL_FROM); }

out(true, $thanks . ' Your reference number is ' . $ref . '.');
