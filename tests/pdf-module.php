<?php
/** php tests/pdf-module.php [--libreoffice] [--mustang=/absolute/path/to/Mustang-CLI.jar] */
declare(strict_types=1);
namespace OCP { interface IConfig {} interface ITempManager {} interface IRequest {} }
namespace OCP\App { interface IAppManager {} }
namespace OCP\Files { interface IAppData {} class NotFoundException extends \RuntimeException {} }
namespace OCP\AppFramework {
    class App { public function __construct(public string $appId) {} }
    class Controller { public function __construct($name, protected $request) {} }
}
namespace OCP\AppFramework\Http {
    class JSONResponse { public function __construct(public array $data, public int $status = 200) {} }
    class DataDownloadResponse { public function __construct(public string $data, public string $filename, public string $type, public int $status, public array $headers) {} }
}
namespace Psr\Log { interface LoggerInterface {} }
namespace OCP\Lock { interface ILockingProvider { public const LOCK_EXCLUSIVE = 2; } }
namespace OCA\ShiRE\Service {
    class KositValidatorService {
        public bool $valid = true;
        public int $calls = 0;
        public function validateXml(string $xml): array { $this->calls++; return ['valid' => $this->valid]; }
    }
}
namespace {
    require __DIR__ . '/../lib/AppInfo/Application.php';
    use OCA\ShiRE\Service\{PdfWorkspace, PdfProcessRunner, OdtInvoiceRenderer, TemplateConsistencyService,
        InvoiceTemplateService, PdfDependencyService, PdfInvoiceService, KositValidatorService};
    foreach (['PdfWorkspace', 'PdfProcessRunner', 'OdtInvoiceRenderer', 'TemplateConsistencyService',
        'InvoiceTemplateService', 'PdfDependencyService', 'PdfInvoiceService'] as $class) {
        require __DIR__ . '/../lib/Service/' . $class . '.php';
    }
    require __DIR__ . '/../lib/Config/AppFiles.php';
    foreach (['DraftService', 'IncRamID', 'InvoiceReviewService', 'DomService'] as $class) require __DIR__ . '/../lib/Service/' . $class . '.php';
    require __DIR__ . '/../lib/Controller/PdfInvoiceController.php';
    require __DIR__ . '/../lib/Controller/PdfAdminController.php';

