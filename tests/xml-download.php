<?php

declare(strict_types=1);
namespace OCP { interface IRequest {} }
namespace OCP\Lock { interface ILockingProvider { public const LOCK_EXCLUSIVE = 2; } }
namespace OCP\Files { interface IAppData {} }
namespace OCP\AppFramework {
    class Controller { public function __construct($name, protected $request) {} }
}
namespace OCP\AppFramework\Http {
    class JSONResponse { public function __construct(public array $data, public int $status = 200) {} }
    class DataDownloadResponse {
        public function __construct(public string $data, public string $filename, public string $type, public int $status, public array $headers) {}
    }
}
namespace OCA\ShiRE\Service {
    class KositValidatorService {
        public bool $active = true;
        public function isActive(): bool { return $this->active; }
        public bool $valid = true;
        public int $calls = 0;
        public function versions(): array { return []; }
        public function validateXml(string $xml): array {
            $this->calls++;
            return ['valid' => $this->valid, 'html' => 'report'];
        }
    }
}
namespace {
    require __DIR__ . '/../lib/Config/AppFiles.php';
    require __DIR__ . '/../lib/Controller/XmlValidationController.php';
    require __DIR__ . '/proof-fixtures.php';
    require __DIR__ . '/../lib/Service/DraftService.php';
    $request = new class implements \OCP\IRequest {
        public array $params = ['windowId' => 'window'];
        public function getParam($key) { return $this->params[$key] ?? null; }
    };
    $storage = new class implements \OCP\Files\IAppData {
        public array $files = ['tmp.xml' => '<invoice/>'];
        public function getFolder($name) { return $this; }
        public function fileExists($name) { return isset($this->files[$name]); }
        public function newFile($name, $content) { $this->files[$name] = $content; }
        public function getFile($name) {
            return new class($this, $name) {
                public function __construct(private object $storage, private string $name) {}
                public function getContent() { return $this->storage->files[$this->name]; }
                public function putContent($content) { $this->storage->files[$this->name] = $content; }
                public function delete() { unset($this->storage->files[$this->name]); }
            };
        }
    };
    $locking = new class implements \OCP\Lock\ILockingProvider {
        private bool $locked = false;
        public function acquireLock($key, $type) { if ($this->locked) throw new \RuntimeException('Already locked'); $this->locked = true; }
        public function releaseLock($key, $type) { $this->locked = false; }
    };
    $validator = new \OCA\ShiRE\Service\KositValidatorService();
    $users = proofUsers();
    $proof = new \OCA\ShiRE\Service\ValidationProofService($storage, $users, $locking, $validator);
    $drafts = new \OCA\ShiRE\Service\DraftService($storage, $proof);
    $drafts->create('window', '26 200');
    $controller = new \OCA\ShiRE\Controller\XmlValidationController('shi_re', $request, $storage, $validator, $proof, $drafts);
    function check(bool $value): void { if (!$value) throw new \RuntimeException('Download test failed'); }
    $validation = $controller->validateXmlInvoice();
    $request->params['xmlHash'] = $validation->data['xmlHash'];
    check($controller->downloadXmlInvoice()->status === 409); // Hash alone is insufficient.
    $request->params['validationToken'] = $validation->data['validationToken'];
    check(!str_contains($storage->files[$proof::FILE], $request->params['validationToken']));
    $users->uid = 'bob';
    check($controller->downloadXmlInvoice()->status === 409);
    $users->uid = 'alice';
    $download = $controller->downloadXmlInvoice();
    check($download instanceof \OCP\AppFramework\Http\DataDownloadResponse);
    check($download->filename === 'tmp.xml' && $download->data === '<invoice/>');
    check($download->headers['Cache-Control'] === 'no-store');
    $request->params['download'] = true;
    check($controller->validateXmlInvoice() instanceof \OCP\AppFramework\Http\DataDownloadResponse);
    check($validator->calls === 1); // Neither download repeats validation.
    $request->params['download'] = false;
    $storage->files['tmp.xml'] = '<changed/>';
    $request->params['xmlHash'] = hash('sha256', '<changed/>');
    check($controller->downloadXmlInvoice()->status === 409); // Even a fresh client hash cannot replace proof.
    $validator->valid = false;
    check($controller->validateXmlInvoice()->data['validationToken'] === null);
    check(!$storage->fileExists($proof::FILE));
    check($controller->downloadXmlInvoice()->status === 409);
    $validator->active = false;
    $calls = $validator->calls;
    $unvalidated = $controller->validateXmlInvoice();
    check($unvalidated->data['skipped'] === true && $unvalidated->data['valid'] === null);
    check(!isset($unvalidated->data['html']) && $validator->calls === $calls);
    $request->params['validationToken'] = $unvalidated->data['validationToken'];
    check($controller->downloadXmlInvoice()->status === 200);
    $record = $proof->requireProof('<changed/>', $request->params['validationToken'], 'window', 'review');
    check($record['validated'] === false && $record['versions'] === []);
    $validator->active = true;
    check($controller->downloadXmlInvoice()->status === 409);
    echo "XML proof tests passed: single validation, user/token binding, changed XML and failed revalidation.\n";
}
