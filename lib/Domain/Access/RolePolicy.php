<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Access;

/**
 * Welche Rolle was darf (Konzept 18.1).
 *
 * Material planen dürfen Lagerleitung, Materialteam und Programmteam (für Spiele und
 * Programmpunkte); packen und den Status pflegen dürfen alle außer Lesezugriff.
 * Ernährungsangaben sehen nur Lagerleitung und Küchenteam (Konzept 18.3).
 */
final class RolePolicy {
	private const MATRIX = [
		'leitung' => ['view', 'program', 'tasks', 'toggleTasks', 'cateringView', 'catering', 'wizard', 'members', 'delete', 'material', 'pack', 'sites'],
		'programm' => ['view', 'program', 'toggleTasks', 'material', 'pack'],
		'kueche' => ['view', 'toggleTasks', 'cateringView', 'catering', 'pack'],
		'material' => ['view', 'toggleTasks', 'material', 'pack'],
		'mitarbeit' => ['view', 'toggleTasks', 'pack'],
		'lesen' => ['view'],
	];

	/** @param list<Role> $roles */
	public static function allows(array $roles, Permission $permission): bool {
		foreach ($roles as $role) {
			if (in_array($permission->value, self::MATRIX[$role->value], true)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<Role> $roles
	 * @return array<string, bool> Permission-Wert => erlaubt, für die Oberfläche
	 */
	public static function capabilities(array $roles): array {
		$result = [];
		foreach (Permission::cases() as $permission) {
			$result[$permission->value] = self::allows($roles, $permission);
		}
		return $result;
	}
}
