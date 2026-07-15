<?php

namespace OCA\MyXRechnung\Controller;

use OCA\MyXRechnung\Config\AppFiles;
use OCA\MyXRechnung\Service\KositValidatorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\JSONResponse;
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
    public function validateXmlInvoice(): JSONResponse {
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
}
