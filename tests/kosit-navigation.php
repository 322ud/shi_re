<?php
// Vertragstest für die Navigationserweiterung im benachbarten Validator-Repository.
declare(strict_types=1);
namespace OCP {
    interface IConfig {} interface IGroupManager {} interface IL10N {} interface INavigationManager {}
    interface IURLGenerator {} interface IUserSession {}
}
namespace OCP\App { interface IAppManager {} }
namespace OCP\EventDispatcher { class Event {} interface IEventListener { public function handle(Event $event): void; } }
namespace OCP\Navigation\Events { class LoadAdditionalEntriesEvent extends \OCP\EventDispatcher\Event {} }
namespace {
    require ($argv[1] ?? __DIR__ . '/../../kosit_validator') . '/lib/Listener/NavigationListener.php';
    $navigation = new class implements \OCP\INavigationManager {
        public array $entries = [];
        public function add(array $entry): void { $this->entries[] = $entry; }
    };
    $config = new class implements \OCP\IConfig {
        public bool $hide = false;
        public function getAppValue($app, $key, $default): string { return $this->hide ? 'yes' : 'no'; }
    };
    $session = new class implements \OCP\IUserSession {
        public bool $loggedIn = true;
        public function getUser(): ?object { return $this->loggedIn ? new class { public function getUID(): string { return 'user'; } } : null; }
    };
    $groups = new class implements \OCP\IGroupManager {
        public bool $admin = false;
        public function isAdmin($uid): bool { return $this->admin; }
    };
    $apps = new class implements \OCP\App\IAppManager {
        public bool $enabled = true;
        public function isEnabledForUser($app, $user): bool { return $this->enabled; }
    };
    $urls = new class implements \OCP\IURLGenerator {
        public function linkToRoute($route): string { return '/apps/kosit_validator'; }
        public function imagePath($app, $icon): string { return '/icon.svg'; }
    };
    $l10n = new class implements \OCP\IL10N { public function t($text): string { return $text; } };
    $listener = new \OCA\KoSITValidator\Listener\NavigationListener($navigation, $config, $session, $groups, $apps, $urls, $l10n);
    foreach ([[false,false,true,true,1], [true,false,true,true,0], [true,true,true,true,1],
        [false,false,false,true,0], [false,true,true,false,0]] as [$hide,$admin,$loggedIn,$enabled,$count]) {
        $config->hide = $hide; $groups->admin = $admin; $session->loggedIn = $loggedIn; $apps->enabled = $enabled;
        $navigation->entries = [];
        $listener->handle(new \OCP\Navigation\Events\LoadAdditionalEntriesEvent());
        if (count($navigation->entries) !== $count) throw new \RuntimeException('Visibility test failed');
    }
    echo "Navigation visibility tests passed.\n";
}
