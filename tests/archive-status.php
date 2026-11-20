<?php
declare(strict_types=1);
namespace OCP\Files {
    interface IAppData {}
    class NotFoundException extends \Exception {}
}
namespace {
    require __DIR__ . '/../lib/Service/ArchiveStatusService.php';
    $storage = new class implements \OCP\Files\IAppData {
        public array $names = [];
        public ?\Throwable $error = null;
        public function getFolder($name) {
            if ($this->error) throw $this->error;
            return $this;
        }
        public function getDirectoryListing() {
            return array_map(fn ($name) => new class($name) {
                public function __construct(private string $name) {}
                public function getName() { return $this->name; }
            }, $this->names);
        }
    };
    $service = new \OCA\ShiRE\Service\ArchiveStatusService($storage);
    foreach ([
        [[], 'leer'],
        [['draft.xml', 'tmp.xml', 'legacy.pdf'], 'leer'],
        [['26 009.xml', '25 999.pdf', '26 101.pdf', '26 101.xml', '26 010.xml', '99 999.xml.bak'], 'bis 26 101'],
        [['26 500.xml'], 'bis 26 500'],
        [['26 501.pdf'], 'bis 26 501'],
    ] as [$names, $expected]) {
        $storage->names = $names;
        if ($service->summary() !== $expected) throw new \RuntimeException('Incorrect archive summary');
    }
    $storage->error = new \OCP\Files\NotFoundException();
    if ($service->summary() !== 'leer') throw new \RuntimeException('Missing archive');
    $storage->error = new \RuntimeException('Storage unavailable');
    if ($service->summary() !== 'nicht ermittelbar') throw new \RuntimeException('Storage failure hidden');
    echo "Archive status passed: highest number, XML/PDF, drafts, empty archive, storage errors.\n";
}
