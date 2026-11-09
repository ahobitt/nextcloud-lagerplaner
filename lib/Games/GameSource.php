<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Games;

use OCA\Lagerplaner\Domain\Games\Game;
use OCA\Lagerplaner\Domain\Games\GameLicense;
use OCA\Lagerplaner\Service\GamesUnavailableException;

/** Adapter für eine externe Spielequelle (Konzept 17.3). */
interface GameSource {
	public function key(): string;

	public function license(): GameLicense;

	/**
	 * @return array{games: list<Game>, skipped: int, totalPages: int}
	 * @throws GamesUnavailableException
	 */
	public function fetchPage(int $page): array;
}
