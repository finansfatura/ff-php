<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Finansfatura\Payload;

// earsiv casing and totals
$p = Payload::earsiv(['vkn_tckn' => '11111111111', 'title' => 'Ahmet'], [
    ['title' => 'A', 'qty' => 2, 'unit_price' => 100.0, 'vat_rate' => 0.2],
    ['title' => 'B', 'qty' => 1, 'unit_price' => 50.0, 'vat_rate' => 0.2],
], ['transactionHeaderId' => 't-1']);
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
    ['issuer' => ['vkn_tckn' => '1234567801', 'title' => 'Satici'], 'transactionHeaderId' => 't-1'],
);
$c = $p['canonical'];
eq($c['DocumentType'], 'EFATURA', 'efatura DocumentType');
eq($c['RecipientAlias'], 'urn:mail:defaultpk@example.com', 'recipient alias');
eq($c['Issuer']['VKNorTCKN'], '1234567801', 'issuer VKNorTCKN');

// no float drift — banker's rounding on kuruş
// 3 * 33.33 = 99.99 exactly; 1.5 * 33.33 = 49.995 rounds half-even to 50.00
// (%0 KDV olduğu için istisna sebebi de şart — bkz. aşağıdaki istisna testi)
$p = Payload::earsiv(['vkn_tckn' => '1'], [
    ['title' => 'A', 'qty' => 3, 'unit_price' => '33.33', 'vat_rate' => 0],
    ['title' => 'B', 'qty' => '1.5', 'unit_price' => '33.33', 'vat_rate' => 0],
], ['transactionHeaderId' => 't-1', 'exemptionCode' => '301', 'exemptionReason' => '11/1-a Mal ihracatı']);
eq($p['canonical']['Lines'][0]['LineTotal'], 99.99, 'line 0 exact 99.99');
eq($p['canonical']['Lines'][1]['LineTotal'], 50.0, 'line 1 half-even 50.00');
eq($p['canonical']['Totals']['SubtotalExclVAT'], 149.99, 'subtotal 149.99');

// transaction_header_id attached, alias defaults to empty
$lines = [['title' => 'A', 'qty' => 1, 'unit_price' => 100.0, 'vat_rate' => 0.2]];
$p = Payload::earsiv(['vkn_tckn' => '11111111111'], $lines, ['transactionHeaderId' => '9f1c2d3e-4a5b']);
eq($p['transaction_header_id'], '9f1c2d3e-4a5b', 'transaction_header_id attached');
// empty alias is sent explicitly — that's what makes the server resolve it
eq($p['canonical']['RecipientAlias'], '', 'alias defaults to empty string');
// the sale is mandatory — every document hangs off one
throwsMatching(
    fn() => Payload::earsiv(['vkn_tckn' => '1'], $lines),
    \InvalidArgumentException::class,
    'sale-less document rejected'
);
// …except a refund: attaching it would count the sale twice. Ama iade ASIL
// FATURA ATFI ister — GİB atıfsız iadeyi reddediyor.
$iade = Payload::earsiv(['vkn_tckn' => '1'], $lines, [
    'invoiceTypeCode' => 'IADE',
    'returnInfo' => ['number' => 'FF32026000000123', 'issue_date' => '2026-09-27'],
]);
ok(!array_key_exists('transaction_header_id', $iade), 'IADE is issued unattached');
eq($iade['canonical']['InvoiceTypeCode'], 'IADE', 'IADE invoice type code');

// --- KDV istisnası -----------------------------------------------------------
// İstisna BELGE DÜZEYİNDE verilir, yalnız %0 satırlara iner. Karışık belgede
// KDV'li satıra iliştirilse istisna beyanı o satırı da kapsamış görünürdü.
$p = Payload::earsiv(['vkn_tckn' => '1'], [
    ['title' => 'İstisnalı', 'qty' => 1, 'unit_price' => 100.0, 'vat_rate' => 0],
    ['title' => 'KDV\'li', 'qty' => 1, 'unit_price' => 100.0, 'vat_rate' => 0.2],
], ['transactionHeaderId' => 't-1', 'exemptionCode' => '301', 'exemptionReason' => '11/1-a Mal ihracatı']);
$outLines = $p['canonical']['Lines'];
eq($outLines[0]['TaxExemptionReasonCode'], '301', '%0 satıra istisna kodu iner');
eq($outLines[0]['TaxExemptionReason'], '11/1-a Mal ihracatı', '%0 satıra istisna metni iner');
ok(!isset($outLines[1]['TaxExemptionReasonCode']), "KDV'li satıra istisna İLİŞTİRİLMEZ");

