<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_user();
$what = g('what', 20);
if ($what === 'payouts' && $u['role'] !== 'admin') { http_response_code(403); exit('Admins only.'); }

function csv_out(string $name, array $header, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Ymd-His') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF"); // Excel-friendly UTF-8
    fputcsv($o, $header);
    foreach ($rows as $r) fputcsv($o, array_map(function ($v) { $v = (string) $v; return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v; }, $r));
    fclose($o);
    exit;
}

if ($what === 'leads') {
    $rows = all('SELECT l.ref, l.created_at, l.customer_name, l.customer_phone, l.customer_city, l.project_type, l.size_info, l.system_kw, l.pm_surya_ghar, l.stage, us.name assigned, p.code, p.name pname,
        l.project_value, l.commission_rate, l.commission_basis, l.commission_amount, l.commission_status, l.payment_received_on, l.commission_paid_on, l.commission_ref, l.lost_reason, l.updated_at
        FROM leads l LEFT JOIN partners p ON p.id = l.partner_id LEFT JOIN users us ON us.id = l.assigned_to ORDER BY l.created_at DESC');
    csv_out('finrush-leads', ['Ref', 'Created', 'Customer', 'Mobile', 'City', 'Type', 'Size/bill', 'System kW', 'PM Surya Ghar', 'Stage', 'Assigned to', 'Partner ID', 'Partner', 'Project value', 'Rate %', 'Worked out as', 'Commission', 'Commission status', 'Payment received', 'Commission paid', 'Payout ref', 'Closed reason', 'Updated'],
        array_map(fn($r) => array_values(array_merge($r, ['stage' => stage_label($r['stage'])])), $rows));
}
if ($what === 'partners') {
    $rows = all('SELECT code, name, mobile, email, city, district, occupation, status, kyc_status, pan, bank_name, bank_last4, ifsc, created_at, approved_at, last_login_at FROM partners ORDER BY created_at');
    csv_out('finrush-partners', ['Partner ID', 'Name', 'Mobile', 'Email', 'City', 'District/State', 'Occupation', 'Status', 'KYC', 'PAN', 'Bank', 'A/c last 4', 'IFSC', 'Joined', 'Approved', 'Last login'], array_map('array_values', $rows));
}
if ($what === 'payouts') {
    $c = all("SELECT 'Commission' kind, p.code, p.name, p.pan, l.ref, l.customer_name, l.commission_amount gross, l.commission_tds tds, l.commission_net net, l.commission_paid_on paid_on, l.commission_ref ref2
              FROM leads l JOIN partners p ON p.id = l.partner_id WHERE l.commission_status IN ('paid','reversed')");
    $b = all("SELECT 'Bonus' kind, p.code, p.name, p.pan, b.month ref, '' customer_name, b.amount gross, b.tds, b.net, b.paid_on, b.ref ref2 FROM bonuses b JOIN partners p ON p.id = b.partner_id");
    $rows = array_merge($c, $b);
    usort($rows, fn($x, $y) => strcmp((string) $y['paid_on'], (string) $x['paid_on']));
    csv_out('finrush-payouts', ['Type', 'Partner ID', 'Partner', 'PAN', 'Lead ref / month', 'Customer', 'Gross', 'TDS', 'Net', 'Paid on', 'Bank ref'], array_map('array_values', $rows));
}
http_response_code(400); echo 'Unknown export.';
