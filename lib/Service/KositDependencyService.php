<?php

declare(strict_types=1);

namespace OCA\MyXRechnung\Service;

use OC\Installer;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ITempManager;

/** Ermittelt das aktuelle stabile GitHub-Release und installiert die fehlende App. */
class KositDependencyService {
    public function __construct(
        private IAppManager $apps,
        private IConfig $config,
        private IClientService $clients,
        private ITempManager $temp,
        private Installer $installer,
    ) {
    }

    public function settings(): array {
        $result = [];
        $result['hide_navigation'] = $this->config->getAppValue('kosit_validator', 'hide_navigation', 'no') === 'yes';
        $result['installed'] = $this->apps->getAppInstalledVersions()['kosit_validator'] ?? null;
        $result['enabled'] = $this->apps->isEnabledForAnyone('kosit_validator');
        $result['navigation_supported'] = false;
        if ($result['enabled']) {
            $this->apps->loadApp('kosit_validator');
            $result['navigation_supported'] = defined('OCA\\KoSITValidator\\AppInfo\\Application::SUPPORTS_HIDDEN_NAVIGATION');
        }
        return $result;
    }

    public function save(array $data): void {
        if (!is_bool($data['hide_navigation'] ?? null)) {
            throw new \InvalidArgumentException('Ungültige Navigationseinstellung.');
        }
        $this->config->setAppValue('kosit_validator', 'hide_navigation', $data['hide_navigation'] ? 'yes' : 'no');
    }

    public function latest(): array {
        $client = $this->clients->newClient();
        try {
            $response = $client->get('https://api.github.com/repos/322ud/kosit_validator/releases/latest', [
                'headers' => $this->githubHeaders(), 'timeout' => 10, 'allow_redirects' => false,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('GitHub antwortet nicht mit einem Release.');
            }
            $release = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_string($release['tag_name'] ?? null) || !empty($release['draft']) || !empty($release['prerelease'])) {
                throw new \RuntimeException('Kein stabiles Release.');
            }
            foreach ($release['assets'] ?? [] as $asset) {
                if (preg_match('/^kosit_validator-([0-9]+\.[0-9]+\.[0-9]+)\.tar\.gz$/D', $asset['name'] ?? '', $match)
                    && is_int($asset['id'] ?? null) && ($asset['size'] ?? PHP_INT_MAX) <= 20 * 1024 * 1024
                    && preg_match('/^sha256:([a-f0-9]{64})$/D', $asset['digest'] ?? '', $digest)) {
                    return ['release_tag' => $release['tag_name'], 'release_version' => $match[1],
                        'release_sha256' => $digest[1], 'asset_id' => $asset['id']];
                }
            }
            throw new \RuntimeException('Release-Paket oder SHA-256-Digest fehlt.');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Aktuelles GitHub-Release nicht abrufbar. Ein veröffentlichtes kosit_validator-VERSION.tar.gz mit SHA-256-Digest und bei privatem Repository ein serverseitiger GitHub-Token werden benötigt.');
        }
    }

