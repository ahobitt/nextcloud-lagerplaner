<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Wizard;

/** Ein vom Assistenten vorgeschlagenes Element mit stabiler Regelkennung. */
interface ProposedElement {
	/** Eindeutig je Lager und Elementart, zum Beispiel "2027-07-26|morning|woelflinge". */
	public function ruleKey(): string;

	/** Ändert sich, sobald sich ein inhaltlich relevantes Feld ändert. */
	public function fingerprint(): string;

	/** Vorlagenregel samt Version, aus der das Element stammt. */
	public function templateRef(): string;

	/** @return array<string, mixed> */
	public function toArray(): array;
}
