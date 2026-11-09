<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Rerun;

use OCA\Lagerplaner\Domain\Provenance\Provenance;

/** Ein bereits gespeichertes Element, wie es der RerunPlanner sieht. */
final class ExistingElement {
	public function __construct(
		public readonly int $id,
		public readonly ?string $ruleKey,
		public readonly string $fingerprint,
		public readonly Provenance $provenance,
		public readonly string $label,
	) {
	}
}
