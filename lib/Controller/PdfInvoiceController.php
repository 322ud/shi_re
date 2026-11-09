<?php
declare(strict_types=1);

namespace OCA\MyXRechnung\Controller;

use OCA\MyXRechnung\Config\AppFiles;
use OCA\MyXRechnung\Service\InvoiceTemplateService;
use OCA\MyXRechnung\Service\KositValidatorService;
use OCA\MyXRechnung\Service\PdfDependencyService;
use OCA\MyXRechnung\Service\PdfInvoiceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IAppData;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class PdfInvoiceController extends Controller {
    public function __construct(string $appName, IRequest $request,
        private IAppData $appData, private KositValidatorService $validator,
        private PdfDependencyService $dependencies, private InvoiceTemplateService $templates,
        private PdfInvoiceService $pdf, private LoggerInterface $logger,
    ) { parent::__construct($appName, $request); }

    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/pdf/status')]
    public function status(): JSONResponse {
        $status = $this->dependencies->status();
        // Werkzeugpfade/Versionen und administrative Upload-Details nur in der Administration.
        return new JSONResponse(['success' => true, 'status' => [
            'enabled' => $status['enabled'], 'templateVersion' => $status['templateVersion'],
            'message' => $status['enabled'] ? '' : 'PDF-Modul ist deaktiviert. Bitte die Administration prüfen.',
        ]]);
    }

    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/en16931/downloadPdfInvoice')]
    public function download(): DataDownloadResponse|JSONResponse {
        try {
            $xml = $this->appData->getFolder('/')->getFile(AppFiles::XmlWorkFile)->getContent();
            $hash = $this->request->getParam('xmlHash');
            $version = $this->request->getParam('templateVersion');
            if (!is_string($hash) || !hash_equals(hash('sha256', $xml), $hash)
                || !is_string($version) || !hash_equals($this->templates->current()['version'], $version)) {
                return new JSONResponse(['success' => false, 'error' => 'XML oder Vorlage hat sich geändert. Bitte erneut validieren.'], 409);
            }
            // Niemals allein dem vom Browser gelieferten Hash/Validierungsstatus vertrauen.
            if ($this->validator->validateXml($xml)['valid'] !== true) {
                return new JSONResponse(['success' => false, 'error' => 'Die XML-Datei ist nicht valide.'], 422);
            }
            $result = $this->pdf->generate($xml, $version);
            return new DataDownloadResponse($result['content'], $result['filename'], 'application/pdf', 200, [
                'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
                'X-Invoice-Filename' => rawurlencode($result['filename']),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('PDF-Rechnung fehlgeschlagen', ['exception' => $e, 'app' => 'my_xrechnung']);
            $status = in_array($e->getCode(), [409, 422, 503], true) ? $e->getCode() : 500;
            return new JSONResponse(['success' => false, 'error' => in_array($status, [409, 422], true) ? $e->getMessage()
                : 'PDF-Erzeugung fehlgeschlagen. Bitte die Administration bzw. das Serverprotokoll prüfen.'], $status);
        }
    }
}
