<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\ExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Druckfertige Unterlagen; die Oberfläche öffnet sie im Druckdialog (PDF speichern). */
class ExportApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private ExportService $exports,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function show(int $id, string $type, string $param = ''): JSONResponse {
		return $this->respond(fn () => $this->exports->document($id, (string)$this->userId, $type, $param));
	}
}
