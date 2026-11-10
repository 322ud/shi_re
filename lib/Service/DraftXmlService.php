<?php

namespace OCA\ShiRE\Service;

use DOMDocument;
use OCA\ShiRE\Config\AppFiles;
use OCP\Files\IAppData;

final class DraftXmlService {

    public function __construct(
        private IAppData $appData,
    ) {
    }

    public function save(DOMDocument $dom): void {
        $xml = $dom->saveXML();

        if ($xml === false) {
            throw new \RuntimeException('Draft-XML konnte nicht serialisiert werden.');
        }

        $folder = $this->appData->getFolder('/');

        if ($folder->fileExists(AppFiles::DraftXmlFile)) {
            $folder->getFile(AppFiles::DraftXmlFile)->putContent($xml);
        } else {
            $folder->newFile(AppFiles::DraftXmlFile, $xml);
        }
    }

    public function load(): DOMDocument {
        $folder = $this->appData->getFolder('/');

        if (!$folder->fileExists(AppFiles::DraftXmlFile)) {
            throw new \RuntimeException('draft.xml existiert nicht.');
        }

        $xml = $folder->getFile(AppFiles::DraftXmlFile)->getContent();

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;

        if (!$dom->loadXML($xml)) {
            throw new \RuntimeException('draft.xml konnte nicht geladen werden.');
        }

        return $dom;
    }

    public function delete(): void {
        $folder = $this->appData->getFolder('/');

        if ($folder->fileExists(AppFiles::DraftXmlFile)) {
            $folder->getFile(AppFiles::DraftXmlFile)->delete();
        }
    }
}
