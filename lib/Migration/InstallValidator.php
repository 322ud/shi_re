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
        return 'KoSIT-Validator-App bereitstellen';
    }

    public function run(IOutput $output): void {
        try {
            if ($this->dependency->settings()['installed'] === null) {
                $this->dependency->ensureInstalled();
                $output->info('inv_validator wurde installiert.');
            }
        } catch (\Throwable $e) {
            $output->warning($e->getMessage() . ' Die Einrichtung kann beim nächsten App-Start durch einen Administrator wiederholt werden.');
        }
    }
}
