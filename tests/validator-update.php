<?php
declare(strict_types=1);
namespace OCP { interface IUserSession {} interface IConfig {} interface ITempManager {} interface IRequest {} interface IGroupManager {} }
namespace OCP\AppFramework { class Controller { public function __construct($name, protected $request) {} } }
namespace OCP\AppFramework\Http { class JSONResponse { public function __construct(public array $data, public int $status = 200) {} } }
namespace OCP\App { interface IAppManager {} }
namespace OCP\Http\Client { interface IClientService {} }
namespace OCP\Files { interface IAppData {} }
namespace OCP\Lock { interface ILockingProvider { const LOCK_EXCLUSIVE = 2; } }
namespace {
    foreach (['Config/AppFiles', 'Service/KositValidatorService', 'Service/KositDependencyService', 'Service/ValidationProofService', 'Service/DraftService', 'Service/ValidatorUpdateService'] as $file) require __DIR__ . '/../lib/' . $file . '.php';
    function check(bool $ok): void { if (!$ok) throw new \RuntimeException('Assertion failed'); }
    function rejects(callable $fn): void { try { $fn(); } catch (\Throwable $e) { return; } throw new \RuntimeException('Expected failure'); }
    $storage = new class implements \OCP\Files\IAppData {
        public array $files = [];
        public function getFolder($path): self { return $this; }
        public function fileExists($name): bool { return isset($this->files[$name]); }
        public function newFile($name, $content): void { $this->files[$name] = $content; }
        public function getFile($name): object { return new class($this, $name) {
            public function __construct(private object $store, private string $name) {}
            public function getContent(): string { return $this->store->files[$this->name]; }
            public function delete(): void { unset($this->store->files[$this->name]); }
        }; }
    };
    $locking = new class implements \OCP\Lock\ILockingProvider {
        public bool $held = false;
        public $onAcquire = null;
        public function acquireLock($key, $type): void {
            if ($this->held) throw new \RuntimeException('Locked');
            $this->held = true;
            if ($this->onAcquire) ($this->onAcquire)();
        }
        public function releaseLock($key, $type): void { $this->held = false; }
    };
    $users = new class implements \OCP\IUserSession {};
    $validator = (new \ReflectionClass(\OCA\ShiRE\Service\KositValidatorService::class))->newInstanceWithoutConstructor();
    $proof = new \OCA\ShiRE\Service\ValidationProofService($storage, $users, $locking, $validator);
    $drafts = new \OCA\ShiRE\Service\DraftService($storage, $proof);
    $dependency = new class extends \OCA\ShiRE\Service\KositDependencyService {
        public array $values = ['validation_enabled' => true, 'auto_update' => true];
        public int $calls = 0;
        public int $checks = 0;
        public function latest(): array { $this->checks++; return ['release_version' => '1.0.4']; }
        public $install = null;
        public function __construct() {}
        public function settings(): array { return $this->values; }
        public function ensureInstalled(): void { $this->calls++; if ($this->install) ($this->install)(); }
    };
    $updates = new \OCA\ShiRE\Service\ValidatorUpdateService($proof, $drafts, $dependency);
    foreach (['auto_update', 'validation_enabled'] as $key) {
        $dependency->values[$key] = false;
        check($updates->run() === 'disabled');
        check($dependency->calls === 0);
        $dependency->values[$key] = true;
    }
    // A draft appears just as the update acquires the shared lock.
    $locking->onAcquire = function () use ($storage) {
        $storage->files['draft.json'] = json_encode(['windowId' => 'other', 'lastSeenAt' => time()]);
    };
    check($updates->run() === 'deferred');
    check(!$locking->held && $dependency->calls === 0);
    $locking->onAcquire = null;
    unset($storage->files['draft.json']);
    $otherProof = new \OCA\ShiRE\Service\ValidationProofService($storage, $users, $locking, $validator);
    $otherDrafts = new \OCA\ShiRE\Service\DraftService($storage, $otherProof);
    $dependency->install = function () use ($otherDrafts, $locking) {
        check($locking->held);
        rejects(fn () => $otherDrafts->create('other', '123'));
    };
    check($updates->run() === 'updated');
    check(!$locking->held && !isset($storage->files['validator-update.json']));
    $storage->files['draft.json'] = '{broken';
    rejects(fn () => $updates->run());
    unset($storage->files['draft.json']);
    $dependency->install = fn () => throw new \RuntimeException('Download failed');
    rejects(fn () => $updates->run());
    check(!isset($storage->files['validator-update.json']));
    $dependency->install = fn () => throw new \RuntimeException('Migration failed', 503);
    rejects(fn () => $updates->run());
    check(!$locking->held && isset($storage->files['validator-update.json']));
    rejects(fn () => $otherDrafts->create('other', '123'));
    unset($storage->files['validator-update.json']);
    require __DIR__ . '/../lib/Controller/ValidatorAdminController.php';
    $request = new class implements \OCP\IRequest {
        public ?string $action = null;
        public function getParam($key) { return $key === 'action' ? $this->action : null; }
    };
    $config = new class implements \OCP\IConfig { public function getAppValue($app, $key, $default) { return $default; } };
    $session = new class implements \OCP\IUserSession { public function getUser() { return null; } };
    $groups = new class implements \OCP\IGroupManager {};
    $validatorStatus = new class extends \OCA\ShiRE\Service\KositValidatorService {
        public function __construct() {}
        public function isActive(): bool { return false; }
    };
    $controller = new \OCA\ShiRE\Controller\ValidatorAdminController('shi_re', $request, $dependency, $session, $groups, $config, $validatorStatus, $updates);
    $dependency->values['validation_enabled'] = false;
    check($controller->status()->data['success']);
    check($dependency->checks === 0);
    $dependency->values['validation_enabled'] = true;
    $dependency->values['installed'] = '1.0.2';
    check($controller->status()->data['status']['update_available']);
    check($dependency->checks === 1);
    $request->action = 'auto_update';
    $dependency->values['auto_update'] = false;
    check($controller->status()->data['update'] === 'disabled');
    $method = new \ReflectionMethod($controller, 'status');
    check(!$method->getAttributes('OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired'));
    check(!(new \ReflectionMethod($controller, 'install'))->getAttributes('OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired'));
    echo "Update authorization, competing draft, global lock and recovery tests passed.\n";
}
