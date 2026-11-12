<?php
declare(strict_types=1);

namespace OCA\ShiRE\Service;

use OCP\IConfig;

class PdfDependencyService {
    public function __construct(
        private IConfig $config,
        private PdfProcessRunner $runner,
        private PdfWorkspace $workspace,
        private InvoiceTemplateService $templates,
        private TemplateConsistencyService $consistency,
    ) {}

    public function tools(): array {
        $tools = [
            'libreoffice' => $this->config->getSystemValueString('shi_re_libreoffice', '/usr/bin/libreoffice'),
            'java' => $this->config->getSystemValueString('shi_re_java', '/usr/bin/java'),
            'mustang' => $this->config->getSystemValueString('shi_re_mustang_jar', ''),
            'verapdf' => $this->config->getSystemValueString('shi_re_verapdf', ''),
        ];
        foreach ($tools as $name => $path) {
            if ($name === 'verapdf' && $path === '') continue;
            if (!str_starts_with($path, '/') || !is_file($path) || !is_readable($path)
                || ($name !== 'mustang' && !is_executable($path))) {
                throw new \RuntimeException("PDF-Werkzeug $name fehlt oder ist nicht zugreifbar. Pfad in config.php prüfen.");
            }
        }
        if (!class_exists(\ZipArchive::class) || !class_exists(\DOMDocument::class) || !function_exists('proc_open')) {
            throw new \RuntimeException('PDF-Modul benötigt PHP ZIP, DOM und proc_open.');
        }
        return $tools;
    }

    public function status(): array {
        $errors = [];
        $versions = [];
        $bundle = null;
        try {
            $bundle = $this->templates->current();
            $this->consistency->check($bundle);
            if ($this->templates->error() !== '') $errors[] = $this->templates->error();
        } catch (\Throwable $e) { $errors[] = $e->getMessage(); }
        $checks = ['templates' => $this->templates->customStatus()];
        if ($this->templates->usesCustomTemplates() && $errors) {
            $checks['templates']['ok'] = false;
            $checks['templates']['detail'] = 'prüfung fehlgeschlagen';
        }
        $tools = [
            'libreoffice' => $this->config->getSystemValueString('shi_re_libreoffice', '/usr/bin/libreoffice'),
            'java' => $this->config->getSystemValueString('shi_re_java', '/usr/bin/java'),
            'mustang' => $this->config->getSystemValueString('shi_re_mustang_jar', ''),
            'verapdf' => $this->config->getSystemValueString('shi_re_verapdf', ''),
        ];
        foreach ($tools as $key => $path) {
            $dir = null;
            try {
                if ($path === '' && $key === 'verapdf') {
                    $checks[$key] = ['ok' => false, 'version' => '', 'detail' => 'Optional; nicht eingerichtet.'];
                    continue;
                }
                if (!str_starts_with($path, '/') || !is_file($path) || !is_readable($path)
                    || ($key !== 'mustang' && !is_executable($path))) {
                    throw new \RuntimeException("PDF-Werkzeug $key fehlt oder ist nicht zugreifbar. Pfad in config.php prüfen.");
                }
                $dir = $this->workspace->create();
                $command = $key === 'mustang' ? [$tools['java'], '-jar', $path, '--help']
                    : [$path, $key === 'java' ? '-version' : '--version'];
                $result = trim($this->runner->run($command, $dir, $key === 'mustang' ? 15 : 10));
                if ($key === 'mustang') {
                    foreach (['combine', 'extract', 'validate', 'no-additional-attachments'] as $feature) {
                        if (!str_contains($result, $feature)) throw new \RuntimeException('MustangCLI unterstützt nicht alle benötigten Parameter: ' . $feature);
                    }
                    $this->runner->run([$tools['java'], '-Djava.awt.headless=true', '-cp',
                        (new PdfMetadataService())->classPath($path), 'ShiRePdfMetadata', '--check'], $dir, 10);
                }
                if ($key === 'mustang') {
                    $versions[$key] = $this->mustangVersion($path);
                } else {
                    preg_match('/\b\d+\.\d+(?:\.[\w.-]+)*/', $result, $match);
                    $versions[$key] = $match[0] ?? mb_substr(strtok($result, "\n") ?: '', 0, 120);
                }
                $checks[$key] = ['ok' => true, 'version' => $versions[$key], 'detail' => $path];
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                $checks[$key] = ['ok' => false, 'version' => '', 'detail' => $e->getMessage()];
            } finally {
                if ($dir !== null) $this->workspace->remove($dir);
            }
        }
        if (!class_exists(\ZipArchive::class) || !class_exists(\DOMDocument::class) || !function_exists('proc_open')) {
            $errors[] = 'PDF-Modul benötigt PHP ZIP, DOM und proc_open.';
        }
        if (!$checks['java']['ok']) {
            $checks['mustang']['ok'] = false;
            $checks['mustang']['detail'] .= ' Java: ' . $checks['java']['detail'];
        }
        return ['enabled' => !$errors, 'errors' => $errors, 'versions' => $versions, 'checks' => $checks,
            'customTemplatesStatus' => $checks['templates']['detail'],
            'templateVersion' => $bundle['version'] ?? null,
            'pdfaVerification' => $tools['verapdf'] !== ''];
    }

    private function mustangVersion(string $path): string {
        // CLI help also contains invoice format versions; these are not release versions.
        if (!class_exists(\ZipArchive::class)) return 'unbekannt';
        $jar = new \ZipArchive();
        if ($jar->open($path) !== true) return 'unbekannt';
        try {
            $properties = $jar->getFromName('META-INF/maven/org.mustangproject/Mustang-CLI/pom.properties');
            if ($properties !== false && preg_match('/^version\s*[=:]\s*(\d+\.\d+(?:\.[\w.-]+)*)\s*$/m', $properties, $match)) {
                return $match[1];
            }
            $manifest = $jar->getFromName('META-INF/MANIFEST.MF');
            if ($manifest !== false) {
                // Unfold continuation lines and inspect only the main manifest section.
                $manifest = preg_replace('/\r?\n /', '', $manifest);
                $main = preg_split('/\r?\n\r?\n/', $manifest, 2)[0];
                if (preg_match('/^Implementation-Version:\s*(\d+\.\d+(?:\.[\w.-]+)*)\s*$/m', $main, $match)) {
                    return $match[1];
                }
            }
            return 'unbekannt';
        } finally {
            $jar->close();
        }
    }
}
