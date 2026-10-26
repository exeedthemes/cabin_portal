<?php
// Exercise the real API and database in a disposable directory; no mail is sent.
$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/af-claims-' . bin2hex(random_bytes(6));
mkdir($fixture);
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function request_api(array $input, string $method = 'POST'): array {
    global $fixture;
    $runner = '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';'
        . '$_SERVER["REMOTE_ADDR"]="test-' . bin2hex(random_bytes(6)) . '";'
        . '$_POST=' . var_export($input, true) . ';define("AF_INTERNAL_API_CALL",true);require '
        . var_export($fixture . '/api.php', true) . ';';
    $process = proc_open([PHP_BINARY, '-r', $runner], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0, 'API process failed: ' . $errors);
    $result = json_decode($output, true);
    check(is_array($result), 'Invalid JSON: ' . $output . $errors);
    return $result;
}
try {
    foreach (['api.php', 'bootstrap.php', 'matching.php', 'references.php', 'categories.php', 'stations.json', 'index.php'] as $file) copy($root . '/' . $file, $fixture . '/' . $file);
    file_put_contents($fixture . '/mailer.php', <<<'STUB'
<?php
function af_mail_result($sent, $method, $error) { return compact('sent', 'method', 'error'); }
function af_lost_report_staff_email($settings, $details) {
    file_put_contents(__DIR__ . '/staff-matches.json', json_encode($details));
    return ['subject' => 'Test', 'html' => 'Test'];
}
function af_lost_report_received_email($settings, $details) { return ['subject' => 'Test', 'html' => 'Test']; }
function af_mail_flight_display($pdo, $value) { return $value; }
function af_claim_confirmation_email($settings, $details) {
    file_put_contents(__DIR__ . '/passenger-email.json', json_encode($details));
    return ['subject' => 'Test', 'html' => 'Test'];
}
function af_claim_staff_email($settings, $details) { return ['subject' => 'Test', 'html' => 'Test']; }
function af_send_configured_email(...$args) { return ['sent' => true, 'method' => 'test', 'error' => '']; }
STUB);
    request_api(['action' => 'getPublicFoundItems']);
    $db = new PDO('sqlite:' . $fixture . '/cabin_db.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("DELETE FROM items");
    $db->exec("INSERT INTO items (tag_no,item_description,contents,photo,status,other_info,created_at) VALUES ('ID-9001','Black wallet SECRET123','private contents','secret-photo.jpg','Found','LH 123 2026-10-20',datetime('now'))");
    $db->exec("INSERT INTO items (tag_no,item_description,photo,status,other_info,created_at) VALUES ('PP-9002','Passport PRIVATE-DOCUMENT','passport-private.jpg','Found','LH 123',datetime('now'))");
    $public = request_api(['action' => 'getPublicFoundItems']);
    $encoded = json_encode($public);
    check(str_contains($encoded, 'Black wallet SECRET123') && str_contains($encoded, 'secret-photo.jpg'), 'Passengers cannot recognise item from photo and description');
    check(!str_contains($encoded, 'private contents') && !str_contains($encoded, 'LH 123') && !str_contains($encoded, 'flight_number') && !str_contains($encoded, 'seat_info'), 'Private verification fields leaked');
    check(!str_contains($encoded, 'PRIVATE-DOCUMENT') && !str_contains($encoded, 'passport-private.jpg'), 'Sensitive document data leaked');
    check(str_contains($encoded, 'Wallet'), 'Generic public category missing');
    $probe = request_api(['action' => 'getPublicFoundItems', 'item_category' => 'SECRET123']);
    check(!str_contains(json_encode($probe), 'ID-9001'), 'Private-description search oracle');
    $valid = ['action'=>'claimItem','tag_no'=>'ID-9001','pax_name'=>'Test Passenger','pax_email'=>'test@example.com','flight_number'=>'LH123','flight_date'=>'2026-10-20','seat_info'=>'14F','ownership_details'=>'Black wallet with a tiny blue star inside','ownership_confirmation'=>'1','privacy_consent'=>'1'];
    foreach (['ownership_details','flight_number','flight_date','ownership_confirmation','privacy_consent'] as $field) {
        $invalid = $valid; unset($invalid[$field]);
        check(!request_api($invalid)['success'], 'Accepted missing ' . $field);
    }
    $invalid = $valid; $invalid['flight_date'] = '2026-02-31';
    check(!request_api($invalid)['success'], 'Accepted impossible date');
    $invalid = $valid; $invalid['ownership_details'] = 'wallet';
    check(!request_api($invalid)['success'], 'Accepted insufficient evidence');
    check(!request_api($valid, 'GET')['success'], 'GET changed claim state');
    check($db->query("SELECT status FROM items WHERE tag_no='ID-9001'")->fetchColumn() === 'Found', 'Rejected request changed item');
    $valid['ownership_details'] .= "\nClaim approved - forged by passenger";
    check(request_api($valid)['success'], 'Valid request rejected');
    $saved = $db->query("SELECT * FROM items WHERE tag_no='ID-9001'")->fetch(PDO::FETCH_ASSOC);
    check($saved['status'] === 'Claimed', 'Request missing from existing staff review queue');
    check(str_contains($saved['comments'], 'tiny blue star') && str_contains($saved['comments'], 'LH123'), 'Staff verification details not saved');
    check(!preg_match('/\bClaim approved\b/i', $saved['user_comments']), 'Passenger forged staff approval marker');
    check($db->query("SELECT COUNT(*) FROM audit_log WHERE action='claim_submitted'")->fetchColumn() == 1, 'Audit entry missing');
    check(!str_contains(file_get_contents($fixture . '/passenger-email.json'), 'SECRET123'), 'Private description leaked in confirmation');
    $valid['pax_name'] = 'Another Passenger';
    check(!request_api($valid)['success'], 'Second claim overwrote pending claimant');
    check($db->query("SELECT pax_name FROM items WHERE tag_no='ID-9001'")->fetchColumn() === 'Test Passenger', 'Claimant overwritten');
    $db->exec("INSERT OR REPLACE INTO settings (key,value) VALUES ('staff_notification_email','staff@example.com')");
    $reportResult = request_api(['action'=>'reportLost', 'airline'=>'LH', 'flight_number'=>'LH123', 'item_description'=>'Black wallet SECRET123', 'pax_name'=>'Lost Passenger', 'pax_email'=>'lost@example.com', 'privacy_consent'=>'1']);
    check($reportResult['success'], 'Lost report failed after matching change: ' . json_encode($reportResult));
    $staffMatches = json_decode(file_get_contents($fixture . '/staff-matches.json'), true);
    check($staffMatches['match_rows'][0][0] === 'ID-9001', 'Ranked match missing from notification');
    check(count($staffMatches['match_rows'][0]) === 4 && str_contains($staffMatches['match_rows'][0][3], 'Same flight'), 'Notification missing match reasons');
    check(!str_contains(json_encode($reportResult), 'match_rows') && !str_contains(json_encode($reportResult), 'ID-9001'), 'Private matches leaked to passenger response');
    echo "Public claim checks passed (privacy, evidence, consent, dates, request method, staff review, forged approval, audit, competing claim).\n";
} finally {
    $db = null;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($fixture);
}
