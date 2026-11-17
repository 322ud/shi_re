<?php
declare(strict_types=1);
require __DIR__ . '/invoice-xml.php';
require __DIR__ . '/../lib/Service/OdtInvoiceRenderer.php';
require __DIR__ . '/../lib/Service/CancellationInvoiceService.php';
use OCA\ShiRE\Service\{OdtInvoiceRenderer, CancellationInvoiceService};
$source = template();
$service->apply($source, $data, '26 201', $date);
$sourceXml = $source->saveXML();
$stornoTemplate = file_get_contents(__DIR__ . '/../config/Storno.xml');
$cancellation = new CancellationInvoiceService();
$result = $cancellation->build($stornoTemplate, $sourceXml, '26 202', new DateTimeImmutable('2026-11-17'));
$xp = OdtInvoiceRenderer::xpath($result);
foreach ([
    '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID' => '26 202',
    '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode' => '384',
    '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString' => '20261117',
    '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID' => '26 201',
    '//ram:SpecifiedTradeProduct/ram:Description' => 'Vollständige Stornierung zu 26 201',
    '//ram:BilledQuantity' => '-1',
    '//ram:BuyerTradeParty/ram:Name' => $data['buyer']['name'],
] as $path => $expected) check($xp->evaluate('string(' . $path . ')') === $expected, $path);
$original = OdtInvoiceRenderer::xpath($source);
foreach (['LineTotalAmount', 'TaxBasisTotalAmount', 'TaxTotalAmount', 'GrandTotalAmount', 'DuePayableAmount'] as $field) {
    $path = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:' . $field;
    check($xp->evaluate('string(' . $path . ')') === '-' . $original->evaluate('string(' . $path . ')'), 'Exact reversal: ' . $field);
}
check($source->saveXML() === $sourceXml, 'Archive unchanged');
$failed = false;
try { $cancellation->build($stornoTemplate, $result->saveXML(), '26 203', $date); }
catch (InvalidArgumentException) { $failed = true; }
check($failed, 'Reject cancellation of cancellation');
echo "Cancellation tests passed: reference, new number/date, description, recipient, exact negative amounts and unchanged archive.\n";
