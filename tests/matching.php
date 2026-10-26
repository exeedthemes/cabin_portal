<?php
require_once dirname(__DIR__) . '/matching.php';
function check_match($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE items (tag_no TEXT, item_description TEXT, other_info TEXT, pax_email TEXT, status TEXT, created_at TEXT)');
$insert = $db->prepare('INSERT INTO items VALUES (?, ?, ?, ?, ?, ?)');
foreach ([
    ['ID-1', 'Black leather wallet', 'LH 0123 2026-10-20', '', 'Found', '2026-10-20'],
    ['ID-2', 'Black leather wallet', 'LH 124', '', 'Found', '2026-10-21'],
    ['ID-3', 'Phone charger', 'LH 123', '', 'Found', '2026-10-22'],
    ['ID-4', 'Black leather wallet', 'LH 123', '', 'Delivered', '2026-10-23'],
    ['ID-5', 'Black leather wallet', 'LH 123', '', 'Disposed', '2026-10-23'],
    ['ID-6', 'Blue purse', 'LH 1234', '', 'Lost', '2026-10-24'],
    ['ID-7', 'Unknown object', '', 'PAX@example.com', 'Claimed', '2026-10-24'],
] as $item) $insert->execute($item);
$candidates = af_match_candidates($db);
check_match(count($candidates) === 5, 'Closed items included');
$report = ['item_description' => 'I lost a black leather wallet', 'flight_number' => 'lh-123', 'pax_email' => ''];
$matches = af_rank_matches($report, $candidates);
check_match(array_column($matches, 'tag_no') === ['ID-1', 'ID-2'], 'Incorrect ranking, unrelated flight match, or partial flight number');
check_match(in_array('Same flight', $matches[0]['match_reasons']), 'Flight normalization failed');
check_match(!in_array('Same flight', af_rank_matches(['item_description'=>'Blue purse', 'flight_number'=>'LH123'], $candidates)[0]['match_reasons']), 'Partial flight matched');
check_match(af_rank_matches(['item_description'=>'%', 'flight_number'=>'', 'pax_email'=>''], $candidates) === [], 'Wildcard or empty fields matched everything');
check_match(af_rank_matches(['item_description'=>'wallet', 'flight_number'=>'LH123'], $candidates, 1)[0]['tag_no'] === 'ID-1', 'Result limit or category ranking failed');
check_match(af_rank_matches(['pax_email'=>' pax@EXAMPLE.com '], $candidates)[0]['tag_no'] === 'ID-7', 'Email normalization failed');
check_match(af_rank_matches(['tag_no'=>'ID-7', 'pax_email'=>'pax@example.com'], $candidates) === [], 'Self match');
check_match(af_match_flight('2026-10-20') === '', 'Date treated as flight');
check_match(af_match_flight('U2 0123A') === 'U2123A', 'Alphanumeric flight normalization');
$reversed = af_rank_matches($report, array_reverse($candidates));
check_match(array_column($reversed, 'tag_no') === array_column($matches, 'tag_no'), 'Ranking depends on candidate order');
$otherStation = new PDO('sqlite::memory:');
$otherStation->exec('CREATE TABLE items (tag_no TEXT, item_description TEXT, other_info TEXT, pax_email TEXT, status TEXT, created_at TEXT)');
check_match(af_match_candidates($otherStation) === [], 'Station connection isolation failed');
echo "Matching checks passed (ranking, reasons, normalization, wildcard/empty input, closed items, self matches, limits, station isolation).\n";
