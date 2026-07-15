<?php

namespace OCA\MyXRechnung\Service;

use DOMDocument;
use DOMXPath;
use OCA\MyXRechnung\Config\AppFiles;
use OCP\Files\IAppData;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

class DomService {

    private ?DOMDocument $dom = null;

    public function __construct(
        private string $appName,
        private LoggerInterface $logger,
        private IAppData $appData,
        private IAppManager $appManager,
    ) {
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

    private function readTemplateXml(): string {
        $appPath = $this->appManager->getAppPath($this->appName);

        if ($appPath === null) {
            throw new \RuntimeException(
                "App-Pfad für {$this->appName} nicht gefunden"
            );
        }

        $filename =
        $appPath .
        '/' . AppFiles::ConfigDir .
        '/' . AppFiles::XmlTemplate;

        if (!is_readable($filename)) {
            throw new \RuntimeException(
                "Kann Template $filename nicht lesen"
            );
        }

        $xml = file_get_contents($filename);

        if ($xml === false || $xml === '') {
            throw new \RuntimeException(
                "Template $filename ist leer oder nicht lesbar"
            );
        }

        return $xml;
    }

    public function setValue(string $xpathExpression, string $value): void {
        $xpath = new DOMXPath($this->load());
        $nodes = $xpath->query($xpathExpression);

        if ($nodes === false || $nodes->length === 0) {
            return;
        }

        $nodes->item(0)->nodeValue = $value;
    }

    public function getValue(string $xpathExpression): ?string {
        $xpath = new DOMXPath($this->load());
        $nodes = $xpath->query($xpathExpression);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return $nodes->item(0)->nodeValue;
    }

    public function getDom(): DOMDocument {
        return $this->load();
    }
}
