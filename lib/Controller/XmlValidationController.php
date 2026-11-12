<?php

namespace OCA\ShiRE\Controller;

use OCA\ShiRE\Config\AppFiles;
use OCA\ShiRE\Service\KositValidatorService;
use OCA\ShiRE\Service\ValidationProofService;
use OCA\ShiRE\Service\DraftService;
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
        private ValidationProofService $proof,
        private DraftService $drafts,
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
            return $this->proof->locked(function () {
                $windowId = (string)$this->request->getParam('windowId');
                $this->drafts->requireActive($windowId);
                $this->proof->clear();
                $folder = $this->appData->getFolder('/');

                if (!$folder->fileExists(AppFiles::XmlWorkFile)) {
                    throw new \RuntimeException(
                        'XML-Datei ' . AppFiles::XmlWorkFile . ' wurde nicht gefunden.'
                    );
                }

                $xml = $folder->getFile(AppFiles::XmlWorkFile)->getContent();
                $result = $this->validatorService->validateXml($xml);

                $token = $result['valid'] === true
                    ? $this->proof->remember($xml, $windowId, $this->validatorService->versions()) : null;
                return new JSONResponse([
                    'validationToken' => $token,
                    'success' => true,
                    'valid' => $result['valid'],
                    'html' => $result['html'],
                    'xmlHash' => hash('sha256', $xml),
                ]);
            });
        } catch (\Throwable $e) {
            return new JSONResponse([
                'success' => false,
                'valid' => false,
                'html' => '<p>Validator-Fehler: ' .
                htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                '</p>',
                'error' => $e->getMessage(),
            ], $e->getCode() === 409 ? 409 : 500);
        }
    }

    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/en16931/downloadXmlInvoice')]
    public function downloadXmlInvoice(): DataDownloadResponse|JSONResponse {
        try {
            return $this->proof->locked(function () {
                $windowId = (string)$this->request->getParam('windowId');
                $this->drafts->requireActive($windowId);
                $xml = $this->appData->getFolder('/')->getFile(AppFiles::XmlWorkFile)->getContent();
                $expectedHash = $this->request->getParam('xmlHash');
                if (!is_string($expectedHash) || !hash_equals(hash('sha256', $xml), $expectedHash)) {
                    return new JSONResponse([
                        'success' => false,
                        'error' => 'Die XML-Datei hat sich geändert. Bitte erneut validieren.',
                    ], 409);
                }
                $this->proof->requireProof($xml, (string)$this->request->getParam('validationToken'), $windowId, 'review');
                return new DataDownloadResponse($xml, AppFiles::XmlWorkFile, 'application/xml; charset=UTF-8', 200, [
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            });
        } catch (\Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()], $e->getCode() === 409 ? 409 : 500);
        }
    }
}