// %0 satır + istisna yok → yerelde patlar. Sunucu zaten
// ERROR_INVOICE_ZERO_VAT_NEEDS_EXEMPTION döner; burada hata alanı adıyla gelir.
throwsMatching(
    fn() => Payload::earsiv(['vkn_tckn' => '1'],
        [['title' => 'A', 'qty' => 1, 'unit_price' => 100.0, 'vat_rate' => 0]],
        ['transactionHeaderId' => 't-1']),
    \InvalidArgumentException::class,
    '%0 KDV istisna sebebi olmadan reddedilir'
);

// Kod var metin yok → yine patlar: GİB cbc:TaxExemptionReason'ı boş kabul etmiyor.
throwsMatching(
    fn() => Payload::earsiv(['vkn_tckn' => '1'],
        [['title' => 'A', 'qty' => 1, 'unit_price' => 100.0, 'vat_rate' => 0]],
        ['transactionHeaderId' => 't-1', 'exemptionCode' => '301']),
    \InvalidArgumentException::class,
    'istisna kodu metinsiz reddedilir'
);

// --- Senaryo -----------------------------------------------------------------
// TEMEL ile TİCARİ farkı hukuki: TEMEL'de alıcı yanıt veremez, belge kesindir.
$p = Payload::efatura(['vkn_tckn' => '1234567801'],
    [['title' => 'A', 'qty' => 1, 'unit_price' => 100.0, 'vat_rate' => 0.2]],
    '', ['transactionHeaderId' => 't-1', 'scenario' => 'temelfatura']);
eq($p['canonical']['Scenario'], 'TEMELFATURA', 'senaryo büyük harfe çevrilir');

// Gönderilmezse hiç yazılmaz — sunucu TICARIFATURA'ya normalleştirir.
$p = Payload::efatura(['vkn_tckn' => '1234567801'],
    [['title' => 'A', 'qty' => 1, 'unit_price' => 100.0, 'vat_rate' => 0.2]],
    '', ['transactionHeaderId' => 't-1']);
ok(!isset($p['canonical']['Scenario']), 'senaryo verilmezse gönderilmez');

// e-Arşiv'de senaryo seçimi yok: sunucuda sessizce ezilmek yerine burada patlar.
throwsMatching(
    fn() => Payload::earsiv(['vkn_tckn' => '1'],
        [['title' => 'A', 'qty' => 1, 'unit_price' => 100.0, 'vat_rate' => 0.2]],
        ['transactionHeaderId' => 't-1', 'scenario' => 'TEMELFATURA']),
    \InvalidArgumentException::class,
    'e-Arşiv senaryo kabul etmez'
);
throwsMatching(
    fn() => Payload::efatura(['vkn_tckn' => '1234567801'],
        [['title' => 'A', 'qty' => 1, 'unit_price' => 100.0, 'vat_rate' => 0.2]],
        '', ['transactionHeaderId' => 't-1', 'scenario' => 'IHRACAT']),
    \InvalidArgumentException::class,
    'desteklenmeyen senaryo reddedilir'
);

// --- İade atfı ---------------------------------------------------------------
// Anahtar snake_case olmalı: canonical'ın geri kalanı PascalCase bağlanır ama
// *_info blokları TAG'LIDIR; "ReturnInfo" sunucuda sessizce düşer.
$ref = $iade['canonical']['return_info']['Originals'][0];
eq($ref['Number'], 'FF32026000000123', 'atıf numarası');
eq($ref['IssueDate'], '2026-09-27T00:00:00Z', 'tarih RFC 3339\'e çevrilir');
ok(isset($iade['canonical']['return_info']), 'anahtar return_info (snake_case)');
ok(!isset($iade['canonical']['ReturnInfo']), 'PascalCase ReturnInfo GÖNDERİLMEZ');

