<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

final class PlanIssue {
	public const ERROR = 'error';
	public const WARNING = 'warning';

	public const TOTAL_MISMATCH = 'total_mismatch';
	public const UNASSIGNED = 'unassigned';
	public const UNKNOWN_VARIANT = 'unknown_variant';
	public const UNKNOWN_COMBINATION = 'unknown_combination';
	public const NOT_SATISFIED = 'not_satisfied';
	public const UNRESOLVED = 'unresolved';
	public const MANUAL_CHECK = 'manual_check';
	public const SEVERE_NOT_COVERED = 'severe_not_covered';
	public const RECIPE_CONFLICT = 'recipe_conflict';
	public const RECIPE_MISSING = 'recipe_missing';
	public const RECIPE_NOT_ASSIGNED = 'recipe_not_assigned';

	public function __construct(
		public readonly string $code,
		public readonly string $severity,
		public readonly string $message,
		public readonly ?string $combinationKey = null,
		public readonly ?string $variantKey = null,
	) {
	}

	/** @return array<string, ?string> */
	public function toArray(): array {
		return [
			'code' => $this->code,
			'severity' => $this->severity,
			'message' => $this->message,
			'combinationKey' => $this->combinationKey,
			'variantKey' => $this->variantKey,
		];
	}
}
