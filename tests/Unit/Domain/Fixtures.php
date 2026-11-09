<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

/** Beispieldaten aus dem Konzept (Abschnitt 8.5 und 20). */
final class Fixtures {
	/** @return array<string, mixed> */
	public static function summerCamp(array $overrides = []): array {
		return array_replace_recursive([
			'name' => 'Sommerlager 2027',
			'start' => '2027-07-24',
			'end' => '2027-08-04',
			'organizer' => 'Stamm Beispiel',
			'campType' => 'tent',
			'groups' => [
				['key' => 'woelflinge', 'count' => 10],
				['key' => 'jungpfadfinder', 'count' => 12],
				['key' => 'pfadfinder', 'count' => 8],
				['key' => 'rover', 'count' => 7],
				['key' => 'leitende', 'count' => 5],
			],
			'location' => ['known' => false, 'region' => 'Süddeutschland'],
			'program' => ['theme' => 'Wikinger', 'hike' => true, 'wideGame' => true, 'freeTime' => 'medium'],
			'catering' => ['selfCatering' => true, 'meals' => ['breakfast', 'lunch', 'dinner'], 'kitchenTeam' => 'unknown'],
			'organisation' => ['materialResponsible' => 'yes'],
		], $overrides);
	}
}
