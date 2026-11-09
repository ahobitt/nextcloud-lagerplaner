<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

/** Die Spieledatenbank ist nicht eingeschaltet oder gerade nicht erreichbar. */
class GamesUnavailableException extends \RuntimeException {
}
