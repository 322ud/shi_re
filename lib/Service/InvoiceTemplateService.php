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
        if (!$this->usesCustomTemplates()) $version = '';
        if ($version === '') {
            $base = $this->apps->getAppPath(Application::APP_ID) . '/config/';
            $bundle = [];
            foreach (self::FILES as $key => $name) {
                $content = @file_get_contents($base . $name);
                if ($content === false) throw new \RuntimeException("Vorlage fehlt: $name");
                $bundle[$key] = $content;
            }
        } else {
            if (!preg_match('/^[a-f0-9]{64}$/D', $version)) throw new \RuntimeException('Ungültige Vorlagenversion.');
            $json = $this->appData->getFolder('pdf-templates')->getFile($version . '.json')->getContent();
            if (!hash_equals($version, hash('sha256', $json))) throw new \RuntimeException('Vorlagensatz ist beschädigt.');
            $bundle = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            $bundle['odt'] = base64_decode($bundle['odt'], true);
            foreach (self::FILES as $key => $_) {
                if (!isset($bundle[$key]) || !is_string($bundle[$key])) throw new \RuntimeException('Unvollständiger Vorlagensatz.');
            }
        }
        $bundle['version'] = $version ?: hash('sha256', $bundle['odt'] . $bundle['xml'] . $bundle['mapping']);
        return $this->snapshot = $bundle;
    }

    /** Status of the saved custom set, independent of the currently selected defaults. */
    public function customStatus(): array {
        $version = $this->config->getAppValue(Application::APP_ID, 'pdf_template_version', '');
        $result = ['ok' => false, 'version' => $version === '' ? '' : substr($version, 0, 12), 'detail' => 'nicht vorhanden'];
        if ($this->config->getAppValue(Application::APP_ID, 'pdf_template_error', '') !== '') {
            $result['detail'] = 'prüfung fehlgeschlagen';
            return $result;
        }
        if ($version === '') return $result;
        try {
            if (!preg_match('/^[a-f0-9]{64}$/D', $version)) throw new \RuntimeException('Ungültige Version');
            $json = $this->appData->getFolder('pdf-templates')->getFile($version . '.json')->getContent();
            if (!hash_equals($version, hash('sha256', $json))) throw new \RuntimeException('Beschädigte Vorlagen');
        } catch (\OCP\Files\NotFoundException) {
            return $result;
        } catch (\Throwable) {
            $result['detail'] = 'prüfung fehlgeschlagen';
            return $result;
        }
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

    /** Fehlgeschlagene Uploads deaktivieren PDF, die letzte gültige XML-Vorlage bleibt lesbar. */
    public function upload(array $replacements): array {
        try {
            $bundle = $this->current();
            foreach (self::FILES as $key => $_) {
                if (isset($replacements[$key])) $bundle[$key] = $replacements[$key];
            }
            $this->consistency->check($bundle);
            $json = json_encode(['xml' => $bundle['xml'], 'odt' => base64_encode($bundle['odt']), 'mapping' => $bundle['mapping']], JSON_THROW_ON_ERROR);
            $version = hash('sha256', $json);
            try {
                $folder = $this->appData->getFolder('pdf-templates');
            } catch (\OCP\Files\NotFoundException) {
                $folder = $this->appData->newFolder('pdf-templates');
            }
            if (!$folder->fileExists($version . '.json')) $folder->newFile($version . '.json', $json);
            $this->config->setAppValue(Application::APP_ID, 'pdf_template_version', $version);
            $this->config->setAppValue(Application::APP_ID, 'pdf_template_checked_version', $version);
            $this->config->setAppValue(Application::APP_ID, 'pdf_template_error', '');
            $this->snapshot = null;
            return $this->current();
        } catch (\Throwable $e) {
            $this->disable($e->getMessage());
            throw $e;
        }
    }

    public function disable(string $reason): void {
        $this->config->setAppValue(Application::APP_ID, 'pdf_template_error', mb_substr($reason, 0, 4000));
    }
}
