<?php

namespace OCA\MyXRechnung\Controller;

use OCA\MyXRechnung\Config\AppFiles;
use OCA\MyXRechnung\Service\KositValidatorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\Files\IAppData;
use OCP\IRequest;

class XmlValidationController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IAppData $appData,
        private KositValidatorService $validatorService,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[Route(
    Route::TYPE_FRONTPAGE,
    verb: 'POST',
    url: '/en16931/validateXmlInvoice'
    )]
    public function validateXmlInvoice(): JSONResponse|DataDownloadResponse {
        // Die bestehende Route bleibt auch mit einem älteren Nextcloud-Routencache erreichbar.
        if ($this->request->getParam('download') === true) {
            return $this->downloadXmlInvoice();
        }
        try {
            $folder = $this->appData->getFolder('/');

            if (!$folder->fileExists(AppFiles::XmlWorkFile)) {
                throw new \RuntimeException(
                    'XML-Datei ' . AppFiles::XmlWorkFile . ' wurde nicht gefunden.'
                );
            }

            $xml = $folder->getFile(AppFiles::XmlWorkFile)->getContent();
            $result = $this->validatorService->validateXml($xml);

            return new JSONResponse([
                'success' => true,
                'valid' => $result['valid'],
                'html' => $result['html'],
                'xmlHash' => $result['valid'] === true ? hash('sha256', $xml) : null,
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse([
                'success' => false,
                'valid' => false,
                'html' => '<p>Validator-Fehler: ' .
                htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                '</p>',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/en16931/downloadXmlInvoice')]
    public function downloadXmlInvoice(): DataDownloadResponse|JSONResponse {
        try {
            $xml = $this->appData->getFolder('/')->getFile(AppFiles::XmlWorkFile)->getContent();
            $expectedHash = $this->request->getParam('xmlHash');
            if (!is_string($expectedHash) || !hash_equals(hash('sha256', $xml), $expectedHash)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Die XML-Datei hat sich geändert. Bitte erneut validieren.',
                ], 409);
            }
            // Genau den gelesenen Inhalt prüfen und ausliefern, auch bei parallelen Änderungen.
            $result = $this->validatorService->validateXml($xml);
            if ($result['valid'] !== true) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Die XML-Datei ist nicht valide und kann nicht heruntergeladen werden.',
                ], 422);
            }
            return new DataDownloadResponse($xml, AppFiles::XmlWorkFile, 'application/xml; charset=UTF-8', 200, [
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
