<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Provenance;

/**
 * Herkunfts- und Bearbeitungsstand eines Planungselements (Konzept 3.10, 17.6).
 */
final class Provenance {
	public function __construct(
		public readonly Origin $origin,
		public readonly ReviewState $reviewState,
		public readonly ?int $userModifiedAt = null,
		/** Gesetzt, wenn die Lagerleitung einen Vorschlag bewusst gelöscht hat. */
		public readonly ?int $deletedAt = null,
	) {
	}

	public function isDeleted(): bool {
		return $this->deletedAt !== null;
	}

	/**
	 * Ein neuer Assistentenlauf darf ein Element nur dann selbst ändern oder löschen,
	 * wenn es vom Assistenten oder aus einer Vorlage stammt, noch ein Vorschlag ist
	 * und seit der Erzeugung nicht manuell bearbeitet wurde.
	 */
	public function isAutoChangeable(): bool {
		return in_array($this->origin, [Origin::Assistant, Origin::Template], true)
			&& $this->reviewState === ReviewState::Proposal
			&& $this->userModifiedAt === null
			&& $this->deletedAt === null;
	}

	public static function fromStrings(string $origin, string $reviewState, ?int $userModifiedAt, ?int $deletedAt = null): self {
		return new self(Origin::from($origin), ReviewState::from($reviewState), $userModifiedAt, $deletedAt);
	}
}
