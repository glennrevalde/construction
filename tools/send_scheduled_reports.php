<?php
declare(strict_types=1);

/**
 * E-mails memorized reports that have a schedule (daily / weekly / monthly) as CSV attachments.
 * Run once a day, e.g.  0 6 * * *  php /path/to/accounting-system/tools/send_scheduled_reports.php
 * With several companies the same line sends every active company's reports (or add --company=N for one).
 * Uses PHP mail(); configure sendmail / SMTP in php.ini for your server.
 * Each report runs with the permissions of the user who memorized it.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

// With several companies, each company's reports are sent from its own database. The cron line stays the
// same: without --company the script runs itself once per active company, each in a separate process,
// so nothing read for one company is ever at hand while another company's reports are made.
$opts = getopt('', ['company:']);
if (Master::enabled() && !isset($opts['company'])) {
    $ids = Master::sql()->column("SELECT id FROM companies WHERE status = 'active' ORDER BY id");
    if (!function_exists('passthru')) {
        out('passthru() is switched off: run this script once per company with --company=N (' . implode(', ', $ids) . ').');
        exit(1);
    }
    foreach ($ids as $cid) {
        out('--- company ' . $cid);
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --company=' . (int)$cid);
    }
    exit(0);
}
$tenant = Tenant::bootCli(isset($opts['company']) ? (int)$opts['company'] : null);
if ($tenant && $tenant['status'] !== 'active') {
    out('Company ' . $tenant['id'] . ' is not active: nothing sent.');
    exit(0);
}

$due = DB::all(
    "SELECT m.*, u.status FROM memorized_reports m JOIN users u ON u.id = m.created_by
      WHERE m.schedule IS NOT NULL AND m.email_to IS NOT NULL AND u.status = 'active'
        AND (m.last_sent_at IS NULL
             OR (m.schedule = 'daily' AND m.last_sent_at < CURDATE())
             OR (m.schedule = 'weekly' AND m.last_sent_at < CURDATE() - INTERVAL 6 DAY)
             OR (m.schedule = 'monthly' AND m.last_sent_at < CURDATE() - INTERVAL 1 MONTH))"
);
$company = Settings::get('company_name');
foreach ($due as $m) {
    Auth::actAs((int)$m['created_by']);
    if (!Access::page($m['report_key'])) {
        out("Skipped {$m['name']}: the owner no longer has access.");
        continue;
    }
    $def = Access::pageDef($m['report_key']);
    parse_str((string)$m['query_string'], $q);
    $_GET = $q;
    [$head, $rows] = Report::csvFor($def['params']['report'] ?? '');
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, $head, ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($fh, $r, ',', '"', '');
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    $boundary = 'b' . bin2hex(random_bytes(8));
    $file = preg_replace('/\W+/', '_', strtolower($m['name'])) . '.csv';
    $body = "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$m['name']} from $company, generated " . date('m/d/Y h:i A') . ".\r\n"
        . "--$boundary\r\nContent-Type: text/csv; name=\"$file\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$file\"\r\n\r\n"
        . chunk_split(base64_encode($csv)) . "--$boundary--";
    $ok = @mail((string)$m['email_to'], $m['name'] . ' - ' . $company, $body, "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$boundary\"");
    DB::update('memorized_reports', ['last_sent_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $m['id']]);
    Audit::log($ok ? 'report_emailed' : 'report_email_failed', 'home', 'memorized_report', (int)$m['id'], $m['name'], ['reason' => 'To ' . $m['email_to'], 'page_key' => $m['report_key']]);
    out(($ok ? 'Sent ' : 'FAILED ') . $m['name'] . ' to ' . $m['email_to']);
}
out(count($due) . ' scheduled report(s) processed.');
