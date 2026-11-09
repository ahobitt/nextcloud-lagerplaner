<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\PlanningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ItemApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private PlanningService $planning,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function createProgramItem(int $id, array $fields = []): JSONResponse {
		return $this->respond(fn () => $this->planning->createProgramItem($id, (string)$this->userId, $fields), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function restoreProgramItem(int $id): JSONResponse {
		return $this->respond(fn () => $this->planning->restoreProgramItem($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function createTask(int $id, array $fields = []): JSONResponse {
		return $this->respond(fn () => $this->planning->createTask($id, (string)$this->userId, $fields), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function deleteTask(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->planning->deleteTask($id, (string)$this->userId);
			return ['deleted' => $id];
		});
	}

	#[NoAdminRequired]
	public function claimTask(int $id): JSONResponse {
		return $this->respond(fn () => $this->planning->claimTask($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function attachGame(int $id, int $gameId = 0): JSONResponse {
		return $this->respond(fn () => $this->planning->attachGame($id, (string)$this->userId, $gameId), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function detachGame(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->planning->detachGame($id, (string)$this->userId);
			return ['deleted' => $id];
		});
	}

	#[NoAdminRequired]
	public function confirmProgramItem(int $id): JSONResponse {
		return $this->respond(fn () => $this->planning->confirmProgramItem($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function updateProgramItem(int $id, array $fields = []): JSONResponse {
		return $this->respond(fn () => $this->planning->updateProgramItem($id, (string)$this->userId, $fields));
	}

	#[NoAdminRequired]
	public function deleteProgramItem(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->planning->deleteProgramItem($id, (string)$this->userId);
			return ['deleted' => $id];
		});
	}

	#[NoAdminRequired]
	public function updateTask(int $id, array $fields = []): JSONResponse {
		return $this->respond(fn () => $this->planning->updateTask($id, (string)$this->userId, $fields));
	}
}
