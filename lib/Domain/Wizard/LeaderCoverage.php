<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Wizard;

/**
 * Hinweis, wenn für die Teilnehmenden zu wenige Leitende eingetragen sind. Keine Vorschrift,
 * sondern eine Faustregel; maßgeblich sind die Vorgaben des Verbands. Der Assistent bleibt
 * bedienbar.
 */
final class LeaderCoverage {
	/** Ab so vielen Teilnehmenden je Leitung erscheint ein Hinweis */
	public const MAX_PER_LEADER = 8;

	/** @return array{level: string, text: string}|null warning oder info */
	public static function hint(int $participants, int $leaders): ?array {
		if ($participants <= 0) {
			return null;
		}
		if ($leaders <= 0) {
			return ['level' => 'warning', 'text' => sprintf('Für %d Kinder und Jugendliche sind keine Leitenden eingetragen. Ein Lager braucht Aufsicht; tragt die Leitenden ein, damit auch Aufgaben und Dienste verteilt werden können.', $participants)];
		}
		if ($leaders === 1) {
			return ['level' => 'warning', 'text' => sprintf('Nur eine leitende Person für %d Kinder und Jugendliche. Plant mindestens zwei Leitende ein, damit im Notfall jemand bei der Gruppe bleiben kann.', $participants)];
		}
		$ratio = $participants / $leaders;
		if ($ratio > self::MAX_PER_LEADER) {
			return ['level' => 'info', 'text' => sprintf('Auf eine leitende Person kommen etwa %d Kinder und Jugendliche (%d Teilnehmende, %d Leitende). Als Faustregel plant man eine Leitung für sechs bis acht Teilnehmende, bei jüngeren Kindern mehr; maßgeblich sind die Vorgaben eures Verbands.', (int)round($ratio), $participants, $leaders)];
		}
		return null;
	}
}
