<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

/**
 * Interner Bearbeitungsstand einer Platzanfrage (Konzept 5.9). Die App bucht nichts beim
 * Betreiber; der Status hält nur fest, wie weit die Lagerleitung ist.
 */
enum ShortlistStatus: string {
	case Interesting = 'interesting';
	case Requested = 'requested';
	case Answered = 'answered';
	case Reserved = 'reserved';
	case Booked = 'booked';
	case Cancelled = 'cancelled';

	public function label(): string {
		return match ($this) {
			self::Interesting => 'Interessant',
			self::Requested => 'Angefragt',
			self::Answered => 'Rückmeldung erhalten',
			self::Reserved => 'Reserviert',
			self::Booked => 'Verbindlich gebucht',
			self::Cancelled => 'Abgesagt',
		};
	}
}
