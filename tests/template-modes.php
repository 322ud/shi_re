<?php
/** timeout --kill-after=2s 10s php -d memory_limit=64M tests/template-modes.php */
declare(strict_types=1);
namespace OCP { interface IConfig {} interface ITempManager {} }
namespace OCP\App { interface IAppManager {} }
namespace OCP\Files { interface IAppData {} class NotFoundException extends \RuntimeException {} }
namespace OCA\ShiRE\AppInfo { class Application { public const APP_ID = 'shi_re'; } }
namespace {
    foreach (['PdfWorkspace', 'PdfProcessRunner', 'OdtInvoiceRenderer', 'IncRamID', 'InvoiceXmlService',
        'CancellationInvoiceService', 'TemplateConsistencyService', 'InvoiceTemplateService', 'PdfDependencyService'] as $class) {
        require __DIR__ . '/../lib/Service/' . $class . '.php';
    }
    function check(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    function rejects(callable $call, string $text): void {
        try { $call(); } catch (\Throwable $e) {
            check(str_contains($e->getMessage(), $text), $e->getMessage()); return;
        }
        throw new \RuntimeException('Expected rejection: ' . $text);
    }
    $temp = new class implements \OCP\ITempManager {
        public array $paths = [];
        public function getTemporaryFolder($suffix): string {
            $path = sys_get_temp_dir() . '/shire-template-modes-' . bin2hex(random_bytes(6));
            mkdir($path, 0700); $this->paths[] = $path; return $path;
        }
    };
    $workspace = new \OCA\ShiRE\Service\PdfWorkspace($temp);
    $consistency = new \OCA\ShiRE\Service\TemplateConsistencyService(new \OCA\ShiRE\Service\OdtInvoiceRenderer($workspace));
    $config = new class implements \OCP\IConfig {
        public array $app = ['custom_templates' => 'yes', 'pdf_enabled' => 'no'];
        public array $system = ['shi_re_libreoffice' => PHP_BINARY, 'shi_re_java' => '/missing/java'];
        public function getAppValue($app, $key, $default) { return $this->app[$key] ?? $default; }
        public function setAppValue($app, $key, $value): void { $this->app[$key] = $value; }
        public function getSystemValueString($key, $default): string { return $this->system[$key] ?? $default; }
    };
    $storage = new class implements \OCP\Files\IAppData {
        public array $files = [];
        public function getFolder($name): self { return $this; }
        public function newFolder($name): self { return $this; }
        public function fileExists($name): bool { return isset($this->files[$name]); }
        public function newFile($name, $content): void { $this->files[$name] = $content; }
        public function getFile($name): object {
            if (!isset($this->files[$name])) throw new \OCP\Files\NotFoundException();
            return new class($this->files[$name]) {
                public function __construct(private string $content) {}
                public function getContent(): string { return $this->content; }
            };
        }
    };
    $apps = new class implements \OCP\App\IAppManager {
        public function getAppPath($id): string { return dirname(__DIR__); }
    };
    // No real tool process is started by this test.
    $runner = new class extends \OCA\ShiRE\Service\PdfProcessRunner {
        public function run(array $command, string $cwd, int $timeout = 60): string { return 'Test tool 1.0'; }
    };
    $templates = new \OCA\ShiRE\Service\InvoiceTemplateService($storage, $config, $apps, $consistency);
    $dependencies = new \OCA\ShiRE\Service\PdfDependencyService($config, $runner, $workspace, $templates, $consistency);
    $xml = file_get_contents(__DIR__ . '/../config/XRechnung.xml');
    $storno = file_get_contents(__DIR__ . '/../config/Storno.xml');
    $mapping = file_get_contents(__DIR__ . '/../config/XRechnung.tsv');
    try {
        // Removing a PDF-mapped field which the input form does not use is valid in XML mode.
        $xmlOnly = preg_replace('/<ram:IBANID>.*?<\/ram:IBANID>/', '', $xml);
        $saved = $templates->upload(['xml' => $xmlOnly, 'mapping' => 'ignored'], ['xml' => 'eigene.xml']);
        check($saved['xml'] === $xmlOnly && $saved['mapping'] === $mapping, 'XML-only upload ignores PDF replacements and preserves them');
        check($templates->customStatus()['ok'], 'XML-only template is active');
        $before = $storage->files;
        $dependencies->setPdfEnabled(true);
        $status = $dependencies->status();
        check($status['pdf_enabled'] && !$status['checks']['templates']['ok'], 'Enabling PDF fully rechecks existing custom templates');
        check($templates->current()['xml'] === $xml && $templates->error() !== '', 'Inconsistent PDF set falls back to defaults');
        check($storage->files === $before, 'Mode changes do not rewrite or delete saved templates');
        $dependencies->setPdfEnabled(false);
        $status = $dependencies->status();
        check(!$status['pdf_enabled'] && $status['checks']['templates']['ok'], 'Returning to XML rechecks and reactivates compatible XML');
        check($templates->current()['xml'] === $xmlOnly, 'Custom XML restored after disabling PDF');
        $fresh = new \OCA\ShiRE\Service\InvoiceTemplateService($storage, $config, $apps, $consistency);
        check($fresh->current()['xml'] === $xmlOnly && $fresh->customStatus()['ok'], 'XML-only choice survives a new request');
        $templates->setCustomTemplates(false);
        $dependencies->setPdfEnabled(true);
        $dependencies->status();
        $dependencies->setPdfEnabled(false);
        $dependencies->status();
        check(!$templates->usesCustomTemplates() && $templates->current()['xml'] === $xml, 'Mode changes preserve explicit custom-template opt-out');
        $templates->setCustomTemplates(true);

        // Missing fields used by the input form must fail even without ODT/TSV checks.
        $missingBuyer = str_replace('<ram:Name>Musterkunde GmbH</ram:Name>', '', $xml);
        rejects(fn () => $templates->upload(['xml' => $missingBuyer]), 'BuyerTradeParty/ram:Name');
        check(!$templates->customStatus()['ok'] && $templates->current()['xml'] === $xml, 'Invalid input-form XML falls back safely');
        rejects(fn () => $templates->upload(['xml' => '<broken>']), 'Ungültiges XML');
        rejects(fn () => $templates->upload(['xml' => '<!DOCTYPE x><x/>']), 'DTD');
        rejects(fn () => $templates->upload(['xml' => str_repeat('x', 2097153)]), '2 MiB');
        $templates->upload(['xml' => $xml, 'storno_xml' => $storno]);
        check($templates->customStatus()['ok'], 'Both invoice and cancellation XML pass without PDF');
        $brokenStorno = preg_replace('/<ram:IssuerAssignedID>.*?<\/ram:IssuerAssignedID>/', '', $storno);
        rejects(fn () => $templates->upload(['storno_xml' => $brokenStorno]), 'IssuerAssignedID');
        $templates->upload(['storno_xml' => $storno]);
        $dependencies->setPdfEnabled(true);
        check($dependencies->status()['checks']['templates']['ok'], 'Compatible XML/ODT/TSV pass when PDF is enabled');
        rejects(fn () => $templates->upload(['mapping' => '{{broken}}']), 'Mapping');
        $dependencies->setPdfEnabled(false);
        check($dependencies->status()['checks']['templates']['ok'], 'Broken PDF mapping does not block XML mode');
        check($templates->current()['mapping'] === '{{broken}}', 'Inactive PDF mapping remains stored');
        $dependencies->setPdfEnabled(true);
        check(!$dependencies->status()['checks']['templates']['ok'], 'Broken mapping is detected again on reactivation');
        $templates->upload(['mapping' => $mapping]);
        check($templates->customStatus()['ok'], 'Repairing PDF mapping restores the set');
        $config->system['shi_re_libreoffice'] = '/missing/libreoffice';
        check(!$dependencies->status()['pdf_enabled'] && !$templates->pdfTemplatesEnabled(), 'Missing LibreOffice selects XML-only checks');
        foreach ($temp->paths as $path) check(!file_exists($path), 'Temporary directory was removed');
        printf("Template mode tests passed; PHP peak allocation: %.1f MiB.\n", memory_get_peak_usage(true) / 1048576);
    } finally {
        foreach ($temp->paths as $path) $workspace->remove($path);
    }
}
