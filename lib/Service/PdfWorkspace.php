<?php
declare(strict_types=1);

namespace OCA\MyXRechnung\Service;

/** Private Arbeitsverzeichnisse; Nextcloud räumt sie auch nach einem Prozessabbruch auf. */
class PdfWorkspace {
    public function __construct(private \OCP\ITempManager $temp) {}

    public function create(): string {
        $path = rtrim($this->temp->getTemporaryFolder('myx-pdf'), '/');
        if (!is_dir($path) || !is_writable($path) || !chmod($path, 0700)) {
            throw new \RuntimeException('PDF-Arbeitsverzeichnis ist nicht beschreibbar.');
        }
        return $path;
    }

    public function remove(string $path): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (new \FilesystemIterator($path) as $entry) {
                $this->remove($entry->getPathname());
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
