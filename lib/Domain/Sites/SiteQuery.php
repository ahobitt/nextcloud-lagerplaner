<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

/**
 * Filter der Lagerplatzsuche (Konzept 5.8).
 *
 * Unbekannte Angaben schließen einen Platz nicht stillschweigend aus: Er erscheint mit dem
 * Hinweis, welche Angaben fehlen. Auf Wunsch blendet die Suche solche Plätze aus.
 */
final class SiteQuery {
	/** @param list<string> $features verlangte Ausstattung */
	public function __construct(
		public readonly string $text = '',
		public readonly string $state = '',
		public readonly ?SiteType $type = null,
		public readonly ?int $persons = null,
		public readonly ?int $beds = null,
		public readonly array $features = [],
		public readonly bool $includeUnknown = true,
		public readonly ?float $maxDistanceKm = null,
		/** Nur Postleitzahlen mit diesem Anfang, für die Suche in der PLZ-Region */
		public readonly string $postalPrefix = '',
		/** Auch geschlossene Plätze zeigen */
		public readonly bool $includeClosed = false,
	) {
	}

	/** Dieselbe Suche, aber statt des Suchtexts die PLZ-Region */
	public function inPostalRegion(string $prefix): self {
		return new self('', $this->state, $this->type, $this->persons, $this->beds, $this->features, $this->includeUnknown, $this->maxDistanceKm, $prefix, $this->includeClosed);
	}

	/**
	 * @param array<string, mixed> $params
	 * @throws \InvalidArgumentException
	 */
	public static function fromArray(array $params): self {
		$int = static function (string $key, int $max) use ($params): ?int {
			$value = $params[$key] ?? null;
			if ($value === null || $value === '') {
				return null;
			}
			if (!is_numeric($value) || (int)$value < 1 || (int)$value > $max) {
				throw new \InvalidArgumentException('Ungültiger Suchwert: ' . $key);
			}
			return (int)$value;
		};
		$text = is_string($params['q'] ?? null) ? trim($params['q']) : '';
		$features = $params['features'] ?? [];
		if (is_string($features)) {
			$features = $features === '' ? [] : explode(',', $features);
		}
		if (!is_array($features) || array_diff($features, SiteFeatures::KEYS) !== [] || mb_strlen($text) > 100) {
			throw new \InvalidArgumentException('Ungültiger Filter.');
		}
		$type = $params['type'] ?? '';
		$distance = $params['distance'] ?? null;
		return new self(
			$text,
			is_string($params['state'] ?? null) ? mb_substr(trim($params['state']), 0, 60) : '',
			is_string($type) && $type !== '' ? (SiteType::tryFrom($type) ?? throw new \InvalidArgumentException('Unbekannte Art.')) : null,
			$int('persons', 100000),
			$int('beds', 100000),
			array_values($features),
			!in_array($params['unknown'] ?? '1', ['0', 'false', false, 0], true),
			$distance === null || $distance === '' ? null : (float)$int('distance', 2000),
			'',
			in_array($params['closed'] ?? '0', ['1', 'true', true, 1], true),
		);
	}

	/**
	 * Passt der Platz? Gibt null zurück, wenn nicht; sonst die Angaben, die für diese
	 * Suche unbekannt sind (zur Kennzeichnung).
	 *
	 * @return list<string>|null
	 */
	public function evaluate(Site $site, ?float $distanceKm): ?array {
		if ($this->text !== '') {
			$needle = mb_strtolower($this->text);
			$haystack = mb_strtolower(implode(' ', array_filter([$site->name, $site->city, $site->postalCode, $site->state])));
			if (!str_contains($haystack, $needle)) {
				return null;
			}
		}
		if ($this->postalPrefix !== '' && !str_starts_with((string)$site->postalCode, $this->postalPrefix)) {
			return null;
		}
		if ($this->state !== '' && mb_strtolower((string)$site->state) !== mb_strtolower($this->state)) {
			return null;
		}
		if ($this->type !== null && !$site->type->offers($this->type)) {
			return null;
		}
		$unknown = [];
		if ($this->persons !== null) {
			if ($site->capacity === null) {
				$unknown[] = 'capacity';
			} elseif ($site->capacity < $this->persons) {
				return null;
			}
		}
		if ($this->beds !== null) {
			if ($site->beds === null) {
				$unknown[] = 'beds';
			} elseif ($site->beds < $this->beds) {
				return null;
			}
		}
		foreach ($this->features as $feature) {
			$value = $site->features->get($feature);
			if ($value === false) {
				return null;
			}
			if ($value === null) {
				$unknown[] = $feature;
			} elseif ($value === SiteFeatures::PARTIAL) {
				// teilweise erfüllt den Filter nicht eindeutig: wie unbekannt, aber gekennzeichnet
				$unknown[] = 'partial:' . $feature;
			}
		}
		if ($this->maxDistanceKm !== null) {
			if ($distanceKm === null) {
				$unknown[] = 'distance';
			} elseif ($distanceKm > $this->maxDistanceKm) {
				return null;
			}
		}
		return $unknown !== [] && !$this->includeUnknown ? null : $unknown;
	}

	/** Luftlinie in Kilometern (Haversine). */
	public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
		$r = 6371.0;
		$dLat = deg2rad($lat2 - $lat1);
		$dLon = deg2rad($lon2 - $lon1);
		$a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
		return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
	}
}
