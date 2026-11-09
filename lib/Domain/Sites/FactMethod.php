<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

/**
 * Woher eine einzelne Angabe stammt. Angaben ohne eigenen Herkunftseintrag stammen aus der
 * Quelle des Platzes (oder sind unbekannt).
 *
 * Vorrang: von Hand (Administration) vor Rückmeldung vor Anreicherung (Website, Geodaten) vor
 * Quelle. Stammdaten, für die die Quelle zuständig ist, aktualisiert sie trotzdem wieder,
 * außer bei einer bewussten Korrektur von Hand.
 */
enum FactMethod: string {
	/** automatisch auf der Website des Platzes erkannt */
	case Website = 'website';
	/** aus den Koordinaten ermittelt (Postleitzahl, Ort, Land) */
	case Geocoding = 'geocoding';
	/** aus einer geprüften und übernommenen Rückmeldung */
	case Feedback = 'feedback';
	/** von der Administration bewusst gesetzt; gilt immer */
	case Manual = 'manual';

	public function rank(): int {
		return match ($this) {
			self::Manual => 4,
			self::Feedback => 3,
			self::Website, self::Geocoding => 2,
		};
	}

	/** Bewusste Korrektur: die Quelle überschreibt sie nie. */
	public function isDeliberate(): bool {
		return $this === self::Manual;
	}
}
