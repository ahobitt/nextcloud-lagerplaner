<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Games;

/** Ein Spiel aus einer externen Quelle, in das interne Format übersetzt. */
final class Game {
	/**
	 * @param list<string> $types Spielarten
	 * @param list<string> $environments zum Beispiel Drinnen, Draußen
	 * @param list<string> $materials Materialkategorien, keine Mengen
	 * @param list<string> $suitability Hinweise zur Gruppenzusammensetzung
	 * @param list<string> $knownAs alternative Namen
	 */
	public function __construct(
		public readonly string $source,
		public readonly int $sourceId,
		public readonly string $title,
		public readonly string $summary,
		public readonly string $descriptionHtml,
		public readonly string $link,
		public readonly string $sourceModified,
		public readonly ?int $minAge,
		public readonly ?int $durationMinutes,
		public readonly ?int $groupMin,
		/** null bedeutet nach oben offen */
		public readonly ?int $groupMax,
		public readonly array $types,
		public readonly array $environments,
		public readonly array $materials,
		public readonly array $suitability,
		public readonly array $knownAs,
	) {
	}
}
