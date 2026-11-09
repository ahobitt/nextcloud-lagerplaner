<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\TeamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class TeamApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private TeamService $team,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse {
		return $this->respond(fn () => $this->team->list($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function create(int $id, string $type = '', string $memberId = '', array $roles = []): JSONResponse {
		return $this->respond(fn () => $this->team->add($id, (string)$this->userId, $type, $memberId, $roles), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id, array $roles = []): JSONResponse {
		return $this->respond(fn () => $this->team->updateRoles($id, (string)$this->userId, $roles));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->team->remove($id, (string)$this->userId);
			return ['deleted' => $id];
		});
	}

	#[NoAdminRequired]
	public function search(int $id, string $q = ''): JSONResponse {
		return $this->respond(fn () => $this->team->search($id, (string)$this->userId, $q));
	}
}
