<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Access;

/** Rollen innerhalb eines Lagers (Konzept 18.1). Eine Person kann mehrere haben. */
enum Role: string {
	case Leitung = 'leitung';
	case Programm = 'programm';
	case Kueche = 'kueche';
	case Material = 'material';
	case Mitarbeit = 'mitarbeit';
	case Lesen = 'lesen';

	/**
	 * @param mixed $values Liste von Rollenschlüsseln
	 * @return list<self>
	 * @throws \InvalidArgumentException
	 */
	public static function parseList(mixed $values): array {
		if (!is_array($values) || $values === []) {
			throw new \InvalidArgumentException('Bitte wähle mindestens eine Rolle.');
		}
		$roles = [];
		foreach ($values as $value) {
			$role = is_string($value) ? self::tryFrom($value) : null;
			if ($role === null) {
				throw new \InvalidArgumentException('Unbekannte Rolle.');
			}
			$roles[$role->value] = $role;
		}
		return array_values($roles);
	}

	/** @param list<self> $roles */
	public static function toCsv(array $roles): string {
		return implode(',', array_map(static fn (self $r): string => $r->value, $roles));
	}

	/** @return list<self> unbekannte Einträge aus älteren Versionen werden übergangen */
	public static function fromCsv(?string $csv): array {
		$roles = [];
		foreach (explode(',', (string)$csv) as $value) {
			$role = self::tryFrom(trim($value));
			if ($role !== null) {
				$roles[$role->value] = $role;
			}
		}
		return array_values($roles);
	}
}
