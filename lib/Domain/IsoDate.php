<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain;

/** Kalenderdatum JJJJ-MM-TT, das es wirklich gibt (2026-02-30 nicht). */
final class IsoDate {
	public static function valid(mixed $value): bool {
		if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date !== false && $date->format('Y-m-d') === $value;
	}
}
