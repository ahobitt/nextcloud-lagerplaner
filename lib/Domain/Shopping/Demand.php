<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Shopping;

use OCA\Lagerplaner\Domain\Recipes\Recipe;

/** Ein Rezept wird an einem Tag in einer Anzahl Standardportionen gekocht. */
final class Demand {
	public function __construct(
		public readonly string $day,
		public readonly string $mealType,
		public readonly Recipe $recipe,
		public readonly float $portions,
		/**
		 * Allergen-Zusagen der Menüvariante, für die gekocht wird (Requirement-Werte, zum Beispiel
		 * gluten_free). Zutaten dafür sind eigene Produkte und werden nie mit anderen zusammengelegt.
		 *
		 * @var list<string>
		 */
		public readonly array $promises = [],
	) {
	}
}
