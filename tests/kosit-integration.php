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
namespace OCA\INValidator\Service {
    class ValidatorAdminService {
        public static array $status = [
            'validator' => ['installedVersion' => '1.6.0', 'latestVersion' => '9.9.9'],
            'configuration' => ['installedVersion' => '2026-01-31', 'installedXRechnungVersion' => '3.0.2', 'latestVersion' => '2099-01-01'],
        ];
        public function getStatus(bool $checkUpdates = true): array {
            \check(!$checkUpdates, 'Version lookup must not query remote updates');
            return self::$status;
        }
    }
}
namespace {
    require __DIR__ . '/../lib/Service/KositDependencyService.php';
    require __DIR__ . '/../lib/Service/KositReleaseArchive.php';
    require __DIR__ . '/../lib/Service/KositValidatorService.php';
    use OCA\ShiRE\Service\KositDependencyService;
    use OCA\ShiRE\Service\KositReleaseArchive;
    use OCA\ShiRE\Service\KositValidatorService;
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
    $tar = tarEntry('inv_validator/appinfo/info.xml', '<info><id>inv_validator</id><version>0.5.1</version></info>')
        . tarEntry('inv_validator/lib/AppInfo/Application.php', '<?php')
        . tarEntry('inv_validator/lib/Service/KoSITValidatorService.php', '<?php') . str_repeat("\0", 1024);
    $package = gzencode($tar);
    $archive = $root . '/valid.tar.gz'; file_put_contents($archive, $package);
    mkdir($root . '/unpacked');
    KositReleaseArchive::unpack($archive, $root . '/unpacked', '0.5.1');
    check(is_file($root . '/unpacked/inv_validator/appinfo/info.xml'), 'Valid archive');
    foreach ([tarEntry('inv_validator/../../escape', 'x'), tarEntry('inv_validator/link', '', '2', '/tmp'),
        tarEntry('inv_validator/hard', '', '1', 'target'), tarEntry('inv_validator/dev', '', '3'),
        substr($tar, 0, 100), str_replace('inv_validator', 'wrong_validator', $tar)] as $i => $bad) {
        $path = $root . '/bad' . $i . '.tar.gz'; file_put_contents($path, gzencode($bad));
        $dir = $root . '/bad' . $i; mkdir($dir);
        rejects(fn () => KositReleaseArchive::unpack($path, $dir, '0.5.1'));
    }
    mkdir($root . '/wrong-version');
    rejects(fn () => KositReleaseArchive::unpack($archive, $root . '/wrong-version', '9.0.0'));

