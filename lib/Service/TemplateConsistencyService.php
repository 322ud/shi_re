<?php
declare(strict_types=1);

namespace OCA\ShiRE\Service;

class TemplateConsistencyService {
    public function __construct(private OdtInvoiceRenderer $renderer) {}

    public function check(array $bundle): void {
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
            $this->renderer->values($map, $bundle['xml']);
        } catch (\InvalidArgumentException $e) {
            $errors[] = 'c) ' . $e->getMessage();
        }
        if ($errors) throw new \InvalidArgumentException(implode("\n", $errors));
    }
}
