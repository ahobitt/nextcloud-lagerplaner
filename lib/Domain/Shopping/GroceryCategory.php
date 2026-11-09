<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Shopping;

use OCA\Lagerplaner\Domain\Recipes\Unit;

/**
 * Warengruppen der Einkaufsliste (Konzept 10.1). Die Zuordnung erfolgt über Stichwörter
 * im Zutatennamen und ist ein Vorschlag; Unbekanntes landet unter „Sonstiges“.
 */
enum GroceryCategory: string {
	case Produce = 'produce';
	case Dairy = 'dairy';
	case Bakery = 'bakery';
	case Dry = 'dry';
	case Canned = 'canned';
	case Spices = 'spices';
	case Other = 'other';

	/** Stichwörter in Kleinschreibung; die erste passende Gruppe gewinnt. */
	private const KEYWORDS = [
		'spices' => ['salz', 'pfeffer', 'gewürz', 'paprikapulver', 'kreuzkümmel', 'chili', 'curry', 'zimt', 'oregano', 'majoran', 'muskat', 'lorbeer', 'kräuter', 'öl', 'essig', 'zucker', 'honig', 'brühe', 'hefe', 'tomatenmark', 'senf', 'puderzucker'],
		'canned' => ['dose', 'gehackte tomaten', 'kidneybohnen', 'kichererbsen', 'mais', 'kokosmilch', 'apfelmus'],
		'dairy' => ['milch', 'butter', 'sahne', 'käse', 'quark', 'joghurt', 'schmand', 'eier', 'ei '],
		'bakery' => ['brot', 'brötchen', 'fladenbrot', 'toast'],
		'dry' => ['mehl', 'reis', 'nudeln', 'spaghetti', 'linsen', 'haferflocken', 'couscous', 'bulgur', 'polenta', 'rosinen', 'grieß'],
		'produce' => ['kartoffel', 'zwiebel', 'knoblauch', 'möhre', 'karotte', 'lauch', 'paprikaschote', 'zucchini', 'ingwer', 'petersilie', 'apfel', 'äpfel', 'banane', 'obst', 'gemüse', 'tomate', 'gurke', 'salat', 'zitrone', 'brokkoli', 'spinat', 'kohl', 'pilz', 'champignon'],
	];

	public static function classify(string $name, Unit $unit): self {
		// Nur das Hauptwort vor dem Komma zählt: „Kartoffeln, mehligkochend“ ist Gemüse, kein Mehl.
		$text = ' ' . mb_strtolower(trim(explode(',', $name)[0])) . ' ';
		// „Milchreis“ ist Trockenware, kein Milchprodukt.
		if (str_contains($text, 'milchreis')) {
			return self::Dry;
		}
		foreach (self::KEYWORDS as $category => $words) {
			foreach ($words as $word) {
				if (str_contains($text, $word)) {
					return self::from($category);
				}
			}
		}
		return in_array($unit, [Unit::Can, Unit::Jar], true) ? self::Canned : self::Other;
	}

	/** Haltbares wird beim ersten Einkauf besorgt, Frisches zum Tag, an dem es gebraucht wird. */
	public function isShelfStable(): bool {
		return in_array($this, [self::Dry, self::Canned, self::Spices], true);
	}

	public function label(): string {
		return match ($this) {
			self::Produce => 'Obst und Gemüse',
			self::Dairy => 'Milchprodukte und Eier',
			self::Bakery => 'Brot und Backwaren',
			self::Dry => 'Trockenware',
			self::Canned => 'Konserven',
			self::Spices => 'Gewürze, Öl und Backzutaten',
			self::Other => 'Sonstiges',
		};
	}

	public function order(): int {
		return array_search($this, self::cases(), true);
	}
}
