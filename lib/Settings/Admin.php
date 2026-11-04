<?php

declare(strict_types=1);

namespace OCA\MyXRechnung\Settings;

use OCA\MyXRechnung\Service\KositDependencyService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;
use OCP\IConfig;

class Admin implements ISettings {
    public function __construct(private KositDependencyService $dependency, private IConfig $config) {
    }

    public function getForm(): TemplateResponse {
        Util::addScript('my_xrechnung', 'admin');
        return new TemplateResponse('my_xrechnung', 'admin', [
            'status' => $this->dependency->settings(),
            'allowDraftDeletion' => $this->config->getAppValue('my_xrechnung', 'allow_draft_deletion', 'no') === 'yes',
        ]);
    }

    public function getSection(): string {
        return 'my_xrechnung';
    }

    public function getPriority(): int {
        return 50;
    }
}
