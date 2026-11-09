<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

/**
 * Entfernt personenbezogene Kontaktangaben aus Freitexten (Konzept 5.3, Schritt 3):
 * E-Mail-Adressen (auch verschleiert als „name(at)domain.de“) und Telefonnummern. Die Homepage
 * des Betreibers bleibt als eigenes Feld.
 */
final class ContactScrubber {
	public const PLACEHOLDER = '[Kontakt entfernt]';

	public function scrub(string $text): string {
		$text = preg_replace('/[\p{L}\p{N}._%+-]+\s*(?:@|\(at\)|\[at\])\s*[\p{L}\p{N}-]+(?:\s*(?:\.|\(dot\)|\[dot\])\s*[\p{L}\p{N}-]+)*\s*(?:\.|\(dot\)|\[dot\])\s*\p{L}{2,}/iu', self::PLACEHOLDER, $text) ?? $text;
		// Telefonnummern: mit +49 oder 0 beginnend, mindestens acht Ziffern mit üblichen Trennzeichen.
		$text = preg_replace('/(?<![\p{L}\p{N}])(?:\+\d{2}|0)[\d \/()\-]{6,}\d/u', self::PLACEHOLDER, $text) ?? $text;
		return $text;
	}
}
