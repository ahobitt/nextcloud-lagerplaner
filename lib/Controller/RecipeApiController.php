<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\RecipeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class RecipeApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private RecipeService $recipes,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse {
		return $this->respond(fn () => $this->recipes->list($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function show(int $id, string $portions = ''): JSONResponse {
		$value = is_numeric($portions) && (float)$portions > 0 && (float)$portions <= 5000 ? (float)$portions : null;
		return $this->respond(fn () => $this->recipes->get($id, (string)$this->userId, $value));
	}

	#[NoAdminRequired]
	public function create(int $id, array $recipe = [], bool $allergensChecked = false): JSONResponse {
		$data = ['allergensChecked' => $allergensChecked] + $recipe;
		return $this->respond(fn () => $this->recipes->create($id, (string)$this->userId, $data), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id, array $recipe = [], bool $allergensChecked = false): JSONResponse {
		$data = ['allergensChecked' => $allergensChecked] + $recipe;
		return $this->respond(fn () => $this->recipes->update($id, (string)$this->userId, $data));
	}

	#[NoAdminRequired]
	public function confirmAllergens(int $id): JSONResponse {
		return $this->respond(fn () => $this->recipes->confirmAllergens($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->recipes->delete($id, (string)$this->userId);
			return ['deleted' => $id];
		});
	}

	#[NoAdminRequired]
	public function library(int $id): JSONResponse {
		return $this->respond(fn () => $this->recipes->library($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function import(int $id, array $keys = []): JSONResponse {
		return $this->respond(fn () => ['imported' => $this->recipes->import($id, (string)$this->userId, $keys)]);
	}
}
