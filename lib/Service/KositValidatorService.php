<?php

declare(strict_types=1);

namespace OCA\MyXRechnung\Service;

use OCP\App\IAppManager;
use OCP\AppFramework\App;

/** Adapter: Ausführung und Konfiguration gehören ausschließlich zu inv_validator. */
class KositValidatorService {
    public function __construct(private IAppManager $appManager) {
    }

    public function validateXml(string $xml): array {
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
