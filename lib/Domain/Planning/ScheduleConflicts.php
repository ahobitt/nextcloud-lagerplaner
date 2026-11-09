<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Planning;

/**
 * Zeitliche Überschneidungen in der laufenden Programmplanung (Konzept 6.3).
 *
 * Ein Konflikt liegt vor, wenn sich zwei Einträge am selben Tag zeitlich überschneiden
 * und mindestens eine Gruppe beide betrifft. Keine Konflikte sind:
 * - parallele Programme verschiedener Gruppen,
 * - ein Programmpunkt und seine Schlechtwetteralternative,
 * - unterwegs eingenommene Mahlzeiten wie das Lunchpaket auf der Wanderung.
 * Konflikte sind Hinweise, keine Sperre: Gewollte Überschneidungen bleiben möglich.
 */
final class ScheduleConflicts {
	/**
	 * @param list<ScheduleEntry> $entries
	 * @return array<int, list<array{kind: string, id: int, title: string, start: string, end: string}>> Programmpunkt-ID => Überschneidungen
	 */
	public function forProgramItems(array $entries): array {
		$byDay = [];
		foreach ($entries as $entry) {
			if (!$entry->portable) {
				$byDay[$entry->day][] = $entry;
			}
		}
		$result = [];
		foreach ($byDay as $dayEntries) {
			foreach ($dayEntries as $a) {
				if ($a->kind !== 'program') {
					continue;
				}
				foreach ($dayEntries as $b) {
					if ($a === $b || !$this->overlap($a, $b) || !$this->sharedGroup($a, $b) || $this->alternatives($a, $b)) {
						continue;
					}
					$result[$a->id][] = [
						'kind' => $b->kind,
						'id' => $b->id,
						'title' => $b->title,
						'start' => self::time($b->start),
						'end' => self::time($b->end()),
					];
				}
			}
		}
		return $result;
	}

	private function overlap(ScheduleEntry $a, ScheduleEntry $b): bool {
		return $a->start < $b->end() && $b->start < $a->end();
	}

	private function sharedGroup(ScheduleEntry $a, ScheduleEntry $b): bool {
		return $a->groups === [] || $b->groups === [] || array_intersect($a->groups, $b->groups) !== [];
	}

	private function alternatives(ScheduleEntry $a, ScheduleEntry $b): bool {
		return ($a->kind === 'program' && $b->kind === 'program')
			&& ($a->alternativeTo === $b->id || $b->alternativeTo === $a->id);
	}

	public static function time(int $minutes): string {
		return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
	}
}
