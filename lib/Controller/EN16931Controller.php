<?php

namespace OCA\MyXRechnung\Controller;

use OCA\MyXRechnung\Config\AppFiles;
use OCA\MyXRechnung\Service\DomService;
use OCA\MyXRechnung\Service\DraftService;
use OCA\MyXRechnung\Service\IncRamID;
use OCA\MyXRechnung\Service\InvoiceXmlService;
use OCA\MyXRechnung\Service\StripItems;
use OCA\MyXRechnung\Service\DraftXmlService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;

class EN16931Controller extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private DomService $domService,
        private DraftService $draftService,
        private DraftXmlService $draftXmlService,
        private InvoiceXmlService $invoiceXmlService,
        private IConfig $config,
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

            $dom = $this->domService->loadTemplate();
            $ramID = IncRamID::next($this->domService->load());
            IncRamID::set($dom, $ramID);
            StripItems::do($dom);

            $draft = $this->draftService->create($windowId, $ramID);
            // $draft['ramID'] = $ramID;

            $this->draftXmlService->save($dom);

            return new JSONResponse([
                'success' => true,
                'draft' => $this->publicDraftData($draft),
                'ramID' => $ramID,
                'seed' => $this->readSeedData($dom),
            ]);
        } catch (\Throwable $e) {
            if ($e->getCode() === 409) {
                $activeDraft = $this->draftService->get();
                return new JSONResponse([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'code' => 'active_draft',
                    'deletionToken' => $activeDraft === null
                        || $this->config->getAppValue('my_xrechnung', 'allow_draft_deletion', 'no') !== 'yes'
                        ? null : $this->draftService->deletionToken($activeDraft),
                ], 409);
            }
            return $this->errorResponse($e);
        }
    }

    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/en16931/deleteActiveDraft')]
    public function deleteActiveDraft(): JSONResponse {
        try {
            if ($this->config->getAppValue('my_xrechnung', 'allow_draft_deletion', 'no') !== 'yes') {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Das Löschen aktiver Rechnungsentwürfe ist deaktiviert.',
                ], 403);
            }
            if ($this->request->getParam('confirmation') !== 'ja') {
                return new JSONResponse([
                    'success' => false,
                    'error' => "bestätige mit 'ja'!",
                ], 400);
            }
            $token = $this->request->getParam('deletionToken');
            if (!is_string($token) || $token === '') {
                throw new \InvalidArgumentException('Kennung des Rechnungsentwurfs fehlt.');
            }
            $this->draftService->deleteConfirmed($token, $this->draftXmlService);
            return new JSONResponse(['success' => true]);
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
            $dom = $this->draftXmlService->load();

            $this->invoiceXmlService->apply(
                $dom,
                $invoiceData,
                $draft['ramID'],
                new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')),
            );

            $this->domService->saveDom($dom, AppFiles::XmlWorkFile);

            $this->draftXmlService->delete();
            $this->draftService->complete($windowId);

            return new JSONResponse([
                'success' => true,
                'draft' => $this->publicDraftData($draft),
                'xmlInvoiceFile' => AppFiles::XmlWorkFile,
                'ramID' => IncRamID::get($dom),
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

    private function readSeedData(\DOMDocument $dom): array {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace(
            'ram',
            'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100'
        );

        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');

        return [
            'typeCode' => trim($this->getValue($xpath, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode') ?? ''),
            'seller' => [
                'name' => $this->getValue($xpath, '//ram:SellerTradeParty/ram:Name') ?? '',
                'address' => $this->getValue($xpath, '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineOne') ?? '',
                'address2' => $this->getValue($xpath, '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineTwo') ?? '',
                'address3' => $this->getValue($xpath, '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineThree') ?? '',
                'postcode' => $this->getValue($xpath, '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode') ?? '',
                'city' => $this->getValue($xpath, '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CityName') ?? '',
                'country' => $this->getValue($xpath, '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID') ?? '',
                'iban' => $this->getValue($xpath, '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID') ?? '',
            ],
            'buyer' => [
                'buyerReference' => $this->getValue($xpath, '//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference') ?? '',
                'name' => $this->getValue($xpath, '//ram:BuyerTradeParty/ram:Name') ?? '',
                'address' => $this->getValue($xpath, '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:LineOne') ?? '',
                'postcode' => $this->getValue($xpath, '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode') ?? '',
                'city' => $this->getValue($xpath, '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CityName') ?? '',
                'country' => $this->getValue($xpath, '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID') ?? 'DE',
            ],
            'firstItem' => [
                'name' => $this->getValue(
                    $xpath,
                    '(//ram:IncludedSupplyChainTradeLineItem
                    /ram:SpecifiedTradeProduct
                    /ram:Name)[1]'
                ) ?? '',
                'unitPrice' => $this->getValue(
                    $xpath,
                    '(//ram:IncludedSupplyChainTradeLineItem
                    /ram:SpecifiedLineTradeAgreement
                    /ram:NetPriceProductTradePrice
                    /ram:ChargeAmount)[1]'
                ) ?? '',
                'vatRate' => $this->getValue(
                    $xpath,
                    '(//ram:IncludedSupplyChainTradeLineItem
                    /ram:SpecifiedLineTradeSettlement
                    /ram:ApplicableTradeTax
                    /ram:RateApplicablePercent)[1]'
                ) ?? '',
            ],
        ];
    }

    private function getValue(\DOMXPath $xpath, string $expression): ?string {
        $nodes = $xpath->query($expression);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return $nodes->item(0)?->nodeValue;
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
        ], $e instanceof \InvalidArgumentException ? 400 : 500);
    }
}
