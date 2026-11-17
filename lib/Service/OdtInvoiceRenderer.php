<?php
declare(strict_types=1);

namespace OCA\ShiRE\Service;

class OdtInvoiceRenderer {
    private const TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    private const PARTS = ['content.xml', 'styles.xml'];
    public const MAX_ODT = 10485760;

    public function __construct(private PdfWorkspace $workspace) {}

    public static function xml(string $xml): \DOMDocument {
        if ($xml === '') throw new \InvalidArgumentException('XML ist leer.');
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \InvalidArgumentException('DTD und externe XML-Entitäten sind nicht erlaubt.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = true;
            if (!$dom->loadXML($xml, LIBXML_NONET)) {
                throw new \InvalidArgumentException('Ungültiges XML.');
            }
            if ($dom->doctype !== null) throw new \InvalidArgumentException('DTD ist nicht erlaubt.');
            return $dom;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<string, string> Platzhalter => XPath; dritte Spalte ist nur Dokumentation. */
    public function mapping(string $tsv): array {
        $map = [];
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $tsv));
        rewind($stream);
        try {
            $line = 0;
            while (($row = fgetcsv($stream, 0, "\t", '"', '')) !== false) {
                $line++;
                if ($row === [null]) continue;
                if (count($row) < 2 || count($row) > 3 || !preg_match('/^\{\{[A-Za-z][A-Za-z0-9_]*\}\}$/D', $row[0])) {
                    throw new \InvalidArgumentException("Ungültige Mapping-Zeile $line.");
                }
                if (isset($map[$row[0]])) throw new \InvalidArgumentException("Doppelter Platzhalter: {$row[0]}");
                $path = trim($row[1]);
                if (!str_starts_with($path, '/')) throw new \InvalidArgumentException("Absoluter XPath fehlt in Zeile $line.");
                $map[$row[0]] = $path;
            }
        } finally {
            fclose($stream);
        }
        if (!$map) throw new \InvalidArgumentException('Mapping-Tabelle ist leer.');
        return $map;
    }

    public static function xpath(\DOMDocument $dom): \DOMXPath {
        $xpath = new \DOMXPath($dom);
        foreach (['rsm' => 'CrossIndustryInvoice', 'ram' => 'ReusableAggregateBusinessInformationEntity',
            'qdt' => 'QualifiedDataType', 'udt' => 'UnqualifiedDataType'] as $prefix => $name) {
            $xpath->registerNamespace($prefix, 'urn:un:unece:uncefact:data:standard:' . $name . ':100');
        }
        return $xpath;
    }

    public function values(array $map, string $xml, bool $templateCheck = false): array {
        $dom = self::xml($xml);
        $xpath = self::xpath($dom);
        if ($dom->documentElement?->namespaceURI !== 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100'
            || $dom->documentElement?->localName !== 'CrossIndustryInvoice') {
            throw new \InvalidArgumentException('Erwartet wird eine EN16931-CII-XML.');
        }
        $lines = $xpath->query('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem');
        if ($lines->length !== 1) throw new \InvalidArgumentException('PDF-Modul unterstützt genau eine Rechnungsposition.');
        $values = [];
        foreach ($map as $key => $path) {
            $nodes = @$xpath->query($path);
            if ($nodes === false || $nodes->length !== 1 || !($nodes->item(0) instanceof \DOMElement)
                || $nodes->item(0)->getElementsByTagName('*')->length !== 0) {
                throw new \InvalidArgumentException("Feld fehlt, ist ungültig oder mehrdeutig: $key ($path)");
            }
            $node = $nodes->item(0);
            $values[$key] = $templateCheck && $key === '{{DocumentType}}'
                ? trim($node->textContent) : $this->format($key, trim($node->textContent), $node);
        }
        if (trim($values['{{ID}}'] ?? '') === '') throw new \InvalidArgumentException('Rechnungsnummer {{ID}} fehlt.');
        return $values;
    }

