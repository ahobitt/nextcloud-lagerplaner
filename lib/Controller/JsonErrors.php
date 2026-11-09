<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use Closure;
use OCA\Lagerplaner\Domain\Catering\InvalidCateringInput;
use OCA\Lagerplaner\Domain\Wizard\InvalidWizardInput;
use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Service\NotFoundException;
use OCA\Lagerplaner\Service\PreviewOutdatedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/** Übersetzt fachliche Fehler in HTTP-Antworten mit verständlicher Meldung. */
trait JsonErrors {
	protected function respond(Closure $callback, int $status = Http::STATUS_OK): JSONResponse {
		try {
			return new JSONResponse($callback(), $status);
		} catch (InvalidWizardInput $e) {
			return new JSONResponse(['message' => 'Bitte prüfe die markierten Angaben.', 'errors' => $e->errors], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (InvalidCateringInput $e) {
			return new JSONResponse(['message' => 'Bitte prüfe die markierten Angaben.', 'errors' => $e->errors], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (PreviewOutdatedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
