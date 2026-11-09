<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

/** Betriebsstatus eines Platzes, getrennt vom Status in der Quelle (aktiv / nicht mehr in der Quelle). */
enum OperatingStatus: string {
	case Active = 'active';
	case Closed = 'closed';
	case Closing = 'closing';
	case TemporarilyUnavailable = 'temporarily_unavailable';
	case Unknown = 'unknown';

	/**
	 * Wirksamer Status an einem Tag (JJJJ-MM-TT): „schließt“ gilt ab dem Datum als geschlossen,
	 * ein angekündigtes „geschlossen“ vorher als „schließt“; „vorübergehend nicht verfügbar“ gilt
	 * nur zwischen Beginn und Ende.
	 */
	public static function effective(?string $status, ?string $from, ?string $until, string $today): ?self {
		$value = $status === null ? null : self::tryFrom($status);
		return match ($value) {
			self::Closed => $from !== null && $today < $from ? self::Closing : self::Closed,
			self::Closing => $from !== null && $today >= $from ? self::Closed : self::Closing,
			self::TemporarilyUnavailable => ($until !== null && $today > $until) || ($from !== null && $today < $from) ? self::Active : self::TemporarilyUnavailable,
			default => $value,
		};
	}
}
