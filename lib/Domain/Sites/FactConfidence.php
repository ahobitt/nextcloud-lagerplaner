<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

enum FactConfidence: string {
	case High = 'high';
	case Medium = 'medium';
	case Low = 'low';
}
