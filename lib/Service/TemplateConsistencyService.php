<?php
declare(strict_types=1);

namespace OCA\ShiRE\Service;

class TemplateConsistencyService {
    public function __construct(private OdtInvoiceRenderer $renderer) {}

    /** Exercise the same field access and calculations as the input form on a copy. */
    public function checkXml(string $xml, ?string $invoiceXml = null): void {
        if (strlen($xml) > 2097152) throw new \InvalidArgumentException('XML maximal 2 MiB.');
        $dom = OdtInvoiceRenderer::xml($xml);
        if ($invoiceXml !== null) {
            $sourceId = IncRamID::get(OdtInvoiceRenderer::xml($invoiceXml));
            $number = $sourceId === '99 998' ? '99 999' : '99 998';
            (new CancellationInvoiceService())->build($xml, $invoiceXml, $number, new \DateTimeImmutable('2026-01-01'));
            return;
        }
        if (!preg_match('/^\d{2} \d{3}$/D', IncRamID::get($dom))) {
            throw new \InvalidArgumentException('Rechnungsnummer muss das Format JJ NNN haben.');
        }
        (new InvoiceXmlService())->apply($dom, [
            'buyer' => ['name' => 'Vorlagenprüfung', 'street' => 'Teststraße 1', 'postcode' => '12345',
                'city' => 'Teststadt', 'country' => 'DE', 'reference' => 'Test'],
            'items' => [['description' => 'Vorlagenprüfung', 'quantity' => '1.0']],
        ], '99 999', new \DateTimeImmutable('2026-01-01'));
    }

    public function check(array $bundle, bool $templateCheck = false): void {
        if (strlen($bundle['xml']) > 2097152 || strlen($bundle['mapping']) > 262144) {
            throw new \InvalidArgumentException('XML maximal 2 MiB, Mapping maximal 256 KiB.');
        }
        $map = $this->renderer->mapping($bundle['mapping']);
        $placeholders = $this->renderer->placeholders($bundle['odt']);
        $errors = [];
        $missing = array_diff($placeholders, array_keys($map));
        $unused = array_diff(array_keys($map), $placeholders);
        if ($missing) $errors[] = 'a) Mapping fehlt für: ' . implode(', ', $missing);
        if ($unused) $errors[] = 'b) ODT-Platzhalter fehlt für: ' . implode(', ', $unused);
        try {
            $this->renderer->values($map, $bundle['xml'], templateCheck: $templateCheck);
        } catch (\InvalidArgumentException $e) {
            $errors[] = 'c) ' . $e->getMessage();
        }
        if ($errors) throw new \InvalidArgumentException(implode("\n", $errors));
    }
}
