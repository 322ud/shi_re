<?php

declare(strict_types=1);

namespace OCA\MyXRechnung\Service;

use OCP\App\IAppManager;
use OCP\AppFramework\App;

/** Adapter: Ausführung und Konfiguration gehören ausschließlich zu kosit_validator. */
class KositValidatorService {
    public function __construct(private IAppManager $appManager) {
    }

    public function validateXml(string $xml): array {
        if (!$this->appManager->isEnabledForUser('kosit_validator')) {
            throw new \RuntimeException('Die App kosit_validator ist nicht verfügbar. Bitte die Administration zur Einrichtung öffnen.');
        }
        $this->appManager->loadApp('kosit_validator');
        // Eigener App-Container: insbesondere IAppConfig muss kosit_validator gehören.
        $app = new App('kosit_validator');
        $validator = $app->getContainer()->get(\OCA\KoSITValidator\Service\KoSITValidatorService::class);
        $result = $validator->validateXml($xml);

        return [
            'valid' => $result->isValid(),
            'html' => $result->getReportHtml() ?: '<p>Kein HTML-Prüfbericht vorhanden.</p>',
        ];
    }
}
