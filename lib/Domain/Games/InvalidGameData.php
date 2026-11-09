<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Games;

/** Ein Beitrag der Quelle lässt sich nicht als Spiel lesen und wird übersprungen. */
final class InvalidGameData extends \RuntimeException {
}
