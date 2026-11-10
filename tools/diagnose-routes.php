<?php
declare(strict_types=1);

// Run with the same PHP version and Unix user as Nextcloud. No controller actions are executed.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = $argv[1] ?? '/opt/nextcloud';
if (!is_file($root . '/lib/base.php')) {
    fwrite(STDERR, "Usage: php diagnose-routes.php /path/to/nextcloud\n");
    exit(1);
}
define('OC_CONSOLE', 1);
require_once $root . '/lib/base.php';

function failure(Throwable $error): void {
    do {
        echo get_class($error) . ': ' . $error->getMessage() . "\n";
    } while ($error = $error->getPrevious());
}

try {
    $apps = \OCP\Server::get(\OCP\App\IAppManager::class);
    echo 'PHP: ' . PHP_VERSION . "\nApp path: " . $apps->getAppPath('my_xrechnung') . "\n";
    $apps->loadApp('my_xrechnung');
    $application = new \OCA\MyXRechnung\AppInfo\Application();
    foreach (['EN16931Controller', 'InvoiceReviewController', 'PdfInvoiceController'] as $name) {
        echo "\nController: $name\n";
        try {
            $class = 'OCA\\MyXRechnung\\Controller\\' . $name;
            $reflection = new ReflectionClass($class);
            echo 'File: ' . $reflection->getFileName() . "\n";
            $application->getContainer()->get($class);
            echo "Construction: OK\n";
        } catch (Throwable $error) {
            failure($error);
        }
    }
    echo "\nRegistered POST routes (CLI, freshly loaded):\n";
    $router = \OCP\Server::get(\OCP\Route\IRouter::class);
    $router->loadRoutes('my_xrechnung');
    $context = new \Symfony\Component\Routing\RequestContext();
    $context->setMethod('POST');
    $matcher = new \Symfony\Component\Routing\Matcher\UrlMatcher($router->getRouteCollection(), $context);
    foreach (['/en16931/createXmlInvoice', '/en16931/review', '/pdf/invoices'] as $path) {
        echo "$path: ";
        try {
            $match = $matcher->match('/apps/my_xrechnung' . $path);
            echo json_encode($match['caller'] ?? $match['_route'], JSON_UNESCAPED_SLASHES) . "\n";
        } catch (Throwable $error) {
            failure($error);
        }
    }
} catch (Throwable $error) {
    failure($error);
    exit(1);
}
