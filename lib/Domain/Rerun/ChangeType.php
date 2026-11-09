<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Rerun;

enum ChangeType: string {
	/** Neuer Vorschlag, den es bisher nicht gab. */
	case Add = 'add';
	/** Unveränderter Vorschlag des Assistenten wird angepasst. */
	case Update = 'update';
	/** Unveränderter Vorschlag des Assistenten entfällt. */
	case Remove = 'remove';
	/** Bestätigtes oder bearbeitetes Element weicht vom neuen Vorschlag ab; es bleibt erhalten. */
	case Conflict = 'conflict';
	/** Bestätigtes oder bearbeitetes Element passt nicht mehr zum neuen Entwurf; es bleibt erhalten. */
	case Orphaned = 'orphaned';
	/** Die Lagerleitung hat diesen Vorschlag gelöscht; er wird nicht erneut angelegt. */
	case Suppressed = 'suppressed';
}
