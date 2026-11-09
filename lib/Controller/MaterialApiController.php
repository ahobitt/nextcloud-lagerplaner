<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\MaterialService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class MaterialApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private MaterialService $material,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse {
		return $this->respond(fn () => $this->material->overview($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function create(int $id, array $material = []): JSONResponse {
		return $this->respond(fn () => $this->material->create($id, (string)$this->userId, $material), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id, array $material = []): JSONResponse {
		return $this->respond(function () use ($id, $material) {
			$this->material->update($id, (string)$this->userId, $material);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function status(int $id, string $status = ''): JSONResponse {
		return $this->respond(function () use ($id, $status) {
			$this->material->setStatus($id, (string)$this->userId, $status);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->material->delete($id, (string)$this->userId);
			return ['deleted' => $id];
		});
	}

	#[NoAdminRequired]
	public function addUse(int $id, mixed $programItemId = null, mixed $quantity = null): JSONResponse {
		return $this->respond(function () use ($id, $programItemId, $quantity) {
			$this->material->addUse($id, (string)$this->userId, $programItemId, $quantity);
			return ['ok' => true];
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function updateUse(int $id, mixed $quantity = null): JSONResponse {
		return $this->respond(function () use ($id, $quantity) {
			$this->material->updateUse($id, (string)$this->userId, $quantity);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function removeUse(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->material->removeUse($id, (string)$this->userId);
			return ['deleted' => $id];
		});
	}

	#[NoAdminRequired]
	public function suggestion(int $id, string $ref = '', bool $take = true): JSONResponse {
		return $this->respond(function () use ($id, $ref, $take) {
			$take
				? $this->material->takeSuggestion($id, (string)$this->userId, $ref)
				: $this->material->dismissSuggestion($id, (string)$this->userId, $ref);
			return ['ok' => true];
		});
	}
}
