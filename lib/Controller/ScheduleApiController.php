<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\PlanningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Verschieben im Tagesablauf mit Zeitanpassung, Rückgängig und Essenszeiten. */
class ScheduleApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private PlanningService $planning,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function move(int $id, string $kind = '', int $entryId = 0, string $day = '', string $time = ''): JSONResponse {
		return $this->respond(fn () => $this->planning->moveEntry($id, (string)$this->userId, $kind, $entryId, $day, $time));
	}

	#[NoAdminRequired]
	public function restore(int $id, array $changes = []): JSONResponse {
		return $this->respond(function () use ($id, $changes) {
			$this->planning->restoreTimes($id, (string)$this->userId, $changes);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function mealTimes(int $id, string $type = '', string $time = ''): JSONResponse {
		return $this->respond(fn () => $this->planning->setMealTimes($id, (string)$this->userId, $type, $time));
	}
}
