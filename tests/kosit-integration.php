<?php
// Isolierte Vertragstests mit Test-Doubles; benötigt keinen Nextcloud-Server.
declare(strict_types=1);
namespace OCP { interface IConfig {} interface ITempManager {} }
namespace OCP\App { interface IAppManager {} class AppPathNotFoundException extends \RuntimeException {} }
namespace OCP\Http\Client { interface IClientService {} }
namespace OC {
    class Installer {
        public array $calls = [];
        public function __construct(private string $root, private object $apps) {}
        public function getInstallPath(): string { return $this->root; }
        public function installApp(string $id): void { $this->calls[] = $id; $this->apps->versions[$id] = '0.5.1'; }
    }
}
namespace OCP\AppFramework {
    class App {
        public static object $container;
        public static string $id;
        public function __construct(string $id) { self::$id = $id; }
        public function getContainer(): object { return self::$container; }
    }
}
namespace {
    require __DIR__ . '/../lib/Service/KositDependencyService.php';
    require __DIR__ . '/../lib/Service/KositReleaseArchive.php';
    require __DIR__ . '/../lib/Service/KositValidatorService.php';
    use OCA\MyXRechnung\Service\KositDependencyService;
    use OCA\MyXRechnung\Service\KositReleaseArchive;
    use OCA\MyXRechnung\Service\KositValidatorService;
    function check(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    function rejects(callable $action): void {
        try { $action(); } catch (\RuntimeException $e) { return; }
        throw new \RuntimeException('Expected rejection');
    }
    function tarEntry(string $name, string $content, string $type = '0', string $link = ''): string {
        $header = str_pad($name, 100, "\0") . sprintf('%07o', 0644) . "\0" . str_repeat("0000000\0", 2)
            . sprintf('%011o', strlen($content)) . "\0" . "00000000000\0" . str_repeat(' ', 8)
            . $type . str_pad($link, 100, "\0") . "ustar\00000";
        $header = str_pad($header, 512, "\0");
        $sum = array_sum(unpack('C*', $header));
        $header = substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);
        return $header . str_pad($content, (int)(ceil(strlen($content) / 512) * 512), "\0");
    }
    $root = sys_get_temp_dir() . '/myx-kosit-test-' . bin2hex(random_bytes(5));
    mkdir($root, 0700);
    $tar = tarEntry('kosit_validator/appinfo/info.xml', '<info><id>kosit_validator</id><version>0.5.1</version></info>')
        . tarEntry('kosit_validator/lib/AppInfo/Application.php', '<?php')
        . tarEntry('kosit_validator/lib/Service/KoSITValidatorService.php', '<?php') . str_repeat("\0", 1024);
    $package = gzencode($tar);
    $archive = $root . '/valid.tar.gz'; file_put_contents($archive, $package);
    mkdir($root . '/unpacked');
    KositReleaseArchive::unpack($archive, $root . '/unpacked', '0.5.1');
    check(is_file($root . '/unpacked/kosit_validator/appinfo/info.xml'), 'Valid archive');
    foreach ([tarEntry('kosit_validator/../../escape', 'x'), tarEntry('kosit_validator/link', '', '2', '/tmp'),
        tarEntry('kosit_validator/hard', '', '1', 'target'), tarEntry('kosit_validator/dev', '', '3'),
        substr($tar, 0, 100), str_replace('kosit_validator', 'wrong_validator', $tar)] as $i => $bad) {
        $path = $root . '/bad' . $i . '.tar.gz'; file_put_contents($path, gzencode($bad));
        $dir = $root . '/bad' . $i; mkdir($dir);
        rejects(fn () => KositReleaseArchive::unpack($path, $dir, '0.5.1'));
    }
    mkdir($root . '/wrong-version');
    rejects(fn () => KositReleaseArchive::unpack($archive, $root . '/wrong-version', '9.0.0'));

