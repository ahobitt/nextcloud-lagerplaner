<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

/** Ungültige Angaben in der Küchenplanung, je Feld eine verständliche Meldung. */
final class InvalidCateringInput extends \InvalidArgumentException {
	/** @param array<string, string> $errors Feldpfad => Meldung */
	public function __construct(public readonly array $errors) {
		parent::__construct(implode(' ', $errors));
	}
}
