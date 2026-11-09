<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Wizard;

/**
 * Prüft Programmpunkte und Mahlzeiten auf zeitliche Überschneidungen je Gruppe
 * (Konzept 6.3). Mahlzeiten gelten für alle; unterwegs eingenommene Mahlzeiten
 * überschneiden sich gewollt mit der Aktivität und werden nicht geprüft.
 */
final class Timeline {
	/** Planungsdauer der Mahlzeiten in Minuten. */
	public const MEAL_MINUTES = ['breakfast' => 30, 'lunch' => 30, 'snack' => 15, 'dinner' => 45];

	/**
	 * @param list<ProposedProgramItem> $items
	 * @param list<ProposedMeal> $meals
	 * @param list<string> $groupKeys Gruppen mit eigenen Programmpunkten; leer bedeutet eine gemeinsame Gruppe
	 * @return list<string> verständliche Beschreibung jeder Überschneidung
	 */
	public function conflicts(array $items, array $meals, array $groupKeys): array {
		$groups = $groupKeys === [] ? ['*'] : $groupKeys;
		$conflicts = [];

		foreach ($groups as $group) {
			$entries = [];
			foreach ($items as $item) {
				if ($item->groups === [] || in_array($group, $item->groups, true)) {
					$entries[] = [$item->day, self::minutes($item->startTime), $item->durationMinutes, $item->title];
				}
			}
			foreach ($meals as $meal) {
				if (!$meal->portable) {
					$entries[] = [$meal->day, self::minutes($meal->time), self::MEAL_MINUTES[$meal->mealType] ?? 30, 'Mahlzeit ' . $meal->mealType];
				}
			}
			usort($entries, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

			for ($i = 1, $n = count($entries); $i < $n; $i++) {
				[$day, $start, , $title] = $entries[$i];
				for ($j = $i - 1; $j >= 0 && $entries[$j][0] === $day; $j--) {
					if ($entries[$j][1] + $entries[$j][2] > $start) {
						$conflicts[] = sprintf('%s %s: „%s“ überschneidet sich mit „%s“', $day, $group, $title, $entries[$j][3]);
					}
				}
			}
		}
		return array_values(array_unique($conflicts));
	}

	/** Minuten seit Mitternacht als „HH:MM“ */
	public static function time(int $minutes): string {
		return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
	}

	public static function minutes(string $time): int {
		[$h, $m] = array_map('intval', explode(':', $time));
		return $h * 60 + $m;
	}
}