    $apps = new class implements \OCP\App\IAppManager {
        public array $versions = [];
        public bool $enabled = false;
        public function getAppInstalledVersions(): array { return $this->versions; }
        public function isEnabledForAnyone(string $id): bool { return $this->enabled; }
        public function isEnabledForUser(string $id): bool { return $this->enabled; }
        public function getAppPath(string $id): string { throw new \OCP\App\AppPathNotFoundException(); }
        public function clearAppsCache(): void {}
        public function loadApp(string $id): void {}
        public function enableApp(string $id): void {
            check(isset($this->versions[$id]), 'Must install before enabling'); $this->enabled = true;
        }
    };
    $config = new class implements \OCP\IConfig {
        public array $values = [];
        public function getAppValue($app, $key, $default) { return $this->values[$app][$key] ?? $default; }
        public function setAppValue($app, $key, $value): void { $this->values[$app][$key] = $value; }
        public function getSystemValueString($key, $default): string { return 'test-token'; }
    };
    $temp = new class($root) implements \OCP\ITempManager {
        public function __construct(private string $root) {}
        public function getTemporaryFile(string $suffix): string { return $this->root . '/download' . $suffix; }
    };
    $http = new class($package) implements \OCP\Http\Client\IClientService {
        public array $requests = [];
        public bool $badDigest = false;
        public bool $evilRedirect = false;
        public function __construct(private string $package) {}
        public function newClient(): object { return $this; }
        public function get(string $url, array $options): object {
            $this->requests[] = [$url, $options];
            if (str_ends_with($url, '/latest')) {
                $body = json_encode(['tag_name' => '0.5.1', 'assets' => [['name' => 'kosit_validator-0.5.1.tar.gz',
                    'id' => 42, 'size' => strlen($this->package), 'digest' => 'sha256:' . ($this->badDigest ? str_repeat('0', 64) : hash('sha256', $this->package))]]]);
                return self::response(200, $body);
            }
            if (str_contains($url, '/releases/assets/')) {
                check($options['headers']['Authorization'] === 'Bearer test-token', 'API uses token');
                file_put_contents($options['sink'], '');
                return self::response(302, '', $this->evilRedirect ? 'https://evil.example/a' : 'https://release-assets.githubusercontent.com/test');
            }
            check(!isset($options['headers']['Authorization']), 'Token must not follow redirect');
            file_put_contents($options['sink'], $this->package);
            return self::response(200, '');
        }
        private static function response(int $code, string $body, string $location = ''): object {
            return new class($code, $body, $location) {
                public function __construct(private int $code, private string $body, private string $location) {}
                public function getStatusCode(): int { return $this->code; }
                public function getBody(): string { return $this->body; }
                public function getHeader($key): string { return $this->location; }
            };
        }
    };
    mkdir($root . '/apps');
    $installer = new \OC\Installer($root . '/apps', $apps);
    $dependency = new KositDependencyService($apps, $config, $http, $temp, $installer);
    $http->evilRedirect = true;
    rejects(fn () => $dependency->ensureInstalled());
    check(!is_dir($root . '/apps/kosit_validator'), 'Malicious redirect rejected');
    $http->evilRedirect = false; $http->badDigest = true;
    rejects(fn () => $dependency->ensureInstalled());
    check(!is_dir($root . '/apps/kosit_validator'), 'Hash mismatch rejected');
    $http->badDigest = false;
    $dependency->ensureInstalled();
    check($apps->enabled && count($installer->calls) === 1, 'Installed and enabled');
    $dependency->ensureInstalled();
    check(count($installer->calls) === 1, 'Existing app not reinstalled');
    $dependency->save(['hide_navigation' => true]);
    check($config->values['kosit_validator']['hide_navigation'] === 'yes', 'Visibility stored in dependency app');

    \OCP\AppFramework\App::$container = new class {
        public function get(string $name): object {
            check($name === 'OCA\\KoSITValidator\\Service\\KoSITValidatorService', 'Separate validator service');
            return new class {
                public function validateXml(string $xml): object {
                    check($xml === '<invoice/>', 'Exact XML forwarded');
                    return new class {
                        public function isValid(): bool { return false; }
                        public function getReportHtml(): string { return '<p>Invalid invoice</p>'; }
                    };
                }
            };
        }
    };
    $result = (new KositValidatorService($apps))->validateXml('<invoice/>');
    check(\OCP\AppFramework\App::$id === 'kosit_validator', 'Correct app config scope');
    check($result === ['valid' => false, 'html' => '<p>Invalid invoice</p>'], 'Validation result forwarded');
    $apps->enabled = false;
    rejects(fn () => (new KositValidatorService($apps))->validateXml('<invoice/>'));
    echo "KoSIT adapter, archive, installation, digest and redirect tests passed.\n";
}
