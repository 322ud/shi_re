<?php

namespace OCA\MyXRechnung\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;	// nur solange kein POST!
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]	// nur solange kein POST!
    #[Route(Route::TYPE_FRONTPAGE, verb: 'GET', url: '/')]
    public function index(): TemplateResponse {
        Util::addStyle('my_xrechnung', 'style');
        Util::addScript('my_xrechnung', 'main');
        return new TemplateResponse('my_xrechnung', 'index');
    }
}
