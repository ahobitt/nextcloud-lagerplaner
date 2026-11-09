<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Recipes;

use OCA\Lagerplaner\Domain\Catering\MenuVariant;
use OCA\Lagerplaner\Domain\Catering\Requirement;

/**
 * Passt ein Rezept zu dem, was die Küche für eine Menüvariante zusagt?
 *
 * Allergenangaben haben drei Zustände: nicht geprüft, geprüft ohne die betreffenden
 * Allergene, geprüft mit Allergenen. Fehlende Angaben bei einem ungeprüften Rezept
 * bedeuten nicht „enthält nichts“; ein solches Rezept kann deshalb keine Variante
 * tragen, die Freiheit von einem Allergen zusagt.
 */
final class RecipeCompatibility {
	/** @return list<string> Widersprüche als verständliche Sätze */
	public function conflicts(Recipe $recipe, MenuVariant $variant, bool $allergensChecked = false): array {
		$conflicts = [];
		$allergenPromises = array_values(array_filter($variant->declared, static fn (Requirement $r): bool => $r->kind() === 'allergen'));
		if (!$allergensChecked && $allergenPromises !== []) {
			$conflicts[] = sprintf('Die Allergenangaben von „%s“ sind nicht geprüft. „%s“ darf damit nicht als %s gelten.',
				$recipe->title, $variant->label, implode(', ', array_map(static fn (Requirement $r): string => $r->label(), $allergenPromises)));
		}
		foreach ($variant->declared as $requirement) {
			if ($requirement === Requirement::Vegetarian && $recipe->diet === Diet::Omnivore) {
				$conflicts[] = sprintf('„%s“ ist als vegetarisch markiert, das Rezept „%s“ ist es nicht.', $variant->label, $recipe->title);
			}
			if ($requirement === Requirement::Vegan && $recipe->diet !== Diet::Vegan) {
				$conflicts[] = sprintf('„%s“ ist als vegan markiert, das Rezept „%s“ ist es nicht.', $variant->label, $recipe->title);
			}
		}
		foreach ($recipe->contains as $allergen) {
			if ($allergen !== Allergen::Other && in_array($allergen->violates(), $variant->satisfies, true)) {
				$conflicts[] = sprintf('„%s“ ist als %s markiert, das Rezept „%s“ enthält aber %s.',
					$variant->label, $allergen->violates()->label(), $recipe->title, $allergen->label());
			}
		}
		return $conflicts;
	}
}
