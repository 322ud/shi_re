<?php
declare(strict_types=1);
namespace OCP { interface IConfig {} interface IRequest {} interface IUserSession {} interface ISession {} }
namespace OCP\AppFramework { class Controller { protected $request; } }
namespace OCP\AppFramework\Http { class JSONResponse { public function __construct(public array $data) {} } }
namespace {
    require __DIR__ . '/../lib/Service/ProgressService.php';
    function check(bool $ok): void { if (!$ok) throw new RuntimeException('Progress test failed'); }
    $config = new class implements \OCP\IConfig {
        public bool $enabled = false;
        public array $values = [];
        public function getAppValue($app, $key, $default) { return $this->enabled ? 'yes' : $default; }
        public function setUserValue($uid, $app, $key, $value) { $this->values[$uid][$key] = $value; }
        public function getUserValue($uid, $app, $key, $default) { return $this->values[$uid][$key] ?? $default; }
    };
    $request = new class implements \OCP\IRequest {
        public array $params = ['progressId' => '0123456789abcdef0123456789abcdef', 'windowId' => 'window'];
        public function getParam($key) { return $this->params[$key] ?? null; }
    };
    $users = new class implements \OCP\IUserSession {
        public string $uid = 'alice';
        public function getUser() { return new class($this->uid) {
            public function __construct(private string $uid) {}
            public function getUID() { return $this->uid; }
        }; }
    };
    $session = new class implements \OCP\ISession { public int $closed = 0; public function close() { $this->closed++; } };
    $make = fn () => new \OCA\ShiRE\Service\ProgressService($config, $request, $users, $session);
    // Detailed reporting is always enabled, even with the legacy setting off.
    $writer = $make();
    $reader = $make();
    $id = $request->params['progressId'];
    $writer->start('PDF wird mit LibreOffice erzeugt …');
    $snapshot = $reader->read($id, 'window');
    check($snapshot['current']['text'] === 'PDF wird mit LibreOffice erzeugt …' && $snapshot['events'] === []);
    check((bool)preg_match('/^\d{2}:\d{2}:\d{2}$/D', $snapshot['current']['time']));
    check($session->closed >= 2); // Writer and polling request release the session independently.
    $writer->finish('PDF aus der Rechnungsvorlage erzeugt.');
    $writer->start('PDF-Rechnung wird mit Mustang geprüft …');
    $writer->fail();
    $snapshot = $reader->read($id, 'window');
    check($snapshot['current'] === null && count($snapshot['events']) === 2);
    check(str_contains($snapshot['events'][1]['text'], 'fehlgeschlagen'));
    check($reader->read($id, 'other-window') === null && $reader->read('other', 'window') === null);
    $users->uid = 'bob'; check($reader->read($id, 'window') === null); $users->uid = 'alice';
    $request->params['progressId'] = str_repeat('a', 32);
    $make()->start('Nächster Vorgang');
    check($reader->read($id, 'window') === null && count($config->values['alice']) === 1);
    $old = json_decode($config->values['alice']['progress_snapshot'], true);
    $old['updated'] = time() - 3601;
    $config->values['alice']['progress_snapshot'] = json_encode($old);
    check($reader->read(str_repeat('a', 32), 'window') === null);
    $before = $config->values;
    $request->params['progressId'] = '../invalid'; $make()->start('ignored');
    check($before === $config->values);
    // Exercise the established status route without initializing release lookup dependencies.
    require __DIR__ . '/../lib/Controller/ValidatorAdminController.php';
    $request->params = ['action' => 'progress', 'progressId' => str_repeat('b', 32), 'windowId' => 'window'];
    $make()->start('Aktueller Schritt');
    $reflection = new ReflectionClass(\OCA\ShiRE\Controller\ValidatorAdminController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('request')->setValue($controller, $request);
    $reflection->getProperty('progress')->setValue($controller, $make());
    check($controller->status()->data['progress']['current']['text'] === 'Aktueller Schritt');
    $request->params['windowId'] = 'other-window';
    check($controller->status()->data['progress'] === null);
    $request->params['windowId'] = 'window';
    $users->uid = 'bob';
    check($controller->status()->data['progress'] === null);
    $users->uid = 'alice';
    echo "Progress snapshots: live state, timestamps, failures, session release, isolation, expiration and always-on reporting passed.\n";
}
