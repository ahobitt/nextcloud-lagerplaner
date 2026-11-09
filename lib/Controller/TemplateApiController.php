<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\TemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class TemplateApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private TemplateService $templates,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(fn () => $this->templates->list((string)$this->userId));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->respond(fn () => $this->templates->get($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function saveItem(int $programItemId = 0, string $name = '', bool $shared = false, ?int $replaceId = null): JSONResponse {
		return $this->respond(fn () => $this->templates->saveItem((string)$this->userId, $programItemId, $name, $shared, $replaceId), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function saveDay(int $campId = 0, string $day = '', string $name = '', bool $shared = false, ?int $replaceId = null): JSONResponse {
		return $this->respond(fn () => $this->templates->saveDay((string)$this->userId, $campId, $day, $name, $shared, $replaceId), Http::STATUS_CREATED);
	}

	/** @param array<string, mixed> $changes name, shared */
	#[NoAdminRequired]
	public function update(int $id, array $changes = []): JSONResponse {
		return $this->respond(function () use ($id, $changes) {
			$this->templates->update((string)$this->userId, $id, $changes);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->templates->delete((string)$this->userId, $id);
			return ['deleted' => $id];
		});
	}

	#[NoAdminRequired]
	public function apply(int $id, int $campId = 0, string $day = '', string $startTime = ''): JSONResponse {
		return $this->respond(fn () => $this->templates->apply((string)$this->userId, $id, $campId, $day, $startTime));
	}
}
