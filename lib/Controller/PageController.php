<?php

namespace OCA\MyXRechnung\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
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
    #[NoCSRFRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'GET', url: '/')]
    public function index(): TemplateResponse {
        // Gemeinsames Layout der App laden.
        Util::addStyle('my_xrechnung', 'style');
        Util::addStyle('my_xrechnung', 'validation-actions');

        // Reihenfolge ist wichtig:
        // 1. state stellt window.MyXRechnung und den gemeinsamen Zustand bereit.
        // 2. dom enthält DOM-Hilfsfunktionen.
        // 3. api nutzt den Zustand und kapselt Serveraufrufe/Heartbeat.
        // 4. calc berechnet Netto/USt/Summe.
        // 5. views bauen die Oberflächen.
        // 6. main startet die App.
        Util::addScript('my_xrechnung', 'state');
        Util::addScript('my_xrechnung', 'dom');
        Util::addScript('my_xrechnung', 'validation-actions');
        Util::addScript('my_xrechnung', 'api');
        Util::addScript('my_xrechnung', 'calc');
        Util::addScript('my_xrechnung', 'draft');
        Util::addScript('my_xrechnung', 'views/form');
        Util::addScript('my_xrechnung', 'views/create-xml-invoice');
        Util::addScript('my_xrechnung', 'main');

        return new TemplateResponse('my_xrechnung', 'index');
    }
}
