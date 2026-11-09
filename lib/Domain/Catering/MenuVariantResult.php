<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

final class MenuVariantResult {
	/**
	 * @param array<string, int> $headcounts Variantenschlüssel => Personen
	 * @param list<PlanIssue> $issues
	 */
	public function __construct(
		public readonly array $headcounts,
		public readonly array $issues,
	) {
	}

	public function total(): int {
		return array_sum($this->headcounts);
	}

	public function hasErrors(): bool {
		foreach ($this->issues as $issue) {
			if ($issue->severity === PlanIssue::ERROR) {
				return true;
			}
		}
		return false;
	}
}
