<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

/** Altersabhängige Portionsberechnung (Konzept 9.3, 9.7). */
final class PortionCalculator {
	/**
	 * @param array<string, int> $counts Gruppe => Personen
	 * @param array<string, float> $factors Gruppe => Portionsfaktor
	 */
	public function standardPortions(array $counts, array $factors): float {
		$sum = 0.0;
		foreach ($counts as $group => $count) {
			if ($count <= 0) {
				continue;
			}
			if (!isset($factors[$group])) {
				throw new \InvalidArgumentException('Für „' . $group . '“ fehlt ein Portionsfaktor.');
			}
			$sum += $count * $factors[$group];
		}
		return round($sum, 2);
	}

	/**
	 * Zutatenmenge für eine Anzahl Standardportionen, optional mit Sicherheitszuschlag in Prozent.
	 */
	public function ingredientQuantity(float $recipeQuantity, float $recipeServings, float $standardPortions, float $safetyMarginPercent = 0.0): float {
		if ($recipeServings <= 0) {
			throw new \InvalidArgumentException('Die Portionenzahl des Rezepts muss größer als 0 sein.');
		}
		if ($safetyMarginPercent < 0) {
			throw new \InvalidArgumentException('Der Sicherheitszuschlag darf nicht negativ sein.');
		}
		return round($recipeQuantity / $recipeServings * $standardPortions * (1 + $safetyMarginPercent / 100), 3);
	}
}
