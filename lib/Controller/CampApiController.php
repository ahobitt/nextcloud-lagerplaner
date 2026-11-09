<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\CampService;
use OCA\Lagerplaner\Service\PlanningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class CampApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private CampService $camps,
		private PlanningService $planning,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = (string)$this->userId;
		return $this->respond(fn () => array_map(
			static fn ($camp): array => $camp->jsonSerialize() + ['isOwner' => $camp->getOwnerUid() === $uid],
			$this->camps->listForUser($uid),
		));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$camp = $this->camps->getForUser($id, (string)$this->userId);
			return [
				'camp' => $camp,
				'groups' => $this->camps->groups($id),
				'access' => $this->camps->accessFor($camp, (string)$this->userId),
			];
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->camps->delete($id, (string)$this->userId);
			return ['deleted' => $id];
		});
	}

	#[NoAdminRequired]
	public function program(int $id): JSONResponse {
		return $this->respond(fn () => $this->planning->program($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function deletedProgram(int $id): JSONResponse {
		return $this->respond(fn () => $this->planning->deletedProgram($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function people(int $id): JSONResponse {
		return $this->respond(fn () => $this->camps->people($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function programConflicts(int $id): JSONResponse {
		return $this->respond(fn () => (object)$this->planning->programConflicts($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function programGames(int $id): JSONResponse {
		return $this->respond(fn () => $this->planning->programGames($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function tasks(int $id): JSONResponse {
		return $this->respond(fn () => $this->planning->tasks($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function meals(int $id): JSONResponse {
		return $this->respond(fn () => $this->planning->meals($id, (string)$this->userId));
	}
}
