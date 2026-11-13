<?php
declare(strict_types=1);
namespace OCP { interface IRequest {} }
namespace OCP\Files { interface IAppData {} }
namespace OCP\Lock { interface ILockingProvider { public const LOCK_EXCLUSIVE = 2; } }
namespace OCP\AppFramework {
    class Controller { public function __construct($name, protected $request) {} }
}
namespace OCP\AppFramework\Http {
    class JSONResponse { public function __construct(public array $data, public int $status = 200) {} }
}
namespace OCA\ShiRE\Service { class InvoiceTemplateService {} class PdfDependencyService {} }
namespace {
    require __DIR__ . '/proof-fixtures.php';
    require __DIR__ . '/../lib/Controller/PdfAdminController.php';
    $request = new class implements \OCP\IRequest {
        public mixed $confirmation = null;
        public function getParam($key) { return $key === 'action' ? 'clear_archive' : $this->confirmation; }
    };
    $storage = new class implements \OCP\Files\IAppData {
        public array $names = ['26 121.xml', '26 121.pdf', 'draft.xml', 'draft.json', 'en16931.xml', 'legacy.pdf', 'validation-proof.json'];
        public function getFolder($name) { return $this; }
        public function getDirectoryListing() {
            return array_map(fn ($name) => new class($this, $name) {
                public function __construct(private object $storage, private string $name) {}
                public function getName() { return $this->name; }
                public function delete() { $this->storage->names = array_values(array_diff($this->storage->names, [$this->name])); }
            }, $this->names);
        }
    };
    $locking = new class implements \OCP\Lock\ILockingProvider {
        public bool $locked = false;
        public function acquireLock($key, $type) { $this->locked = true; }
        public function releaseLock($key, $type) { $this->locked = false; }
    };
    $proof = new \OCA\ShiRE\Service\ValidationProofService($storage, proofUsers(), $locking);
    $controller = new \OCA\ShiRE\Controller\PdfAdminController('shi_re', $request,
        new \OCA\ShiRE\Service\InvoiceTemplateService(), new \OCA\ShiRE\Service\PdfDependencyService(), $storage, $proof);
    foreach (['saveSettings', 'clearArchive'] as $method) {
        $reflection = new \ReflectionMethod($controller, $method);
        foreach (['NoAdminRequired', 'NoCSRFRequired'] as $attribute) {
            if ($reflection->getAttributes('OCP\\AppFramework\\Http\\Attribute\\' . $attribute)) {
                throw new \RuntimeException('Admin or CSRF protection disabled');
            }
        }
    }
    foreach ([null, 'nein', 'JA', true] as $answer) {
        $request->confirmation = $answer;
        if ($controller->saveSettings()->status !== 400 || count($storage->names) !== 7) throw new \RuntimeException('Confirmation bypass');
    }
    $request->confirmation = 'ja';
    if (!$controller->saveSettings()->data['success'] || $locking->locked
        || $storage->names !== ['draft.xml', 'draft.json', 'en16931.xml', 'validation-proof.json']) {
        throw new \RuntimeException('Archive deletion or preservation failed');
    }
    if (!$controller->saveSettings()->data['success']) throw new \RuntimeException('Empty archive failed');
    echo "Archive deletion passed: confirmation, preservation, repeated deletion, lock release.\n";
}
