<?php

declare(strict_types=1);
namespace OCP { interface IRequest {} }
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
        public bool $valid = true;
        public int $calls = 0;
        public function validateXml(string $xml): array {
            $this->calls++;
            return ['valid' => $this->valid, 'html' => 'report'];
        }
    }
}
namespace {
    require __DIR__ . '/../lib/Config/AppFiles.php';
    require __DIR__ . '/../lib/Controller/XmlValidationController.php';
    $request = new class implements \OCP\IRequest {
        public string $hash = '';
        public bool $download = false;
        public function getParam($key) { return $key === 'download' ? $this->download : $this->hash; }
    };
    $storage = new class implements \OCP\Files\IAppData {
        public string $xml = '<invoice/>';
        public function getFolder($name) { return $this; }
        public function getFile($name) { return $this; }
        public function fileExists($name) { return true; }
        public function getContent() { return $this->xml; }
    };
    $validator = new \OCA\ShiRE\Service\KositValidatorService();
    $controller = new \OCA\ShiRE\Controller\XmlValidationController('shi_re', $request, $storage, $validator);
    function check(bool $value): void { if (!$value) throw new \RuntimeException('Download test failed'); }
    $validation = $controller->validateXmlInvoice();
    $request->hash = $validation->data['xmlHash'];
    $download = $controller->downloadXmlInvoice();
    check($download instanceof \OCP\AppFramework\Http\DataDownloadResponse);
    check($download->filename === 'en16931.xml' && $download->data === '<invoice/>');
    check($download->headers['Cache-Control'] === 'no-store');
    $request->download = true;
    check($controller->validateXmlInvoice() instanceof \OCP\AppFramework\Http\DataDownloadResponse);
    $request->download = false;
    $validator->valid = false;
    check($controller->downloadXmlInvoice()->status === 422);
    check($controller->validateXmlInvoice()->data['xmlHash'] === hash('sha256', $storage->xml));
    $storage->xml = '<changed/>';
    $calls = $validator->calls;
    check($controller->downloadXmlInvoice()->status === 409);
    check($validator->calls === $calls);
    echo "XML download tests passed: valid, invalid and replaced XML.\n";
}
