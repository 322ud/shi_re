<?php
declare(strict_types=1);
namespace OCA\ShiRE\Service;

use OCP\Files\IAppData;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;

/** Server-owned proof; callers serialize the whole invoice operation with locked(). */
class ValidationProofService {
    public const FILE = 'validation-proof.json';
    private int $depth = 0;

    public function __construct(private IAppData $appData, private IUserSession $users,
        private ILockingProvider $locking, private KositValidatorService $validator) {}

    public function locked(callable $action): mixed {
        if ($this->depth > 0) return $action();
        $this->locking->acquireLock('shi_re/review', ILockingProvider::LOCK_EXCLUSIVE);
        $this->depth++;
        try { return $action(); }
        finally {
            $this->depth--;
            $this->locking->releaseLock('shi_re/review', ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    public function clear(): void {
        $folder = $this->appData->getFolder('/');
        if ($folder->fileExists(self::FILE)) $folder->getFile(self::FILE)->delete();
    }

    public function remember(string $xml, string $windowId, array $versions, bool $validated = true): string {
        if (!$validated && $this->validator->isActive()) {
            throw new \RuntimeException('XML-Prüfung ist aktiv. Bitte erneut prüfen.', 409);
        }
        $token = bin2hex(random_bytes(32));
        $this->write(['hash' => hash('sha256', $xml), 'tokenHash' => hash('sha256', $token),
            'user' => $this->user(), 'windowId' => $windowId, 'stage' => 'review',
            'filename' => null, 'versions' => $validated ? $versions : [], 'validated' => $validated]);
        return $token;
    }

    public function requireProof(string $xml, string $token, string $windowId,
        string $stage, ?string $filename = null): array {
        $folder = $this->appData->getFolder('/');
        $proof = $folder->fileExists(self::FILE)
            ? json_decode($folder->getFile(self::FILE)->getContent(), true) : null;
        if (!is_array($proof) || $token === '' || $windowId === ''
            || ($proof['user'] ?? null) !== $this->user()
            || ($proof['windowId'] ?? null) !== $windowId
            || ($proof['stage'] ?? null) !== $stage
            || ($proof['filename'] ?? null) !== $filename
            || !hash_equals($proof['tokenHash'] ?? '', hash('sha256', $token))
            || !hash_equals($proof['hash'] ?? '', hash('sha256', $xml))) {
            throw new \RuntimeException('Gültiger Validierungsnachweis fehlt oder die XML wurde geändert. Bitte eine neue Rechnung starten.', 409);
        }
        if (($proof['validated'] ?? true) === false && $this->validator->isActive()) {
            throw new \RuntimeException('XML-Prüfung wurde aktiviert. Bitte erneut prüfen.', 409);
        }
        return $proof;
    }

    public function allowPdf(array $proof, string $filename): void {
        $proof['stage'] = 'pdf';
        $proof['filename'] = $filename;
        $this->write($proof);
    }

    private function user(): string {
        $user = $this->users->getUser();
        if ($user === null) throw new \RuntimeException('Anmeldung erforderlich.', 403);
        return $user->getUID();
    }

    private function write(array $proof): void {
        $folder = $this->appData->getFolder('/');
        $json = json_encode($proof, JSON_THROW_ON_ERROR);
        if ($folder->fileExists(self::FILE)) $folder->getFile(self::FILE)->putContent($json);
        else $folder->newFile(self::FILE, $json);
    }
}
