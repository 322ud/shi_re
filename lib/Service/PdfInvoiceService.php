<?php
declare(strict_types=1);

namespace OCA\ShiRE\Service;

use OCP\IConfig;

class PdfInvoiceService {
    public function __construct(
        private PdfDependencyService $dependencies,
        private InvoiceTemplateService $templates,
        private OdtInvoiceRenderer $renderer,
        private PdfProcessRunner $runner,
        private PdfWorkspace $workspace,
        private IConfig $config,
        private ?ProgressService $progress = null,
    ) {}

    /** Aufrufer muss exakt diese XML-Bytes serverseitig zur PDF-Erzeugung freigegeben haben. */
    public function generate(string $xml, string $templateVersion, array $validatorVersions = [], string $author = 'unbekannt'): array {
        $this->progress?->start('PDF-Werkzeuge und Vorlagen werden geprüft …');
        $status = $this->dependencies->status();
        if (!$status['enabled']) throw new \RuntimeException(implode("\n", $status['errors']), 503);
        $bundle = $this->templates->current();
        if (!hash_equals($bundle['version'], $templateVersion)) {
            throw new \RuntimeException('Die Vorlagen haben sich geändert. Bitte erneut validieren.', 409);
        }
        $this->progress?->finish('PDF-Werkzeuge und Vorlagen verfügbar.');
        $this->progress?->start('Rechnungsdaten werden in die ODT-Vorlage übertragen …');
        $xpath = OdtInvoiceRenderer::xpath(OdtInvoiceRenderer::xml($xml));
        $prefix = $xpath->evaluate('string(/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode)') === '384' ? 'storno_' : '';
        $values = $this->renderer->values($this->renderer->mapping($bundle[$prefix . 'mapping']), $xml);
        $name = self::filename($values['{{ID}}']);
        $tools = $this->dependencies->tools();
        $dir = $this->workspace->create();
        try {
            $this->write($dir . '/invoice.xml', $xml);
            $this->write($dir . '/invoice.odt', $this->renderer->render($bundle[$prefix . 'odt'], $values));
            $this->progress?->finish('ODT-Rechnungsvorlage ausgefüllt.');
            $this->progress?->start('PDF wird mit LibreOffice erzeugt …');
            $profile = 'file://' . implode('/', array_map('rawurlencode', explode('/', $dir . '/lo-profile')));
            $this->runner->run([$tools['libreoffice'], '-env:UserInstallation=' . $profile,
                '--headless', '--nologo', '--nodefault', '--norestore', '--convert-to',
                'pdf:writer_pdf_Export:{"SelectPdfVersion":{"type":"long","value":"3"}}',
                '--outdir', $dir, $dir . '/invoice.odt'], $dir);
            $this->pdf($dir . '/invoice.pdf'); // LibreOffice meldet manche Exportfehler mit Exitcode 0.
            $this->progress?->finish('PDF aus der Rechnungsvorlage erzeugt.');
            $this->progress?->start('XML wird mit Mustang in die PDF eingebettet …');
            $base = [$tools['java'], '-jar', $tools['mustang'], '--disable-file-logging'];
            $this->runner->run(array_merge($base, ['--action', 'combine', '--source', $dir . '/invoice.pdf',
                '--source-xml', $dir . '/invoice.xml', '--out', $dir . '/combined.pdf',
                '--format', 'fx', '--version', '1', '--profile', $this->profile($xml),
                '--no-additional-attachments']), $dir);
            $this->pdf($dir . '/combined.pdf');
            $this->progress?->finish('XML-Rechnung in PDF eingebettet.');
            $this->progress?->start('PDF-Metadaten werden ergänzt …');
            $metadata = new PdfMetadataService();
            $this->write($dir . '/metadata.xml', $metadata->properties($values['{{DocumentType}}'], $status['versions'] ?? [],
                $this->config->getAppValue('shi_re', 'validation_enabled', 'yes') === 'yes' ? $validatorVersions : [],
                $tools['verapdf'] !== '', $author));
            $this->runner->run([$tools['java'], '-Djava.awt.headless=true', '-cp',
                $metadata->classPath($tools['mustang']), 'ShiRePdfMetadata',
                $dir . '/combined.pdf', $dir . '/final.pdf', $dir . '/metadata.xml'], $dir);
            $pdf = $this->pdf($dir . '/final.pdf');
            $this->progress?->finish('PDF-Metadaten ergänzt.');
            $this->progress?->start('Eingebettete XML wird abgeglichen …');
            $this->runner->run(array_merge($base, ['--action', 'extract', '--source', $dir . '/final.pdf',
                '--out', $dir . '/extracted.xml']), $dir);
            $extracted = @file_get_contents($dir . '/extracted.xml');
            if ($extracted === false || !hash_equals(hash('sha256', $xml), hash('sha256', $extracted))) {
                throw new \RuntimeException('Die eingebettete XML stimmt nicht mit der validierten XML überein.');
            }
            $this->progress?->finish('Eingebettete XML stimmt mit der archivierten XML überein.');
            $this->progress?->start('PDF-Rechnung wird mit Mustang geprüft …');
            $this->runner->run(array_merge($base, ['--action', 'validate', '--source', $dir . '/final.pdf']), $dir);
            $this->progress?->finish('Mustang-Prüfung erfolgreich abgeschlossen.');
            if ($tools['verapdf'] !== '') {
                $this->progress?->start('PDF/A-3b wird mit veraPDF geprüft …');
                $report = $this->runner->run([$tools['verapdf'], '--format', 'xml', '--flavour', '3b', $dir . '/final.pdf'], $dir);
                $doc = OdtInvoiceRenderer::xml($report);
                $xp = new \DOMXPath($doc);
                $results = $xp->query('//*[local-name()="validationReport"]');
                if ($results->length !== 1 || $results->item(0)->getAttribute('isCompliant') !== 'true') {
                    throw new \RuntimeException('PDF/A-3b-Prüfung fehlgeschlagen.');
                }
                $this->progress?->finish('PDF/A-3b-Prüfung erfolgreich abgeschlossen.');
            } else {
                $this->progress?->finish('Zusätzliche PDF/A-3b-Prüfung mit veraPDF nicht aktiviert.');
            }
            return ['content' => $pdf, 'filename' => $name];
        } catch (\Throwable $e) {
            $this->progress?->fail();
            throw $e;
        } finally {
            $this->progress?->start('Temporäre PDF-Arbeitsdateien werden entfernt …');
            $this->workspace->remove($dir);
            $this->progress?->finish('Temporäre PDF-Arbeitsdateien entfernt.');
        }
    }

