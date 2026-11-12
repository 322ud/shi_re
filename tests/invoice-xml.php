<?php

declare(strict_types=1);

require __DIR__ . '/../lib/Service/IncRamID.php';
require __DIR__ . '/../lib/Service/InvoiceXmlService.php';

use OCA\ShiRE\Service\InvoiceXmlService;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function template(): DOMDocument {
    $dom = new DOMDocument();
    $dom->load(__DIR__ . '/../config/en16931.xml');
    // Keep calculation expectations independent of the deployed default rate.
    $dom->getElementsByTagNameNS('urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100', 'ChargeAmount')->item(0)->textContent = '181.50';
    return $dom;
}
$data = [
    'buyer' => ['name' => 'Müller & Söhne <Test>', 'street' => 'Testweg 3', 'postcode' => '01234', 'city' => 'Köln', 'country' => 'de', 'reference' => 'A&B/2026'],
    'items' => [['description' => 'Beratung & Prüfung <Patent>', 'quantity' => 1.3, 'unitPrice' => 1]],
    'vatRate' => 99, 'expenses' => [],
];
$service = new InvoiceXmlService();
$date = new DateTimeImmutable('2026-11-03');
$dom = template();
$service->apply($dom, $data, '26 201', $date);
$serialized = $dom->saveXML();
$roundtrip = new DOMDocument();
check($roundtrip->loadXML($serialized), 'XML round trip');
$x = new DOMXPath($roundtrip);
$x->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
$x->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
foreach ([
    '//ram:BuyerTradeParty/ram:Name' => 'Müller & Söhne <Test>',
    '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:LineOne' => 'Testweg 3',
    '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode' => '01234',
    '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CityName' => 'Köln',
    '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID' => 'DE',
    '//ram:BuyerReference' => 'A&B/2026',
    '//ram:SpecifiedTradeProduct/ram:Description' => 'Beratung & Prüfung <Patent>',
    '//ram:BilledQuantity' => '1.3',
    '//ram:BilledQuantity/@unitCode' => 'HUR',
    '//ram:NetPriceProductTradePrice/ram:ChargeAmount' => '181.50',
    '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CalculatedAmount' => '44.83',
    '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:LineTotalAmount' => '235.95',
    '//ram:GrandTotalAmount' => '280.78',
    '//ram:DuePayableAmount' => '280.78',
] as $path => $expected) {
    check($x->evaluate('string(' . $path . ')') === $expected, $path);
}
foreach ($x->query('//udt:DateTimeString') as $node) {
    check($node->textContent === '20261103', 'Stale template date');
}
check(OCA\ShiRE\Service\IncRamID::get($dom) === '26 201', 'Reserved number');
check(OCA\ShiRE\Service\IncRamID::next($dom) === '26 202', 'Next number');
foreach ([0, -1, 1.23, 'abc', null] as $bad) {
    $invalid = $data;
    $invalid['items'][0]['quantity'] = $bad;
    try {
        $service->apply(template(), $invalid, '26 201', $date);
        throw new RuntimeException('Invalid quantity accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
foreach (['items', 'buyer', 'expenses'] as $field) {
    $invalid = $data;
    $invalid[$field] = $field === 'expenses' ? [['quantity' => 1]] : [];
    try {
        $service->apply(template(), $invalid, '26 201', $date);
        throw new RuntimeException('Invalid data accepted: ' . $field);
    } catch (InvalidArgumentException $expected) {
    }
}
// Rounding at half a cent: 0.1 hours at 0.05 EUR rounds to 0.01 EUR.
$small = template();
$small->getElementsByTagNameNS('urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100', 'ChargeAmount')->item(0)->textContent = '0.05';
$data['items'][0]['quantity'] = 0.1;
$service->apply($small, $data, '26 201', $date);
check($small->getElementsByTagNameNS('urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100', 'GrandTotalAmount')->item(0)->textContent === '0.01', 'Half-cent rounding');
echo "Invoice XML tests passed.\n";

foreach (['381', ''] as $unsupportedType) {
    $unsupported = template();
    $unsupported->getElementsByTagNameNS('urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100', 'TypeCode')->item(0)->nodeValue = $unsupportedType;
    try {
        $service->apply($unsupported, $data, '26 201', $date);
        throw new RuntimeException('Unsupported document type accepted');
    } catch (InvalidArgumentException $error) {
        check($error->getMessage() === 'Typecode [' . $unsupportedType . '] kann (noch) nicht verarbeitet werden.', 'Unsupported type message');
    }
}
