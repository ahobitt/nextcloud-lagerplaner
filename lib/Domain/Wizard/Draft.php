<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Wizard;

/** Ergebnis eines Assistentenlaufs, noch nicht gespeichert (Konzept 3.8). */
final class Draft {
	/**
	 * @param list<string> $days
	 * @param list<ProposedProgramItem> $programItems
	 * @param list<ProposedMeal> $meals
	 * @param list<ProposedTask> $tasks
	 * @param list<string> $hints
	 */
	public function __construct(
		public readonly string $templateVersion,
		public readonly WizardInput $input,
		public readonly array $days,
		public readonly array $programItems,
		public readonly array $meals,
		public readonly array $tasks,
		public readonly array $hints,
	) {
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return [
			'templateVersion' => $this->templateVersion,
			'input' => $this->input->toArray(),
			'days' => $this->days,
			'programItems' => array_map(static fn (ProposedElement $e): array => $e->toArray(), $this->programItems),
			'meals' => array_map(static fn (ProposedElement $e): array => $e->toArray(), $this->meals),
			'tasks' => array_map(static fn (ProposedElement $e): array => $e->toArray(), $this->tasks),
			'hints' => $this->hints,
		];
	}
}
