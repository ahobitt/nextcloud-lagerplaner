<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Geo;

/**
 * Liest Koordinaten aus dem, was Menschen typischerweise einfügen: Zahlen („51.0625, 7.0612“,
 * „51,0625 7,0612“, „N 51.0625 E 7.0612“), Links von OpenStreetMap, Google Maps oder Apple
 * Karten sowie geo:-Adressen. Gibt [Breite, Länge] zurück oder null, wenn nichts eindeutig ist.
 */
final class CoordinateParser {
	/** @return array{0: float, 1: float}|null */
	public function parse(string $input): ?array {
		$text = trim($input);
		if ($text === '') {
			return null;
		}
		$number = '(-?\d{1,3}(?:[.,]\d+)?)';
		$patterns = [
			// OpenStreetMap: ?mlat=..&mlon=.. oder #map=Zoom/Breite/Länge
			'/[?&]mlat=' . $number . '.*?[?&]mlon=' . $number . '/i',
			'/#map=\d+(?:\.\d+)?\/' . $number . '\/' . $number . '/i',
			// Google Maps: Ortsmarke !3dBreite!4dLänge (genauer als die Kartenmitte), dann @Breite,Länge
			'/!3d' . $number . '!4d' . $number . '/i',
			'/@' . $number . ',' . $number . '/',
			// Google, Apple und andere: q=, ll=, query=, center=, sll=, daddr=
			'/[?&](?:q|ll|query|center|sll|daddr|coordinate)=(?:loc:)?' . $number . '(?:,|%2C)\s*' . $number . '/i',
			// geo:Breite,Länge
			'/^geo:' . $number . ',' . $number . '/i',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $text, $m) === 1) {
				return self::checked(self::float($m[1]), self::float($m[2]));
			}
		}
		if (preg_match('#^https?://#i', $text) === 1) {
			return null;
		}

		// Zahlenpaar: „51.0625, 7.0612“, „51,0625 7,0612“, „51.0625;7.0612“, „N 51.0625 E 7.0612“
		$plain = preg_replace('/[°NnEeOo]/u', ' ', $text) ?? $text;
		if (preg_match('/^\s*(-?\d{1,2}\.\d+)\s*[,;\s]\s*(-?\d{1,3}\.\d+)\s*$/', $plain, $m) === 1
			|| preg_match('/^\s*(-?\d{1,2},\d+)\s*[;\s]\s*(-?\d{1,3},\d+)\s*$/', $plain, $m) === 1
			|| preg_match('/^\s*(-?\d{1,2})\s*,\s*(-?\d{1,3})\s*$/', $plain, $m) === 1) {
			return self::checked(self::float($m[1]), self::float($m[2]));
		}
		return null;
	}

	/** „51.0625, 7.0612“ mit sechs Nachkommastellen (etwa 10 cm genau) */
	public static function format(float $lat, float $lon): string {
		return rtrim(rtrim(sprintf('%.6F', $lat), '0'), '.') . ', ' . rtrim(rtrim(sprintf('%.6F', $lon), '0'), '.');
	}

	private static function float(string $value): float {
		return (float)str_replace(',', '.', $value);
	}

	/** @return array{0: float, 1: float}|null */
	private static function checked(float $lat, float $lon): ?array {
		if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || ($lat == 0.0 && $lon == 0.0)) {
			return null;
		}
		return [round($lat, 6), round($lon, 6)];
	}
}
