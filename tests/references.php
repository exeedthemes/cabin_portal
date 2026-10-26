<?php
require_once __DIR__ . '/../references.php';
$pdo = new PDO('sqlite::memory:');
foreach (['items', 'pending_reports', 'deleted_items'] as $table) $pdo->exec("CREATE TABLE $table (tag_no TEXT)");
$checks = 0;
function reference_check(string $actual, string $expected): void { global $checks; $checks++; if ($actual !== $expected) throw new RuntimeException("Expected $expected, got $actual"); }
reference_check(af_next_reference_tag($pdo), 'ID-0001');
reference_check(af_next_reference_tag($pdo, 'PP'), 'PP-0001');
$pdo->exec("INSERT INTO items VALUES ('ID-0001'), ('ID-0003'), ('PP-0007'), ('LR-9999'), ('ID-text')");
reference_check(af_next_reference_tag($pdo), 'ID-0004');
reference_check(af_next_reference_tag($pdo, 'PP'), 'PP-0008');
$pdo->exec("INSERT INTO pending_reports VALUES ('ID-0009')");
reference_check(af_next_reference_tag($pdo), 'ID-0010');
$pdo->exec("INSERT INTO deleted_items VALUES ('ID-0011'), ('PP-0012')");
reference_check(af_next_reference_tag($pdo), 'ID-0012');
reference_check(af_next_reference_tag($pdo, 'PP'), 'PP-0013');
$pdo->exec("INSERT INTO items VALUES ('ID-099999')");
reference_check(af_next_reference_tag($pdo), 'ID-100000');
$pdo->exec("DELETE FROM items WHERE tag_no = 'ID-099999'; INSERT INTO items VALUES ('ID-000042')");
reference_check(af_next_reference_tag($pdo), 'ID-000043');
echo "Reference checks passed ($checks assertions).\n";
