<?php

namespace OCA\MyXRechnung\Service;

use OCA\MyXRechnung\Config\AppFiles;
use OCP\Files\IAppData;

final class DraftService {

    private const TIMEOUT_SECONDS = 300; // 5 Minuten

    public function __construct(
        private IAppData $appData,
    ) {
    }

    /**
     * Legt einen neuen Rechnungsentwurf an und reserviert die berechnete Nummer.
     *
     * Der Lock schützt nur den kurzen kritischen Bereich. Der dauerhafte Zustand
     * steht in draft.json: windowId + lastSeenAt.
     */
    public function create(string $windowId): array {
        $this->assertWindowId($windowId);

        return $this->withLock(function () use ($windowId) {
            $existing = $this->readUnlocked();

            if ($existing !== null && !$this->isExpired($existing)) {
                throw new \RuntimeException(
                    'Es existiert bereits ein aktiver Rechnungsentwurf in einer anderen App-Instanz.'
                );
            }

            if ($existing !== null && $this->isExpired($existing)) {
                $this->deleteUnlocked();
            }

            $now = time();
            $draft = [
                'windowId' => $windowId,
                'createdAt' => $now,
                'lastSeenAt' => $now,
            ];

            $this->writeUnlocked($draft);
            return $draft;
        });
    }

    /**
     * Heartbeat aus main.js. Ein offenes Browserfenster hält den Draft aktiv.
     */
    public function ping(string $windowId): array {
        $this->assertWindowId($windowId);

        return $this->withLock(function () use ($windowId) {
            $draft = $this->requireActiveUnlocked($windowId);
            $draft['lastSeenAt'] = time();
            $this->writeUnlocked($draft);
            return $draft;
        });
    }

    /**
     * Prüft vor jedem fachlichen Schritt, ob dieser Browser-Tab noch Inhaber des
     * Drafts ist. Andernfalls wird abgebrochen, um doppelte Rechnungsnummern zu
     * verhindern.
     */
    public function requireActive(string $windowId): array {
        $this->assertWindowId($windowId);

        return $this->withLock(function () use ($windowId) {
            return $this->requireActiveUnlocked($windowId);
        });
    }

    /**
     * Nach erfolgreichem Schreiben der XML-Rechnung wird der Draft freigegeben
     */
    public function complete(string $windowId): void {
        $this->assertWindowId($windowId);

        $this->withLock(function () use ($windowId) {
            $this->requireActiveUnlocked($windowId);
            $this->deleteUnlocked();
            return null;
        });
    }

    /**
     * Bewusstes Verwerfen durch dieselbe Fensterinstanz.
     */
    public function cancel(string $windowId): void {
        $this->assertWindowId($windowId);

        $this->withLock(function () use ($windowId) {
            $this->requireActiveUnlocked($windowId);
            $this->deleteUnlocked();
            return null;
        });
    }

    public function get(): ?array {
        return $this->withLock(function () {
            $draft = $this->readUnlocked();

            if ($draft !== null && $this->isExpired($draft)) {
                $this->deleteUnlocked();
                return null;
            }

            return $draft;
        });
    }

    private function requireActiveUnlocked(string $windowId): array {
        $draft = $this->readUnlocked();

        if ($draft === null) {
            throw new \RuntimeException(
                'Dieser Rechnungsentwurf ist nicht mehr aktiv. Eine andere App-Instanz hat die Rechnungsnummer möglicherweise übernommen.'
            );
        }

        if ($this->isExpired($draft)) {
            $this->deleteUnlocked();
            throw new \RuntimeException(
                'Dieser Rechnungsentwurf ist abgelaufen. Die Rechnungsnummer wurde wieder freigegeben.'
            );
        }

        if (($draft['windowId'] ?? '') !== $windowId) {
            throw new \RuntimeException(
                'Dieser Rechnungsentwurf gehört zu einer anderen App-Instanz. Vorgang abgebrochen.'
            );
        }

        return $draft;
    }

    private function isExpired(array $draft): bool {
        $lastSeenAt = (int)($draft['lastSeenAt'] ?? $draft['createdAt'] ?? 0);
        return $lastSeenAt + self::TIMEOUT_SECONDS < time();
    }

    private function assertWindowId(string $windowId): void {
        if ($windowId === '' || strlen($windowId) > 128) {
            throw new \InvalidArgumentException('windowId fehlt oder ist ungültig.');
        }
    }

    private function readUnlocked(): ?array {
        $folder = $this->appData->getFolder('/');

        try {
            if (!$folder->fileExists(AppFiles::DraftFile)) {
                return null;
            }
            $file = $folder->getFile(AppFiles::DraftFile);
            $json = $file->getContent();
        } catch (\Throwable $e) {
            return null;
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            $this->deleteUnlocked();
            return null;
        }

        return $data;
    }

    private function writeUnlocked(array $draft): void {
        $folder = $this->appData->getFolder('/');
        $json = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Draft konnte nicht als JSON serialisiert werden.');
        }

        try {
            if ($folder->fileExists(AppFiles::DraftFile)) {
                $folder->getFile(AppFiles::DraftFile)->putContent($json);
                return;
            }
        } catch (\Throwable $e) {
            // stale AppData-Referenz ignorieren und Datei neu anlegen
        }

        $folder->newFile(AppFiles::DraftFile, $json);
    }

    private function deleteUnlocked(): void {
        $folder = $this->appData->getFolder('/');

        try {
            if ($folder->fileExists(AppFiles::DraftFile)) {
                $folder->getFile(AppFiles::DraftFile)->delete();
            }
        } catch (\Throwable $e) {
            // Datei ist bereits physisch weg oder AppData-Referenz ist stale
        }
    }

    /**
     * flock() schützt den kurzen kritischen Bereich auf Dateisystemebene.
     * Die Lock-Datei selbst darf existieren bleiben; die Sperre hängt am offenen
     * File-Handle und wird beim Schließen automatisch freigegeben.
     */
    private function withLock(callable $callback): mixed {
        $lockPath = sys_get_temp_dir() . '/my_xrechnung_' . AppFiles::DraftLockFile;
        $handle = fopen($lockPath, 'c');

        if ($handle === false) {
            throw new \RuntimeException('Kann Draft-Lock-Datei nicht öffnen.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Kann Draft-Sperre nicht setzen.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
