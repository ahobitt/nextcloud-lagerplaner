<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\CateringService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class CateringApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private CateringService $catering,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function overview(int $id): JSONResponse {
		return $this->respond(fn () => $this->catering->overview($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function saveNeeds(int $id, array $combinations = [], array $severe = []): JSONResponse {
		$data = ['combinations' => $combinations, 'severe' => $severe];
		return $this->respond(fn () => $this->catering->saveNeeds($id, (string)$this->userId, $data));
	}

	#[NoAdminRequired]
	public function mealPlan(int $id): JSONResponse {
		return $this->respond(fn () => $this->catering->mealPlan($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function saveMealPlan(int $id, array $variants = [], array $assignments = []): JSONResponse {
		$data = ['variants' => $variants, 'assignments' => $assignments];
		return $this->respond(fn () => $this->catering->saveMealPlan($id, (string)$this->userId, $data));
	}

	#[NoAdminRequired]
	public function copyMealPlan(int $id, string $scope = 'same_type', bool $overwrite = false): JSONResponse {
		return $this->respond(fn () => ['changed' => $this->catering->copyMealPlan($id, (string)$this->userId, $scope, $overwrite)]);
	}

	#[NoAdminRequired]
	public function cancelMeal(int $id, bool $cancelled = true): JSONResponse {
		return $this->respond(function () use ($id, $cancelled) {
			$this->catering->setCancelled($id, (string)$this->userId, $cancelled);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function proposeMenu(int $id): JSONResponse {
		return $this->respond(fn () => $this->catering->proposeMealPlan($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function setDish(int $id, int $recipeId = 0): JSONResponse {
		return $this->respond(fn () => $this->catering->setMainDish($id, (string)$this->userId, $recipeId));
	}

	#[NoAdminRequired]
	public function dishes(int $id): JSONResponse {
		return $this->respond(fn () => (object)$this->catering->dishes($id, (string)$this->userId));
	}
}
