<?php

declare(strict_types=1);

namespace OCA\ShiRE\Migration;

use OCA\ShiRE\Service\KositDependencyService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class InstallValidator implements IRepairStep {
    public function __construct(private KositDependencyService $dependency) {
    }

    public function getName(): string {
        return 'KoSIT-Validator-Abhängigkeit prüfen';
    }

    public function run(IOutput $output): void {
        try {
            if ($this->dependency->settings()['installed'] === null) {
                $output->warning('INValidator bitte separat durch die Nextcloud-Administration installieren und aktivieren.');
            }
        } catch (\Throwable $e) {
            $output->warning($e->getMessage() . ' Bitte die Einrichtung administrativ prüfen.');
        }
    }
}