    private function githubHeaders(): array {
        $headers = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'my_xrechnung', 'X-GitHub-Api-Version' => '2022-11-28'];
        $token = $this->config->getSystemValueString('my_xrechnung_github_token', '');
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    public function ensureInstalled(): void {
        $settings = $this->latest();
        $installed = $this->apps->getAppInstalledVersions()['kosit_validator'] ?? null;
        if ($installed !== null && version_compare($installed, $settings['release_version'], '>=')) {
            return;
        }
        // Vorhandenen Code über den administrativen Release-Deploymentweg aktualisieren.
        if ($installed !== null) {
            throw new \RuntimeException('Ein neueres kosit_validator-Release ist verfügbar. Bitte über den administrativen Release-Deploymentweg aktualisieren.');
        }
        try {
            $path = $this->apps->getAppPath('kosit_validator');
            $info = $this->apps->getAppInfoByPath($path . '/appinfo/info.xml');
            if (($info['version'] ?? '') !== $settings['release_version']) {
                throw new \RuntimeException('Vorhandener Validator-App-Code entspricht nicht dem aktuellen Release. Bitte administrativ aktualisieren.');
            }
            $this->installer->installApp('kosit_validator');
            $this->apps->enableApp('kosit_validator');
            return;
        } catch (AppPathNotFoundException) {
        }
        $installRoot = $this->installer->getInstallPath();
        if ($installRoot === null || !is_writable($installRoot)) {
            throw new \RuntimeException('Kein beschreibbares Nextcloud-App-Verzeichnis vorhanden.');
        }
        $lock = fopen($installRoot . '/.my_xrechnung-kosit-install.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Installationssperre konnte nicht geöffnet werden.');
        }
        $staging = null;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Die Validator-App wird bereits installiert.');
            }
            $destination = $installRoot . '/kosit_validator';
            if (file_exists($destination) || is_link($destination)) {
                throw new \RuntimeException('Validator-App-Verzeichnis ist inzwischen vorhanden. Bitte erneut prüfen.');
            }
            $archive = $this->download($settings);
            $staging = $installRoot . '/.myx-kosit-' . bin2hex(random_bytes(8));
            if (!mkdir($staging, 0700)) {
                throw new \RuntimeException('Installationsverzeichnis konnte nicht erstellt werden.');
            }
            KositReleaseArchive::unpack($archive, $staging, $settings['release_version']);
            if (!rename($staging . '/kosit_validator', $destination)) {
                throw new \RuntimeException('Validator-App konnte nicht bereitgestellt werden.');
            }
            // Nextcloud prüft Kompatibilität und übernimmt Installation/Migrationen.
            $this->apps->clearAppsCache();
            $this->installer->installApp('kosit_validator');
            $this->apps->enableApp('kosit_validator');
        } finally {
            if ($staging !== null && is_dir($staging)) {
                self::removeTemporaryTree($staging);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function download(array $settings): string {
        $client = $this->clients->newClient();
        $headers = $this->githubHeaders();
        $base = 'https://api.github.com/repos/322ud/kosit_validator';
        try {
            $archive = $this->temp->getTemporaryFile('.tar.gz');
            if ($archive === false) {
                throw new \RuntimeException('Temporäre Datei fehlt.');
            }
            $headers['Accept'] = 'application/octet-stream';
            $options = ['headers' => $headers, 'timeout' => 120, 'allow_redirects' => false, 'sink' => $archive];
            $options['on_headers'] = static function ($response): void {
                if ((int)$response->getHeaderLine('Content-Length') > 20 * 1024 * 1024) {
                    throw new \RuntimeException('Release-Paket zu groß.');
                }
            };
            $options['progress'] = static function ($total, $downloaded): void {
                if ($downloaded > 20 * 1024 * 1024) {
                    throw new \RuntimeException('Release-Paket zu groß.');
                }
            };
            $download = $client->get($base . '/releases/assets/' . $settings['asset_id'], $options);
            if (in_array($download->getStatusCode(), [301, 302, 303, 307, 308], true)) {
                $location = $download->getHeader('Location');
                $url = parse_url($location);
                if (($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') !== 'release-assets.githubusercontent.com'
                    || isset($url['user']) || isset($url['pass']) || isset($url['port'])) {
                    throw new \RuntimeException('Unerwartetes Download-Ziel.');
                }
                $options['headers'] = ['User-Agent' => 'my_xrechnung'];
                $download = $client->get($location, $options);
            }
            if ($download->getStatusCode() !== 200 || filesize($archive) > 20 * 1024 * 1024
                || !hash_equals($settings['release_sha256'], hash_file('sha256', $archive))) {
                throw new \RuntimeException('Download oder SHA-256-Prüfung fehlgeschlagen.');
            }
            return $archive;
        } catch (\Throwable $e) {
            // HTTP-Fehler können signierte URLs oder Authentifizierungsdaten enthalten.
            throw new \RuntimeException('GitHub-Release konnte nicht geprüft heruntergeladen werden. Release-Paket, SHA-256 und gegebenenfalls den serverseitigen GitHub-Token prüfen.');
        }
    }

    private static function removeTemporaryTree(string $path): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTemporaryTree($path . '/' . $entry);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
