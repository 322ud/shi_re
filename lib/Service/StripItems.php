<?php

namespace OCA\ShiRE\Service;

final class StripItems {

    /**
     * Löscht alle
     * ram:IncludedSupplyChainTradeLineItem
     * außer dem ersten.
     */
    public static function do(\DOMDocument $dom): void {
        $xpath = new \DOMXPath($dom);

        $xpath->registerNamespace(
            'rsm',
            'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100'
        );

        $xpath->registerNamespace(
            'ram',
            'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100'
        );

        $nodes = $xpath->query(
            '/rsm:CrossIndustryInvoice' .
            '/rsm:SupplyChainTradeTransaction' .
            '/ram:IncludedSupplyChainTradeLineItem'
        );

        if ($nodes === false || $nodes->length <= 1) {
            return;
        }

        $remove = [];

        for ($i = 1; $i < $nodes->length; $i++) {
            $remove[] = $nodes->item($i);
        }

        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Die XML-Vorlage enthält genau eine Musterposition. Nachdem alle weiteren
        // Positionen entfernt wurden, wird diese verbleibende Vorlage in einen
        // neutralen Ausgangszustand zurückgesetzt. Die GUI kann anschließend die
        // tatsächlichen Rechnungsdaten in diese Position schreiben oder daraus
        // weitere Positionen erzeugen.
        $firstItem = $nodes->item(0);

        if ($firstItem === null) {
            return;
        }

        // Die Musterbeschreibung aus dem Template darf nicht in neue Rechnungen
        // übernommen werden.
        $description = $xpath->query(
            './/ram:SpecifiedTradeProduct/ram:Description',
            $firstItem
        );

        if ($description !== false && $description->length > 0) {
            $description->item(0)->nodeValue = '';
        }

        // Eine neue Rechnung startet ohne berechnete Menge. Die GUI setzt später
        // den tatsächlich abgerechneten Wert.
        $billedQuantity = $xpath->query(
            './/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity',
            $firstItem
        );

        if ($billedQuantity !== false && $billedQuantity->length > 0) {
            $billedQuantity->item(0)->nodeValue = '0';
        }
    }
}
