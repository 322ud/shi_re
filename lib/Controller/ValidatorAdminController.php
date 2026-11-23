<?php

declare(strict_types=1);

namespace OCA\ShiRE\Controller;

use OCA\ShiRE\Service\KositDependencyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IUserSession;

class ValidatorAdminController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private KositDependencyService $dependency,
        private IUserSession $session,
        private IGroupManager $groups,
        private IConfig $config,
        private \OCA\ShiRE\Service\KositValidatorService $validator,
        private ?\OCA\ShiRE\Service\ProgressService $progress = null,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/validator/status')]
    public function status(): JSONResponse {
        // This route predates progress reporting and remains available with an older route cache.
        // Return before release lookup: polling must not contact GitHub or change settings.
        if ($this->request->getParam('action') === 'progress') {
            return new JSONResponse(['success' => true, 'progress' => $this->progress?->read(
                (string)$this->request->getParam('progressId'), (string)$this->request->getParam('windowId'))]);
        }
        $status = $this->dependency->settings();
        $status['message_area'] = $this->config->getAppValue('shi_re', 'message_area', 'yes') === 'yes';
        $status['validation_active'] = $this->validator->isActive();
        $user = $this->session->getUser();
        $status['admin'] = $user !== null && $this->groups->isAdmin($user->getUID());
        try {
            $status['latest'] = $this->dependency->latest()['release_version'];
            $status['update_available'] = $status['installed'] !== null
                && version_compare($status['installed'], $status['latest'], '<');
        } catch (\Throwable $e) {
            $status['check_error'] = $e->getMessage();
        }
        return new JSONResponse(['success' => true, 'status' => $status]);
    }

    // Kein NoAdminRequired: Installation und Konfiguration nur für Administratoren, mit CSRF-Schutz.
    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/admin/validator/install')]
    public function install(): JSONResponse {
        try {
            $this->dependency->ensureInstalled();
            return new JSONResponse(['success' => true, 'status' => $this->dependency->settings()]);
        } catch (\Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/admin/progress/settings')]
    public function saveProgressSettings(): JSONResponse {
        $enabled = $this->request->getParam('message_area');
        if (!is_bool($enabled)) return new JSONResponse(['success' => false, 'error' => 'Ungültige Einstellung.'], 400);
        $this->config->setAppValue('shi_re', 'message_area', $enabled ? 'yes' : 'no');
        return new JSONResponse(['success' => true]);
    }

    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/admin/draft/settings')]
    public function saveDraftSettings(): JSONResponse {
        $allowed = $this->request->getParam('allow_draft_deletion');
        if (!is_bool($allowed)) {
            return new JSONResponse(['success' => false, 'error' => 'Ungültige Entwurfseinstellung.'], 400);
        }
        $this->config->setAppValue('shi_re', 'allow_draft_deletion', $allowed ? 'yes' : 'no');
        return new JSONResponse(['success' => true]);
    }

    #[Route(Route::TYPE_FRONTPAGE, verb: 'POST', url: '/admin/validator/settings')]
    public function save(): JSONResponse {
        // Reuse the established admin/CSRF-protected settings route; keep the newer alias working too.
        if ($this->request->getParam('message_area') !== null) {
            return $this->saveProgressSettings();
        }
        try {
            $data = [];
            foreach (['hide_navigation', 'auto_update', 'validation_enabled'] as $key) {
                $value = $this->request->getParam($key);
                if ($value !== null) $data[$key] = $value;
            }
            if (!$data) throw new \InvalidArgumentException('Einstellung fehlt.');
            $this->dependency->save($data);
            return new JSONResponse(['success' => true, 'status' => $this->dependency->settings()]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
