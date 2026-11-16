<?php
declare(strict_types=1);

namespace OCA\ShiRE\Service;

/** Input for the bundled Java helper; no invoice data is interpolated into a shell command. */
class PdfMetadataService {
    public function classPath(string $mustang): string {
        return dirname(__DIR__, 2) . '/tools/pdf-metadata' . PATH_SEPARATOR . $mustang;
    }

    public function author(string $displayName, string $host): string {
        preg_match_all('/(?:^|[\s\p{Z}\p{Pd}]+)(\p{L}|\p{N})/u', trim($displayName), $initials);
        $domain = parse_url('https://' . $host, PHP_URL_HOST);
        if ($initials[1] === [] || !is_string($domain) || $domain === '') {
            throw new \RuntimeException('PDF-Autor konnte nicht ermittelt werden.');
        }
        // Use the domain behind the Nextcloud host, e.g. cloud.322ud.de -> 322ud.de.
        $domain = preg_replace('/^cloud\./i', '', rtrim($domain, '.'));
        return mb_strtolower(implode('', $initials[1]) . '@' . $domain, 'UTF-8');
    }

    public function properties(string $title, array $tools, array $validator, bool $veraPdf, string $author = 'unbekannt'): string {
        $info = OdtInvoiceRenderer::xml(file_get_contents(dirname(__DIR__, 2) . '/appinfo/info.xml'));
        $xp = new \DOMXPath($info);
        $properties = [
            'title' => $title,
            'author' => $author,
            'creator' => $xp->evaluate('string(/info/name)') . ' ' . $xp->evaluate('string(/info/version)'),
            'producer' => 'MustangCLI ' . ($tools['mustang'] ?? 'unbekannt'),
            'shi_re:libreOfficeVersion' => $tools['libreoffice'] ?? 'unbekannt',
        ];
        // An empty version set means this invoice was not checked with INValidator.
        if ($validator !== []) {
            $properties['shi_re:INValidatorVersion'] = $validator['INValidatorVersion'] ?? 'unbekannt';
            $properties['inv_validator:kositValidatorVersion'] = $validator['kositValidatorVersion'] ?? 'unbekannt';
            $properties['inv_validator:xrechnungSzenarioVersion'] = $validator['xrechnungSzenarioVersion'] ?? 'unbekannt';
        }
        if ($veraPdf) $properties['shi_re:veraPDFVersion'] = $tools['verapdf'] ?? 'unbekannt';
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->appendChild($doc->createElement('metadata'));
        foreach ($properties as $key => $value) {
            $entry = $root->appendChild($doc->createElement('property'));
            $entry->setAttribute('name', $key);
            $entry->appendChild($doc->createTextNode($value === '' ? 'unbekannt' : $value));
        }
        return $doc->saveXML();
    }
}
