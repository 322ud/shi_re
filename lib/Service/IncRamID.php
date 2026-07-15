<?php

namespace OCA\MyXRechnung\Service;

final class IncRamID {

    public static function do(\DOMDocument $dom): string {
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
            '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID'
        );

        if ($nodes->length !== 1) {
            throw new \RuntimeException(
                __CLASS__ . " bricht ab: kann Rechnungsnummer nicht finden"
            );
        }

        $node = $nodes->item(0);
        $oldId = trim($node->nodeValue);

        if (!preg_match('/^(\d{2}) (\d{3})$/', $oldId, $m)) {
            throw new \UnexpectedValueException(
                __CLASS__ . " bricht ab: vorherige Rechnungsnummer $oldId nicht im Format 'yy nnn'"
            );
        }

        $yy = (int)$m[1];
        $nnn = (int)$m[2];

        $currentYear = (int)date('y');

        if ($yy < $currentYear) {
            $yy = $currentYear;
            $nnn = 0;
        } else {
            $nnn++;
        }

        if ($nnn > 999) {
            throw new \OverflowException(
                __CLASS__ . " bricht ab: maximale Rechnungsnummer $oldId erreicht"
            );
        }

        $newId = sprintf('%02d %03d', $yy, $nnn);
        $node->nodeValue = $newId;

        return $newId;
    }
}
