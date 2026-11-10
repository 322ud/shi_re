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
        try {
            $tools = $this->tools();
            $dir = $this->workspace->create();
            try {
                if ($tools['verapdf'] !== '') {
                    $versions['verapdf'] = trim($this->runner->run([$tools['verapdf'], '--version'], $dir, 10));
                }
                $versions['libreoffice'] = trim($this->runner->run([$tools['libreoffice'], '--version'], $dir, 10));
                $versions['java'] = trim($this->runner->run([$tools['java'], '-version'], $dir, 10));
                $help = $this->runner->run([$tools['java'], '-jar', $tools['mustang'], '--help'], $dir, 15);
                foreach (['combine', 'extract', 'validate', 'no-additional-attachments'] as $feature) {
                    if (!str_contains($help, $feature)) throw new \RuntimeException('Mustang-CLI unterstützt nicht alle benötigten Parameter: ' . $feature);
                }
                $versions['mustang'] = mb_substr(trim($help), 0, 120);
            } finally { $this->workspace->remove($dir); }
        } catch (\Throwable $e) { $errors[] = $e->getMessage(); }
        return ['enabled' => !$errors, 'errors' => $errors, 'versions' => $versions,
            'templateVersion' => $bundle['version'] ?? null,
            'pdfaVerification' => ($tools['verapdf'] ?? '') !== ''];
    }
}
