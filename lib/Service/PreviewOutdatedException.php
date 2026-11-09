<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

/** Die Planung hat sich seit der Vorschau geändert; die Vorschau muss neu geladen werden. */
class PreviewOutdatedException extends \RuntimeException {
}
