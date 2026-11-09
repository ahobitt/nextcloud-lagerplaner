<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Planning;

/** Eine Verschiebung würde einen Eintrag über das Tagesende hinausschieben. */
final class ScheduleOverflow extends \InvalidArgumentException {
}
