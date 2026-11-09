<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

use OCA\Lagerplaner\Domain\Recipes\Diet;
use OCA\Lagerplaner\Domain\Recipes\Recipe;
use OCA\Lagerplaner\Domain\Recipes\RecipeCompatibility;

/**
 * Einfacher Speiseplan-Vorschlag mit Gerichten, die fast immer gehen.
 *
 * Jede Mahlzeit bekommt ein Gericht für alle. Mittags wechseln sich warme Gerichte ab,
 * abends gibt es Abendbrot, wenn es mittags warm war, sonst ein weiteres warmes Gericht;
 * unterwegs ein Lunchpaket. Personen, für die das Gericht nicht passt (etwa glutenfrei),
 * bekommen eine eigene Variante mit dem ersten passenden Gericht. Die Küche ändert
 * danach, was sie möchte; Allergenangaben bleiben ungeprüft, bis sie bestätigt sind.
 */
final class MealPlanProposal {
	public const WARM = ['spaghetti_tomatensosse', 'gemuesecurry', 'wraps', 'linseneintopf', 'gemuesereis', 'chili_sin_carne', 'gemueseeintopf', 'kartoffelsuppe'];
	public const BREAKFAST = ['fruehstueck'];
	public const SUPPER = ['abendbrot'];
	public const PORTABLE = ['lunchpaket'];
	public const SNACK = ['obst_snack'];
	/** Ausweichgerichte für eigene Varianten, in dieser Reihenfolge */
	public const ALTERNATIVES = ['gemuesereis', 'gemuesecurry', 'chili_sin_carne', 'gemueseeintopf', 'kartoffelsuppe', 'linseneintopf', 'brotzeit_glutenfrei', 'obst_snack'];

	/** @return list<string> alle Grundrezepte, die der Vorschlag braucht */
	public static function templates(): array {
		return array_values(array_unique([...self::WARM, ...self::BREAKFAST, ...self::SUPPER, ...self::PORTABLE, ...self::SNACK, ...self::ALTERNATIVES]));
	}

	/**
	 * Wählt für jede Mahlzeit ein Grundrezept.
	 *
	 * @param list<array{day: string, type: string, portable: bool}> $meals nach Tag und Uhrzeit sortiert
	 * @return list<string> Grundrezept je Mahlzeit, in derselben Reihenfolge
	 */
	public function choose(array $meals): array {
		$warmLunchDays = [];
		foreach ($meals as $meal) {
			if ($meal['type'] === 'lunch' && !$meal['portable']) {
				$warmLunchDays[$meal['day']] = true;
			}
		}
		$warm = 0;
		$keys = [];
		foreach ($meals as $meal) {
			$keys[] = match (true) {
				$meal['portable'] => self::PORTABLE[0],
				$meal['type'] === 'breakfast' => self::BREAKFAST[0],
				$meal['type'] === 'snack' => self::SNACK[0],
				$meal['type'] === 'dinner' && isset($warmLunchDays[$meal['day']]) => self::SUPPER[0],
				default => self::WARM[$warm++ % count(self::WARM)],
			};
		}
		return $keys;
	}

	/**
	 * Plan einer Mahlzeit: Gericht für alle und eigene Varianten für die, denen es nicht passt.
	 *
	 * @param array<int, Recipe> $recipes Rezepte des Lagers nach ID
	 * @param list<int> $alternativeIds Ausweichrezepte in bevorzugter Reihenfolge
	 * @return array<string, mixed> Daten für MealVariantPlanInput::fromArray
	 */
	public function plan(int $mainId, array $recipes, array $alternativeIds, DietaryProfile $profile, ?MealVariantPlanInput $existing = null): array {
		$main = $recipes[$mainId];
		$everyoneKey = $existing?->everyone ?? 'alle';
		$data = $existing === null || $existing->isEmpty() ? ['variants' => [], 'assignments' => []] : $existing->toArray();
		$variants = array_values(array_filter($data['variants'], static fn (array $v): bool => $v['key'] !== $everyoneKey));
		$everyone = ['key' => $everyoneKey, 'label' => 'Alle', 'satisfies' => self::dietPromise($main), 'recipeId' => $mainId];
		$assignments = array_filter($data['assignments'], static fn (string $variant): bool => $variant !== $everyoneKey);

		// Wer zur Variante für alle nicht passt und noch keiner anderen Variante zugeordnet ist, bekommt eine eigene.
		$everyoneVariant = new MenuVariant($everyoneKey, 'Alle', array_map(static fn (string $r): Requirement => Requirement::from($r), $everyone['satisfies']));
		$compatibility = new RecipeCompatibility();
		foreach ($profile->combinations() as $combination) {
			if ($combination->count < 1 || $combination->isUnresolved() || isset($assignments[$combination->key]) || $everyoneVariant->missingFor($combination) === []) {
				continue;
			}
			$existingKey = null;
			foreach ($variants as $variant) {
				if ($variant['satisfies'] === array_map(static fn (Requirement $r): string => $r->value, $combination->requirements)) {
					$existingKey = $variant['key'];
				}
			}
			if ($existingKey === null) {
				if (count($variants) + 1 >= MealVariantPlanInput::MAX_VARIANTS) {
					continue;
				}
				$satisfies = array_map(static fn (Requirement $r): string => $r->value, $combination->requirements);
				$candidate = new MenuVariant('x', 'x', $combination->requirements);
				$recipeId = null;
				foreach ([$mainId, ...$alternativeIds] as $id) {
					// Ausgewählt wird nach den Angaben im Rezept; ob sie stimmen, bestätigt die Küche.
					if (isset($recipes[$id]) && $compatibility->conflicts($recipes[$id], $candidate, true) === []) {
						$recipeId = $id;
						break;
					}
				}
				$existingKey = 'v' . (count($variants) + 1);
				$variants[] = ['key' => $existingKey, 'label' => $combination->label(), 'satisfies' => $satisfies, 'recipeId' => $recipeId];
			}
			$assignments[$combination->key] = $existingKey;
		}

		return ['variants' => [$everyone, ...$variants], 'assignments' => $assignments, 'everyone' => $everyoneKey];
	}

	/** @return list<string> was das Gericht für alle zusagen kann: nur die Ernährungsweise */
	private static function dietPromise(Recipe $recipe): array {
		return match ($recipe->diet) {
			Diet::Vegan => [Requirement::Vegan->value],
			Diet::Vegetarian => [Requirement::Vegetarian->value],
			default => [],
		};
	}
}
