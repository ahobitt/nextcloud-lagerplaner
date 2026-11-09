<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\WizardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class WizardApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private WizardService $wizard,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function defaults(): JSONResponse {
		return $this->respond(fn () => $this->wizard->defaults());
	}

	#[NoAdminRequired]
	public function preview(array $input = []): JSONResponse {
		return $this->respond(fn () => $this->wizard->preview($input, (string)$this->userId)->toArray());
	}

	#[NoAdminRequired]
	public function create(array $input = []): JSONResponse {
		return $this->respond(fn () => $this->wizard->create((string)$this->userId, $input), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function lastInput(int $id): JSONResponse {
		return $this->respond(fn () => $this->wizard->lastInput($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function rerunPreview(int $id, array $input = []): JSONResponse {
		return $this->respond(fn () => $this->wizard->rerunPreview($id, (string)$this->userId, $input));
	}

	#[NoAdminRequired]
	public function rerunApply(int $id, array $input = [], string $token = '', array $groupDecisions = []): JSONResponse {
		return $this->respond(fn () => $this->wizard->rerunApply($id, (string)$this->userId, $input, $token, $groupDecisions));
	}
}
