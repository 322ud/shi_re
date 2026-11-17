<?php

namespace OCA\ShiRE\Service;

use DOMDocument;
use DOMXPath;
use OCA\ShiRE\Config\AppFiles;
use OCP\Files\IAppData;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

class DomService {

    private ?DOMDocument $dom = null;

    /**
     * DomService kapselt den Zugriff auf die EN16931-XML.
     *
     * Wichtig für den Draft-Ablauf: load() lädt die aktuell gespeicherte XML,
     * save() schreibt sie zurück. Zwischen zwei HTTP-Requests bleibt kein PHP-DOM
     * erhalten. Deshalb darf fachlicher Zustand wie ein offener Draft nicht nur in
     * $this->dom liegen, sondern muss separat persistent gespeichert werden.
     */

    public function __construct(
        private string $appName,
        private LoggerInterface $logger,
        private IAppData $appData,
        private IAppManager $appManager,
        private InvoiceTemplateService $templates,
    ) {
    }

    /**
     * Berechnet die nächste Nummer aus der höchsten Nummer der aktiven Vorlage
     * und der archivierten XML-/PDF-Dateinamen in AppData.
     */
    public function nextInvoiceNumber(): string {
        $dom = $this->loadTemplate();
        $latest = IncRamID::get($dom);
        $folder = $this->appData->getFolder('/');
        foreach ($folder->getDirectoryListing() as $file) {
            if (preg_match('/^(\d{2} \d{3})\.(xml|pdf)$/D', $file->getName(), $matches)) {
                $latest = max($latest, $matches[1]);
            }
        }
        IncRamID::set($dom, $latest);
        return IncRamID::next($dom);
    }

    public function cancellation(string $filename, string $number, \DateTimeImmutable $date): DOMDocument {
        if (!preg_match('/^\d{2} \d{3}\.xml$/D', $filename)) {
            throw new \InvalidArgumentException('Ungültiger Archivdateiname.');
        }
        $xml = $this->appData->getFolder('/')->getFile($filename)->getContent();
        $dom = (new CancellationInvoiceService())->build($this->templates->current()['storno_xml'], $xml, $number, $date);
        return $dom;
    }

    public function load(): DOMDocument {
        if ($this->dom !== null) {
            return $this->dom;
        }

        $xml = $this->readOrCreateXml();

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;

        if (!$dom->loadXML($xml)) {
            throw new \RuntimeException('Kann EN16931 XML nicht laden');
        }

        $this->dom = $dom;
        return $this->dom;
    }

    /**
     * Schreibt den aktuell geladenen DOM zurück nach AppData.
     * Im aktuellen Ablauf wird diese Methode erst nach erfolgreichem Aufbau
     * der XML-Rechnung in createXmlInvoice() aufgerufen.
     */
    public function save(): void {
        $xml = $this->load()->saveXML();

        if ($xml === false) {
            throw new \RuntimeException('Kann EN16931 XML nicht serialisieren');
        }

        $folder = $this->appData->getFolder('/');
        $fileName = AppFiles::XmlWorkFile;

        if ($folder->fileExists($fileName)) {
            $folder->getFile($fileName)->putContent($xml);
        } else {
            $folder->newFile($fileName, $xml);
        }
    }

    /**
     * Lädt die EN16931-Template-XML aus dem aktiven Vorlagensatz.
     *
     * Diese Methode liefert immer einen neuen DOMDocument-Zustand auf Basis
     * der Vorlage (Standard aus config/, eigene Vorlagen aus AppData).
     * Die geladene XML enthält noch keine laufende Rechnungsnummer
     * (ramID), keine GUI-Daten und keine vom Benutzer erzeugten Positionen.
     *
     * Verwendet in:
     * - draft()
     *
     * Ablauf:
     * 1. Template-XML lesen
     * 2. DOMDocument erzeugen
     * 3. XML in den DOM laden
     * 4. vorbereiteten DOM zurückgeben
     *
     * Die weitere Initialisierung (IncRamID, StripItems usw.) erfolgt bewusst
     * außerhalb des DomService, damit die fachliche Logik getrennt bleibt.
     */
    public function loadTemplate(): DOMDocument {
        $xml = $this->readTemplateXml();

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;

        if (!$dom->loadXML($xml)) {
            throw new \RuntimeException('Kann EN16931 Template-XML nicht laden.');
        }

        return $dom;
    }

   /**
    * Speichert einen vollständigen DOM-Zustand als XML-Datei im AppData-Bereich.
    *
    * Die Methode kennt keine fachliche Bedeutung der XML. Sie übernimmt lediglich
    * die Persistierung eines bereits aufgebauten DOMDocument.
    *
    * Verwendet für:
    * - draft.xml (vorbereiteter Entwurfszustand)
    * - tmp.xml (fertige Rechnung)
    *
    * Der Aufrufer entscheidet:
    * - welche XML gespeichert wird
    * - unter welchem Dateinamen gespeichert wird
    * - ob es sich um einen Entwurf oder eine finale Rechnung handelt
    *
    * Existiert die Zieldatei bereits, wird sie überschrieben.
    */
    public function saveDom(DOMDocument $dom, string $fileName): void {
        $xml = $dom->saveXML();

        if ($xml === false) {
            throw new \RuntimeException('Kann EN16931 XML nicht serialisieren.');
        }

        $folder = $this->appData->getFolder('/');

        if ($folder->fileExists($fileName)) {
            $folder->getFile($fileName)->putContent($xml);
        } else {
            $folder->newFile($fileName, $xml);
        }
    }

    /**
     * Gibt die vorhandene Arbeits-XML zurück oder initialisiert sie aus dem Template.
     */
    private function readOrCreateXml(): string {
        $folder = $this->appData->getFolder('/');
        $fileName = AppFiles::XmlWorkFile;

        if ($folder->fileExists($fileName)) {
            return $folder->getFile($fileName)->getContent();
        }

        $template = $this->readTemplateXml();
        $folder->newFile($fileName, $template);

        return $template;
    }

    /**
     * Liest das schreibgeschützte XML-Template aus dem App-Verzeichnis.
     */
    private function readTemplateXml(): string {
        return $this->templates->current()['xml'];
    }

    /**
     * Setzt den Wert des ersten Knotens, der zum XPath-Ausdruck passt.
     *
     * Die EN16931-Namespaces werden automatisch registriert, damit Ausdrücke mit
     * rsm:, ram:, qdt: und udt: funktionieren.
     */
    public function setValue(string $xpathExpression, string $value): void {
        $xpath = $this->createXPath();
        $nodes = $xpath->query($xpathExpression);

        if ($nodes === false || $nodes->length === 0) {
            return;
        }

        $nodes->item(0)->nodeValue = $value;
    }

    /**
     * Liest den Wert des ersten Knotens, der zum XPath-Ausdruck passt.
     */
    public function getValue(string $xpathExpression): ?string {
        $xpath = $this->createXPath();
        $nodes = $xpath->query($xpathExpression);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return $nodes->item(0)->nodeValue;
    }

    public function getDom(): DOMDocument {
        return $this->load();
    }

    private function createXPath(): DOMXPath {
        $xpath = new DOMXPath($this->load());

        $xpath->registerNamespace(
            'rsm',
            'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100'
        );
        $xpath->registerNamespace(
            'ram',
            'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100'
        );
        $xpath->registerNamespace(
            'qdt',
            'urn:un:unece:uncefact:data:standard:QualifiedDataType:100'
        );
        $xpath->registerNamespace(
            'udt',
            'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100'
        );

        return $xpath;
    }
}

