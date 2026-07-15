<?php

namespace OCA\MyXRechnung\Controller;

use OCA\MyXRechnung\Config\AppFiles;
use OCA\MyXRechnung\Service\DomService;
use OCA\MyXRechnung\Service\DraftService;
use OCA\MyXRechnung\Service\IncRamID;
use OCA\MyXRechnung\Service\StripItems;
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
        private DraftService $draftService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Button "neue Rechnung".
     *
     * Erwartet windowId aus main.js. Die Nummer wird nur reserviert und in
     * draft.json gespeichert. Die EN16931-XML-Arbeitskopie wird initialisiert,
     * bereinigt und für die Datenerfassung vorbereitet.
     * Die EN16931-XML-Datei selbst wird noch nicht gespeichert.
     */
    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/en16931/draft')]
    public function draft(): JSONResponse {
        try {
            $windowId = $this->windowIdFromRequest();

            $dom = $this->domService->load();
            $nextRamID = IncRamID::next($dom);
            $draft = $this->draftService->create($nextRamID, $windowId);

            // Die XML-Vorlage enthält genau eine Musterposition. Bevor die vom Benutzer
            // erfassten Rechnungspositionen eingefügt werden, werden alle eventuell noch
            // vorhandenen Positionen entfernt. Die erste Position bleibt als Vorlage
            // erhalten und kann anschließend mit den aktuellen Rechnungsdaten befüllt
            // oder dupliziert werden.
            StripItems::do($dom);

            return new JSONResponse([
                'success' => true,
                'draft' => $this->publicDraftData($draft),
                'seed' => $this->readSeedData(),
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Heartbeat alle 30 Sekunden aus main.js.
     *
     * Nur dieselbe windowId darf lastSeenAt aktualisieren. Ein anderer Tab kann
     * einen fremden Entwurf nicht am Leben halten.
     */
    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/en16931/ping')]
    public function ping(): JSONResponse {
        try {
            $draft = $this->draftService->ping($this->windowIdFromRequest());

            return new JSONResponse([
                'success' => true,
                'draft' => $this->publicDraftData($draft),
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Button "Rechnung erstellen".
     *
     * Es wird geprüft, ob draft.json existiert, nicht abgelaufen ist
     * und zur aktuellen windowId gehört. Anschließend werden die
     * eingegebenen Rechnungsdaten in den EN16931-DOM geschrieben
     * und als XML-Datei gespeichert.
     */
    #[NoAdminRequired]
    #[Route(
        Route::TYPE_FRONTPAGE,
        verb: 'POST',
        url: '/en16931/createXmlInvoice'
    )]
    public function createXmlInvoice(): JSONResponse {
        try {
            $windowId = $this->windowIdFromRequest();
            $invoiceData = $this->request->getParam('invoiceData', []);

            if (!is_array($invoiceData)) {
                throw new \InvalidArgumentException(
                    'invoiceData muss ein Objekt sein.'
                );
            }

            $draft = $this->draftService->requireActive($windowId);

            $dom = $this->domService->load();

            // TODO: SetHeaderData::do($dom, $invoiceData);
            // TODO: SetBuyerData::do($dom, $invoiceData);
            // TODO: BuildTradeLineItems::do($dom, $invoiceData);
            // TODO: BuildTradeTaxes::do($dom, $invoiceData);
            // TODO: BuildMonetarySummation::do($dom, $invoiceData);

            $this->domService->save();
            $this->draftService->complete($windowId);

            return new JSONResponse([
                'success' => true,
                'draft' => $this->publicDraftData($draft),
                'xmlInvoiceFile' => AppFiles::XmlWorkFile,
                'ramID' => $draft['ramID'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Bewusstes Verwerfen durch dieselbe App-Instanz.
     */
    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/en16931/cancel')]
    public function cancel(): JSONResponse {
        try {
            $this->draftService->cancel($this->windowIdFromRequest());

            return new JSONResponse([
                'success' => true,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    private function windowIdFromRequest(): string {
        return (string)$this->request->getParam('windowId', '');
    }

    private function readSeedData(): array {
        return [
            'seller' => [
                'name' => $this->domService->getValue('//ram:SellerTradeParty/ram:Name') ?? '',
            ],
            'buyer' => [
                'buyerReference' => $this->domService->getValue('//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference') ?? '',
                'name' => $this->domService->getValue('//ram:BuyerTradeParty/ram:Name') ?? '',
                'address' => $this->domService->getValue('//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:LineOne') ?? '',
                'postcode' => $this->domService->getValue('//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode') ?? '',
                'city' => $this->domService->getValue('//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CityName') ?? '',
                'country' => $this->domService->getValue('//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID') ?? 'DE',
            ],
        ];
    }

    private function publicDraftData(array $draft): array {
        return [
            'ramID' => $draft['ramID'] ?? null,
            'windowId' => $draft['windowId'] ?? null,
            'createdAt' => $draft['createdAt'] ?? null,
            'lastSeenAt' => $draft['lastSeenAt'] ?? null,
        ];
    }

    private function errorResponse(\Throwable $e): JSONResponse {
        return new JSONResponse([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}
