<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Recipes;

use OCA\Lagerplaner\Domain\Catering\Requirement;

/**
 * Allergene und Unverträglichkeiten, die ein Rezept laut Küche enthält.
 *
 * Laktose und Milcheiweiß sind getrennt, damit ein Rezept mit laktosefreier Milch
 * für eine Laktoseintoleranz taugt, aber nicht für eine Milcheiweißallergie.
 */
enum Allergen: string {
	case Gluten = 'gluten';
	case Lactose = 'lactose';
	case MilkProtein = 'milk_protein';
	case Egg = 'egg';
	case TreeNuts = 'tree_nuts';
	case Peanuts = 'peanuts';
	case Soy = 'soy';
	case Other = 'other';

	public function label(): string {
		return match ($this) {
			self::Gluten => 'Gluten',
			self::Lactose => 'Laktose',
			self::MilkProtein => 'Milcheiweiß',
			self::Egg => 'Ei',
			self::TreeNuts => 'Schalenfrüchte',
			self::Peanuts => 'Erdnüsse',
			self::Soy => 'Soja',
			self::Other => 'weitere Allergene',
		};
	}

	/** Die Anforderung, die durch dieses Allergen verletzt wird. */
	public function violates(): Requirement {
		return match ($this) {
			self::Gluten => Requirement::GlutenFree,
			self::Lactose => Requirement::LactoseFree,
			self::MilkProtein => Requirement::MilkFree,
			self::Egg => Requirement::EggFree,
			self::TreeNuts => Requirement::TreeNutFree,
			self::Peanuts => Requirement::PeanutFree,
			self::Soy => Requirement::SoyFree,
			self::Other => Requirement::Other,
		};
	}

	/** Schlüssel der schweren Allergien (Konzept 9.10) zum Rezept-Allergen. */
	public static function fromSevereKey(string $key): ?self {
		return match ($key) {
			'gluten' => self::Gluten,
			'milk' => self::MilkProtein,
			'egg' => self::Egg,
			'tree_nuts' => self::TreeNuts,
			'peanuts' => self::Peanuts,
			'soy' => self::Soy,
			'other' => self::Other,
			default => null,
		};
	}
}
