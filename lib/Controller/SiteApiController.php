<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Domain\Sites\SiteQuery;
use OCA\Lagerplaner\Service\SiteDirectoryService;
use OCA\Lagerplaner\Service\SiteFeedbackService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SiteApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private SiteDirectoryService $directory,
		private SiteFeedbackService $feedback,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function search(string $q = '', string $state = '', string $type = '', string $persons = '', string $beds = '', string $features = '', string $unknown = '1', string $distance = '', int $offset = 0, string $home = '', string $closed = '0'): JSONResponse {
		$criteria = ['q' => $q, 'state' => $state, 'type' => $type, 'persons' => $persons, 'beds' => $beds, 'features' => $features, 'unknown' => $unknown, 'distance' => $distance, 'closed' => $closed];
		return $this->respond(fn () => $this->directory->search((string)$this->userId, SiteQuery::fromArray($criteria), 50, max(0, $offset), $home === '' ? null : $home));
	}

	#[NoAdminRequired]
	public function show(int $id, string $home = ''): JSONResponse {
		return $this->respond(fn () => $this->directory->get($id, (string)$this->userId, $home === '' ? null : $home));
	}

	/** Standort des eigenen Stammes ändern (Gruppenadministration des Stammes) */
	#[NoAdminRequired]
	public function updateHome(string $groupId, string $input = ''): JSONResponse {
		return $this->respond(fn () => $this->directory->updateGroupHome((string)$this->userId, $groupId, $input));
	}

	#[NoAdminRequired]
	public function create(array $site = []): JSONResponse {
		return $this->respond(fn () => $this->directory->createManual((string)$this->userId, $site), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id, array $site = []): JSONResponse {
		return $this->respond(function () use ($id, $site) {
			$this->directory->updateManual($id, (string)$this->userId, $site);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->directory->deleteManual($id, (string)$this->userId);
			return ['deleted' => $id];
		});
	}

	/** Angaben eines Platzes ergänzen oder korrigieren; bei importierten Plätzen die Administration */
	#[NoAdminRequired]
	public function correct(int $id, array $site = [], string $evidenceUrl = ''): JSONResponse {
		return $this->respond(fn () => $this->directory->correct($id, (string)$this->userId, $site, $evidenceUrl === '' ? null : $evidenceUrl));
	}

	/** „Feedback / Angaben ergänzen“ zu einem Platz */
	#[NoAdminRequired]
	public function feedback(int $id, string $kind = '', array $values = [], string $message = ''): JSONResponse {
		return $this->respond(fn () => $this->feedback->submit((string)$this->userId, $id, $kind, $values, $message), Http::STATUS_CREATED);
	}

	/** Ein Lagerplatz fehlt im Verzeichnis */
	#[NoAdminRequired]
	public function missingSite(array $values = [], string $message = ''): JSONResponse {
		return $this->respond(fn () => $this->feedback->submit((string)$this->userId, null, 'new_site', $values, $message), Http::STATUS_CREATED);
	}
}
