<?php

namespace OCA\ShiRE\Controller;

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
        IRequest $request,
        private \OCP\IURLGenerator $urls,
        private \OCA\ShiRE\Service\TextEditorLoader $textEditorLoader
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'GET', url: '/')]
    public function index(): TemplateResponse {
        $this->textEditorLoader->load();
        Util::addScript('shi_re', 'readme');
        // Gemeinsames Layout der App laden.
        Util::addStyle('shi_re', 'style');
        Util::addStyle('shi_re', 'readme');
        Util::addStyle('shi_re', 'validation-actions');

        // Reihenfolge ist wichtig:
        // 1. state stellt window.ShiRE und den gemeinsamen Zustand bereit.
        // 2. dom enthält DOM-Hilfsfunktionen.
        // 3. api nutzt den Zustand und kapselt Serveraufrufe/Heartbeat.
        // 4. calc berechnet Netto/USt/Summe.
        // 5. views bauen die Oberflächen.
        // 6. main startet die App.
        Util::addScript('shi_re', 'state');
        Util::addScript('shi_re', 'dom');
        Util::addScript('shi_re', 'validation-actions');
        Util::addScript('shi_re', 'api');
        Util::addScript('shi_re', 'progress');
        Util::addScript('shi_re', 'calc');
        Util::addScript('shi_re', 'draft');
        Util::addScript('shi_re', 'views/form');
        Util::addScript('shi_re', 'views/create-xml-invoice');
        Util::addScript('shi_re', 'main');

        return new TemplateResponse('shi_re', 'index', ['readmeUrl' => $this->urls->linkToRoute('shi_re.page.readme')]);
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'GET', url: '/readme')]
    public function readme(string $document = 'readme'): TemplateResponse {
        $this->textEditorLoader->load();
        Util::addStyle('shi_re', 'readme');
        Util::addScript('shi_re', 'readme');
        return new TemplateResponse('shi_re', 'readme', [
            'readmeUrl' => $this->urls->linkToRoute('shi_re.page.readme'),
            'appUrl' => $this->urls->linkToRoute('shi_re.page.index'),
            'document' => in_array($document, ['admin', 'user', 'developer'], true) ? $document : 'readme',
        ]);
    }
}
