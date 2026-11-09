<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Planning;

/** Ein Programmpunkt oder eine Mahlzeit auf der Zeitachse eines Tages. */
final class ScheduleEntry {
	/** @param list<string> $groups leer bedeutet: alle Gruppen */
	public function __construct(
		public readonly string $kind,
		public readonly int $id,
		public readonly string $day,
		public readonly int $start,
		public readonly int $minutes,
		public readonly array $groups,
		public readonly string $title,
		/** Schlechtwetteralternative zu diesem Programmpunkt */
		public readonly ?int $alternativeTo = null,
		/** unterwegs eingenommene Mahlzeit */
		public readonly bool $portable = false,
	) {
	}

	public function end(): int {
		return $this->start + $this->minutes;
	}
}
