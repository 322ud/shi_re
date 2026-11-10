<?php
declare(strict_types=1);

namespace OCP\AppFramework {
    class App {
        public function __construct(public string $appId) {}
    }
}

namespace {
    // Nextcloud ordnet OCA\ShiRE\ dem lib/-Verzeichnis der App zu.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'OCA\\ShiRE\\';
        if (!str_starts_with($class, $prefix)) return;
        $file = __DIR__ . '/../lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
    });
    $app = new \OCA\ShiRE\AppInfo\Application();
    if ($app->appId !== 'shi_re' || $app::APP_ID !== 'shi_re') {
        throw new \RuntimeException('Application must initialize the correct Nextcloud app ID.');
    }
    echo "Application loads through the Nextcloud namespace-to-lib mapping.\n";
}