// Atıfsız iade yerelde patlar — sunucu da ERROR_RETURN_ORIGINAL_REQUIRED döner.
throwsMatching(
    fn() => Payload::earsiv(['vkn_tckn' => '1'], $lines, ['invoiceTypeCode' => 'IADE']),
    \InvalidArgumentException::class,
    'atıfsız iade reddedilir'
);
// TEVKIFATIADE ve YTBIADE de aynı kurala tabi
foreach (['TEVKIFATIADE', 'YTBIADE'] as $t) {
    throwsMatching(
        fn() => Payload::earsiv(['vkn_tckn' => '1'], $lines, ['invoiceTypeCode' => $t]),
        \InvalidArgumentException::class,
        "$t de atıf ister"
    );
}
// Satış belgesine atıf iliştirilmez
throwsMatching(
    fn() => Payload::earsiv(['vkn_tckn' => '1'], $lines, [
        'transactionHeaderId' => 't-1',
        'returnInfo' => ['number' => 'X', 'issue_date' => '2026-09-27'],
    ]),
    \InvalidArgumentException::class,
    'satışta returnInfo reddedilir'
);

// --- Para birimi / kur (yalnız iadede anlamlı) -------------------------------
$usd = Payload::earsiv(['vkn_tckn' => '1'], $lines, [
    'invoiceTypeCode' => 'IADE',
    'returnInfo' => ['number' => 'FF32026000000123', 'issue_date' => '2026-09-27'],
    'currency' => 'usd', 'exchangeRate' => 41.37, 'exchangeRateDate' => '2026-09-27',
]);
eq($usd['canonical']['Currency'], 'USD', 'para birimi büyük harfe çevrilir');
eq($usd['canonical']['ExchangeRate'], 41.37, 'kur gönderilir');
eq($usd['canonical']['ExchangeRateDate'], '2026-09-27', 'kur tarihi gönderilir');
// TRY belgede kur alanı hiç gönderilmez
ok(!isset($iade['canonical']['ExchangeRate']), 'TRY belgede kur yok');
// Dövizli belge kursuz kesilemez
throwsMatching(
    fn() => Payload::earsiv(['vkn_tckn' => '1'], $lines, [
        'invoiceTypeCode' => 'IADE',
        'returnInfo' => ['number' => 'X', 'issue_date' => '2026-09-27'],
        'currency' => 'USD',
    ]),
    \InvalidArgumentException::class,
    'kursuz döviz reddedilir'
);

// --- Tevkifat ve özel matrah --------------------------------------------------
// ORAN GÖNDERİLMEZ: her GİB kodunun yasal oranı sabit, sunucu koddan türetiyor.
$p = Payload::earsiv(['vkn_tckn' => '1'], [
    ['title' => 'Temizlik hizmeti', 'qty' => 1, 'unit_price' => 1000.0, 'vat_rate' => 0.2,
     'withholding_code' => ' 612 ', 'withholding_name' => 'Temizlik hizmeti'],
    ['title' => 'İkinci el araç', 'qty' => 1, 'unit_price' => 550000.0, 'vat_rate' => 0.2,
     'tax_base_amount' => 50000.0, 'tax_base_code' => '812', 'tax_base_reason' => 'İkinci el araç kâr marjı'],
], ['transactionHeaderId' => 't-1']);
$wl = $p['canonical']['Lines'][0];
eq($wl['WithholdingCode'], '612', 'tevkifat kodu kırpılarak gider');
ok(!isset($wl['WithholdingPercent']), 'ORAN GÖNDERİLMEZ — sunucu koddan türetir');
$tb = $p['canonical']['Lines'][1];
eq($tb['TaxBaseAmount'], 50000.0, 'özel matrah tutarı');
eq($tb['TaxBaseCode'], '812', 'özel matrah kodu');
// matrahsız satıra matrah alanları iliştirilmez
ok(!isset($wl['TaxBaseAmount']), 'matrahsız satırda TaxBaseAmount yok');
ok(!isset($tb['WithholdingCode']), 'tevkifatsız satırda WithholdingCode yok');

done('PayloadTest');
