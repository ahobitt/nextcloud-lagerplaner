<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

/**
 * Ausstattung eines Platzes: vorhanden (true), teilweise vorhanden ('partial', etwa Strom nur
 * im Haus), nicht vorhanden (false), unbekannt (null). Unbekannt heißt nie „nicht vorhanden“
 * (Konzept 5.5); teilweise erfüllt einen Filter nicht eindeutig.
 */
final class SiteFeatures {
	public const KEYS = ['water', 'sanitary', 'kitchen', 'electricity', 'fireplace', 'accessible'];
	public const PARTIAL = 'partial';
	public const LABELS = [
		'water' => 'Trinkwasser',
		'sanitary' => 'Sanitäranlagen',
		'kitchen' => 'Küche',
		'electricity' => 'Strom',
		'fireplace' => 'Feuerstelle',
		'accessible' => 'Barrierefrei',
	];

	/** @param array<string, bool|string|null> $values */
	private function __construct(public readonly array $values) {
	}

	/** Nur echte Wahrheitswerte und „partial“ zählen; alles andere ist unbekannt. */
	public static function fromArray(mixed $raw): self {
		$values = [];
		foreach (self::KEYS as $key) {
			$value = is_array($raw) ? ($raw[$key] ?? null) : null;
			$values[$key] = is_bool($value) || $value === self::PARTIAL ? $value : null;
		}
		return new self($values);
	}

	public function get(string $key): bool|string|null {
		return $this->values[$key] ?? null;
	}

	/** @return array<string, bool|string|null> */
	public function toArray(): array {
		return $this->values;
	}
}
