<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

/**
 * Übersetzt einen Eintrag des neutralen Importformats („lagerplaner-sites/1“) und eigene
 * Einträge in das interne Datenmodell. Fremde Daten werden defensiv gelesen: Was sich
 * nicht zuverlässig deuten lässt, bleibt unbekannt (Konzept 5.5).
 */
final class SiteNormalizer {
	public function __construct(private ContactScrubber $scrubber = new ContactScrubber()) {
	}

	/**
	 * @param array<string, mixed> $raw
	 * @throws InvalidSiteData
	 */
	public function normalize(array $raw): Site {
		$sourceId = self::text($raw['sourceId'] ?? null, 128);
		$name = self::text($raw['name'] ?? null, 200);
		if ($sourceId === null) {
			throw new InvalidSiteData('Eintrag ohne Kennung der Quelle.');
		}
		if ($name === null) {
			throw new InvalidSiteData("Eintrag $sourceId ohne Namen.");
		}
		$type = is_string($raw['type'] ?? null) ? SiteType::tryFrom($raw['type']) : null;
		if ($type === null) {
			throw new InvalidSiteData("Eintrag $sourceId: Art muss tent, house oder both sein.");
		}
		$address = is_array($raw['address'] ?? null) ? $raw['address'] : [];
		$coordinates = is_array($raw['coordinates'] ?? null) ? $raw['coordinates'] : [];
		$lat = self::number($coordinates['lat'] ?? null, -90, 90);
		$lon = self::number($coordinates['lon'] ?? null, -180, 180);
		if ($lat === null || $lon === null) {
			$lat = $lon = null;
		} else {
			// 7 Nachkommastellen (etwa 1 cm): übersteht jede Datenbank unverändert, sonst sähe jeder Abgleich eine Änderung
			$lat = round($lat, 7);
			$lon = round($lon, 7);
		}
		$description = self::text($raw['description'] ?? null, 5000, true);
		// Kein Land voreinstellen: Quellen enthalten auch Plätze außerhalb Deutschlands.
		$country = strtoupper((string)(self::text($address['country'] ?? null, 2) ?? ''));

		return new Site(
			$sourceId,
			$name,
			$type,
			self::text($address['street'] ?? null, 200),
			self::text($address['postalCode'] ?? null, 16),
			self::text($address['city'] ?? null, 120),
			self::text($address['state'] ?? null, 60),
			preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
			$lat,
			$lon,
			self::count($raw['capacity'] ?? null, 1),
			self::count($raw['beds'] ?? null, 0),
			SiteFeatures::fromArray($raw['features'] ?? null),
			self::text($raw['priceText'] ?? null, 255),
			$description === null ? null : $this->scrubber->scrub($description),
			self::url($raw['homepage'] ?? null),
			self::url($raw['sourceUrl'] ?? null),
			is_string($raw['sourceDate'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw['sourceDate']) === 1 ? $raw['sourceDate'] : null,
		);
	}

	private static function text(mixed $value, int $max, bool $multiline = false): ?string {
		if (!is_string($value) && !is_int($value)) {
			return null;
		}
		$value = trim((string)$value);
		if (!$multiline) {
			$value = preg_replace('/\s+/u', ' ', $value) ?? $value;
		}
		return $value === '' ? null : mb_substr($value, 0, $max);
	}

	private static function number(mixed $value, float $min, float $max): ?float {
		return is_int($value) || is_float($value) ? ((float)$value >= $min && (float)$value <= $max ? (float)$value : null) : null;
	}

	/** Kapazitäten nur als ganze Zahl; Angaben wie „ca. 50–80“ bleiben unbekannt. */
	/** Ganze Zahl, auch als Text („50“) aus Formularen; sonst unbekannt */
	private static function count(mixed $value, int $min): ?int {
		if (is_string($value) && preg_match('/^\s*\d{1,6}\s*$/', $value) === 1) {
			$value = (int)$value;
		}
		return is_int($value) && $value >= $min && $value <= 100000 ? $value : null;
	}

	private static function url(mixed $value): ?string {
		return is_string($value) && preg_match('#^https?://[^\s]{1,490}$#i', trim($value)) === 1 ? trim($value) : null;
	}
}
