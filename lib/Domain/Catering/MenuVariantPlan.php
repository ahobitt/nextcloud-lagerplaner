<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

/**
 * Prüft die Zuordnung von Bedarfskombinationen zu Menüvarianten einer Mahlzeit (Konzept 9.8).
 *
 * Jede Kombination mit Personen muss genau einer Variante zugeordnet sein, und
 * die Variante muss alle Anforderungen der Kombination erfüllen. Weil die
 * Zuordnung als Abbildung Kombination => Variante gespeichert wird, kann eine
 * Kombination strukturell nicht doppelt gezählt werden.
 */
final class MenuVariantPlan {
	/**
	 * @param list<MenuVariant> $variants
	 * @param array<string, string> $assignments Kombinationsschlüssel => Variantenschlüssel
	 */
	public function evaluate(DietaryProfile $profile, array $variants, array $assignments): MenuVariantResult {
		$variantsByKey = [];
		foreach ($variants as $variant) {
			$variantsByKey[$variant->key] = $variant;
		}

		$headcounts = array_fill_keys(array_keys($variantsByKey), 0);
		$issues = [];

		foreach ($assignments as $combinationKey => $variantKey) {
			$combinationKey = (string)$combinationKey;
			$combination = $profile->get($combinationKey);
			if ($combination === null) {
				$issues[] = new PlanIssue(PlanIssue::UNKNOWN_COMBINATION, PlanIssue::ERROR,
					'Zugeordnete Bedarfskombination gibt es nicht mehr.', $combinationKey, $variantKey);
			} elseif (!isset($variantsByKey[$variantKey])) {
				$issues[] = new PlanIssue(PlanIssue::UNKNOWN_VARIANT, PlanIssue::ERROR,
					'„' . $combination->label() . '“ ist einer Variante zugeordnet, die es nicht gibt.', $combinationKey, $variantKey);
			}
		}

		foreach ($profile->combinations() as $combination) {
			$variantKey = $assignments[$combination->key] ?? null;
			if ($variantKey === null || !isset($variantsByKey[$variantKey])) {
				if ($variantKey === null) {
					$issues[] = new PlanIssue(PlanIssue::UNASSIGNED, PlanIssue::ERROR,
						'„' . $combination->label() . '“ (' . $combination->count . ') ist noch keiner Menüvariante zugeordnet.',
						$combination->key);
				}
				continue;
			}

			$variant = $variantsByKey[$variantKey];
			$headcounts[$variantKey] += $combination->count;

			$missing = $variant->missingFor($combination);
			if ($missing !== []) {
				$issues[] = new PlanIssue(PlanIssue::NOT_SATISFIED, PlanIssue::ERROR,
					'„' . $variant->label . '“ ist nicht ' . implode(', ', array_map(static fn (Requirement $r): string => $r->label(), $missing))
					. ', wird aber für „' . $combination->label() . '“ eingeplant.',
					$combination->key, $variantKey);
			}
			if ($combination->isUnresolved()) {
				$issues[] = new PlanIssue(PlanIssue::UNRESOLVED, PlanIssue::WARNING,
					$combination->count . ' Person(en): ' . $combination->label() . '. Bitte vor dem Lager klären.',
					$combination->key, $variantKey);
			}
			if ($combination->needsManualCheck()) {
				$issues[] = new PlanIssue(PlanIssue::MANUAL_CHECK, PlanIssue::WARNING,
					'„' . $combination->label() . '“ muss die Küche fachlich prüfen.',
					$combination->key, $variantKey);
			}
		}

		return new MenuVariantResult($headcounts, $issues);
	}
}
