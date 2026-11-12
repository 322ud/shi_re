<?php
declare(strict_types=1);

namespace OCA\ShiRE\Service;

use OCA\ShiRE\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Files\IAppData;
use OCP\IConfig;

/** Unveränderliche Vorlagensätze; erst nach vollständigem Schreiben den Zeiger umschalten. */
class InvoiceTemplateService {
    private ?array $snapshot = null;
    private const FILES = ['xml' => 'en16931.xml', 'odt' => 'X-Rechnung.odt', 'mapping' => 'mapping.tsv'];

    public function __construct(
        private IAppData $appData,
        private IConfig $config,
        private IAppManager $apps,
        private TemplateConsistencyService $consistency,
    ) {}

    public function current(): array {
        if ($this->snapshot !== null) return $this->snapshot;
        $version = $this->config->getAppValue(Application::APP_ID, 'pdf_template_version', '');
        if (!$this->usesCustomTemplates() || $this->error() !== '') $version = '';
        if ($version === '') {
            $base = $this->apps->getAppPath(Application::APP_ID) . '/config/';
            $bundle = [];
            foreach (self::FILES as $key => $name) {
                $content = @file_get_contents($base . $name);
                if ($content === false) throw new \RuntimeException("Vorlage fehlt: $name");
                $bundle[$key] = $content;
            }
        } else {
            try {
                $bundle = $this->loadStored($version);
                $this->consistency->check($bundle);
            } catch (\Throwable $e) {
                $this->disable($e->getMessage());
                return $this->current();
            }
        }
        $bundle['version'] = $version ?: hash('sha256', $bundle['odt'] . $bundle['xml'] . $bundle['mapping']);
        return $this->snapshot = $bundle;
    }

    /** Read stored contents even when the set is inactive, for later corrections. */
    private function loadStored(string $version): array {
        if (!preg_match('/^[a-f0-9]{64}$/D', $version)) throw new \RuntimeException('Ungültige Vorlagenversion.');
        $json = $this->appData->getFolder('pdf-templates')->getFile($version . '.json')->getContent();
        if (!hash_equals($version, hash('sha256', $json))) throw new \RuntimeException('Vorlagensatz ist beschädigt.');
        $bundle = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $bundle['odt'] = base64_decode($bundle['odt'], true);
        foreach (self::FILES as $key => $_) {
            if (!isset($bundle[$key]) || !is_string($bundle[$key])) throw new \RuntimeException('Unvollständiger Vorlagensatz.');
        }
        return $bundle;
    }

    /** Status of the saved custom set, independent of the currently selected defaults. */
    public function customStatus(): array {
        $version = $this->config->getAppValue(Application::APP_ID, 'pdf_template_version', '');
        $result = ['ok' => false, 'version' => $version === '' ? '' : substr($version, 0, 12), 'detail' => 'inaktiv'];
        $failed = $this->config->getAppValue(Application::APP_ID, 'pdf_template_error', '') !== '';
        if ($failed) $result['detail'] = 'inaktiv';
        if ($version === '') return $result;
        try {
            if (!preg_match('/^[a-f0-9]{64}$/D', $version)) throw new \RuntimeException('Ungültige Version');
            $json = $this->appData->getFolder('pdf-templates')->getFile($version . '.json')->getContent();
            if (!hash_equals($version, hash('sha256', $json))) throw new \RuntimeException('Beschädigte Vorlagen');
            $bundle = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            $result['files'] = $bundle['files'] ?? [];
            // Older bundles have no upload metadata. Only identify a default
            // when its contents match; missing metadata alone is not evidence.
            foreach (self::FILES as $key => $name) {
                if (isset($result['files'][$key])) continue;
                $default = @file_get_contents($this->apps->getAppPath(Application::APP_ID) . '/config/' . $name);
                $content = $key === 'odt' ? base64_decode($bundle[$key] ?? '', true) : ($bundle[$key] ?? null);
                if ($default !== false && $content === $default) {
                    $result['files'][$key] = ['name' => $name, 'source' => 'default'];
                }
            }
        } catch (\OCP\Files\NotFoundException) {
            return $result;
        } catch (\Throwable) {
            $result['detail'] = 'inaktiv';
            return $result;
        }
        if ($failed || !$this->usesCustomTemplates()) return $result;
        $result['ok'] = $this->config->getAppValue(Application::APP_ID, 'pdf_template_checked_version', '') === $version;
        $result['detail'] = $result['ok'] ? 'geprüft' : 'nicht geprüft';
        return $result;
    }

    public function usesCustomTemplates(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'custom_templates', 'no') === 'yes';
    }

    public function setCustomTemplates(bool $enabled): void {
        $this->config->setAppValue(Application::APP_ID, 'custom_templates', $enabled ? 'yes' : 'no');
        $this->snapshot = null;
    }

    public function error(): string {
        if (!$this->usesCustomTemplates()) return '';
        return $this->config->getAppValue(Application::APP_ID, 'pdf_template_error', '');
    }

    /** Fehlgeschlagene Uploads schalten XML und PDF auf Standardvorlagen zurück. */
    public function upload(array $replacements, array $filenames = []): array {
        if (!$replacements) throw new \InvalidArgumentException('Bitte mindestens eine neue Vorlage auswählen.');
        try {
            $savedVersion = $this->config->getAppValue(Application::APP_ID, 'pdf_template_version', '');
            $bundle = $savedVersion === '' ? $this->current() : $this->loadStored($savedVersion);
            foreach (self::FILES as $key => $_) {
                if (isset($replacements[$key])) {
                    $bundle[$key] = $replacements[$key];
                    $bundle['files'][$key] = [
                        'name' => basename(str_replace('\\', '/', $filenames[$key] ?? $_)),
                        'date' => gmdate('d.m.Y H:i') . ' UTC',
                    ];
                }
            }
            $stored = ['xml' => $bundle['xml'], 'odt' => base64_encode($bundle['odt']), 'mapping' => $bundle['mapping']];
            if (isset($bundle['files'])) $stored['files'] = $bundle['files'];
            $json = json_encode($stored, JSON_THROW_ON_ERROR);
            $version = hash('sha256', $json);
            try {
                $folder = $this->appData->getFolder('pdf-templates');
            } catch (\OCP\Files\NotFoundException) {
                $folder = $this->appData->newFolder('pdf-templates');
            }
            if (!$folder->fileExists($version . '.json')) $folder->newFile($version . '.json', $json);
            // Persist the candidate before validation, but never expose it as active.
            $this->disable('Vorlagensatz noch nicht erfolgreich geprüft.');
            $this->config->setAppValue(Application::APP_ID, 'pdf_template_checked_version', '');
            $this->config->setAppValue(Application::APP_ID, 'pdf_template_version', $version);
            $this->consistency->check($bundle);
            $this->config->setAppValue(Application::APP_ID, 'pdf_template_checked_version', $version);
            $this->config->setAppValue(Application::APP_ID, 'pdf_template_error', '');
            $this->snapshot = null;
            return $this->current();
        } catch (\Throwable $e) {
            $this->disable($e->getMessage());
            throw $e;
        }
    }

    public function discard(): void {
        $this->config->setAppValue(Application::APP_ID, 'pdf_template_version', '');
        $this->config->setAppValue(Application::APP_ID, 'pdf_template_checked_version', '');
        $this->config->setAppValue(Application::APP_ID, 'pdf_template_error', '');
        $this->setCustomTemplates(false);
    }

    public function disable(string $reason): void {
        $this->snapshot = null;
        $this->config->setAppValue(Application::APP_ID, 'pdf_template_error', mb_substr($reason, 0, 4000));
    }
}
