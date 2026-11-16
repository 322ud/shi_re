<?php

declare(strict_types=1);
namespace OCP { interface IConfig {} }
namespace OCP\AppFramework { class Controller { protected $request; } }
namespace OCP\AppFramework\Http {
    class JSONResponse {
        public function __construct(public array $data, public int $status = 200) {}
    }
}
namespace {
    require __DIR__ . '/../lib/Controller/EN16931Controller.php';
    require __DIR__ . '/../lib/Controller/ValidatorAdminController.php';
    $config = new class implements \OCP\IConfig {
        public array $values = [];
        public function getAppValue($app, $key, $default) { return $this->values[$key] ?? $default; }
        public function setAppValue($app, $key, $value): void { $this->values[$key] = $value; }
    };
    $request = new class {
        public array $values = [];
        public function getParam($name) { return $this->values[$name] ?? null; }
    };
    function controller(string $class, object $config, object $request): object {
        $reflection = new ReflectionClass($class);
        $object = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('config')->setValue($object, $config);
        $reflection->getProperty('request')->setValue($object, $request);
        return $object;
    }
    function check(bool $condition): void { if (!$condition) throw new RuntimeException('Setting test failed'); }
    $invoice = controller(\OCA\ShiRE\Controller\EN16931Controller::class, $config, $request);
    $admin = controller(\OCA\ShiRE\Controller\ValidatorAdminController::class, $config, $request);
    check($invoice->deleteActiveDraft()->status === 403); // Default: aus, kein Dateizugriff.
    $request->values = ['allow_draft_deletion' => 'yes'];
    check($admin->saveDraftSettings()->status === 400);
    $request->values = ['allow_draft_deletion' => true];
    check($admin->saveDraftSettings()->status === 200);
    check($invoice->deleteActiveDraft()->status === 400); // Aktiviert, Bestätigung weiterhin erforderlich.
    $request->values = ['allow_draft_deletion' => false, 'confirmation' => 'ja', 'deletionToken' => 'old-token'];
    check($admin->saveDraftSettings()->status === 200);
    check($invoice->deleteActiveDraft()->status === 403); // Auch alter Button/Token wird gesperrt.
    $request->values = ['detailed_progress' => 'yes'];
    check($admin->saveProgressSettings()->status === 400);
    $request->values = ['detailed_progress' => true];
    check($admin->saveProgressSettings()->status === 200);
    check($config->values['detailed_progress'] === 'yes');
    $request->values = ['detailed_progress' => false];
    check($admin->saveProgressSettings()->status === 200);
    check($config->values['detailed_progress'] === 'no');
    echo "Draft deletion default, save and server-side blocking tests passed.\n";
}
