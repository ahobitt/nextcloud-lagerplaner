<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Recipes;

/** Mengeneinheiten in Rezepten. Die Werte sind die angezeigten deutschen Kürzel. */
enum Unit: string {
	case Gram = 'g';
	case Kilogram = 'kg';
	case Millilitre = 'ml';
	case Litre = 'l';
	case Piece = 'Stück';
	case Tablespoon = 'EL';
	case Teaspoon = 'TL';
	case Pinch = 'Prise';
	case Bunch = 'Bund';
	case Can = 'Dose';
	case Pack = 'Packung';
	case Clove = 'Zehe';
	case Jar = 'Glas';
	case ToTaste = 'nach Geschmack';

	/** Zählbare Einheiten werden beim Hochrechnen auf ganze Stück aufgerundet. */
	public function isCountable(): bool {
		return in_array($this, [self::Piece, self::Bunch, self::Can, self::Pack, self::Clove, self::Jar], true);
	}

	public function isSpoon(): bool {
		return in_array($this, [self::Tablespoon, self::Teaspoon, self::Pinch], true);
	}
}