    $apps = new class($root) implements \OCP\App\IAppManager {
        public array $versions = [];
        public bool $enabled = false;
        public int $upgrades = 0;
        public int $enables = 0;
        public int $cacheClears = 0;
        public bool $failUpgrade = false;
        public bool $throwUpgrade = false;
        public ?string $appRoot = null;
        public function __construct(private string $root) {}
        public function getAppInstalledVersions(): array { return $this->versions; }
        public function isEnabledForAnyone(string $id): bool { return $this->enabled; }
        public function isEnabledForUser(string $id): bool { return $this->enabled; }
        public function getAppPath(string $id, bool $ignoreCache = false): string {
            $path = ($this->appRoot ?? $this->root . '/apps') . '/' . $id;
            if (!is_dir($path)) throw new \OCP\App\AppPathNotFoundException();
            return $path;
        }
        public function getAppInfoByPath(string $path): array {
            return ['version' => (string)simplexml_load_file($path)->version];
        }
        public function clearAppsCache(): void { $this->cacheClears++; }
        public function upgradeApp(string $id): bool {
            check($this->cacheClears > 0, 'Cache cleared before upgrade');
            $this->upgrades++;
            $info = $this->getAppInfoByPath($this->getAppPath($id) . '/appinfo/info.xml');
            check($info['version'] === '0.5.1', 'New code deployed before upgrade');
            check(!file_exists($this->getAppPath($id) . '/obsolete.php'), 'Old files removed');
            if ($this->throwUpgrade) throw new \RuntimeException('Migration failed');
            if ($this->failUpgrade) return false;
            $this->versions[$id] = $info['version'];
            return true;
        }
        public function loadApp(string $id): void {}
        public function enableApp(string $id): void {
            check(isset($this->versions[$id]), 'Must install before enabling'); $this->enabled = true; $this->enables++;
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
        public function __construct(public string $package) {}
        public function newClient(): object { return $this; }
        public function get(string $url, array $options): object {
            $this->requests[] = [$url, $options];
            if (str_ends_with($url, '/latest')) {
                $body = json_encode(['tag_name' => '0.5.1', 'assets' => [['name' => 'inv_validator-0.5.1.tar.gz',
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
    check(!is_dir($root . '/apps/inv_validator'), 'Malicious redirect rejected');
    $http->evilRedirect = false; $http->badDigest = true;
    rejects(fn () => $dependency->ensureInstalled());
    check(!is_dir($root . '/apps/inv_validator'), 'Hash mismatch rejected');
    $http->badDigest = false;
    $dependency->ensureInstalled();
    check($apps->enabled && count($installer->calls) === 1, 'Installed and enabled');
    $dependency->ensureInstalled();
    check(count($installer->calls) === 1, 'Existing app not reinstalled');
    $apps->versions['inv_validator'] = '0.6.0';
    $requests = count($http->requests);
    $dependency->ensureInstalled();
    check(count($http->requests) === $requests + 1 && $apps->upgrades === 0, 'No downgrade or package download');

    // Eine ältere Installation im tatsächlich verwendeten App-Root ersetzen.
    mkdir($root . '/custom-apps');
    rename($root . '/apps/inv_validator', $root . '/custom-apps/inv_validator');
    $apps->appRoot = $root . '/custom-apps';
    $destination = $apps->getAppPath('inv_validator');
    $oldInfo = '<info><id>inv_validator</id><version>0.5.0</version></info>';
    file_put_contents($destination . '/appinfo/info.xml', $oldInfo);
    file_put_contents($destination . '/obsolete.php', 'old code');
    $apps->versions['inv_validator'] = '0.5.0';
    $apps->enabled = false;
    check($dependency->settings()['auto_update'] === true, 'Auto update defaults to yes');
    check($dependency->settings()['validation_enabled'] === true, 'Validation defaults to yes');
    $dependency->save(['validation_enabled' => false, 'auto_update' => false]);
    check($dependency->settings()['validation_enabled'] === false, 'Validation preference persists');
    check($dependency->settings()['auto_update'] === false, 'Explicit auto update preference persists');
    rejects(fn () => (new KositValidatorService($apps, $config))->validateXml('<invoice/>'));
    $dependency->save(['validation_enabled' => true]);
    $dependency->save(['hide_navigation' => true]);

    $lock = fopen($root . '/custom-apps/.shi_re-kosit-install.lock', 'c');
    flock($lock, LOCK_EX);
    rejects(fn () => $dependency->ensureInstalled());
    flock($lock, LOCK_UN); fclose($lock);
    check(file_exists($destination . '/obsolete.php'), 'Concurrent update leaves old code intact');
    $http->badDigest = true;
    rejects(fn () => $dependency->ensureInstalled());
    $http->badDigest = false;
    $http->evilRedirect = true;
    rejects(fn () => $dependency->ensureInstalled());
    $http->evilRedirect = false;
    check(file_get_contents($destination . '/appinfo/info.xml') === $oldInfo, 'Failed verification preserves old code');
    check($apps->upgrades === 0, 'No migration for unverified packages');
    $http->package = gzencode(str_replace('0.5.1', '9.9.9', $tar));
    rejects(fn () => $dependency->ensureInstalled());
    $http->package = $package;
    check(file_get_contents($destination . '/appinfo/info.xml') === $oldInfo, 'Invalid archive preserves old code');

    check(glob($root . '/custom-apps/.myx-kosit-*') === [], 'Failed verification leaves no staging directories');

    file_put_contents($destination . '/appinfo/info.xml', str_replace('0.5.0', '0.6.0', $oldInfo));
    rejects(fn () => $dependency->ensureInstalled());
    check(file_exists($destination . '/obsolete.php'), 'Newer deployed code is not downgraded');
    file_put_contents($destination . '/appinfo/info.xml', $oldInfo);
    rename($destination, $destination . '-linked');
    symlink($destination . '-linked', $destination);
    rejects(fn () => $dependency->ensureInstalled());
    unlink($destination); rename($destination . '-linked', $destination);

    $dependency->ensureInstalled();
    check($apps->versions['inv_validator'] === '0.5.1' && $apps->upgrades === 1, 'Existing installation upgraded');
    check(count($installer->calls) === 1 && $apps->enables === 1, 'Update uses upgrade, preserves disabled state');
    check(!$apps->enabled, 'Disabled app stays disabled');
    check(!file_exists($root . '/apps/inv_validator'), 'Update stays in original app root');
    check(glob($root . '/custom-apps/.myx-kosit-*') === [], 'Successful update removes staging and backup');
    check($config->values['inv_validator']['hide_navigation'] === 'yes', 'Update preserves settings');
    $dependency->ensureInstalled();
    check($apps->upgrades === 1, 'Current version not upgraded again');

    foreach ([false, true] as $throws) {
        $apps->versions['inv_validator'] = '0.5.0';
        file_put_contents($destination . '/appinfo/info.xml', $oldInfo);
        $apps->failUpgrade = !$throws;
        $apps->throwUpgrade = $throws;
        try {
            $dependency->ensureInstalled();
            throw new \LogicException('Expected migration failure');
        } catch (\RuntimeException $e) {
            check(str_contains($e->getMessage(), 'Sicherung:'), 'Migration failure reports retained backup');
        }
        $backups = glob($root . '/custom-apps/.myx-kosit-*/previous/appinfo/info.xml');
        check(count($backups) === ($throws ? 2 : 1), 'Migration failure preserves old code backup');
        check(file_get_contents(end($backups)) === $oldInfo, 'Backup contains previous release');
        check($apps->getAppInfoByPath($destination . '/appinfo/info.xml')['version'] === '0.5.1', 'Migration failure keeps new code');
    }
    $apps->failUpgrade = false; $apps->throwUpgrade = false;
    $dependency->ensureInstalled();
    check($apps->versions['inv_validator'] === '0.5.1', 'Migration can be retried after failure');
    $apps->enabled = true;
    $dependency->save(['hide_navigation' => true]);
    check($config->values['inv_validator']['hide_navigation'] === 'yes', 'Visibility stored in dependency app');

    \OCP\AppFramework\App::$container = new class {
        public function get(string $name): object {
            if ($name === 'OCA\\INValidator\\Service\\ValidatorAdminService') return new \OCA\INValidator\Service\ValidatorAdminService();
            check($name === 'OCA\\INValidator\\Service\\KoSITValidatorService', 'Separate validator service');
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
    $result = (new KositValidatorService($apps, $config))->validateXml('<invoice/>');
    check(\OCP\AppFramework\App::$id === 'inv_validator', 'Correct app config scope');
    check($result === ['valid' => false, 'html' => '<p>Invalid invoice</p>'], 'Validation result forwarded');
    $versions = (new KositValidatorService($apps, $config))->versions();
    check($versions === ['INValidatorVersion' => '0.5.1', 'kositValidatorVersion' => '1.6.0', 'xrechnungSzenarioVersion' => '2026-01-31'], 'Installed versions and scenario release, not latest or standard version');
    \OCA\INValidator\Service\ValidatorAdminService::$status = ['validator' => ['installedVersion' => null], 'configuration' => ['installedVersion' => null]];
    check((new KositValidatorService($apps, $config))->versions()['kositValidatorVersion'] === 'unbekannt', 'Unknown installed versions are explicit');
    $apps->enabled = false;
    rejects(fn () => (new KositValidatorService($apps, $config))->validateXml('<invoice/>'));
    echo "KoSIT adapter, archive, installation, updates, migration failures, digest and redirect tests passed.\n";
}