    private function format(string $key, string $value, \DOMElement $node): string {
        if ($key === '{{DocumentType}}') {
            if (!in_array($value, ['380', '384'], true)) {
                throw new \InvalidArgumentException("TypeCode $value wird (noch) nicht unterstützt. PDF kann nicht erstellt werden.", 422);
            }
            return $value === '384' ? 'Storno-Rechnung' : 'Rechnung';
        }
        if ($key === '{{Currency}}') return $value === 'EUR' ? '€' : $value;
        if ($key === '{{IBAN}}') {
            $iban = preg_replace('/\s+/u', '', $value);
            return implode("\u{00A0}", str_split($iban, 4));
        }
        if ($node->localName === 'DateTimeString') {
            $date = \DateTimeImmutable::createFromFormat('!Ymd', $value);
            if ($node->getAttribute('format') !== '102' || !$date || $date->format('Ymd') !== $value) {
                throw new \InvalidArgumentException("Ungültiges Datum: $key");
            }
            return $date->format('d.m.Y');
        }
        if ($key === '{{TaxRate}}') {
            if (!preg_match('/^(-?\d+)(?:\.0+)?$/D', $value, $match)) {
                throw new \InvalidArgumentException('TaxRate muss ohne Rundung ganzzahlig darstellbar sein.');
            }
            return $match[1];
        }
        // Nur fachliche Dezimalfelder formatieren, keine Kennungen, Adressen oder Freitexte.
        if (in_array($key, ['{{UnitPrice}}', '{{BasisTotal}}', '{{TaxTotal}}', '{{GrandTotal}}'], true)) {
            if (!preg_match('/^(-?\d+)(?:\.(\d{1,2})0*)?$/D', $value, $m)) {
                throw new \InvalidArgumentException("Betrag nicht mit zwei Nachkommastellen darstellbar: $key");
            }
            return $m[1] . ',' . str_pad($m[2] ?? '', 2, '0');
        }
        if ($key === '{{BilledQuantity}}' || preg_match('/(?:Amount|Quantity|Percent)$/D', $node->localName)) {
            if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) throw new \InvalidArgumentException("Ungültige Dezimalzahl: $key");
            return str_replace('.', ',', $value);
        }
        return $value;
    }

    public function placeholders(string $odt): array {
        $found = [];
        $this->withOdt($odt, function (\ZipArchive $zip) use (&$found): void {
            foreach (self::PARTS as $part) {
                foreach ($this->paragraphs(self::xml($zip->getFromName($part))) as $nodes) {
                    preg_match_all('/\{\{[^{}]*\}\}/u', implode('', array_map(fn ($n) => $n->data, $nodes)), $matches);
                    foreach ($matches[0] as $key) $found[$key] = true;
                }
            }
        });
        return array_keys($found);
    }

    public function render(string $odt, array $values): string {
        return $this->withOdt($odt, function (\ZipArchive $zip) use ($values): void {
            foreach (self::PARTS as $part) {
                $dom = self::xml($zip->getFromName($part));
                foreach ($this->paragraphs($dom) as $nodes) {
                    $text = implode('', array_map(fn ($n) => $n->data, $nodes));
                    preg_match_all('/\{\{[^{}]*\}\}/u', $text, $matches, PREG_OFFSET_CAPTURE);
                    // Rückwärts ersetzen: ursprüngliche Byte-Offsets bleiben gültig.
                    foreach (array_reverse($matches[0]) as [$key, $offset]) {
                        if (!array_key_exists($key, $values)) throw new \InvalidArgumentException("Mapping fehlt: $key");
                        $end = $offset + strlen($key);
                        $position = 0;
                        $inserted = false;
                        foreach ($nodes as $node) {
                            $length = strlen($node->data);
                            $next = $position + $length;
                            if ($next > $offset && $position < $end) {
                                $prefix = substr($node->data, 0, max(0, $offset - $position));
                                $suffix = $end < $next ? substr($node->data, $end - $position) : '';
                                $node->data = $prefix . ($inserted ? '' : $values[$key]) . $suffix;
                                $inserted = true;
                            }
                            $position = $next;
                        }
                    }
                    foreach ($nodes as $node) $this->preserveWhitespace($node);
                }
                if (!$zip->addFromString($part, $dom->saveXML())) throw new \RuntimeException('ODT konnte nicht aktualisiert werden.');
            }
        });
    }

    /** Text pro Absatz sammeln; eingebettete Textfelder gehören zu ihrem eigenen Absatz. */
    private function paragraphs(\DOMDocument $dom): array {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('text', self::TEXT);
        $groups = [];
        foreach ($xpath->query('//text:p | //text:h') as $paragraph) {
            $nodes = [];
            foreach ($xpath->query('.//text()', $paragraph) as $node) {
                $owner = $node->parentNode;
                while ($owner && !($owner->namespaceURI === self::TEXT && in_array($owner->localName, ['p', 'h'], true))) {
                    $owner = $owner->parentNode;
                }
                if ($owner === $paragraph) $nodes[] = $node;
            }
            $groups[] = $nodes;
        }
        return $groups;
    }

    private function preserveWhitespace(\DOMText $node): void {
        $parts = preg_split('/(\r\n|\r|\n|\t| {2,})/', $node->data, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) === 1) return;
        $dom = $node->ownerDocument;
        foreach ($parts as $part) {
            if ($part === '') continue;
            if (preg_match('/^[\r\n]+$/D', $part)) $new = $dom->createElementNS(self::TEXT, 'text:line-break');
            elseif ($part === "\t") $new = $dom->createElementNS(self::TEXT, 'text:tab');
            elseif (preg_match('/^ {2,}$/D', $part)) {
                $new = $dom->createElementNS(self::TEXT, 'text:s');
                $new->setAttributeNS(self::TEXT, 'text:c', (string)strlen($part));
            } else $new = $dom->createTextNode($part);
            $node->parentNode->insertBefore($new, $node);
        }
        $node->parentNode->removeChild($node);
    }

    /** Kein Entpacken fremder ZIP-Pfade auf das Dateisystem. */
    private function withOdt(string $odt, callable $action): string {
        if (strlen($odt) > self::MAX_ODT) throw new \InvalidArgumentException('ODT ist größer als 10 MiB.');
        $dir = $this->workspace->create();
        $file = $dir . '/template.odt';
        $zip = new \ZipArchive();
        try {
            if (file_put_contents($file, $odt) !== strlen($odt) || $zip->open($file) !== true) {
                throw new \InvalidArgumentException('Ungültige ODT-ZIP-Datei.');
            }
            try {
                if ($zip->getFromName('mimetype') !== 'application/vnd.oasis.opendocument.text') {
                    throw new \InvalidArgumentException('Die Vorlage ist kein ODT-Textdokument.');
                }
                $size = 0;
                if ($zip->numFiles > 2000) throw new \InvalidArgumentException('Zu viele ODT-Dateieinträge.');
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $size += $stat['size'];
                    if ($size > 52428800 || ($stat['encryption_method'] ?? 0) !== 0
                        || preg_match('~(^/|(^|/)\.\.(/|$)|\\\\|^(Basic|Scripts)/|signatures\.xml$)~i', $stat['name'])) {
                        throw new \InvalidArgumentException('Unsicherer, signierter oder zu großer ODT-Inhalt.');
                    }
                    // LibreOffice speichert u.a. accelerator/current.xml regulär leer.
                    if ($stat['size'] > 0 && str_ends_with($stat['name'], '.xml')) {
                        $doc = self::xml($zip->getFromIndex($i));
                        $xp = new \DOMXPath($doc);
                        // Links im Text sind erlaubt; externe Bilder/Objekte und Makros nicht.
                        foreach ($xp->query('//@*[local-name()="href"]') as $href) {
                            // meta:template dokumentiert nur die Herkunft, wird nicht eingebunden.
                            if ($href->ownerElement->namespaceURI === 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0'
                                && $href->ownerElement->localName === 'template') continue;
                            if ($href->ownerElement->localName !== 'a' && preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|\.\.)~i', $href->value)) {
                                throw new \InvalidArgumentException('Externe ODT-Ressourcen sind nicht erlaubt.');
                            }
                        }
                        if ($xp->query('//*[local-name()="scripts" or local-name()="event-listeners"]/*')->length > 0) {
                            throw new \InvalidArgumentException('ODT-Makros sind nicht erlaubt.');
                        }
                    }
                }
                foreach (self::PARTS as $part) {
                    if ($zip->locateName($part) === false) throw new \InvalidArgumentException("ODT enthält kein $part.");
                }
                $action($zip);
            } finally {
                $zip->close();
            }
            return file_get_contents($file);
        } finally {
            $this->workspace->remove($dir);
        }
    }
}
