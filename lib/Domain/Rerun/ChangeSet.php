<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Rerun;

final class ChangeSet {
	/** @param list<Change> $changes */
	public function __construct(
		public readonly array $changes,
		public readonly int $unchanged,
	) {
	}

	/** @return list<Change> */
	public function ofType(ChangeType $type): array {
		return array_values(array_filter($this->changes, static fn (Change $c): bool => $c->type === $type));
	}

	public function isEmpty(): bool {
		return $this->changes === [];
	}

	/** Anzahl der Einträge, die bei der Übernahme tatsächlich etwas speichern. */
	public function effectiveCount(): int {
		return count(array_filter($this->changes, static fn (Change $c): bool => $c->hasEffect()));
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		$summary = [];
		foreach (ChangeType::cases() as $type) {
			$summary[$type->value] = count($this->ofType($type));
		}
		$summary['unchanged'] = $this->unchanged;
		$summary['effective'] = $this->effectiveCount();
		return [
			'summary' => $summary,
			'changes' => array_map(static fn (Change $c): array => $c->toArray(), $this->changes),
		];
	}
}
