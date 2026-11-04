<?php

declare(strict_types=1);

namespace OCA\MyXRechnung\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
    public function __construct(private IURLGenerator $urls) {
    }
    public function getID(): string { return 'my_xrechnung'; }
    public function getName(): string { return 'meine X-Rechnung'; }
    public function getPriority(): int { return 80; }
    public function getIcon(): string { return $this->urls->imagePath('my_xrechnung', 'app.svg'); }
}
