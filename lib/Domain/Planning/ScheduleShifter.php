<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Planning;

/**
 * Verschieben mit Zeitanpassung: Der verschobene Eintrag bekommt seine neue Startzeit;
 * was sich danach mit ihm oder mit bereits geschobenen Einträgen überschneidet, rutscht
 * nach hinten, bis eine Lücke die Verschiebung auffängt. Einträge für andere Gruppen, die
 * parallel laufen dürfen, bleiben, wo sie sind.
 *
 * Beispiel: Morgenrunde (15 Min.) vor das Frühstück um 8:00 gezogen ergibt Morgenrunde
 * 8:00, Frühstück 8:15; das Vormittagsprogramm um 9:30 bleibt, weil die Lücke reicht.
 *
 * Schlechtwetteralternativen folgen ihrem Programmpunkt; Mahlzeiten unterwegs überschneiden
 * sich gewollt mit dem Programm und werden weder geschoben noch schieben sie.
 */
final class ScheduleShifter {
	public const DAY_END = 24 * 60;

	/**
	 * @param list<ScheduleEntry> $entries Einträge des Zieltags; der verschobene Eintrag darf fehlen
	 * @return array<string, int> „Art:ID“ => neue Startminute, für jeden geänderten Eintrag
	 * @throws ScheduleOverflow wenn etwas über das Tagesende hinausrutschen würde
	 */
	public function move(array $entries, ScheduleEntry $moved, int $newStart): array {
		$key = self::key($moved);
		$placed = [new ScheduleEntry($moved->kind, $moved->id, $moved->day, $newStart, $moved->minutes, $moved->groups, $moved->title)];
		$result = [$key => $newStart];

		$others = array_values(array_filter($entries, static fn (ScheduleEntry $e): bool => self::key($e) !== $key));
		$blocking = array_values(array_filter($others, static fn (ScheduleEntry $e): bool => $e->alternativeTo === null && !$e->portable));
		usort($blocking, static fn (ScheduleEntry $a, ScheduleEntry $b): int => [$a->start, $a->kind === 'meal' ? 0 : 1] <=> [$b->start, $b->kind === 'meal' ? 0 : 1]);

		foreach ($blocking as $entry) {
			$start = $entry->start;
			do {
				$pushed = false;
				foreach ($placed as $other) {
					if (self::shareGroups($entry, $other) && $start < $other->end() && $other->start < $start + $entry->minutes) {
						$start = $other->end();
						$pushed = true;
					}
				}
			} while ($pushed);
			if ($start !== $entry->start) {
				$result[self::key($entry)] = $start;
			}
			$placed[] = new ScheduleEntry($entry->kind, $entry->id, $entry->day, $start, $entry->minutes, $entry->groups, $entry->title);
		}

		// Alternativen wandern mit ihrem Programmpunkt.
		$starts = [];
		foreach ($entries as $entry) {
			$starts[self::key($entry)] = $entry->start;
		}
		$starts[$key] ??= $moved->start;
		foreach ($others as $entry) {
			$original = $entry->alternativeTo === null ? null : 'program:' . $entry->alternativeTo;
			if ($original !== null && isset($result[$original], $starts[$original])) {
				$result[self::key($entry)] = $entry->start + $result[$original] - $starts[$original];
			}
		}

		$minutes = [$key => $moved->minutes];
		foreach ($others as $entry) {
			$minutes[self::key($entry)] = $entry->minutes;
		}
		foreach ($result as $changed => $start) {
			if ($start < 0 || $start + $minutes[$changed] > self::DAY_END) {
				throw new ScheduleOverflow('An diesem Tag ist dafür nicht genug Zeit: Ein Programmpunkt würde über Mitternacht hinausrutschen.');
			}
		}
		return $result;
	}

	public static function key(ScheduleEntry $entry): string {
		return $entry->kind . ':' . $entry->id;
	}

	/** Leere Gruppenliste heißt „alle“; Mahlzeiten gelten immer für alle. */
	private static function shareGroups(ScheduleEntry $a, ScheduleEntry $b): bool {
		return $a->groups === [] || $b->groups === [] || array_intersect($a->groups, $b->groups) !== [];
	}
}