    function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
    function rejects(callable $action, string $contains = ''): void {
        try { $action(); } catch (Throwable $e) {
            check($contains === '' || str_contains($e->getMessage(), $contains), $e->getMessage()); return;
        }
        throw new RuntimeException('Expected rejection: ' . $contains);
    }
    $temp = new class implements \OCP\ITempManager {
        public array $paths = [];
        public function getTemporaryFolder($suffix): string {
            $dir = sys_get_temp_dir() . '/myx-pdf-test-' . bin2hex(random_bytes(8));
            mkdir($dir, 0700); $this->paths[] = $dir; return $dir;
        }
    };
    $workspace = new PdfWorkspace($temp);
    $renderer = new OdtInvoiceRenderer($workspace);
    $consistency = new TemplateConsistencyService($renderer);
    $bundle = ['odt' => file_get_contents(__DIR__ . '/../config/X-Rechnung.odt'),
        'mapping' => file_get_contents(__DIR__ . '/../config/mapping.tsv'), 'xml' => file_get_contents(__DIR__ . '/../config/en16931.xml')];
    $original = hash('sha256', $bundle['odt']);
    $consistency->check($bundle);
    $map = $renderer->mapping($bundle['mapping']);
    check(count($map) === 32 && count($renderer->placeholders($bundle['odt'])) === 32, 'Real template: 32 placeholders');
    $values = $renderer->values($map, $bundle['xml']);
    check($values['{{DocumentType}}'] === 'Rechnung', 'Document type 380 mapped to Rechnung');
    $unsupportedXml = str_replace('<ram:TypeCode>380</ram:TypeCode>', '<ram:TypeCode>381</ram:TypeCode>', $bundle['xml']);
    $unsupportedMessage = 'TypeCode 381 wird (noch) nicht unterstützt. PDF kann nicht erstellt werden.';
    rejects(fn () => $renderer->values($map, $unsupportedXml), $unsupportedMessage);
    check($values['{{Currency}}'] === '€' && $values['{{TaxRate}}'] === '19', 'Currency and VAT');
    check($values['{{UnitPrice}}'] === '181,50' && $values['{{IssueDate}}'] === '22.05.2026', 'Amounts and dates');
    check($values['{{IBAN}}'] === "DE62\u{00A0}3905\u{00A0}0000\u{00A0}1077\u{00A0}2476\u{00A0}15", 'IBAN grouping');
    $values['{{ProductDescription}}'] = "A & B <C>\nZweite  Zeile\tEnde";
    $rendered = $renderer->render($bundle['odt'], $values);
    check($renderer->placeholders($rendered) === [], 'All split placeholders in content/styles replaced');
    check(hash('sha256', $bundle['odt']) === $original, 'Source ODT preserved');
    $dir = $workspace->create();
    try {
        file_put_contents($dir . '/test.odt', $rendered);
        $zip = new ZipArchive(); $zip->open($dir . '/test.odt');
        $content = $zip->getFromName('content.xml');
        $styles = $zip->getFromName('styles.xml'); $zip->close();
        check(str_contains($content, 'A &amp; B &lt;C&gt;'), 'XML escaping');
        check(str_contains($content, 'text:line-break') && str_contains($content, 'text:tab'), 'ODF whitespace');
        check(str_contains($styles, 'DE62'), 'Footer replaced');
        // Two placeholders in one node and a placeholder split across styled nodes.
        $zip = new ZipArchive(); $zip->open($dir . '/test.odt');
        $doc = OdtInvoiceRenderer::xml($content);
        $xp = new DOMXPath($doc); $xp->registerNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');
        $p = $xp->query('//text:p')->item(0);
        while ($p->firstChild) $p->removeChild($p->firstChild);
        $p->appendChild($doc->createTextNode('{{ID}}/{{Currency}} {{Seller'));
        $span = $doc->createElementNS('urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'text:span');
        $span->appendChild($doc->createTextNode('Name}}'));
        $p->appendChild($span);
        $zip->addFromString('content.xml', $doc->saveXML()); $zip->close();
        $again = $renderer->render(file_get_contents($dir . '/test.odt'), $values);
        check($renderer->placeholders($again) === [], 'Multiple and split replacements');
    } finally { $workspace->remove($dir); }
    rejects(fn () => $consistency->check(array_replace($bundle, ['mapping' => preg_replace('/^.*\n/', '', $bundle['mapping'], 1)])), 'a)');
    rejects(fn () => $consistency->check(array_replace($bundle, ['mapping' => $bundle['mapping'] . "{{Extra}}\t/rsm:CrossIndustryInvoice/ram:Missing\n"])), 'b)');
    rejects(fn () => $consistency->check(array_replace($bundle, ['mapping' => str_replace('/ram:IBANID', '/ram:Missing', $bundle['mapping'])])), 'c)');
    rejects(fn () => $renderer->mapping($bundle['mapping'] . strtok($bundle['mapping'], "\n") . "\n"), 'Doppelter');
    rejects(fn () => $renderer->values($map, str_replace('19.00', '7.50', $bundle['xml'])), 'TaxRate');
    rejects(fn () => OdtInvoiceRenderer::xml('<!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><x>&a;</x>'), 'DTD');
    rejects(fn () => $renderer->placeholders('not a zip'), 'ODT');

    $config = new class implements \OCP\IConfig {
        public array $app = [], $system = [];
        public function getAppValue($app, $key, $default) { return $this->app[$key] ?? $default; }
        public function setAppValue($app, $key, $value): void { $this->app[$key] = $value; }
        public function getSystemValueString($key, $default): string { return $this->system[$key] ?? $default; }
    };
    $storage = new class implements \OCP\Files\IAppData {
        public array $files = [];
        public function getFolder($name): object { return $this; }
        public function newFolder($name): object { return $this; }
        public function fileExists($name): bool { return isset($this->files[$name]); }
        public function newFile($name, $data): void { $this->files[$name] = $data; }
        public function getDirectoryListing(): array {
            return array_map(fn ($name) => $this->getFile($name), array_keys($this->files));
        }
        public function getFile($name): object {
            if (!isset($this->files[$name])) throw new \OCP\Files\NotFoundException();
            return new class($this, $name) {
                public function __construct(private object $storage, private string $name) {}
                public function getContent(): string { return $this->storage->files[$this->name]; }
                public function putContent(string $content): void { $this->storage->files[$this->name] = $content; }
                public function delete(): void { unset($this->storage->files[$this->name]); }
                public function getName(): string { return $this->name; }
                public function getMTime(): int { return $this->name === 'older.pdf' ? 1 : 2; }
            };
        }
    };
    $apps = new class implements \OCP\App\IAppManager { public function getAppPath($id): string { return dirname(__DIR__); } };
    $templates = new InvoiceTemplateService($storage, $config, $apps, $consistency);
    check(!$templates->usesCustomTemplates(), 'Own templates default off');
    $templates->setCustomTemplates(true);
    $defaultVersion = $templates->current()['version'];
    rejects(fn () => $templates->upload(['mapping' => '{{broken}}']), 'Mapping');
    check($templates->error() !== '' && $templates->current()['version'] === $defaultVersion, 'Bad upload disables, preserves last valid');
    $templates->setCustomTemplates(false);
    check($templates->error() === '' && $templates->current()['version'] === $defaultVersion, 'Standard templates ignore failed custom upload');
    $templates->setCustomTemplates(true);
    check($templates->error() !== '', 'Re-enabling preserves custom validation error');
    $saved = $templates->upload([]);
    check($templates->error() === '' && $saved['xml'] === $bundle['xml'], 'Valid complete bundle and recovery');
    $templates->setCustomTemplates(false);
    check($templates->current()['version'] === $defaultVersion, 'Disabling restores bundled templates');
    $templates->setCustomTemplates(true);
    check($templates->current()['version'] === $saved['version'], 'Re-enabling restores saved templates');
    $runner = new PdfProcessRunner();
    $dependencies = new PdfDependencyService($config, $runner, $workspace, $templates, $consistency);
    check(!$dependencies->status()['enabled'], 'Missing Mustang disables module');
    $config->system['shi_re_libreoffice'] = PHP_BINARY;
    $config->system['shi_re_java'] = PHP_BINARY;
    $probeRunner = new class extends PdfProcessRunner {
        public function run(array $command, string $cwd, int $timeout = 60): string { return 'Test tool 24.2.1'; }
    };
    $probe = (new PdfDependencyService($config, $probeRunner, $workspace, $templates, $consistency))->status();
    check($probe['checks']['libreoffice']['ok'] && $probe['checks']['libreoffice']['version'] === '24.2.1', 'Independent LibreOffice check and version');
    check(!$probe['checks']['mustang']['ok'] && !$probe['enabled'], 'Missing Mustang has its own failure');
    check(!$probe['checks']['verapdf']['ok'] && str_contains($probe['checks']['verapdf']['detail'], 'Optional'), 'Missing optional veraPDF is explicit');
    unset($config->system['shi_re_libreoffice'], $config->system['shi_re_java']);
    $dir = $workspace->create();
    try {
        check($runner->run([PHP_BINARY, '-r', 'echo $argv[1];', '$(echo unsafe);x'], $dir) === '$(echo unsafe);x', 'No shell interpolation');
        rejects(fn () => $runner->run([PHP_BINARY, '-r', 'exit(4);'], $dir), 'fehlgeschlagen');
        rejects(fn () => $runner->run([PHP_BINARY, '-r', 'sleep(2);'], $dir, 0), 'Zeitlimit');
    } finally { $workspace->remove($dir); }

    // Vertragstests ohne installierte Werkzeuge, echte Dateien und Aufräumpfade.
    $fakeDependencies = new class($config, $runner, $workspace, $templates, $consistency) extends PdfDependencyService {
        public bool $enabled = true;
        public function status(): array { return ['enabled' => $this->enabled, 'errors' => ['disabled']]; }
        public function tools(): array { return ['libreoffice' => 'lo', 'java' => 'java', 'mustang' => 'mustang.jar', 'verapdf' => '']; }
    };
    $fakeRunner = new class extends PdfProcessRunner {
        public bool $wrongXml = false, $fail = false;
        public array $commands = [];
        public function run(array $command, string $cwd, int $timeout = 60): string {
            $this->commands[] = $command;
            if ($this->fail) throw new RuntimeException('simulated failure');
            if ($command[0] === 'lo') file_put_contents($cwd . '/invoice.pdf', '%PDF-1.7 test');
            if (in_array('combine', $command, true)) file_put_contents($cwd . '/final.pdf', '%PDF-1.7 final');
            if (in_array('extract', $command, true)) file_put_contents($cwd . '/extracted.xml', $this->wrongXml ? '<wrong/>' : file_get_contents($cwd . '/invoice.xml'));
            return '';
        }
    };
    $pdf = new PdfInvoiceService($fakeDependencies, $templates, $renderer, $fakeRunner, $workspace);
    rejects(fn () => $pdf->generate($unsupportedXml, $saved['version']), $unsupportedMessage);
    check($fakeRunner->commands === [], 'Unsupported document type rejected before PDF tools run');
    $result = $pdf->generate($bundle['xml'], $saved['version']);
    check($result['filename'] === '26 200.pdf', 'Invoice ID filename');
    check(PdfInvoiceService::filename('../../a/b') === '_.._a_b.pdf', 'Filename sanitization');
    $fakeRunner->wrongXml = true;
    rejects(fn () => $pdf->generate($bundle['xml'], $saved['version']), 'eingebettete XML');
    $fakeRunner->wrongXml = false; $fakeRunner->fail = true;
    rejects(fn () => $pdf->generate($bundle['xml'], $saved['version']), 'simulated');
    $fakeRunner->fail = false;
    rejects(fn () => $pdf->generate($bundle['xml'], str_repeat('0', 64)), 'Vorlagen');
    $fakeDependencies->enabled = false;
    rejects(fn () => $pdf->generate($bundle['xml'], $saved['version']), 'disabled');
    $fakeDependencies->enabled = true;

    $request = new class implements \OCP\IRequest {
        public array $params = [];
        public function getParam($key) { return $this->params[$key] ?? null; }
    };
    $logger = new class implements \Psr\Log\LoggerInterface { public function error(...$args): void {} };
    $validator = new KositValidatorService();
    $locking = new class implements \OCP\Lock\ILockingProvider {
        public bool $locked = false;
        public bool $blocked = false;
        public function acquireLock($path, $type): void {
            if ($this->blocked || $this->locked) throw new RuntimeException('locked');
            $this->locked = true;
        }
        public function releaseLock($path, $type): void { $this->locked = false; }
    };
    $controller = new \OCA\ShiRE\Controller\PdfInvoiceController('shi_re', $request, $storage, $validator, $fakeDependencies, $templates, $pdf, $logger, $locking);
    $storage->files['en16931.xml'] = $bundle['xml'];
    check($controller->download()->status === 400 && $validator->calls === 0, 'Temporary XML cannot generate PDF');
    $storage->files['26 200.xml'] = $bundle['xml'];
    $storage->files['en16931.xml'] = '<unrelated-temporary-xml/>';
    $request->params = ['filename' => '26 200.xml', 'xmlHash' => hash('sha256', $bundle['xml']), 'templateVersion' => $saved['version']];
    check($controller->download() instanceof \OCP\AppFramework\Http\DataDownloadResponse, 'Validated PDF response');
    check($storage->files['26 200.pdf'] === '%PDF-1.7 final', 'Generated PDF persisted');
    check($storage->files['26 200.xml'] === $bundle['xml'], 'Exact validated XML archived beside PDF');
    $storage->files['26 200.pdf'] = '%PDF original archived content';
    $duplicate = $controller->download();
    check($duplicate->status === 409 && str_contains($duplicate->data['error'], 'nicht überschrieben'), 'Duplicate invoice rejected explicitly');
    check($storage->files['26 200.pdf'] === '%PDF original archived content', 'Archived bytes unchanged');
    check(!$locking->locked, 'Lock released after conflict');
    $locking->blocked = true;
    check($controller->download()->status === 500, 'Unavailable lock fails closed');
    check($storage->files['26 200.pdf'] === '%PDF original archived content', 'Lock contention preserves archive');
    $locking->blocked = false;
    $storage->files['older.pdf'] = '%PDF older';
    check(array_column($controller->invoices()->data['invoices'], 'filename') === ['26 200.xml', '26 200.pdf', 'older.pdf'], 'PDF and archived XML listed, latest first');
    $request->params['filename'] = '26 200.pdf';
    check($controller->saved()->data === '%PDF original archived content', 'Stored PDF retrieved without regeneration');
    $request->params['filename'] = '26 200.xml';
    $xmlDownload = $controller->saved();
    check($xmlDownload->data === $bundle['xml'] && $xmlDownload->type === 'application/xml', 'Archived XML downloadable');
    foreach (['en16931.xml', 'draft.xml', '../26 200.pdf', 'folder\\invoice.pdf', "bad\0.pdf", 'draft.json', ['bad']] as $bad) {
        $request->params['filename'] = $bad;
        check($controller->saved()->status === 400, 'Unsafe filename rejected');
    }
    $request->params['filename'] = 'missing.pdf';
    check($controller->saved()->status === 404, 'Missing invoice handled');
    $request->params['filename'] = '26 200.xml';
    $storage->files['26 200.xml'] = $unsupportedXml;
    $request->params['xmlHash'] = hash('sha256', $unsupportedXml);
    $unsupportedResponse = $controller->download();
    check($unsupportedResponse->status === 422 && $unsupportedResponse->data['error'] === $unsupportedMessage,
        'Unsupported TypeCode message returned to browser');
    $storage->files['en16931.xml'] = $bundle['xml'];
    $storage->files['26 200.xml'] = $bundle['xml'];
    $request->params['xmlHash'] = hash('sha256', $bundle['xml']);
    $validator->valid = false;
    check($controller->download()->status === 422, 'Invalid XML blocked');
    $storage->files['26 200.xml'] = '<changed/>';
    check($controller->download()->status === 409, 'Concurrent XML change blocked');
    // Review acceptance/discard uses the real draft lifecycle and exact XML bytes.
    $storage->files = [];
    $drafts = new \OCA\ShiRE\Service\DraftService($storage);
    $review = new \OCA\ShiRE\Service\InvoiceReviewService($storage, $drafts, $validator, $locking);
    $drafts->create('window', '26 200');
    $storage->files['en16931.xml'] = $bundle['xml'];
    $storage->files['draft.xml'] = 'temporary';
    $hash = hash('sha256', $bundle['xml']);
    rejects(fn () => $review->finish('other-window', $hash, true));
    rejects(fn () => $review->finish('window', 'stale', true), 'geändert');
    $validator->valid = false;
    rejects(fn () => $review->finish('window', $hash, true), 'valide');
    check(!isset($storage->files['26 200.xml']), 'Rejected XML never archived');
    check($review->finish('window', $hash, false) === null, 'Invalid XML may be discarded');
    check($storage->files === [], 'Discard removes all temporary invoice data without archiving');
    $validator->valid = true;
    $drafts->create('window', '26 200');
    $storage->files['en16931.xml'] = $bundle['xml'];
    check($review->finish('window', $hash, true) === '26 200.xml', 'Explicit acceptance archives XML');
    check($storage->files === ['26 200.xml' => $bundle['xml']], 'Only exact accepted XML remains');
    $drafts->create('window', '26 200');
    $storage->files['en16931.xml'] = $bundle['xml'];
    rejects(fn () => $review->finish('window', $hash, true), 'überschrieben');
    check($storage->files['26 200.xml'] === $bundle['xml'], 'Accepted XML never overwritten');
    $review->finish('window', $hash, false);
    $domService = new \OCA\ShiRE\Service\DomService('shi_re', $logger, $storage, $apps, $templates);
    check($domService->nextInvoiceNumber() === '26 201', 'Number advances from archive after temporary XML deletion');
    $storage->files['26 202.pdf'] = '%PDF legacy';
    check($domService->nextInvoiceNumber() === '26 203', 'Legacy PDF also reserves its number');
    foreach (['upload', 'status'] as $method) {
        $reflection = new ReflectionMethod(\OCA\ShiRE\Controller\PdfAdminController::class, $method);
        check(count($reflection->getAttributes('OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired')) === 0, 'Admin-only upload/status');
        check(count($reflection->getAttributes('OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired')) === 0, 'Upload requires CSRF');
    }

    if (in_array('--libreoffice', $argv, true)) {
        $dir = $workspace->create();
        try {
            file_put_contents($dir . '/invoice.odt', $rendered);
            $runner->run(['/usr/bin/libreoffice', '-env:UserInstallation=file://' . $dir . '/profile', '--headless', '--convert-to',
                'pdf:writer_pdf_Export:{"SelectPdfVersion":{"type":"long","value":"3"}}', '--outdir', $dir, $dir . '/invoice.odt'], $dir);
            $output = file_get_contents($dir . '/invoice.pdf');
            check(str_starts_with($output, '%PDF-'), 'Real LibreOffice PDF');
            check(str_contains($output, 'pdfaid:part="3"') || str_contains($output, '<pdfaid:part>3</pdfaid:part>'), 'PDF/A part 3 metadata');
            echo "Real LibreOffice PDF/A-3 export passed.\n";
        } finally { $workspace->remove($dir); }
    }
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--mustang=')) continue;
        $config->system['shi_re_mustang_jar'] = substr($arg, 10);
        check($dependencies->status()['enabled'], implode(' ', $dependencies->status()['errors']));
        $realPdf = new PdfInvoiceService($dependencies, $templates, $renderer, $runner, $workspace);
        $result = $realPdf->generate($bundle['xml'], $saved['version']);
        check(str_starts_with($result['content'], '%PDF-'), 'Real Mustang PDF, extraction and validation');
        echo "Real Mustang combine/extract/validate passed.\n";
    }
    foreach ($temp->paths as $path) check(!file_exists($path), 'Temporary directory removed: ' . $path);
    echo "PDF module tests passed: real ODT, mapping, formatting, uploads, dependency gate, hash validation, process failures and cleanup.\n";
}
