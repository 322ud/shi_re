<?php
declare(strict_types=1);
namespace OCA\ShiRE\Service;

use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

/** One bounded snapshot per user, independent of the invoice lock and PHP session lock. */
class ProgressService {
    private ?array $snapshot = null;
    private bool $initialized = false;
    public function __construct(private IConfig $config, private IRequest $request,
        private IUserSession $users, private ISession $session) {}

    private function initialize(): bool {
        if ($this->initialized) return $this->snapshot !== null;
        $this->initialized = true;
        $id = $this->request->getParam('progressId');
        $window = $this->request->getParam('windowId');
        if ($this->config->getAppValue('shi_re', 'detailed_progress', 'no') !== 'yes'
            || !is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)
            || !is_string($window) || $window === '' || strlen($window) > 128 || !$this->users->getUser()) return false;
        $this->snapshot = ['id' => $id, 'window' => $window, 'updated' => time(), 'current' => null, 'events' => []];
        // Polling must not wait for the invoice request to release its session.
        $this->session->close();
        return true;
    }

    private function event(string $text): array {
        return ['time' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('H:i:s'), 'text' => $text];
    }

    private function save(): void {
        $this->snapshot['updated'] = time();
        $this->config->setUserValue($this->users->getUser()->getUID(), 'shi_re', 'progress_snapshot', json_encode($this->snapshot, JSON_THROW_ON_ERROR));
    }

    public function start(string $text): void {
        if (!$this->initialize()) return;
        $this->snapshot['current'] = $this->event($text);
        $this->save();
    }

    public function finish(string $text): void {
        if (!$this->initialize()) return;
        $this->snapshot['events'][] = $this->event($text);
        $this->snapshot['current'] = null;
        $this->save();
    }

    public function fail(): void {
        if (!$this->initialize() || $this->snapshot['current'] === null) return;
        $this->finish(rtrim($this->snapshot['current']['text'], " ….") . ' — fehlgeschlagen.');
    }

    public function read(string $id, string $window): ?array {
        $user = $this->users->getUser();
        if (!$user || $this->config->getAppValue('shi_re', 'detailed_progress', 'no') !== 'yes') return null;
        $this->session->close();
        $snapshot = json_decode($this->config->getUserValue($user->getUID(), 'shi_re', 'progress_snapshot', ''), true);
        if (!is_array($snapshot) || ($snapshot['id'] ?? null) !== $id || ($snapshot['window'] ?? null) !== $window
            || ($snapshot['updated'] ?? 0) < time() - 3600) return null;
        return ['current' => $snapshot['current'], 'events' => $snapshot['events']];
    }
}
