<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Recipes;

/**
 * Rechnet ein Rezept auf eine Anzahl Standardportionen hoch (Konzept 9.7).
 *
 * Gewichte und Volumen werden in sinnvolle Einheiten umgerechnet, Löffelmaße auf
 * halbe Löffel gerundet. Zählbares (Zwiebeln, Dosen) wird aufgerundet; dann ist
 * das Ergebnis als gerundet markiert, damit nichts stillschweigend verändert wird
 * (Konzept 10.2).
 */
final class RecipeScaler {
	/**
	 * @return list<array{name: string, quantity: ?float, unit: string, note: string, exact: ?float, rounded: bool}>
	 */
	public function scale(Recipe $recipe, float $portions): array {
		if ($portions <= 0) {
			throw new \InvalidArgumentException('Die Portionenzahl muss größer als 0 sein.');
		}
		$factor = $portions / $recipe->servings;
		return array_map(fn (Ingredient $i): array => $this->scaleIngredient($i, $factor), $recipe->ingredients);
	}

	/** @return array{name: string, quantity: ?float, unit: string, note: string, exact: ?float, rounded: bool} */
	private function scaleIngredient(Ingredient $ingredient, float $factor): array {
		$result = ['name' => $ingredient->name, 'quantity' => null, 'unit' => $ingredient->unit->value, 'note' => $ingredient->note, 'exact' => null, 'rounded' => false];
		if ($ingredient->quantity === null) {
			return $result;
		}
		$exact = $ingredient->quantity * $factor;
		$unit = $ingredient->unit;

		if ($unit === Unit::Kilogram || $unit === Unit::Gram) {
			return $this->metric($result, $unit === Unit::Kilogram ? $exact * 1000 : $exact, Unit::Gram, Unit::Kilogram);
		}
		if ($unit === Unit::Litre || $unit === Unit::Millilitre) {
			return $this->metric($result, $unit === Unit::Litre ? $exact * 1000 : $exact, Unit::Millilitre, Unit::Litre);
		}
		if ($unit->isSpoon()) {
			$quantity = max(0.5, round($exact * 2) / 2);
			return ['quantity' => $quantity, 'exact' => round($exact, 2), 'rounded' => abs($quantity - $exact) > 0.25] + $result;
		}
		// Zählbares: aufrunden, kleine Rechenungenauigkeiten nicht als Rest werten.
		$quantity = (float)ceil($exact - 0.01);
		$quantity = max(1.0, $quantity);
		return ['quantity' => $quantity, 'exact' => round($exact, 2), 'rounded' => abs($quantity - $exact) > 0.01] + $result;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array{name: string, quantity: ?float, unit: string, note: string, exact: ?float, rounded: bool}
	 */
	private function metric(array $result, float $base, Unit $small, Unit $large): array {
		if ($base >= 1000) {
			$quantity = round($base / 1000, 2);
			return ['quantity' => $quantity, 'unit' => $large->value, 'exact' => $quantity, 'rounded' => false] + $result;
		}
		$quantity = max(1.0, round($base));
		return ['quantity' => $quantity, 'unit' => $small->value, 'exact' => $quantity, 'rounded' => false] + $result;
	}
}
