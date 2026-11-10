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
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

class PdfInvoiceController extends Controller {
    public function __construct(string $appName, IRequest $request,
        private IAppData $appData, private KositValidatorService $validator,
        private PdfDependencyService $dependencies, private InvoiceTemplateService $templates,
        private PdfInvoiceService $pdf, private LoggerInterface $logger,
        private ILockingProvider $locking,
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
            $filename = $this->request->getParam('filename');
            if (!is_string($filename) || !preg_match('/^\d{2} \d{3}\.xml$/D', $filename)) {
                return new JSONResponse(['success' => false, 'error' => 'Eine angenommene Archiv-XML ist erforderlich.'], 400);
            }
            $xml = $this->appData->getFolder('/')->getFile($filename)->getContent();
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
            // Prüfung und Neuanlage gemeinsam sperren: Auch parallele Requests
            // dürfen eine bereits archivierte Rechnung niemals ersetzen.
            $lock = 'my_xrechnung/pdf/' . hash('sha256', $result['filename']);
            $this->locking->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
            try {
                $folder = $this->appData->getFolder('/');
                $xmlName = substr($result['filename'], 0, -4) . '.xml';
                if ($folder->fileExists($result['filename'])) {
                    throw new \RuntimeException('Zu dieser Rechnungsnummer ist bereits eine Archivdatei gespeichert. '
                        . 'Sie darf nicht überschrieben werden. Bitte „Archiv“ verwenden.', 409);
                }
                if ($xmlName !== $filename) throw new \RuntimeException('Archivname und Rechnungsnummer stimmen nicht überein.', 409);
                $folder->newFile($result['filename'], $result['content']);
            } finally {
                $this->locking->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
            }
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

    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/pdf/invoices')]
    public function invoices(): JSONResponse {
        $files = [];
        foreach ($this->appData->getFolder('/')->getDirectoryListing() as $file) {
            if ($this->isArchiveFile($file->getName())) {
                $files[] = ['filename' => $file->getName(), 'mtime' => $file->getMTime()];
            }
        }
        usort($files, static fn (array $a, array $b): int =>
            ($b['mtime'] <=> $a['mtime']) ?: strnatcasecmp($b['filename'], $a['filename']));
        return new JSONResponse(['success' => true, 'invoices' => $files]);
    }

    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/pdf/saved')]
    public function saved(): DataDownloadResponse|JSONResponse {
        $name = $this->request->getParam('filename');
        if (!is_string($name) || !$this->isArchiveFile($name)
            || preg_match('/[\/\\\\\x00-\x1f]/', $name)) {
            return new JSONResponse(['success' => false, 'error' => 'Ungültiger Archiv-Dateiname.'], 400);
        }
        try {
            $file = $this->appData->getFolder('/')->getFile($name);
            return new DataDownloadResponse($file->getContent(), $name, str_ends_with($name, '.xml') ? 'application/xml' : 'application/pdf', 200, [
                'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\OCP\Files\NotFoundException $e) {
            return new JSONResponse(['success' => false, 'error' => 'Archivdatei nicht gefunden.'], 404);
        }
    }
    private function isArchiveFile(string $name): bool {
        // Arbeits-XML, Entwurf und Vorlagen gehören nicht ins Archiv.
        return str_ends_with($name, '.pdf') || preg_match('/^\d{2} \d{3}\.xml$/D', $name) === 1;
    }

}