    public static function filename(string $id): string {
        $id = preg_replace('/[^\pL\pN _.-]+/u', '_', $id);
        $id = trim(mb_substr($id, 0, 120), " .\t\n\r\0\x0B");
        return ($id === '' ? 'Rechnung' : $id) . '.pdf';
    }

    private function profile(string $xml): string {
        $xpath = OdtInvoiceRenderer::xpath(OdtInvoiceRenderer::xml($xml));
        $guideline = $xpath->evaluate('string(/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)');
        if (str_starts_with($guideline, 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_')) return 'X';
        if ($guideline === 'urn:cen.eu:en16931:2017') return 'E';
        throw new \InvalidArgumentException('XML-Guideline wird vom PDF-Modul nicht unterstützt.');
    }

    private function write(string $path, string $data): void {
        if (file_put_contents($path, $data) !== strlen($data)) throw new \RuntimeException('PDF-Arbeitsdatei konnte nicht geschrieben werden.');
    }

    private function pdf(string $path): string {
        if (!is_file($path) || filesize($path) > 52428800) throw new \RuntimeException('PDF fehlt oder ist größer als 50 MiB.');
        $pdf = file_get_contents($path);
        if (!str_starts_with($pdf, '%PDF-')) throw new \RuntimeException('PDF-Werkzeug hat keine PDF-Datei erzeugt.');
        return $pdf;
    }
}
