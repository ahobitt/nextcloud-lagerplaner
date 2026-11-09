<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Provenance;

enum ReviewState: string {
	case Proposal = 'proposal';
	case Confirmed = 'confirmed';
}
