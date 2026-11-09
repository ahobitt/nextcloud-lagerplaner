<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

/** Alle Bedarfskombinationen eines Lagers (Konzept 8.5, 8.6). */
final class DietaryProfile {
	/** @var array<string, DietaryCombination> */
	private array $combinations = [];

	/** @param list<DietaryCombination> $combinations */
	public function __construct(array $combinations) {
		foreach ($combinations as $combination) {
			if (isset($this->combinations[$combination->key])) {
				throw new \InvalidArgumentException('Bedarfskombination doppelt angegeben: ' . $combination->label());
			}
			$this->combinations[$combination->key] = $combination;
		}
	}

	/** @param array<string, int> $counts Kombinationsschlüssel => Anzahl */
	public static function fromCounts(array $counts): self {
		return new self(array_map(
			static fn (string $key, int $count): DietaryCombination => DietaryCombination::fromKey($key, $count),
			array_keys($counts),
			array_values($counts),
		));
	}

	/** @return list<DietaryCombination> nur Kombinationen mit mindestens einer Person */
	public function combinations(): array {
		return array_values(array_filter($this->combinations, static fn (DietaryCombination $c): bool => $c->count > 0));
	}

	public function get(string $key): ?DietaryCombination {
		return $this->combinations[$key] ?? null;
	}

	public function total(): int {
		return array_sum(array_map(static fn (DietaryCombination $c): int => $c->count, $this->combinations));
	}

	/**
	 * Einzelsummen je Anforderung, abgeleitet aus den Kombinationen.
	 * Beispiel 8.5: 6 vegetarisch + 2 vegetarisch/glutenfrei ergeben 8 vegetarisch.
	 *
	 * @return array<string, int>
	 */
	public function totalsPerRequirement(): array {
		$totals = [];
		foreach ($this->combinations as $combination) {
			foreach ($combination->requirements as $requirement) {
				$totals[$requirement->value] = ($totals[$requirement->value] ?? 0) + $combination->count;
			}
		}
		ksort($totals);
		return $totals;
	}

	/** @return list<PlanIssue> */
	public function checkAgainstParticipants(int $participants): array {
		$total = $this->total();
		if ($total === $participants) {
			return [];
		}
		return [new PlanIssue(
			PlanIssue::TOTAL_MISMATCH,
			PlanIssue::ERROR,
			sprintf('Die Bedarfskombinationen ergeben %d Personen, angemeldet sind %d.', $total, $participants),
		)];
	}
}
