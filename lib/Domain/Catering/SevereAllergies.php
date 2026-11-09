<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

use DateTimeImmutable;
use OCA\Lagerplaner\Domain\Wizard\DraftGenerator;
use OCA\Lagerplaner\Domain\Wizard\ProposedTask;

/**
 * Aggregierte Anzahl schwerer Allergien je Allergen, ohne Personenbezug (Konzept 9.10).
 * Die personenbezogene Liste bleibt in Hitobito.
 */
final class SevereAllergies {
	public const TASK_KEY = 'allergieverfahren';
	private const DAYS_BEFORE_START = 14;

	/** Allergene der standardisierten Anmeldung (Konzept 8.4) und die passende Anforderung. */
	public const ALLERGENS = [
		'gluten' => ['Gluten', Requirement::GlutenFree],
		'milk' => ['Milch beziehungsweise Milcheiweiß', Requirement::MilkFree],
		'egg' => ['Eier', Requirement::EggFree],
		'tree_nuts' => ['Schalenfrüchte', Requirement::TreeNutFree],
		'peanuts' => ['Erdnüsse', Requirement::PeanutFree],
		'soy' => ['Soja', Requirement::SoyFree],
		'other' => ['Weitere Allergene', Requirement::Other],
	];

	/** @param array<string, int> $countsByAllergen */
	public function __construct(public readonly array $countsByAllergen) {
		foreach ($countsByAllergen as $allergen => $count) {
			if (!isset(self::ALLERGENS[$allergen])) {
				throw new \InvalidArgumentException('Unbekanntes Allergen: ' . $allergen);
			}
			if ($count < 0) {
				throw new \InvalidArgumentException('Die Anzahl für ' . $allergen . ' darf nicht negativ sein.');
			}
		}
	}

	public static function label(string $allergen): string {
		return self::ALLERGENS[$allergen][0] ?? $allergen;
	}

	/** @return array<string, int> nur Allergene mit mindestens einer Person */
	public function reported(): array {
		return array_filter($this->countsByAllergen, static fn (int $count): bool => $count > 0);
	}

	/**
	 * Schwere Allergien müssen sich in den Bedarfskombinationen wiederfinden, sonst plant
	 * die Küche keine passende Variante ein. Beispiel: 1 schwere Erdnussallergie, aber keine
	 * Kombination „ohne Erdnüsse“.
	 *
	 * @return list<PlanIssue>
	 */
	public function consistencyIssues(DietaryProfile $profile): array {
		$issues = [];
		foreach ($this->reported() as $allergen => $count) {
			$requirement = self::ALLERGENS[$allergen][1];
			$covered = 0;
			foreach ($profile->combinations() as $combination) {
				if (in_array($requirement, $combination->requirements, true)) {
					$covered += $combination->count;
				}
			}
			if ($covered < $count) {
				$issues[] = new PlanIssue(
					PlanIssue::SEVERE_NOT_COVERED,
					PlanIssue::ERROR,
					sprintf('%d schwere Allergie(n) gegen %s gemeldet, aber nur %d Person(en) in Bedarfskombinationen „%s“.',
						$count, self::label($allergen), $covered, $requirement->label()),
				);
			}
		}
		return $issues;
	}

	public function requiresProcedure(): bool {
		return array_sum($this->countsByAllergen) > 0;
	}

	/** Pflichtaufgabe für die Lagerleitung, sobald eine schwere Allergie gemeldet ist. */
	public function mandatoryTask(DateTimeImmutable $campStart, DateTimeImmutable $today): ?ProposedTask {
		if (!$this->requiresProcedure()) {
			return null;
		}
		$due = $campStart->modify('-' . self::DAYS_BEFORE_START . ' days');
		$todayDate = $today->setTime(0, 0);
		if ($due < $todayDate) {
			$due = $todayDate;
		}
		return new ProposedTask(
			self::TASK_KEY,
			'Allergieverfahren einrichten',
			'Allergieliste aus Hitobito nur an Lagerleitung und benannte Küchenverantwortliche; Ausgabe gekennzeichneter Portionen und verantwortliche Person festlegen; Vernichtung nach dem Lager planen.',
			$due->format('Y-m-d'),
			true,
			DraftGenerator::TEMPLATE_VERSION,
		);
	}
}
