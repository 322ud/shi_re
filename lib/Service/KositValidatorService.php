<?php

declare(strict_types=1);

namespace OCA\ShiRE\Service;

use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\AppFramework\App;

/** Adapter: Ausführung und Konfiguration gehören ausschließlich zu inv_validator. */
class KositValidatorService {
    public function __construct(private IAppManager $appManager, private IConfig $config) {
    }

    /** Local installed versions, never latest releases or the invoice's guideline version. */
    public function versions(): array {
        $versions = [
            'INValidatorVersion' => $this->appManager->getAppInstalledVersions()['inv_validator'] ?? 'unbekannt',
            'kositValidatorVersion' => 'unbekannt',
            'xrechnungSzenarioVersion' => 'unbekannt',
        ];
        if (!$this->appManager->isEnabledForUser('inv_validator')) return $versions;
        $this->appManager->loadApp('inv_validator');
        // Older INValidator releases may not yet expose the administration API.
        if (!method_exists(\OCA\INValidator\Service\ValidatorAdminService::class, 'getStatus')) return $versions;
        $app = new App('inv_validator');
        $status = $app->getContainer()->get(\OCA\INValidator\Service\ValidatorAdminService::class)->getStatus(false);
        $versions['kositValidatorVersion'] = $status['validator']['installedVersion'] ?? 'unbekannt';
        // installedVersion is the scenario release date; installedXRechnungVersion is only the standard version.
        $versions['xrechnungSzenarioVersion'] = $status['configuration']['installedVersion'] ?? 'unbekannt';
        return $versions;
    }

    public function validateXml(string $xml): array {
        if ($this->config->getAppValue('shi_re', 'validation_enabled', 'yes') !== 'yes') {
            throw new \RuntimeException('Die XML-Prüfung mit INValidator ist in ShiRE deaktiviert.');
        }
        if (!$this->appManager->isEnabledForUser('inv_validator')) {
            throw new \RuntimeException('Die App inv_validator ist nicht verfügbar. Bitte die Administration zur Einrichtung öffnen.');
        }
        $this->appManager->loadApp('inv_validator');
        // Eigener App-Container: insbesondere IAppConfig muss inv_validator gehören.
        $app = new App('inv_validator');
        $validator = $app->getContainer()->get(\OCA\INValidator\Service\KoSITValidatorService::class);
        $result = $validator->validateXml($xml);

        return [
            'valid' => $result->isValid(),
            'html' => $result->getReportHtml() ?: '<p>Kein HTML-Prüfbericht vorhanden.</p>',
        ];
    }
}
