<?php
declare(strict_types=1);

namespace OCP\AppFramework {
    class App {
        public function __construct(public string $appId) {}
    }
}

namespace {
    // Nextcloud ordnet OCA\MyXRechnung\ dem lib/-Verzeichnis der App zu.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'OCA\\MyXRechnung\\';
        if (!str_starts_with($class, $prefix)) return;
        $file = __DIR__ . '/../lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
    });
    $app = new \OCA\MyXRechnung\AppInfo\Application();
    if ($app->appId !== 'my_xrechnung' || $app::APP_ID !== 'my_xrechnung') {
        throw new \RuntimeException('Application must initialize the correct Nextcloud app ID.');
    }
    echo "Application loads through the Nextcloud namespace-to-lib mapping.\n";
}
