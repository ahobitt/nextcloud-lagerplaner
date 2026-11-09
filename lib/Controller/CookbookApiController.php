<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\CookbookService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class CookbookApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private CookbookService $cookbook,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function library(int $campId = 0): JSONResponse {
		return $this->respond(fn () => $this->cookbook->library((string)$this->userId, $campId > 0 ? $campId : null));
	}

	#[NoAdminRequired]
	public function preview(int $id): JSONResponse {
		return $this->respond(fn () => $this->cookbook->preview((string)$this->userId, $id));
	}

	#[NoAdminRequired]
	public function import(int $id, int $cookbookId = 0): JSONResponse {
		return $this->respond(fn () => $this->cookbook->import($id, (string)$this->userId, $cookbookId), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function updates(int $id): JSONResponse {
		return $this->respond(fn () => (object)$this->cookbook->updates($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function refresh(int $id): JSONResponse {
		return $this->respond(fn () => $this->cookbook->refresh($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function export(int $id): JSONResponse {
		return $this->respond(fn () => $this->cookbook->export($id, (string)$this->userId), Http::STATUS_CREATED);
	}
}
