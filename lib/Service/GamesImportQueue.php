<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\BackgroundJob\ImportGames;
use OCP\BackgroundJob\IJobList;

/** Stellt einen Abgleich des Spielekatalogs als Hintergrundaufgabe ein (höchstens einen zugleich). */
class GamesImportQueue {
	public function __construct(private IJobList $jobs) {
	}

	public function request(): void {
		if (!$this->pending()) {
			$this->jobs->add(ImportGames::class);
		}
	}

	public function pending(): bool {
		return $this->jobs->has(ImportGames::class, null);
	}
}
