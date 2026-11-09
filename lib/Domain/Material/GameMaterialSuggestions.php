<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Material;

/**
 * Materialvorschläge aus eingeplanten Spielen (Konzept 11.3). Pfadfinder-Spiele.de liefert
 * nur Kategorien wie „Papier & Stifte“, keine Mengen; die Menge bleibt deshalb offen.
 */
final class GameMaterialSuggestions {
	/** Angaben, aus denen kein Material folgt. */
	private const NOTHING = ['nichts', 'kein material', 'keins'];

	/**
	 * @param list<array{programGameId: int, programItemId: int, gameTitle: string, materials: list<string>}> $plannedGames
	 * @param array<string, true> $taken bereits übernommene oder verworfene Vorschläge (Schlüssel)
	 * @return list<array{ref: string, programItemId: int, gameTitle: string, name: string, category: string}>
	 */
	public function suggest(array $plannedGames, array $taken): array {
		$result = [];
		foreach ($plannedGames as $game) {
			foreach ($game['materials'] as $material) {
				$name = trim($material);
				if ($name === '' || in_array(mb_strtolower($name), self::NOTHING, true)) {
					continue;
				}
				if (mb_strtolower($name) === 'diverses') {
					$name = 'Diverses laut Spielbeschreibung';
				}
				$ref = self::ref($game['programGameId'], $name);
				if (isset($taken[$ref])) {
					continue;
				}
				$result[] = [
					'ref' => $ref,
					'programItemId' => $game['programItemId'],
					'gameTitle' => $game['gameTitle'],
					'name' => $name,
					'category' => MaterialCategory::guess($name)->value,
				];
			}
		}
		return $result;
	}

	public static function ref(int $programGameId, string $name): string {
		return 'game:' . $programGameId . ':' . substr(sha1(mb_strtolower($name)), 0, 16);
	}
}
