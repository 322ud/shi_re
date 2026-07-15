<?php

namespace OCA\MyXRechnung\Controller;

use OCA\MyXRechnung\Service\DomService;
use OCA\MyXRechnung\Service\IncRamID;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class EN16931Controller extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private DomService $domService,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/en16931/update')]
    public function updateEN16931(): JSONResponse {
        try {
            $dom = $this->domService->load();

            $newId = IncRamID::do($dom);

            $this->domService->save();

            return new JSONResponse([
                'success' => true,
                'ramID' => $newId,
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
