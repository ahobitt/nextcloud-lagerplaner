<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

/**
 * Eine Menüvariante einer Mahlzeit und die Anforderungen, die sie laut Küche erfüllt (Konzept 9.8).
 *
 * Allergen- und Unverträglichkeitsfreiheit zählt nur, wenn sie ausdrücklich angegeben ist.
 */
final class MenuVariant {
	/** @var list<Requirement> erfüllte Anforderungen einschließlich abgeleiteter Ernährungsweisen */
	public readonly array $satisfies;

	/** @param list<Requirement> $declared ausdrücklich von der Küche angegeben */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly array $declared,
	) {
		$this->satisfies = Requirement::closure($declared);
	}

	/** @return list<Requirement> Anforderungen der Kombination, die diese Variante nicht erfüllt */
	public function missingFor(DietaryCombination $combination): array {
		return array_values(array_filter(
			$combination->requirements,
			fn (Requirement $r): bool => !in_array($r, $this->satisfies, true),
		));
	}
}
