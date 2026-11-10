<?php

declare(strict_types=1);

namespace OCA\ShiRE\Settings;

use OCA\ShiRE\Service\KositDependencyService;
use OCA\ShiRE\Service\PdfDependencyService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;
use OCP\IConfig;

class Admin implements ISettings {
    public function __construct(private KositDependencyService $dependency, private IConfig $config, private PdfDependencyService $pdf) {
    }

    public function getForm(): TemplateResponse {
        Util::addScript('shi_re', 'admin');
        return new TemplateResponse('shi_re', 'admin', [
            'status' => $this->dependency->settings(),
            'allowDraftDeletion' => $this->config->getAppValue('shi_re', 'allow_draft_deletion', 'no') === 'yes',
            'pdfStatus' => $this->pdf->status(),
        ]);
    }

    public function getSection(): string {
        return 'shi_re';
    }

    public function getPriority(): int {
        return 50;
    }
}
