<?php

declare(strict_types=1);

namespace OCA\ShiRE\Service;

/** Befüllt die vorhandene CII-Vorlage für genau einen Stundenposten. */
final class InvoiceXmlService {
    public function apply(\DOMDocument $dom, array $data, string $number, \DateTimeImmutable $date): void {
        $items = $data['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) !== 1 || !is_array($items[0])) {
            throw new \InvalidArgumentException('Genau ein Rechnungsposten ist erforderlich.');
        }
        if (!empty($data['expenses'])) {
            throw new \InvalidArgumentException('Zusätzliche Auslagen werden noch nicht unterstützt.');
        }
        $buyer = $data['buyer'] ?? null;
        if (!is_array($buyer)) {
            throw new \InvalidArgumentException('Rechnungsempfänger fehlt.');
        }
        $values = [];
        foreach (['name', 'street', 'postcode', 'city', 'country', 'reference'] as $key) {
            $value = $buyer[$key] ?? '';
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException('Empfängerfeld fehlt: ' . $key);
            }
            $values[$key] = trim($value);
        }
        $values['country'] = strtoupper($values['country']);
        if (!preg_match('/^[A-Z]{2}$/D', $values['country'])) {
            throw new \InvalidArgumentException('Land muss als zweistelliger Ländercode angegeben werden.');
        }
        $description = $items[0]['description'] ?? null;
        if (!is_string($description) || trim($description) === '') {
            throw new \InvalidArgumentException('Beschreibung fehlt.');
        }
        $hours = self::decimal($items[0]['quantity'] ?? null, 1, 'Stunden');
        if ($hours <= 0) {
            throw new \InvalidArgumentException('Stunden müssen größer als 0 sein.');
        }

        $xpath = new \DOMXPath($dom);
        foreach (['rsm' => 'CrossIndustryInvoice', 'ram' => 'ReusableAggregateBusinessInformationEntity', 'udt' => 'UnqualifiedDataType'] as $prefix => $name) {
            $xpath->registerNamespace($prefix, 'urn:un:unece:uncefact:data:standard:' . $name . ':100');
        }
        $typeCode = trim($xpath->evaluate('string(/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode)'));
        if ($typeCode !== '380') {
            throw new \InvalidArgumentException('Typecode [' . $typeCode . '] kann (noch) nicht verarbeitet werden.');
        }
        $node = static function (string $path) use ($xpath): \DOMElement {
            $nodes = $xpath->query($path);
            if ($nodes === false || $nodes->length !== 1 || !($nodes->item(0) instanceof \DOMElement)) {
                throw new \RuntimeException('XML-Vorlage unvollständig oder mehrdeutig: ' . $path);
            }
            return $nodes->item(0);
        };
        $set = static function (string $path, string $value) use ($node, $dom): void {
            $element = $node($path);
            // Textknoten verhindern, dass & oder < als XML interpretiert werden.
            $element->textContent = '';
            $element->appendChild($dom->createTextNode($value));
        };
        $transaction = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction';
        $line = $transaction . '/ram:IncludedSupplyChainTradeLineItem';
        $agreement = $transaction . '/ram:ApplicableHeaderTradeAgreement';
        $settlement = $transaction . '/ram:ApplicableHeaderTradeSettlement';
        $price = $line . '/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice';
        $lineTax = $line . '/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax';
        $node($line); // Keine zusätzlichen Positionen stillschweigend übernehmen.
        $cents = self::decimal($node($price . '/ram:ChargeAmount')->textContent, 2, 'Stundensatz');
        $rate = self::decimal($node($lineTax . '/ram:RateApplicablePercent')->textContent, 2, 'Steuersatz');
        if ($node($lineTax . '/ram:CategoryCode')->textContent !== 'S' || $rate <= 0 || $rate > 10000) {
            throw new \InvalidArgumentException('Unterstützt wird derzeit eine positive Standard-Umsatzsteuer.');
        }
        // Ganzzahlige Zehntelstunden, Cent und Hundertstelprozent; kaufmännische Rundung.
        $net = intdiv($hours * $cents + 5, 10);
        $tax = intdiv($net * $rate + 5000, 10000);
        $gross = $net + $tax;
        $money = static fn (int $value): string => intdiv($value, 100) . '.' . str_pad((string)($value % 100), 2, '0', STR_PAD_LEFT);

        IncRamID::set($dom, $number);
        foreach ([
            '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString',
            $transaction . '/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString',
            $settlement . '/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString',
        ] as $path) {
            $set($path, $date->format('Ymd'));
            $node($path)->setAttribute('format', '102');
        }
        $set($agreement . '/ram:BuyerReference', $values['reference']);
        $set($agreement . '/ram:BuyerTradeParty/ram:Name', $values['name']);
        foreach (['street' => 'LineOne', 'postcode' => 'PostcodeCode', 'city' => 'CityName', 'country' => 'CountryID'] as $key => $tag) {
            $set($agreement . '/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:' . $tag, $values[$key]);
        }
        $set($line . '/ram:AssociatedDocumentLineDocument/ram:LineID', '1');
        $set($line . '/ram:SpecifiedTradeProduct/ram:Description', trim($description));
        $set($price . '/ram:ChargeAmount', $money($cents));
        $set($price . '/ram:BasisQuantity', '1.0');
        $node($price . '/ram:BasisQuantity')->setAttribute('unitCode', 'HUR');
        $quantity = $line . '/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity';
        $set($quantity, intdiv($hours, 10) . '.' . ($hours % 10));
        $node($quantity)->setAttribute('unitCode', 'HUR');
        $set($line . '/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $money($net));
        $set($lineTax . '/ram:TypeCode', 'VAT');
        $set($lineTax . '/ram:RateApplicablePercent', $money($rate));
        foreach (['CalculatedAmount' => $money($tax), 'BasisAmount' => $money($net), 'TypeCode' => 'VAT', 'CategoryCode' => 'S', 'RateApplicablePercent' => $money($rate)] as $tag => $value) {
            $set($settlement . '/ram:ApplicableTradeTax/ram:' . $tag, $value);
        }
        $set($settlement . '/ram:InvoiceCurrencyCode', 'EUR');
        $set($settlement . '/ram:SpecifiedTradePaymentTerms/ram:Description', 'Zahlbar sofort ohne Abzug.');
        $summary = $settlement . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
        foreach (['LineTotalAmount' => $net, 'ChargeTotalAmount' => 0, 'AllowanceTotalAmount' => 0, 'TaxBasisTotalAmount' => $net, 'TaxTotalAmount' => $tax, 'GrandTotalAmount' => $gross, 'TotalPrepaidAmount' => 0, 'DuePayableAmount' => $gross] as $tag => $value) {
            $set($summary . '/ram:' . $tag, $money($value));
        }
        $node($summary . '/ram:TaxTotalAmount')->setAttribute('currencyID', 'EUR');
    }

    private static function decimal(mixed $value, int $places, string $label): int {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new \InvalidArgumentException($label . ' fehlt oder ist ungültig.');
        }
        $text = str_replace(',', '.', trim((string)$value));
        if (!preg_match('/^([0-9]{1,6})(?:\.([0-9]+))?$/D', $text, $match)) {
            throw new \InvalidArgumentException($label . ' ist ungültig oder zu groß.');
        }
        $fraction = $match[2] ?? '';
        if (trim(substr($fraction, $places), '0') !== '') {
            throw new \InvalidArgumentException($label . ' erlaubt höchstens ' . $places . ' Nachkommastellen.');
        }
        return (int)$match[1] * (10 ** $places) + (int)str_pad(substr($fraction, 0, $places), $places, '0');
    }
}
