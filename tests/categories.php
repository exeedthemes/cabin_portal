<?php
require_once __DIR__ . '/../categories.php';
require_once __DIR__ . '/../fees.php';
$cases = [
    'Phone charger' => 'chargers', 'USB-C cable' => 'chargers', 'Powerbank black' => 'chargers',
    'Laptop bag with a MacBook' => 'bags', 'Bag containing passport and wallet' => 'bags',
    'Passport holder' => 'wallets', 'Wallet containing bank cards' => 'wallets',
    'Passport with private identity number' => 'documents', 'Driving licence' => 'documents',
    'Red iPhone' => 'phones', 'IPAD+PENCIL' => 'phones', 'Samsung Galaxy' => 'phones',
    'APPLE MACBOOK PRO-GRAY' => 'computers', 'silver mac book and sunglasses' => 'computers',
    'HEAD PHONE CASE' => 'audio', 'AirPods' => 'audio', 'Earbuds white case' => 'audio',
    'Canon camera' => 'cameras', 'Apple Watch' => 'watches', 'Nintendo game console' => 'electronics',
    'Schlüsselbund' => 'keys', 'Jewellery bracelet' => 'jewelry', 'Sun glasses' => 'glasses',
    'Pink hoodie' => 'clothing', 'Sneakers' => 'footwear', 'Regenschirm' => 'umbrellas',
    'Professional development book' => 'books', 'Transparent file with magazines inside' => 'stationery',
    'Kids toy' => 'toys', 'Baby bottle' => 'baby', 'Hearing aid' => 'medical',
    'Perfume' => 'toiletries', 'Water bottle' => 'bottles', 'Chocolate' => 'food',
    'Credit card' => 'cash', 'Tennis racket' => 'sports', 'Guitar' => 'instruments',
    'Neck pillow' => 'travel', 'Vape' => 'smoking', 'Unknown object SECRET123' => 'other',
    'Monkeys printed on a picture' => 'other', 'Schuhe' => 'footwear', 'Kopfhörer' => 'audio',
    'Medikamente' => 'medical', 'Reisepass' => 'documents'
];
foreach ($cases as $description => $expected) {
    $actual = af_detect_item_category($description);
    if ($actual !== $expected) throw new RuntimeException("$description: expected $expected, got $actual");
    if (af_fee_category($description) !== $actual) throw new RuntimeException('Billing and public classification must agree');
    if (str_contains(af_public_item_category($description), 'SECRET123')) throw new RuntimeException('Private text leaked');
}
$legacy = ['visible'=>false,'currency'=>'EUR','rates'=>[
    'electronics'=>['handling_cents'=>800,'daily_cents'=>150,'free_days'=>2,'period_days'=>7],
    'other'=>['handling_cents'=>300,'daily_cents'=>50,'free_days'=>1,'period_days'=>1]
]];
$migrated = af_fee_policy(['handover_fee_policy'=>json_encode($legacy)]);
if ($migrated['rates']['phones'] !== $legacy['rates']['electronics']) throw new RuntimeException('Preserve existing electronics rates');
if ($migrated['rates']['keys'] !== $legacy['rates']['other']) throw new RuntimeException('Preserve existing general-item rates');
if ($migrated['visible']) throw new RuntimeException('Category expansion must not change billing visibility');
$migrated['rates']['phones']['handling_cents'] = 999;
if ($migrated['rates']['computers']['handling_cents'] !== 800) throw new RuntimeException('Category rates must remain independently editable');
echo 'Category checks passed (' . count($cases) . ' descriptions; privacy, shared classification and fee compatibility).' . PHP_EOL;
