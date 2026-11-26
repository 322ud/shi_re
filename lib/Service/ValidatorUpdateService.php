<?php
declare(strict_types=1);
namespace OCA\ShiRE\Service;

/** Serializes updates with invoice creation and all protected invoice operations. */
class ValidatorUpdateService {
    public function __construct(private ValidationProofService $proof,
        private DraftService $drafts, private KositDependencyService $dependency) {}

    public function run(bool $automatic = true): string {
        return $this->proof->locked(function () use ($automatic) {
            $this->proof->assertNoUpdate();
            $settings = $this->dependency->settings();
            if ($automatic && (!$settings['validation_enabled'] || !$settings['auto_update'])) {
                return 'disabled';
            }
            // The shared lock already prevents new drafts. Recheck existing activity now.
            if ($this->drafts->hasActiveForUpdate()) return 'deferred';
            $this->proof->markUpdate(true);
            try {
                $this->dependency->ensureInstalled();
            } catch (\Throwable $e) {
                // Interrupted migrations / failed rollback require administrative recovery.
                if ($e->getCode() !== 503) $this->proof->markUpdate(false);
                throw $e;
            }
            $this->proof->markUpdate(false);
            return 'updated';
        });
    }
}
