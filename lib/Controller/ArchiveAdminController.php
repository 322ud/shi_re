<?php
declare(strict_types=1);

namespace OCA\ShiRE\Controller;

use OCA\ShiRE\Service\ValidationProofService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IAppData;
use OCP\IRequest;

/** Administratorzugriff und CSRF-Schutz bleiben aktiv. */
class ArchiveAdminController extends Controller {
    public function __construct(string $appName, IRequest $request,
        private IAppData $appData, private ValidationProofService $proof,
    ) { parent::__construct($appName, $request); }

    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/admin/archive/clear')]
    public function clear(): JSONResponse {
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
}
