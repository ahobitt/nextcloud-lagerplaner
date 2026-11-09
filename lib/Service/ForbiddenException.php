<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

/** Die Person hat Zugriff auf das Lager, aber nicht die nötige Rolle für diese Aktion. */
class ForbiddenException extends \RuntimeException {
}
