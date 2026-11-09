<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Wizard;

/** Ungültige Eingaben im Assistenten, je Feld eine verständliche Meldung (Konzept 16.4). */
final class InvalidWizardInput extends \InvalidArgumentException {
	/** @param array<string, string> $errors Feldpfad => Meldung */
	public function __construct(public readonly array $errors) {
		parent::__construct(implode(' ', $errors));
	}
}
