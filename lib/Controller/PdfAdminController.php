<?php
declare(strict_types=1);

namespace OCA\ShiRE\Controller;

use OCA\ShiRE\Service\InvoiceTemplateService;
use OCA\ShiRE\Service\PdfDependencyService;
use OCA\ShiRE\Service\OdtInvoiceRenderer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Files\IAppData;
use OCA\ShiRE\Service\ValidationProofService;

/** Administratorzugriff und CSRF-Schutz bleiben für alle Methoden aktiv. */
class PdfAdminController extends Controller {
    public function __construct(string $appName, IRequest $request,
        private InvoiceTemplateService $templates, private PdfDependencyService $dependencies,
        private IAppData $appData, private ValidationProofService $proof,
    ) { parent::__construct($appName, $request); }

    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/admin/archive/clear')]
    public function clearArchive(): JSONResponse {
        if ($this->request->getParam('confirmation') !== 'ja') {
            return new JSONResponse(['success' => false, 'error' => 'Bestätigung mit "ja" erforderlich.'], 400);
        }
        try {
            return $this->proof->locked(function () {
                foreach ($this->appData->getFolder('/')->getDirectoryListing() as $file) {
                    if (str_ends_with($file->getName(), '.pdf')
                        || preg_match('/^\d{2} \d{3}\.xml$/D', $file->getName()) === 1) {
                        $file->delete();
                    }
                }
                return new JSONResponse(['success' => true]);
            });
        } catch (\Throwable $e) {
            return new JSONResponse(['success' => false,
                'error' => 'Archiv konnte nicht vollständig geleert werden. Bitte erneut versuchen.'], 500);
        }
    }

    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/admin/pdf/status')]
    public function status(): JSONResponse {
        return new JSONResponse(['success' => true, 'status' => $this->dependencies->status()]);
    }

    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/admin/pdf/settings')]
    public function saveSettings(): JSONResponse {
        // Use the established settings route even when Nextcloud still caches
        // the route list from before the discard action was introduced.
        if ($this->request->getParam('action') === 'clear_archive') {
            return $this->clearArchive();
        }
        if ($this->request->getParam('action') === 'discard_templates') {
            return $this->discard();
        }
        $enabled = $this->request->getParam('custom_templates');
        if (!is_bool($enabled)) return new JSONResponse(['success' => false, 'error' => 'Ungültige Vorlageneinstellung.'], 400);
        $this->templates->setCustomTemplates($enabled);
        return $this->status();
    }

    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/admin/pdf/templates/discard')]
    public function discard(): JSONResponse {
        $this->templates->discard();
        return $this->status();
    }

    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/admin/pdf/templates')]
    public function upload(): JSONResponse {
        if (!$this->templates->usesCustomTemplates()) return new JSONResponse(['success' => false, 'error' => 'Eigene Vorlagen sind deaktiviert.'], 400);
        try {
            // Bei überschrittenem post_max_size verwirft PHP den gesamten Multipart-Body.
            if ($this->request->getParam('templatesCheck') !== 'yes') {
                throw new \InvalidArgumentException('Upload-Daten fehlen. Größenlimits des Webservers und von PHP prüfen.');
            }
            $files = [];
            $filenames = [];
            foreach (['odt' => OdtInvoiceRenderer::MAX_ODT, 'xml' => 2097152, 'mapping' => 262144] as $key => $limit) {
                $file = $this->request->getUploadedFile($key);
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])
                    || filesize($file['tmp_name']) > $limit) {
                    throw new \InvalidArgumentException("Upload $key fehlgeschlagen oder zu groß.");
                }
                $content = file_get_contents($file['tmp_name']);
                if ($content === false) throw new \InvalidArgumentException("Upload $key nicht lesbar.");
                $files[$key] = $content;
                $filenames[$key] = (string) ($file['name'] ?? '');
            }
            if (!$files) return new JSONResponse(['success' => false, 'error' => 'Bitte mindestens eine neue Vorlage auswählen.'], 400);
            $this->templates->upload($files, $filenames);
            return $this->status();
        } catch (\Throwable $e) {
            $this->templates->disable($e->getMessage());
            return new JSONResponse(['success' => false, 'error' => $e->getMessage(), 'status' => $this->dependencies->status()], 400);
        }
    }
}
