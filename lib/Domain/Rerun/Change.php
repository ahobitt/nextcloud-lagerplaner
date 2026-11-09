<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Rerun;

use OCA\Lagerplaner\Domain\Wizard\ProposedElement;

final class Change {
	public function __construct(
		public readonly ChangeType $type,
		public readonly ?ExistingElement $existing,
		public readonly ?ProposedElement $proposed,
		/** Nur bei Konflikten: der neue Vorschlag wird zusätzlich als Alternative angelegt. */
		public readonly bool $addAsAlternative = false,
	) {
	}

	/** Ob die Übernahme dieses Eintrags etwas speichert (Konflikte ohne Alternative und Hinweise tun das nicht). */
	public function hasEffect(): bool {
		return match ($this->type) {
			ChangeType::Add, ChangeType::Update, ChangeType::Remove => true,
			ChangeType::Conflict => $this->addAsAlternative,
			ChangeType::Orphaned, ChangeType::Suppressed => false,
		};
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return [
			'type' => $this->type->value,
			'existingId' => $this->existing?->id,
			'existingLabel' => $this->existing?->label,
			'proposed' => $this->proposed?->toArray(),
			'addAsAlternative' => $this->addAsAlternative,
		];
	}
}
