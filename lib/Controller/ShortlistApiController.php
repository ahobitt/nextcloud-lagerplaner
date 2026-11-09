<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\ShortlistService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ShortlistApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private ShortlistService $shortlist,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse {
		return $this->respond(fn () => $this->shortlist->list($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function add(int $id, int $siteId = 0): JSONResponse {
		return $this->respond(function () use ($id, $siteId) {
			$this->shortlist->add($id, (string)$this->userId, $siteId);
			return ['ok' => true];
		}, Http::STATUS_CREATED);
	}

	/** @param array<string, mixed> $changes status, preferred, notes */
	#[NoAdminRequired]
	public function update(int $id, array $changes = []): JSONResponse {
		return $this->respond(function () use ($id, $changes) {
			$this->shortlist->update($id, (string)$this->userId, $changes);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->shortlist->remove($id, (string)$this->userId);
			return ['deleted' => $id];
		});
	}

	#[NoAdminRequired]
	public function adopt(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->shortlist->adopt($id, (string)$this->userId);
			return ['ok' => true];
		});
	}
}
