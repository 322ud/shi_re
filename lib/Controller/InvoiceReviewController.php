<?php
declare(strict_types=1);
namespace OCA\MyXRechnung\Controller;

use OCA\MyXRechnung\Service\InvoiceReviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class InvoiceReviewController extends Controller {
    public function __construct(string $appName, IRequest $request, private InvoiceReviewService $review) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/en16931/review')]
    public function finish(): JSONResponse {
        try {
            $accept = $this->request->getParam('accept');
            $hash = $this->request->getParam('xmlHash');
            $windowId = $this->request->getParam('windowId');
            if (!is_bool($accept) || !is_string($hash) || !is_string($windowId)) {
                throw new \InvalidArgumentException('Ungültige Entscheidung.');
            }
            $filename = $this->review->finish($windowId, $hash, $accept);
            return new JSONResponse(['success' => true, 'filename' => $filename]);
        } catch (\Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()],
                $e instanceof \InvalidArgumentException ? 400 : (in_array($e->getCode(), [409, 422], true) ? $e->getCode() : 500));
        }
    }
}
