<?php
declare(strict_types=1);

namespace OCA\ShiRE\Service;

/** Builds a full cancellation from archived amounts, never from browser input. */
final class CancellationInvoiceService {
    public function build(string $template, string $archived, string $number, \DateTimeImmutable $date): \DOMDocument {
        $dom = OdtInvoiceRenderer::xml($template);
        $source = OdtInvoiceRenderer::xml($archived);
        $xp = OdtInvoiceRenderer::xpath($dom);
        $original = OdtInvoiceRenderer::xpath($source);
        $document = '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument';
        $transaction = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction';
        $line = $transaction . '/ram:IncludedSupplyChainTradeLineItem';
        $settlement = $transaction . '/ram:ApplicableHeaderTradeSettlement';
        $summary = $settlement . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
        $get = static function (\DOMXPath $xpath, string $path): \DOMElement {
            $nodes = $xpath->query($path);
            if ($nodes === false || $nodes->length !== 1 || !($nodes->item(0) instanceof \DOMElement)) {
                throw new \InvalidArgumentException('Storno: Feld fehlt oder ist mehrdeutig: ' . $path);
            }
            return $nodes->item(0);
        };
        $set = static function (string $path, string $value) use ($get, $xp): void {
            $get($xp, $path)->textContent = $value;
        };
        $copy = static function (string $path) use ($get, $xp, $original, $dom): void {
            $target = $get($xp, $path);
            $target->parentNode->replaceChild($dom->importNode($get($original, $path), true), $target);
        };
        $negate = static function (string $value): string {
            $value = trim($value);
            if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $value)) {
                throw new \InvalidArgumentException('Storno: Erwartet wird ein nicht negativer archivierter Betrag.');
            }
            return preg_match('/^0+(?:\.0+)?$/D', $value) ? $value : '-' . $value;
        };
        if (trim($get($original, $document . '/ram:TypeCode')->textContent) !== '380'
            || trim($get($xp, $document . '/ram:TypeCode')->textContent) !== '384') {
            throw new \InvalidArgumentException('Storno benötigt eine archivierte Rechnung (380) und eine Storno-Vorlage (384).');
        }
        $get($original, $line);
        $get($xp, $line);
        $get($original, $settlement . '/ram:ApplicableTradeTax');
        $oldNumber = trim($get($original, $document . '/ram:ID')->textContent);
        if ($oldNumber === '' || $oldNumber === $number) throw new \InvalidArgumentException('Storno benötigt eine neue Rechnungsnummer.');
        $product = $line . '/ram:SpecifiedTradeProduct';
        $descriptionNodes = $xp->query($product . '/ram:Description');
        $description = trim(($descriptionNodes->item(0) ?? $get($xp, $product . '/ram:Name'))->textContent);
        if ($description === '') throw new \InvalidArgumentException('Beschreibung in der Storno-Vorlage fehlt.');
        if ($descriptionNodes->length === 0) {
            $get($xp, $product)->appendChild($dom->createElementNS($get($xp, $product)->namespaceURI, 'ram:Description'));
        }
        $set($product . '/ram:Description', $description . ' zu ' . $oldNumber);
        IncRamID::set($dom, $number);
        $set($document . '/ram:IssueDateTime/udt:DateTimeString', $date->format('Ymd'));
        $get($xp, $document . '/ram:IssueDateTime/udt:DateTimeString')->setAttribute('format', '102');
        $copy($transaction . '/ram:ApplicableHeaderTradeAgreement');
        $copy($settlement . '/ram:InvoiceCurrencyCode');
        $copy($line . '/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax');
        $copy($settlement . '/ram:ApplicableTradeTax');
        $copy($summary);
        foreach ($xp->query($summary . '/*') as $amount) $amount->textContent = $negate($amount->textContent);
        foreach (['CalculatedAmount', 'BasisAmount'] as $field) {
            $path = $settlement . '/ram:ApplicableTradeTax/ram:' . $field;
            $set($path, $negate($get($original, $path)->textContent));
        }
        $netPath = $line . '/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount';
        $net = trim($get($original, $netPath)->textContent);
        $set($netPath, $negate($net));
        $price = $line . '/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice';
        $set($price . '/ram:ChargeAmount', $net);
        foreach ($xp->query($price . '/ram:BasisQuantity') as $basis) {
            $basis->textContent = '1';
            $basis->setAttribute('unitCode', 'C62');
        }
        $quantity = $get($xp, $line . '/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity');
        $quantity->textContent = '-1';
        $quantity->setAttribute('unitCode', 'C62');
        $set($settlement . '/ram:InvoiceReferencedDocument/ram:IssuerAssignedID', $oldNumber);
        // Optional dates in custom templates must not retain their sample dates.
        foreach ($xp->query($transaction . '//udt:DateTimeString') as $value) {
            $value->textContent = $date->format('Ymd');
            $value->setAttribute('format', '102');
        }
        return $dom;
    }
}
