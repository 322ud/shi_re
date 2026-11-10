<?php
declare(strict_types=1);
namespace OCA\MyXRechnung\Service;

use OCA\MyXRechnung\Config\AppFiles;
use OCP\Files\IAppData;
use OCP\Lock\ILockingProvider;

/** Explicit acceptance is the only operation that archives an invoice XML. */
class InvoiceReviewService {
    public function __construct(private IAppData $appData, private DraftService $drafts,
        private KositValidatorService $validator, private ILockingProvider $locking) {}

    public function finish(string $windowId, string $hash, bool $accept): ?string {
        $lock = 'my_xrechnung/review';
        $this->locking->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        try {
            $draft = $this->drafts->requireActive($windowId);
            $folder = $this->appData->getFolder('/');
            $file = $folder->getFile(AppFiles::XmlWorkFile);
            $xml = $file->getContent();
            if (!hash_equals(hash('sha256', $xml), $hash)) {
                throw new \RuntimeException('Die XML hat sich geändert. Bitte erneut prüfen.', 409);
            }
            $filename = null;
            if ($accept) {
                if (!$this->validator->validateXml($xml)['valid']) {
                    throw new \RuntimeException('Nur valide XML kann angenommen werden.', 422);
                }
                $dom = OdtInvoiceRenderer::xml($xml);
                $id = IncRamID::get($dom);
                if ($id !== $draft['ramID'] || !preg_match('/^\d{2} \d{3}$/D', $id)) {
                    throw new \RuntimeException('Rechnungsnummer passt nicht zum Entwurf.', 409);
                }
                $filename = $id . '.xml';
                if ($folder->fileExists($filename) || $folder->fileExists($id . '.pdf')) {
                    throw new \RuntimeException('Diese Rechnungsnummer ist bereits archiviert und darf nicht überschrieben werden.', 409);
                }
                $folder->newFile($filename, $xml);
            }
            // Delete before releasing the draft: a new invoice must not lose its work file.
            $file->delete();
            if ($folder->fileExists(AppFiles::DraftXmlFile)) $folder->getFile(AppFiles::DraftXmlFile)->delete();
            $this->drafts->complete($windowId);
            return $filename;
        } finally {
            $this->locking->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }
}
