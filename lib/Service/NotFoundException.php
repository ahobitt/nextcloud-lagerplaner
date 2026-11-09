<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

/** Das Element existiert nicht oder gehört nicht zu einem Lager der anfragenden Person. */
class NotFoundException extends \RuntimeException {
}
