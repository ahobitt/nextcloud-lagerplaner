<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Domain\Games\GameQuery;
use OCA\Lagerplaner\Service\GameCatalogService;
use OCA\Lagerplaner\Service\GamesUnavailableException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class GameApiController extends Controller {
	use JsonErrors;

	public function __construct(IRequest $request, private GameCatalogService $catalog) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function search(string $q = '', string $age = '', string $duration = '', string $groupSize = '', string $environment = '', string $type = '', int $offset = 0, string $unknownAge = '1'): JSONResponse {
		// Kein compact() in der Pfeilfunktion: Sie übernimmt nur direkt genannte Variablen.
		$criteria = ['q' => $q, 'age' => $age, 'duration' => $duration, 'groupSize' => $groupSize, 'environment' => $environment, 'type' => $type, 'unknownAge' => $unknownAge];
		return $this->games(fn () => ['enabled' => true] + $this->catalog->search(GameQuery::fromArray($criteria), 30, max(0, $offset)));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->games(fn () => $this->catalog->get($id)->detailArray() + ['license' => $this->catalog->license()->toArray()]);
	}

	#[NoAdminRequired]
	public function facets(): JSONResponse {
		return $this->games(fn () => $this->catalog->facets());
	}

	/** Nur für die Administration: Katalog sofort abgleichen. */
	public function sync(): JSONResponse {
		return $this->games(fn () => $this->catalog->sync());
	}

	private function games(\Closure $callback): JSONResponse {
		try {
			return $this->respond($callback);
		} catch (GamesUnavailableException $e) {
			return new JSONResponse(['enabled' => $this->catalog->isEnabled(), 'message' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}
}
