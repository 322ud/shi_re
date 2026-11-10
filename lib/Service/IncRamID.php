<?php

namespace OCA\ShiRE\Service;

final class IncRamID {

    private const ID_XPATH = '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID';

    /**
     * Berechnet die nächste Rechnungsnummer, ändert den DOM aber nicht.
     *
     * Das ist für den Draft-Ablauf wichtig: Beim Öffnen eines Drafts wird die
     * nächste Nummer angezeigt und gegen parallele Drafts gesperrt, aber noch
     * nicht endgültig in die XML geschrieben. Dadurch entstehen keine Lücken,
     * wenn der Benutzer den Entwurf vor dem Schreiben der XML-Rechnung abbricht.
     */
    public static function next(\DOMDocument $dom): string {
        $oldId = self::get($dom);

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

        return sprintf('%02d %03d', $yy, $nnn);
    }

    /**
     * Schreibt eine bereits berechnete oder reservierte Rechnungsnummer in den DOM.
     * Diese Methode sollte erst beim Erstellen der XML-Rechnung verwendet werden.
     */
    public static function set(\DOMDocument $dom, string $ramID): void {
        if (!preg_match('/^\d{2} \d{3}$/', $ramID)) {
            throw new \UnexpectedValueException(
                __CLASS__ . " bricht ab: Rechnungsnummer $ramID nicht im Format 'yy nnn'"
            );
        }

        self::getIdNode($dom)->nodeValue = $ramID;
    }

    /**
     * Liefert die aktuelle Rechnungsnummer aus der XML.
     */
    public static function get(\DOMDocument $dom): string {
        return trim(self::getIdNode($dom)->nodeValue);
    }

    /**
     * Abwärtskompatible Kurzform: berechnet die nächste Nummer und schreibt sie
     * sofort in den DOM. Für den neuen createXmlInvoice-Ablauf besser next()+set()
     * verwenden, damit die XML erst am Ende geändert wird.
     */
    public static function do(\DOMDocument $dom): string {
        $newId = self::next($dom);
        self::set($dom, $newId);
        return $newId;
    }

    private static function getIdNode(\DOMDocument $dom): \DOMNode {
        $xpath = new \DOMXPath($dom);

        $xpath->registerNamespace(
            'rsm',
            'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100'
        );

        $xpath->registerNamespace(
            'ram',
            'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100'
        );

        $nodes = $xpath->query(self::ID_XPATH);

        if ($nodes === false || $nodes->length !== 1) {
            throw new \RuntimeException(
                __CLASS__ . ' bricht ab: kann Rechnungsnummer nicht finden'
            );
        }

        return $nodes->item(0);
    }
}
