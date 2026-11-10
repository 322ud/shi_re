<?php

declare(strict_types=1);

namespace OCA\ShiRE\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
    public function __construct(private IURLGenerator $urls) {
    }
    public function getID(): string { return 'shi_re'; }
    public function getName(): string { return 'ShiRE'; }
    public function getPriority(): int { return 80; }
    public function getIcon(): string { return $this->urls->imagePath('shi_re', 'app.svg'); }
}
