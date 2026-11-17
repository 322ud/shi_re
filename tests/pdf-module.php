<?php
/** php tests/pdf-module.php [--libreoffice] [--mustang=/absolute/path/to/Mustang-CLI.jar] [--verapdf=/absolute/path/to/verapdf] */
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
        public bool $active = true;
        public function isActive(): bool { return $this->active; }
        public bool $valid = true;
        public int $calls = 0;
        public function versions(): array { return ['INValidatorVersion' => '0.5.1', 'kositValidatorVersion' => '1.6.0', 'xrechnungSzenarioVersion' => '2026-01-31']; }
        public function validateXml(string $xml): array { $this->calls++; return ['valid' => $this->valid]; }
    }
}
namespace {
    require __DIR__ . '/proof-fixtures.php';
    require __DIR__ . '/../lib/AppInfo/Application.php';
    use OCA\ShiRE\Service\{PdfWorkspace, PdfProcessRunner, OdtInvoiceRenderer, TemplateConsistencyService,
        InvoiceTemplateService, PdfDependencyService, PdfInvoiceService, KositValidatorService};
    foreach (['PdfWorkspace', 'PdfProcessRunner', 'OdtInvoiceRenderer', 'TemplateConsistencyService',
        'InvoiceTemplateService', 'PdfMetadataService', 'PdfDependencyService', 'PdfInvoiceService'] as $class) {
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
    $bundle = ['odt' => file_get_contents(__DIR__ . '/../config/XRechnung.odt'),
        'mapping' => file_get_contents(__DIR__ . '/../config/XRechnung.tsv'), 'xml' => file_get_contents(__DIR__ . '/../config/XRechnung.xml')];
    // Explicit invoice fixture: bundled configuration is an editable blank template.
    $bundle['xml'] = str_replace(['26 000', 'DEXX XXXX XXXX XXXX XX'], ['26 200', 'DE62390500001077247615'], $bundle['xml']);
    $original = hash('sha256', $bundle['odt']);
    $consistency->check($bundle);
    $map = $renderer->mapping($bundle['mapping']);
    check(count($map) === 33 && count($renderer->placeholders($bundle['odt'])) === 33, 'Real template: 33 placeholders');
    $values = $renderer->values($map, $bundle['xml']);
    check($values['{{DocumentType}}'] === 'Rechnung', 'Document type 380 mapped to Rechnung');
    $unsupportedXml = str_replace('<ram:TypeCode>380</ram:TypeCode>', '<ram:TypeCode>381</ram:TypeCode>', $bundle['xml']);
    $unsupportedMessage = 'TypeCode 381 wird (noch) nicht unterstützt. PDF kann nicht erstellt werden.';
    rejects(fn () => $renderer->values($map, $unsupportedXml), $unsupportedMessage);
    check($values['{{Currency}}'] === '€' && $values['{{TaxRate}}'] === '19', 'Currency and VAT');
    check($values['{{UnitPrice}}'] === '100,00' && $values['{{IssueDate}}'] === '01.01.2026', 'Amounts and dates');
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
    check($templates->customStatus()['detail'] === 'inaktiv' && !$templates->customStatus()['ok'], 'No custom set must not pass as defaults');
    $templates->setCustomTemplates(true);
    $defaultVersion = $templates->current()['version'];
    rejects(fn () => $templates->upload(['mapping' => '{{broken}}']), 'Mapping');
    check($templates->error() !== '' && $templates->current()['version'] === $defaultVersion, 'Bad upload disables, preserves last valid');
    $templates->setCustomTemplates(false);
    check($templates->error() === '' && $templates->current()['version'] === $defaultVersion, 'Standard templates ignore failed custom upload');
    $templates->setCustomTemplates(true);
    check($templates->error() !== '', 'Re-enabling preserves custom validation error');
    check($templates->customStatus()['detail'] === 'inaktiv', 'Custom failure is inactive');
    rejects(fn () => $templates->upload([]), 'mindestens');
    $invalidVersion = $config->app['pdf_template_version'];
    $storedInvalid = json_decode($storage->getFolder('pdf-templates')->getFile($invalidVersion . '.json')->getContent(), true);
    check($storedInvalid['mapping'] === '{{broken}}', 'Inconsistent first upload is persisted');
    $templates->discard();
    $templates->setCustomTemplates(true);
    $saved = $templates->upload(['xml' => file_get_contents(__DIR__ . '/../config/XRechnung.xml')], ['xml' => 'eigene-rechnung.xml']);
    check($templates->customStatus()['files']['mapping'] === ['name' => 'XRechnung.tsv', 'source' => 'default'], 'Default mapping is identified');
    $metadata = $templates->customStatus()['files'];
    check($metadata['xml']['name'] === 'eigene-rechnung.xml' && $metadata['xml']['date'] !== '', 'Upload stores filename and date');
    check($templates->current()['version'] !== $defaultVersion, 'Own bundle selected');
    rejects(fn () => $templates->upload([]), 'mindestens');
    check($templates->current()['version'] === $saved['version'], 'Empty upload leaves active bundle unchanged');
    rejects(fn () => $templates->upload(['mapping' => '{{broken}}']), 'Mapping');
    check($templates->current()['version'] === $defaultVersion, 'Invalid upload falls back to defaults for XML and PDF');
    check(!$templates->customStatus()['ok'] && $templates->customStatus()['detail'] === 'inaktiv', 'Invalid templates marked inactive');
    $invalidVersion = $config->app['pdf_template_version'];
    $storedInvalid = json_decode($storage->getFolder('pdf-templates')->getFile($invalidVersion . '.json')->getContent(), true);
    check($storedInvalid['mapping'] === '{{broken}}' && $storedInvalid['xml'] === $saved['xml'], 'Invalid replacement is saved alongside unchanged files');
    check($templates->customStatus()['files']['xml'] === $metadata['xml'], 'Unchanged file metadata is preserved');
    check($templates->customStatus()['files']['mapping']['name'] === 'XRechnung.tsv', 'Invalid file metadata is displayed');
    $templates = new InvoiceTemplateService($storage, $config, $apps, $consistency);
    check($templates->current()['version'] === $defaultVersion, 'Inactive state survives a new request');
    rejects(fn () => $templates->upload(['xml' => $saved['xml']], ['xml' => 'korrigiert.xml']), 'Mapping');
    check($templates->current()['version'] === $defaultVersion, 'Partial update cannot silently replace invalid mapping with default');
    $repaired = $templates->upload(['mapping' => $saved['mapping']], ['mapping' => 'korrigiert.tsv']);
    check($templates->customStatus()['ok'] && $repaired['xml'] === $saved['xml'], 'Replacing the invalid file activates repaired bundle');
    check($templates->customStatus()['files']['xml']['name'] === 'korrigiert.xml', 'Repair retains earlier partial correction');
    rejects(fn () => $templates->upload(['mapping' => '{{broken}}']), 'Mapping');
    rejects(fn () => $templates->upload([]), 'mindestens');
    check($templates->current()['version'] === $defaultVersion, 'Empty upload cannot reactivate old set');
    $templates->discard();
    check(!$templates->usesCustomTemplates() && $templates->current()['version'] === $defaultVersion, 'Discard uses defaults');
    check($config->app['pdf_template_version'] === '' && $templates->customStatus()['detail'] === 'inaktiv', 'Discard clears active custom pointer');
    $templates->setCustomTemplates(true);
    check($templates->current()['version'] === $defaultVersion, 'Switch cannot restore discarded set');
    $saved = $templates->upload(['xml' => $saved['xml']], ['xml' => 'eigene-rechnung.xml']);
    check($templates->customStatus()['ok'], 'New valid upload activates own templates');
    $templates->setCustomTemplates(false);
    check($templates->current()['version'] === $defaultVersion, 'Disabling restores defaults');
    $templates->setCustomTemplates(true);
    check($templates->current()['version'] === $saved['version'], 'Re-enabling valid saved templates works');
    $runner = new PdfProcessRunner();
    $metadata = new \OCA\ShiRE\Service\PdfMetadataService();
    check($metadata->author('Max Mustermann', '322UD.DE:8443') === 'mm@322ud.de', 'Lowercase initials and domain without port');
    check($metadata->author('Änne-Marie Öster', 'CLOUD.Example.org') === 'ämö@example.org', 'Unicode and hyphen initials');
    check($metadata->author('Frank Bauer', 'cloud.322ud.de') === 'fb@322ud.de', 'Cloud prefix removed from author domain');
    check($metadata->author('Frank Bauer', 'CLOUD.Example.CO.UK:8443') === 'fb@example.co.uk', 'Multi-part domain suffix preserved');
    $properties = OdtInvoiceRenderer::xml($metadata->properties('Rechnung & Prüfung',
        ['mustang' => '2.26.0', 'libreoffice' => '24.2.1', 'verapdf' => '1.28.2'],
        ['INValidatorVersion' => '0.5.1', 'kositValidatorVersion' => '1.6.0', 'xrechnungSzenarioVersion' => '2026-01-31'], false));
    $xp = new DOMXPath($properties);
    check($xp->evaluate('string(/metadata/property[@name="title"])') === 'Rechnung & Prüfung', 'Metadata values XML escaped');
    check($xp->evaluate('string(/metadata/property[@name="producer"])') === 'MustangCLI 2.26.0', 'Producer uses CLI release');
    check($xp->evaluate('count(/metadata/property[@name="shi_re:veraPDFVersion"])') === 0.0, 'Unused veraPDF omitted');
    check($xp->evaluate('string(/metadata/property[@name="inv_validator:xrechnungSzenarioVersion"])') === '2026-01-31', 'Scenario release preserved');
    check(str_contains($metadata->properties('Rechnung', [], [], true), 'unbekannt'), 'Missing versions explicit');
    foreach ([false, true] as $xmlActive) {
        foreach ([false, true] as $pdfaActive) {
            $doc = OdtInvoiceRenderer::xml($metadata->properties('Rechnung', ['verapdf' => '1.28.2'],
                $xmlActive ? ['INValidatorVersion' => '0.5.1'] : [], $pdfaActive));
            $xp = new DOMXPath($doc);
            check($xp->evaluate('count(/metadata/property[starts-with(@name,"inv_validator:") or @name="shi_re:INValidatorVersion"])')
                === ($xmlActive ? 3.0 : 0.0), 'INValidator metadata only for active XML validation');
            check($xp->evaluate('count(/metadata/property[@name="shi_re:veraPDFVersion"])')
                === ($pdfaActive ? 1.0 : 0.0), 'veraPDF metadata only for active PDF/A validation');
        }
    }

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
    $dir = $workspace->create();
    try {
        $config->system['shi_re_mustang_jar'] = $dir . '/Mustang-CLI-0.0.0.jar';
        $mustangRunner = new class extends PdfProcessRunner {
            public function run(array $command, string $cwd, int $timeout = 60): string {
                return 'Factur-X 1.0 combine extract validate no-additional-attachments';
            }
        };
        $mustangDependencies = new PdfDependencyService($config, $mustangRunner, $workspace, $templates, $consistency);
        $jar = new ZipArchive();
        $jar->open($config->system['shi_re_mustang_jar'], ZipArchive::CREATE);
        $jar->addFromString('META-INF/maven/org.mustangproject/Mustang-CLI/pom.properties', "version=2.26.0\n");
        $jar->addFromString('META-INF/MANIFEST.MF', "Manifest-Version: 1.0\r\nImplementation-Version: 2.25.0\r\n\r\n");
        $jar->close();
        $probe = $mustangDependencies->status();
        check($probe['checks']['mustang']['ok'] && $probe['checks']['mustang']['version'] === '2.26.0', 'Mustang release from own Maven metadata, not help or filename');
        $jar->open($config->system['shi_re_mustang_jar']);
        $jar->deleteName('META-INF/maven/org.mustangproject/Mustang-CLI/pom.properties');
        $jar->close();
        check($mustangDependencies->status()['versions']['mustang'] === '2.25.0', 'Mustang manifest fallback');
        $jar->open($config->system['shi_re_mustang_jar']);
        $jar->addFromString('META-INF/MANIFEST.MF', "Manifest-Version: 1.0\r\n\r\nName: dependency\r\nImplementation-Version: 9.9.9\r\n");
        $jar->close();
        check($mustangDependencies->status()['versions']['mustang'] === 'unbekannt', 'Missing release metadata must not report format or dependency versions');
    } finally {
        unset($config->system['shi_re_mustang_jar']);
        $workspace->remove($dir);
    }
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
        public bool $wrongXml = false, $fail = false, $failMetadata = false;
        public array $commands = [];
        public string $metadata = '';
        public function run(array $command, string $cwd, int $timeout = 60): string {
            $this->commands[] = $command;
            if ($this->fail) throw new RuntimeException('simulated failure');
            if ($command[0] === 'lo') file_put_contents($cwd . '/invoice.pdf', '%PDF-1.7 test');
            if (in_array('combine', $command, true)) file_put_contents($cwd . '/combined.pdf', '%PDF-1.7 combined');
            if (in_array('ShiRePdfMetadata', $command, true)) {
                if ($this->failMetadata) throw new RuntimeException('metadata failure');
                $this->metadata = file_get_contents($cwd . '/metadata.xml');
                file_put_contents($cwd . '/final.pdf', '%PDF-1.7 final');
            }
            if (in_array('extract', $command, true)) file_put_contents($cwd . '/extracted.xml', $this->wrongXml ? '<wrong/>' : file_get_contents($cwd . '/invoice.xml'));
            return '';
        }
    };
    require __DIR__ . '/../lib/Service/ProgressService.php';
    $progress = new class extends \OCA\ShiRE\Service\ProgressService {
        public array $events = [];
        public ?string $current = null;
        public function __construct() {}
        public function start(string $text): void { $this->current = $text; }
        public function finish(string $text): void { $this->events[] = $text; $this->current = null; }
        public function fail(): void { if ($this->current !== null) $this->finish('FAILED: ' . $this->current); }
    };
    $pdf = new PdfInvoiceService($fakeDependencies, $templates, $renderer, $fakeRunner, $workspace, $config, $progress);
    rejects(fn () => $pdf->generate($unsupportedXml, $saved['version']), $unsupportedMessage);
    check($fakeRunner->commands === [], 'Unsupported document type rejected before PDF tools run');
    $result = $pdf->generate($bundle['xml'], $saved['version']);
    check(in_array('combine', $fakeRunner->commands[1], true)
        && in_array('ShiRePdfMetadata', $fakeRunner->commands[2], true)
        && in_array('extract', $fakeRunner->commands[3], true)
        && in_array('validate', $fakeRunner->commands[4], true), 'Metadata written before final extraction and validation');
    check(str_contains($fakeRunner->metadata, '<property name="title">Rechnung</property>'), 'Title derived from TypeCode');
    $config->app['validation_enabled'] = 'no';
    $pdf->generate($bundle['xml'], $saved['version'], ['INValidatorVersion' => '0.5.1']);
    check(!str_contains($fakeRunner->metadata, 'Validator') && !str_contains($fakeRunner->metadata, 'inv_validator:'),
        'Disabling XML validation omits even remembered validator versions');
    $config->app['validation_enabled'] = 'yes';
    check(in_array('PDF aus der Rechnungsvorlage erzeugt.', $progress->events, true), 'LibreOffice completion reported');
    check(in_array('Mustang-Prüfung erfolgreich abgeschlossen.', $progress->events, true), 'Mustang completion reported');
    check(in_array('Zusätzliche PDF/A-3b-Prüfung mit veraPDF nicht aktiviert.', $progress->events, true), 'Skipped veraPDF explicitly reported');
    check(!in_array('PDF/A-3b-Prüfung erfolgreich abgeschlossen.', $progress->events, true), 'No success reported for skipped validation');
    $progress->events = [];
    $fakeRunner->failMetadata = true;
    rejects(fn () => $pdf->generate($bundle['xml'], $saved['version']), 'metadata failure');
    check(in_array('FAILED: PDF-Metadaten werden ergänzt …', $progress->events, true), 'Failed metadata step identified');
    check(!in_array('PDF-Metadaten ergänzt.', $progress->events, true), 'No false metadata completion after failure');
    check(end($progress->events) === 'Temporäre PDF-Arbeitsdateien entfernt.', 'Cleanup completion survives PDF failure');
    $fakeRunner->failMetadata = false;
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
        public function getServerHost(): string { return 'CLOUD.322UD.DE:8443'; }
        public array $params = [];
        public function getParam($key) { return $this->params[$key] ?? null; }
    };
    $logger = new class implements \Psr\Log\LoggerInterface { public function error(...$args): void {} };
    $validator = new KositValidatorService();
    $locking = new class implements \OCP\Lock\ILockingProvider {
        public bool $locked = false;
        public array $locks = [];
        public bool $blocked = false;
        public function acquireLock($path, $type): void {
            if ($this->blocked || isset($this->locks[$path])) throw new RuntimeException('locked');
            $this->locks[$path] = true; $this->locked = true;
        }
        public function releaseLock($path, $type): void { unset($this->locks[$path]); $this->locked = (bool)$this->locks; }
    };
    $users = proofUsers();
    $proof = new \OCA\ShiRE\Service\ValidationProofService($storage, $users, $locking, $validator);
    $authorizePdf = function (string $xml) use ($proof, $request, $validator): void {
        $token = $proof->remember($xml, 'window', $validator->versions());
        $record = $proof->requireProof($xml, $token, 'window', 'review');
        $proof->allowPdf($record, '26 200.xml');
        $request->params['validationToken'] = $token;
        $request->params['windowId'] = 'window';
    };
    $controller = new \OCA\ShiRE\Controller\PdfInvoiceController('shi_re', $request, $storage, $proof, $fakeDependencies, $templates, $pdf, $logger, $locking, $users, $progress);
    $storage->files['tmp.xml'] = $bundle['xml'];
    check($controller->download()->status === 400 && $validator->calls === 0, 'Temporary XML cannot generate PDF');
    $storage->files['26 200.xml'] = $bundle['xml'];
    $storage->files['tmp.xml'] = '<unrelated-temporary-xml/>';
    $request->params = ['filename' => '26 200.xml', 'xmlHash' => hash('sha256', $bundle['xml']), 'templateVersion' => $saved['version']];
    check($controller->download()->status === 409, 'Archive alone does not authorize PDF');
    $authorizePdf($bundle['xml']);
    check($controller->download() instanceof \OCP\AppFramework\Http\DataDownloadResponse, 'Validated PDF response');
    check(str_contains($fakeRunner->metadata, '<property name="author">a@322ud.de</property>'), 'Current user and request host reach PDF metadata');
    check(str_contains($fakeRunner->metadata, '<property name="inv_validator:kositValidatorVersion">1.6.0</property>')
        && str_contains($fakeRunner->metadata, '<property name="inv_validator:xrechnungSzenarioVersion">2026-01-31</property>'), 'Controller forwards local validator versions to PDF metadata');
    check($storage->files['26 200.pdf'] === '%PDF-1.7 final', 'Generated PDF persisted');
    check($storage->files['26 200.xml'] === $bundle['xml'], 'Exact validated XML archived beside PDF');
    check($validator->calls === 0, 'PDF uses remembered validation without calling INValidator');
    check($controller->download()->status === 409, 'PDF proof consumed exactly once');
    $authorizePdf($bundle['xml']);
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
    foreach (['tmp.xml', 'draft.xml', '../26 200.pdf', 'folder\\invoice.pdf', "bad\0.pdf", 'draft.json', ['bad']] as $bad) {
        $request->params['filename'] = $bad;
        check($controller->saved()->status === 400, 'Unsafe filename rejected');
    }
    $request->params['filename'] = 'missing.pdf';
    check($controller->saved()->status === 404, 'Missing invoice handled');
    $request->params['filename'] = '26 200.xml';
    $storage->files['26 200.xml'] = $unsupportedXml;
    $request->params['xmlHash'] = hash('sha256', $unsupportedXml);
    $authorizePdf($unsupportedXml);
    $unsupportedResponse = $controller->download();
    check($unsupportedResponse->status === 422 && $unsupportedResponse->data['error'] === $unsupportedMessage,
        'Unsupported TypeCode message returned to browser');
    $storage->files['tmp.xml'] = $bundle['xml'];
    $storage->files['26 200.xml'] = $bundle['xml'];
    $request->params['xmlHash'] = hash('sha256', $bundle['xml']);
    $validator->valid = false;
    check($controller->download()->status === 409, 'Missing proof blocked');
    $storage->files['26 200.xml'] = '<changed/>';
    check($controller->download()->status === 409, 'Concurrent XML change blocked');
    $validator->active = false;
    $storage->files = [];
    $unvalidatedDrafts = new \OCA\ShiRE\Service\DraftService($storage, $proof);
    $unvalidatedReview = new \OCA\ShiRE\Service\InvoiceReviewService($storage, $unvalidatedDrafts, $proof);
    $unvalidatedDrafts->create('window', '26 200');
    $storage->files['tmp.xml'] = $bundle['xml'];
    $unvalidatedToken = $proof->remember($bundle['xml'], 'window', [], false);
    $unvalidatedReview->finish('window', hash('sha256', $bundle['xml']), true, $unvalidatedToken, true);
    $request->params = ['filename' => '26 200.xml', 'xmlHash' => hash('sha256', $bundle['xml']),
        'templateVersion' => $saved['version'], 'windowId' => 'window', 'validationToken' => $unvalidatedToken];
    $validator->active = true;
    check($controller->download()->status === 409, 'Reenabled XML validation blocks unvalidated PDF authorization');
    $validator->active = false;
    check($controller->download()->status === 200, 'Unvalidated XML can generate PDF when validator is inactive');
    check(!str_contains($fakeRunner->metadata, 'inv_validator:') && !str_contains($fakeRunner->metadata, 'INValidatorVersion'),
        'Unvalidated PDF has no INValidator metadata');
    $validator->active = true;
    // Review acceptance/discard uses the real draft lifecycle and exact XML bytes.
    $storage->files = [];
    $drafts = new \OCA\ShiRE\Service\DraftService($storage, $proof);
    $review = new \OCA\ShiRE\Service\InvoiceReviewService($storage, $drafts, $proof);
    $drafts->create('window', '26 200');
    $storage->files['tmp.xml'] = $bundle['xml'];
    $storage->files['draft.xml'] = 'temporary';
    $hash = hash('sha256', $bundle['xml']);
    rejects(fn () => $review->finish('other-window', $hash, true));
    rejects(fn () => $review->finish('window', 'stale', true), 'geändert');
    $validator->valid = false;
    rejects(fn () => $review->finish('window', $hash, true), 'Validierungsnachweis');
    check(!isset($storage->files['26 200.xml']), 'Rejected XML never archived');
    check($review->finish('window', $hash, false) === null, 'Invalid XML may be discarded');
    check($storage->files === [], 'Discard removes all temporary invoice data without archiving');
    $validator->valid = true;
    $drafts->create('window', '26 200');
    $storage->files['tmp.xml'] = $bundle['xml'];
    $token = $proof->remember($bundle['xml'], 'window', $validator->versions());
    $users->uid = 'bob';
    rejects(fn () => $review->finish('window', $hash, true, $token), 'Validierungsnachweis');
    $users->uid = 'alice';
    rejects(fn () => $review->finish('window', $hash, true, 'wrong'), 'Validierungsnachweis');
    check($review->finish('window', $hash, true, $token) === '26 200.xml', 'Explicit acceptance archives XML');
    check($storage->files === ['26 200.xml' => $bundle['xml']], 'Only exact accepted XML remains');
    $drafts->create('window', '26 200');
    $storage->files['tmp.xml'] = $bundle['xml'];
    $token = $proof->remember($bundle['xml'], 'window', $validator->versions());
    rejects(fn () => $review->finish('window', $hash, true, $token), 'überschrieben');
    check($storage->files['26 200.xml'] === $bundle['xml'], 'Accepted XML never overwritten');
    $review->finish('window', $hash, false);
    $drafts->create('window', '26 201');
    $nextXml = str_replace('26 200', '26 201', $bundle['xml']);
    $storage->files['tmp.xml'] = $nextXml;
    $token = $proof->remember($nextXml, 'window', $validator->versions());
    $review->finish('window', hash('sha256', $nextXml), true, $token, true);
    $proof->requireProof($nextXml, $token, 'window', 'pdf', '26 201.xml');
    rejects(fn () => $proof->requireProof($nextXml . ' ', $token, 'window', 'pdf', '26 201.xml'));
    $drafts->create('window', '26 202');
    rejects(fn () => $proof->requireProof($nextXml, $token, 'window', 'pdf', '26 201.xml'));
    $storage->files['draft.xml'] = '<draft/>';
    $beforeForeignCancel = $storage->files;
    rejects(fn () => $drafts->cancel('other-window'));
    check($storage->files === $beforeForeignCancel, 'Other window cannot discard the active draft');
    $drafts->cancel('window');
    check(!array_key_exists('draft.xml', $storage->files)
        && !array_key_exists('draft.json', $storage->files)
        && !array_key_exists('tmp.xml', $storage->files), 'Cancel removes draft XML, JSON and work XML');
    unset($storage->files['26 201.xml']);
    $domService = new \OCA\ShiRE\Service\DomService('shi_re', $logger, $storage, $apps, $templates);
    check($domService->nextInvoiceNumber() === '26 201', 'Number advances from archive after temporary XML deletion');
    $storage->files['26 202.pdf'] = '%PDF archived';
    check($domService->nextInvoiceNumber() === '26 203', 'Archived PDF also reserves its number');
    $storage->files['tmp.xml'] = str_replace('26 200', '26 250', $bundle['xml']);
    check($domService->nextInvoiceNumber() === '26 203', 'Unaccepted work XML does not advance archived number');
    unset($storage->files['26 200.xml'], $storage->files['26 202.pdf']);
    check($domService->nextInvoiceNumber() === '26 001', 'Without archives, template number advances and work XML is ignored');
    $adminRequest = new class implements \OCP\IRequest {
        public function getParam($key) { return $key === 'action' ? 'discard_templates' : null; }
    };
    $admin = new class('shi_re', $adminRequest, $templates, $fakeDependencies, $storage, $proof) extends \OCA\ShiRE\Controller\PdfAdminController {
        public function discard(): \OCP\AppFramework\Http\JSONResponse {
            return new \OCP\AppFramework\Http\JSONResponse(['success' => true, 'discardCalled' => true]);
        }
    };
    check($admin->saveSettings()->data['discardCalled'] === true, 'Established settings route dispatches explicit discard action');
    foreach (['upload', 'status', 'saveSettings', 'discard'] as $method) {
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
        if (str_starts_with($arg, '--verapdf=')) $config->system['shi_re_verapdf'] = substr($arg, 10);
    }
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--mustang=')) continue;
        $config->system['shi_re_mustang_jar'] = substr($arg, 10);
        check($dependencies->status()['enabled'], implode(' ', $dependencies->status()['errors']));
        $realPdf = new PdfInvoiceService($dependencies, $templates, $renderer, $runner, $workspace, $config);
        $result = $realPdf->generate($bundle['xml'], $saved['version'], $validator->versions());
        check(str_starts_with($result['content'], '%PDF-'), 'Real Mustang PDF, extraction and validation');
        $dir = $workspace->create();
        try {
            file_put_contents($dir . '/final.pdf', $result['content']);
            $info = $runner->run(['/usr/bin/pdfinfo', $dir . '/final.pdf'], $dir);
            check(preg_match('/^Title:\s+Rechnung$/m', $info) === 1, 'PDF Title');
            $appInfo = OdtInvoiceRenderer::xml(file_get_contents(__DIR__ . '/../appinfo/info.xml'));
            $creator = 'ShiRE ' . (new DOMXPath($appInfo))->evaluate('string(/info/version)');
            check(str_contains($info, $creator), 'PDF Creator uses shipped app version');
            $mustangVersion = $dependencies->status()['versions']['mustang'];
            check(str_contains($info, 'MustangCLI ' . $mustangVersion), 'PDF Producer');
            $xmp = OdtInvoiceRenderer::xml($runner->run(['/usr/bin/pdfinfo', '-meta', $dir . '/final.pdf'], $dir));
            $xp = new DOMXPath($xmp);
            foreach (['dc' => 'http://purl.org/dc/elements/1.1/', 'xmp' => 'http://ns.adobe.com/xap/1.0/',
                'pdf' => 'http://ns.adobe.com/pdf/1.3/', 'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
                'shi_re' => 'https://github.com/322ud/shi_re/ns/metadata/1.0/',
                'inv_validator' => 'https://github.com/322ud/inv_validator/ns/metadata/1.0/',
                'schema' => 'http://www.aiim.org/pdfa/ns/schema#',
                'property' => 'http://www.aiim.org/pdfa/ns/property#'] as $prefix => $uri) $xp->registerNamespace($prefix, $uri);
            check($xp->evaluate('string(//dc:title/rdf:Alt/rdf:li[@xml:lang="x-default"])') === 'Rechnung', 'XMP title');
            check($xp->evaluate('string(//xmp:CreatorTool)') === $creator, 'XMP Creator matches Info');
            check($xp->evaluate('string(//pdf:Producer)') === 'MustangCLI ' . $mustangVersion, 'XMP Producer matches Info');
            check($xp->evaluate('string(//shi_re:INValidatorVersion)') === '0.5.1', 'INValidator version embedded');
            check($xp->evaluate('string(//inv_validator:kositValidatorVersion)') === '1.6.0', 'KoSIT version embedded');
            check($xp->evaluate('string(//inv_validator:xrechnungSzenarioVersion)') === '2026-01-31', 'Scenario release embedded');
            check($xp->evaluate('count(//schema:namespaceURI[text()="https://github.com/322ud/shi_re/ns/metadata/1.0/"])') === 1.0, 'ShiRE extension declared once');
            check($xp->evaluate('count(//schema:namespaceURI[text()="https://github.com/322ud/inv_validator/ns/metadata/1.0/"])') === 1.0, 'Validator extension declared once');
            check($xp->evaluate('count(//property:name[text()="DocumentFileName"])') === 1.0, 'Factur-X extension retained');
            $hasVeraPdf = isset($config->system['shi_re_verapdf']);
            check(($xp->evaluate('count(//shi_re:veraPDFVersion)') === 1.0) === $hasVeraPdf, 'veraPDF version only when used');
            // Rewriting must replace old values/schema declarations, including attribute-form XMP from LibreOffice.
            $title = 'Rechnung Ä & <2026>';
            file_put_contents($dir . '/metadata.xml', $metadata->properties($title, $dependencies->status()['versions'], $validator->versions(), false, 'mm@322ud.de'));
            $metadataCommand = ['/usr/bin/java', '-cp', $metadata->classPath($config->system['shi_re_mustang_jar']), 'ShiRePdfMetadata',
                $dir . '/final.pdf', $dir . '/rewritten.pdf', $dir . '/metadata.xml'];
            $runner->run($metadataCommand, $dir);
            $rewrittenXmp = OdtInvoiceRenderer::xml($runner->run(['/usr/bin/pdfinfo', '-meta', $dir . '/rewritten.pdf'], $dir));
            check($rewrittenXmp->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'creator')->length === 1, 'One XMP author after rewrite');
            check($rewrittenXmp->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'creator')->item(0)->textContent === 'mm@322ud.de', 'XMP author updated');
            check(preg_match('/^Author:\s+mm@322ud.de$/m', $runner->run(['/usr/bin/pdfinfo', $dir . '/rewritten.pdf'], $dir)) === 1, 'PDF Info author updated');
            check($rewrittenXmp->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'title')->length === 1, 'One title after rewrite');
            check($rewrittenXmp->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'title')->item(0)->textContent === $title, 'Unicode and XML characters survive PDF metadata round trip');
            check($rewrittenXmp->getElementsByTagNameNS('http://www.aiim.org/pdfa/ns/schema#', 'namespaceURI')->length
                === $xmp->getElementsByTagNameNS('http://www.aiim.org/pdfa/ns/schema#', 'namespaceURI')->length, 'Rewriting does not duplicate schemas');
            check($rewrittenXmp->getElementsByTagNameNS('https://github.com/322ud/shi_re/ns/metadata/1.0/', 'veraPDFVersion')->length === 0, 'Stale optional version removed');
            file_put_contents($dir . '/metadata.xml', $metadata->properties($title, $dependencies->status()['versions'], [], false));
            $runner->run($metadataCommand, $dir);
            $inactiveXmp = OdtInvoiceRenderer::xml($runner->run(['/usr/bin/pdfinfo', '-meta', $dir . '/rewritten.pdf'], $dir));
            $inactiveXp = new DOMXPath($inactiveXmp);
            check($inactiveXp->evaluate('count(//*[namespace-uri()="https://github.com/322ud/inv_validator/ns/metadata/1.0/"])') === 0.0,
                'Inactive validator properties removed from PDF');
            check($inactiveXp->evaluate('count(//*[local-name()="namespaceURI" and text()="https://github.com/322ud/inv_validator/ns/metadata/1.0/"])') === 0.0,
                'Inactive validator schema removed from PDF');
            check($inactiveXp->evaluate('count(//*[local-name()="INValidatorVersion" or local-name()="veraPDFVersion"])') === 0.0,
                'Inactive optional tool versions removed from PDF');
            file_put_contents($dir . '/metadata.xml', '<!DOCTYPE metadata [<!ENTITY test SYSTEM "file:///etc/passwd">]><metadata>&test;</metadata>');
            rejects(fn () => $runner->run($metadataCommand, $dir), 'DOCTYPE');
        } finally { $workspace->remove($dir); }
        echo "Real Mustang combine/extract/validate passed.\n";
    }
    foreach ($temp->paths as $path) check(!file_exists($path), 'Temporary directory removed: ' . $path);
    echo "PDF module tests passed: real ODT, mapping, formatting, uploads, dependency gate, hash validation, process failures and cleanup.\n";
}
