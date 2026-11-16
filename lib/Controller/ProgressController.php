<?php
declare(strict_types=1);
namespace OCA\ShiRE\Controller;

use OCA\ShiRE\Service\ProgressService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ProgressController extends Controller {
    public function __construct(string $appName, IRequest $request, private ProgressService $progress) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/progress/status')]
    public function status(): JSONResponse {
        return new JSONResponse(['success' => true, 'progress' => $this->progress->read(
            (string)$this->request->getParam('progressId'), (string)$this->request->getParam('windowId'))]);
    }
}
