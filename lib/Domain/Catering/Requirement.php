<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

/** Ernährungsanforderungen aus der standardisierten Anmeldung (Konzept 8.4). */
enum Requirement: string {
	case Vegetarian = 'vegetarian';
	case Vegan = 'vegan';
	case OtherDiet = 'other_diet';
	case GlutenFree = 'gluten_free';
	case LactoseFree = 'lactose_free';
	case MilkFree = 'milk_free';
	case EggFree = 'egg_free';
	case TreeNutFree = 'tree_nut_free';
	case PeanutFree = 'peanut_free';
	case SoyFree = 'soy_free';
	case Other = 'other';

	/**
	 * Was eine Variante, die diese Anforderung erfüllt, zusätzlich erfüllt.
	 *
	 * Bewusst nur zwischen Ernährungsweisen: Eine vegane Variante ist auch vegetarisch.
	 * Freiheit von Allergenen oder Unverträglichkeiten wird nie abgeleitet, auch nicht
	 * aus „vegan“, weil eine solche Kennzeichnung keine Spurenfreiheit garantiert.
	 * Sie muss für jede Variante ausdrücklich nach Prüfung der Zutaten und der
	 * Zubereitung festgelegt werden (Konzept 9.9).
	 *
	 * @return list<self>
	 */
	public function implies(): array {
		return match ($this) {
			self::Vegan => [self::Vegetarian],
			default => [],
		};
	}

	/** Ernährungsweise (diet) oder Allergen beziehungsweise Unverträglichkeit (allergen). */
	public function kind(): string {
		return match ($this) {
			self::Vegetarian, self::Vegan, self::OtherDiet => 'diet',
			default => 'allergen',
		};
	}

	/** Anforderungen, die immer eine fachliche Prüfung durch die Küche brauchen. */
	public function needsManualCheck(): bool {
		return $this === self::Other || $this === self::OtherDiet;
	}

	public function label(): string {
		return match ($this) {
			self::Vegetarian => 'vegetarisch',
			self::Vegan => 'vegan',
			self::OtherDiet => 'andere Ernährungsweise',
			self::GlutenFree => 'glutenfrei',
			self::LactoseFree => 'laktosefrei',
			self::MilkFree => 'milcheiweißfrei',
			self::EggFree => 'eifrei',
			self::TreeNutFree => 'ohne Schalenfrüchte',
			self::PeanutFree => 'ohne Erdnüsse',
			self::SoyFree => 'sojafrei',
			self::Other => 'weitere Anforderung',
		};
	}

	/**
	 * Transitive Hülle: alles, was durch die angegebenen Anforderungen mit erfüllt ist.
	 *
	 * @param list<self> $requirements
	 * @return list<self>
	 */
	public static function closure(array $requirements): array {
		$result = [];
		$queue = $requirements;
		while ($queue !== []) {
			$current = array_shift($queue);
			if (isset($result[$current->value])) {
				continue;
			}
			$result[$current->value] = $current;
			foreach ($current->implies() as $implied) {
				$queue[] = $implied;
			}
		}
		return array_values($result);
	}
}
