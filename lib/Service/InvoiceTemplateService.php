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
    private const FILES = ['xml' => 'XRechnung.xml', 'odt' => 'XRechnung.odt', 'mapping' => 'XRechnung.tsv',
        'storno_xml' => 'Storno.xml', 'storno_odt' => 'Storno.odt', 'storno_mapping' => 'Storno.tsv'];

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
                $this->checkBundle($bundle);
            } catch (\Throwable $e) {
                $this->disable($e->getMessage());
                return $this->current();
            }
        }
        $bundle['version'] = $version ?: hash('sha256', implode('', array_intersect_key($bundle, self::FILES)));
        return $this->snapshot = $bundle;
    }

    /** Read stored contents even when the set is inactive, for later corrections. */
    private function loadStored(string $version): array {
        if (!preg_match('/^[a-f0-9]{64}$/D', $version)) throw new \RuntimeException('Ungültige Vorlagenversion.');
        $json = $this->appData->getFolder('pdf-templates')->getFile($version . '.json')->getContent();
        if (!hash_equals($version, hash('sha256', $json))) throw new \RuntimeException('Vorlagensatz ist beschädigt.');
        $bundle = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        foreach (self::FILES as $key => $name) {
            // Existing saved sets predate Storno uploads: complete them from defaults.
            if (!array_key_exists($key, $bundle) && str_starts_with($key, 'storno_')) {
                $bundle[$key] = @file_get_contents($this->apps->getAppPath(Application::APP_ID) . '/config/' . $name);
            } elseif (str_ends_with($key, 'odt')) {
                $bundle[$key] = base64_decode($bundle[$key] ?? '', true);
            }
        }
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
                $content = str_ends_with($key, 'odt') ? base64_decode($bundle[$key] ?? '', true) : ($bundle[$key] ?? null);
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
            $stored = [];
            foreach (self::FILES as $key => $_) {
                $stored[$key] = str_ends_with($key, 'odt') ? base64_encode($bundle[$key]) : $bundle[$key];
            }
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
            $this->checkBundle($bundle);
            $this->config->setAppValue(Application::APP_ID, 'pdf_template_checked_version', $version);
            $this->config->setAppValue(Application::APP_ID, 'pdf_template_error', '');
            $this->snapshot = null;
            return $this->current();
        } catch (\Throwable $e) {
            $this->disable($e->getMessage());
            throw $e;
        }
    }

    private function checkBundle(array $bundle): void {
        foreach (['' => 'XRechnung', 'storno_' => 'Storno'] as $prefix => $label) {
            if ($prefix !== '' && !array_filter(['storno_odt', 'storno_xml', 'storno_mapping'],
                fn ($key) => isset($bundle['files'][$key]) && ($bundle['files'][$key]['source'] ?? '') !== 'default')) continue;
            try {
                $this->consistency->check(['odt' => $bundle[$prefix . 'odt'],
                    'xml' => $bundle[$prefix . 'xml'], 'mapping' => $bundle[$prefix . 'mapping']], templateCheck: $prefix !== '');
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException($label . ': ' . $e->getMessage(), 0, $e);
            }
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
