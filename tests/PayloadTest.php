<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Finansfatura\Payload;

// earsiv casing and totals
$p = Payload::earsiv(['vkn_tckn' => '11111111111', 'title' => 'Ahmet'], [
    ['title' => 'A', 'qty' => 2, 'unit_price' => 100.0, 'vat_rate' => 0.2],
    ['title' => 'B', 'qty' => 1, 'unit_price' => 50.0, 'vat_rate' => 0.2],
]);
eq($p['document_type'], 'EARSIV', 'document_type');
$c = $p['canonical'];
eq($c['DocumentType'], 'EARSIV', 'canonical DocumentType');
eq($c['Recipient']['VKNorTCKN'], '11111111111', 'recipient VKNorTCKN');
// 2*100 + 1*50 = 250 net, 20% KDV = 50, grand 300
eq($c['Totals']['SubtotalExclVAT'], 250.0, 'subtotal');
eq($c['Totals']['VatTotal'], 50.0, 'vat total');
eq($c['Totals']['GrandTotal'], 300.0, 'grand total');
eq($c['Lines'][0]['LineTotal'], 200.0, 'line 0 total');
ok(!array_key_exists('Issuer', $c), 'Issuer absent unless injected');

// issuer injection and efatura alias
$p = Payload::efatura(
    ['vkn_tckn' => '1234567801', 'title' => 'Kurum'],
    [['title' => 'X', 'qty' => 1, 'unit_price' => 10.0, 'vat_rate' => 0.2]],
    'urn:mail:defaultpk@example.com',
    ['issuer' => ['vkn_tckn' => '1234567801', 'title' => 'Satici']],
);
$c = $p['canonical'];
eq($c['DocumentType'], 'EFATURA', 'efatura DocumentType');
eq($c['RecipientAlias'], 'urn:mail:defaultpk@example.com', 'recipient alias');
eq($c['Issuer']['VKNorTCKN'], '1234567801', 'issuer VKNorTCKN');

// no float drift — banker's rounding on kuruş
// 3 * 33.33 = 99.99 exactly; 1.5 * 33.33 = 49.995 rounds half-even to 50.00
$p = Payload::earsiv(['vkn_tckn' => '1'], [
    ['title' => 'A', 'qty' => 3, 'unit_price' => '33.33', 'vat_rate' => 0],
    ['title' => 'B', 'qty' => '1.5', 'unit_price' => '33.33', 'vat_rate' => 0],
]);
eq($p['canonical']['Lines'][0]['LineTotal'], 99.99, 'line 0 exact 99.99');
eq($p['canonical']['Lines'][1]['LineTotal'], 50.0, 'line 1 half-even 50.00');
eq($p['canonical']['Totals']['SubtotalExclVAT'], 149.99, 'subtotal 149.99');

done('PayloadTest');
