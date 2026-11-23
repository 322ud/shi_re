<?php
/** Run with: timeout --kill-after=2s 10s php -d memory_limit=64M tests/template-fallback.php */
declare(strict_types=1);

namespace OCP { interface IConfig {} }
namespace OCP\App { interface IAppManager {} }
namespace OCP\Files { interface IAppData {} class NotFoundException extends \RuntimeException {} }
namespace OCA\ShiRE\AppInfo { class Application { public const APP_ID = 'shi_re'; } }
namespace {
    require __DIR__ . '/../lib/Service/TemplateConsistencyService.php';
    require __DIR__ . '/../lib/Service/InvoiceTemplateService.php';

    function check(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
    }
    $config = new class implements \OCP\IConfig {
        public array $values = [
            'custom_templates' => 'yes', 'pdf_enabled' => 'no',
            'pdf_template_check_mode' => 'xml', 'pdf_template_version' => '',
        ];
        public function getAppValue($app, $key, $default) { return $this->values[$key] ?? $default; }
        public function setAppValue($app, $key, $value): void { $this->values[$key] = $value; }
        public function getSystemValueString($key, $default): string { return '/missing/libreoffice'; }
    };
    $config->values['pdf_template_version'] = str_repeat('a', 64);
    $storage = new class implements \OCP\Files\IAppData {
        public int $attempts = 0;
        public function getFolder($name): self { return $this; }
        public function getFile($name): never {
            ++$this->attempts;
            // Reproduce the original trigger: an exception with an empty message.
            throw new \OCP\Files\NotFoundException();
        }
    };
    $apps = new class implements \OCP\App\IAppManager {
        public string $path = __DIR__ . '/..';
        public function getAppPath($id): string { return $this->path; }
    };
    // This test must never reach ODT/XML validation or launch external tools.
    $consistency = (new \ReflectionClass(\OCA\ShiRE\Service\TemplateConsistencyService::class))->newInstanceWithoutConstructor();
    $make = fn () => new \OCA\ShiRE\Service\InvoiceTemplateService($storage, $config, $apps, $consistency);
    $templates = $make();
    $bundle = $templates->current();
    check($storage->attempts === 1, 'Missing custom template must be read only once.');
    check($bundle['xml'] === file_get_contents(__DIR__ . '/../config/XRechnung.xml'), 'Must fall back to bundled XML.');
    check($bundle['odt'] === file_get_contents(__DIR__ . '/../config/XRechnung.odt'), 'Must fall back to bundled ODT.');
    check($templates->error() !== '', 'An empty exception message must still disable the broken template.');
    check($templates->current() === $bundle, 'Repeated call must return the same fallback.');
    check($make()->current() === $bundle && $storage->attempts === 1, 'A fresh service must respect the stored failure.');

    // Mode changes also encounter the same missing file: no recursive retry.
    $config->values['pdf_template_check_mode'] = 'pdf';
    $config->values['pdf_template_error'] = '';
    check($make()->current() === $bundle && $storage->attempts === 2, 'Mode recheck must fall back after one failed read.');

    // If defaults are also missing, terminate with an error instead of retrying.
    $apps->path = '/missing/shire-test-defaults';
    $config->values['pdf_template_error'] = '';
    $thrown = false;
    try { $make()->current(); }
    catch (\RuntimeException $e) { $thrown = str_contains($e->getMessage(), 'Vorlage fehlt:'); }
    check($thrown && $storage->attempts === 3, 'Missing defaults must fail immediately.');
    printf("Template fallback regression passed; PHP peak allocation: %.1f MiB.\n", memory_get_peak_usage(true) / 1048576);
}
