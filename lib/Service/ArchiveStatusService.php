<?php
declare(strict_types=1);

namespace OCA\ShiRE\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;

class ArchiveStatusService {
    public function __construct(private IAppData $appData) {}

    public function summary(): string {
        $highest = null;
        try {
            foreach ($this->appData->getFolder('/')->getDirectoryListing() as $file) {
                if (preg_match('/^(\d{2} \d{3})\.(?:xml|pdf)$/D', $file->getName(), $match)
                    && ($highest === null || strcmp($match[1], $highest) > 0)) {
                    $highest = $match[1];
                }
            }
        } catch (NotFoundException) {
            return 'leer';
        } catch (\Throwable) {
            return 'nicht ermittelbar';
        }
        return $highest === null ? 'leer' : 'bis ' . $highest;
    }
}
