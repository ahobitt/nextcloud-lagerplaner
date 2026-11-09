<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\AdminSettingsService;
use OCA\Lagerplaner\Service\SiteFeedbackService;
use OCA\Lagerplaner\Settings\Admin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Nur für die Administration und Gruppen, denen sie den Abschnitt „Lagerplaner“ delegiert hat. */
class AdminApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private AdminSettingsService $settings,
		private SiteFeedbackService $feedback,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function state(): JSONResponse {
		return $this->respond(fn () => $this->settings->state());
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function home(string $input = ''): JSONResponse {
		return $this->respond(fn () => $this->settings->setHome($input));
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function games(bool $enabled = true): JSONResponse {
		return $this->respond(fn () => $this->settings->setGamesEnabled($enabled));
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function source(string $source, bool $visible = true): JSONResponse {
		return $this->respond(fn () => $this->settings->setSourceVisible($source, $visible));
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function groupHome(string $groupId, string $label = '', string $input = ''): JSONResponse {
		return $this->respond(fn () => $this->settings->setGroupHome($groupId, $label, $input));
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function removeGroupHome(string $groupId): JSONResponse {
		return $this->respond(fn () => $this->settings->removeGroupHome($groupId));
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function groups(string $q = ''): JSONResponse {
		return $this->respond(fn () => $this->settings->searchGroups($q));
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function importBundled(string $source): JSONResponse {
		return $this->respond(fn () => $this->settings->importBundled($source));
	}

	/** Datenstand als Datei hochladen (Feld „file“), statt occ lagerplaner:sites:import */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function upload(): JSONResponse {
		return $this->respond(function () {
			$file = $this->request->getUploadedFile('file');
			if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
				throw new \InvalidArgumentException('Keine Datei empfangen.');
			}
			if ((int)($file['size'] ?? 0) > AdminSettingsService::MAX_UPLOAD) {
				throw new \InvalidArgumentException('Die Datei ist größer als 10 MB.');
			}
			return $this->settings->importUpload((string)file_get_contents((string)$file['tmp_name']));
		});
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function syncSource(string $source): JSONResponse {
		return $this->respond(fn () => $this->settings->syncSource($source));
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function autoSync(bool $enabled = true): JSONResponse {
		return $this->respond(fn () => $this->settings->setAutoSync($enabled));
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function contributions(string $source): JSONResponse {
		return $this->respond(fn () => $this->settings->contributions($source));
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function markReported(string $source): JSONResponse {
		return $this->respond(fn () => $this->settings->markReported($source));
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function feedbackList(): JSONResponse {
		return $this->respond(fn () => $this->feedback->open());
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function acceptFeedback(int $id, bool $apply = true, string $note = '', ?bool $upstream = null): JSONResponse {
		return $this->respond(fn () => $this->feedback->accept($id, (string)$this->userId, $apply, $note, $upstream) + ['state' => $this->settings->state()]);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function rejectFeedback(int $id, string $note = ''): JSONResponse {
		return $this->respond(function () use ($id, $note) {
			$this->feedback->reject($id, (string)$this->userId, $note);
			return ['state' => $this->settings->state()];
		});
	}
}
