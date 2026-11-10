<?php

declare(strict_types=1);

namespace OCA\ShiRE\Service;

use OC\Installer;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ITempManager;

/** Ermittelt das aktuelle stabile GitHub-Release und installiert oder aktualisiert die App. */
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
        $result['auto_update'] = $this->config->getAppValue('shi_re', 'auto_update_validator', 'no') === 'yes';
        if ($this->config->getAppValue('inv_validator', 'hide_navigation', '') === '') {
            $this->config->setAppValue('inv_validator', 'hide_navigation', 'yes');
        }
        $result['hide_navigation'] = $this->config->getAppValue('inv_validator', 'hide_navigation', 'yes') === 'yes';
        $result['installed'] = $this->apps->getAppInstalledVersions()['inv_validator'] ?? null;
        $result['enabled'] = $this->apps->isEnabledForAnyone('inv_validator');
        $result['navigation_supported'] = false;
        if ($result['enabled']) {
            $this->apps->loadApp('inv_validator');
            $result['navigation_supported'] = defined('OCA\\INValidator\\AppInfo\\Application::SUPPORTS_HIDDEN_NAVIGATION');
        }
        return $result;
    }

    public function save(array $data): void {
        foreach ($data as $key => $value) {
            if (!in_array($key, ['hide_navigation', 'auto_update'], true) || !is_bool($value)) {
                throw new \InvalidArgumentException('Ungültige Einstellung.');
            }
        }
        foreach ($data as $key => $value) {
            $this->config->setAppValue($key === 'hide_navigation' ? 'inv_validator' : 'shi_re',
                $key === 'hide_navigation' ? $key : 'auto_update_validator', $value ? 'yes' : 'no');
        }
    }

    public function latest(): array {
        $client = $this->clients->newClient();
        try {
            $response = $client->get('https://api.github.com/repos/322ud/inv_validator/releases/latest', [
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
                if (preg_match('/^inv_validator-([0-9]+\.[0-9]+\.[0-9]+)\.tar\.gz$/D', $asset['name'] ?? '', $match)
                    && is_int($asset['id'] ?? null) && ($asset['size'] ?? PHP_INT_MAX) <= 20 * 1024 * 1024
                    && preg_match('/^sha256:([a-f0-9]{64})$/D', $asset['digest'] ?? '', $digest)) {
                    return ['release_tag' => $release['tag_name'], 'release_version' => $match[1],
                        'release_sha256' => $digest[1], 'asset_id' => $asset['id']];
                }
            }
            throw new \RuntimeException('Release-Paket oder SHA-256-Digest fehlt.');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Aktuelles GitHub-Release nicht abrufbar. Ein veröffentlichtes inv_validator-VERSION.tar.gz mit SHA-256-Digest und bei privatem Repository ein serverseitiger GitHub-Token werden benötigt.');
        }
    }

    private function githubHeaders(): array {
        $headers = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'shi_re', 'X-GitHub-Api-Version' => '2022-11-28'];
        $token = $this->config->getSystemValueString('shi_re_github_token', '');
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    public function ensureInstalled(): void {
        $settings = $this->latest();
        $installed = $this->apps->getAppInstalledVersions()['inv_validator'] ?? null;
        if ($installed !== null && version_compare($installed, $settings['release_version'], '>=')) {
            return;
        }
        if ($installed === null) {
            try {
                $path = $this->apps->getAppPath('inv_validator');
                $info = $this->apps->getAppInfoByPath($path . '/appinfo/info.xml');
                if (($info['version'] ?? '') !== $settings['release_version']) {
                    throw new \RuntimeException('Vorhandener Validator-App-Code entspricht nicht dem aktuellen Release. Bitte administrativ aktualisieren.');
                }
                $this->installer->installApp('inv_validator');
                $this->apps->enableApp('inv_validator');
                return;
            } catch (AppPathNotFoundException) {
            }
            $installRoot = $this->installer->getInstallPath();
            $destination = $installRoot === null ? null : $installRoot . '/inv_validator';
        } else {
            // Im tatsächlich verwendeten App-Verzeichnis aktualisieren, auch bei mehreren App-Roots.
            $destination = $this->apps->getAppPath('inv_validator', true);
            $installRoot = dirname($destination);
        }
        if ($installRoot === null || !is_writable($installRoot)) {
            throw new \RuntimeException('Kein beschreibbares Nextcloud-App-Verzeichnis vorhanden.');
        }
        $lock = fopen($installRoot . '/.shi_re-kosit-install.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Installationssperre konnte nicht geöffnet werden.');
        }
        $staging = null;
        $keepBackup = false;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Die Validator-App wird bereits installiert.');
            }
            // Nach Erwerb der Sperre erneut prüfen: Ein anderer Aufruf könnte fertig sein.
            $installed = $this->apps->getAppInstalledVersions()['inv_validator'] ?? null;
            if ($installed !== null && version_compare($installed, $settings['release_version'], '>=')) {
                return;
            }
            if ($installed === null) {
                if (file_exists($destination) || is_link($destination)) {
                    throw new \RuntimeException('Validator-App-Verzeichnis ist inzwischen vorhanden. Bitte erneut prüfen.');
                }
            } else {
                if (is_link($destination) || !is_dir($destination)
                    || $this->apps->getAppPath('inv_validator', true) !== $destination) {
                    throw new \RuntimeException('Validator-App-Verzeichnis kann nicht sicher aktualisiert werden.');
                }
                $info = $this->apps->getAppInfoByPath($destination . '/appinfo/info.xml');
                if (version_compare($info['version'] ?? '0', $settings['release_version'], '>')) {
                    throw new \RuntimeException('Vorhandener Validator-App-Code ist neuer als das GitHub-Release.');
                }
            }
            $archive = $this->download($settings);
            $staging = $installRoot . '/.myx-kosit-' . bin2hex(random_bytes(8));
            if (!mkdir($staging, 0700)) {
                throw new \RuntimeException('Installationsverzeichnis konnte nicht erstellt werden.');
            }
            KositReleaseArchive::unpack($archive, $staging, $settings['release_version']);
            $backup = $staging . '/previous';
            if ($installed !== null && !rename($destination, $backup)) {
                throw new \RuntimeException('Vorhandene Validator-App konnte nicht gesichert werden.');
            }
            if (!rename($staging . '/inv_validator', $destination)) {
                if ($installed !== null && !rename($backup, $destination)) {
                    $keepBackup = true;
                    throw new \RuntimeException('Validator-App konnte nicht wiederhergestellt werden. Sicherung: ' . $backup);
                }
                throw new \RuntimeException('Validator-App konnte nicht bereitgestellt werden.');
            }
            $keepBackup = $installed !== null;
            $this->apps->clearAppsCache();
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
            if ($installed === null) {
                $this->installer->installApp('inv_validator');
                $this->apps->enableApp('inv_validator');
            } else {
                // Migrationen können die Datenbank bereits verändert haben: kein Code-Downgrade bei Fehlern.
                try {
                    if (!$this->apps->upgradeApp('inv_validator')) {
                        throw new \RuntimeException('Nextcloud hat das App-Update nicht abgeschlossen.');
                    }
                } catch (\Throwable $e) {
                    throw new \RuntimeException('Nextcloud-Update der Validator-App fehlgeschlagen. Neuer Code und Sicherung bleiben erhalten; Migrationen administrativ prüfen. Sicherung: ' . $backup, 0, $e);
                }
                $keepBackup = false;
            }
        } finally {
            if (!$keepBackup && $staging !== null && is_dir($staging)) {
                self::removeTemporaryTree($staging);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function download(array $settings): string {
        $client = $this->clients->newClient();
        $headers = $this->githubHeaders();
        $base = 'https://api.github.com/repos/322ud/inv_validator';
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
                $options['headers'] = ['User-Agent' => 'shi_re'];
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
