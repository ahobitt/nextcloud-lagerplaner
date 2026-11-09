<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Rerun;

use OCA\Lagerplaner\Domain\Wizard\ProposedElement;

/**
 * Vergleicht gespeicherte Elemente mit einem neuen Entwurf (Konzept 3.10).
 *
 * Grundsätze:
 * - Bestätigte oder manuell geänderte Elemente werden nie automatisch verändert oder gelöscht.
 * - Manuell angelegte Elemente ohne Regelkennung werden nicht betrachtet.
 * - Bewusst gelöschte Vorschläge bleiben als Ausschluss gespeichert und werden nicht
 *   erneut angelegt. Wiederherstellen darf, wer das Programm bearbeiten darf (Lagerleitung
 *   und Programmteam): Wer einen Vorschlag löschen darf, soll ihn auch zurückholen können.
 * - Zu einer Regelkennung gibt es höchstens einen automatisch änderbaren Vorschlag.
 *   Liegt neben einem geschützten Element bereits ein solcher Vorschlag (eine frühere
 *   Alternative), wird er weitergeführt statt eine weitere Alternative anzulegen.
 */
final class RerunPlanner {
	/**
	 * @param list<ExistingElement> $existing
	 * @param list<ProposedElement> $proposed
	 * @param bool $alternativeOnConflict neuen Vorschlag bei Konflikt zusätzlich anlegen (sinnvoll für Programmpunkte)
	 */
	public function plan(array $existing, array $proposed, bool $alternativeOnConflict): ChangeSet {
		$byKey = [];
		foreach ($proposed as $element) {
			$key = $element->ruleKey();
			if (isset($byKey[$key])) {
				throw new \LogicException('Doppelte Regelkennung im Entwurf: ' . $key);
			}
			$byKey[$key] = $element;
		}

		/** @var array<string, list<ExistingElement>> $groups */
		$groups = [];
		foreach ($existing as $element) {
			if ($element->ruleKey !== null) {
				$groups[$element->ruleKey][] = $element;
			}
		}

		$changes = [];
		$unchanged = 0;

		foreach ($groups as $key => $elements) {
			$candidate = $byKey[$key] ?? null;
			$deleted = array_values(array_filter($elements, static fn (ExistingElement $e): bool => $e->provenance->isDeleted()));
			$active = array_values(array_filter($elements, static fn (ExistingElement $e): bool => !$e->provenance->isDeleted()));
			$auto = array_values(array_filter($active, static fn (ExistingElement $e): bool => $e->provenance->isAutoChangeable()));
			$protected = array_values(array_filter($active, static fn (ExistingElement $e): bool => !$e->provenance->isAutoChangeable()));

			if ($candidate === null) {
				foreach ($auto as $element) {
					$changes[] = new Change(ChangeType::Remove, $element, null);
				}
				foreach ($protected as $element) {
					$changes[] = new Change(ChangeType::Orphaned, $element, null);
				}
				continue;
			}

			if ($deleted !== []) {
				$changes[] = new Change(ChangeType::Suppressed, $deleted[0], $candidate);
			}

			// Höchstens ein automatisch änderbares Element führt den Vorschlag weiter.
			foreach ($auto as $i => $element) {
				if ($i > 0) {
					$changes[] = new Change(ChangeType::Remove, $element, null);
				} elseif ($element->fingerprint === $candidate->fingerprint()) {
					$unchanged++;
				} else {
					$changes[] = new Change(ChangeType::Update, $element, $candidate);
				}
			}

			$proposalAlreadyPresent = $auto !== [] || $deleted !== [];
			foreach ($protected as $element) {
				if ($element->fingerprint === $candidate->fingerprint()) {
					$unchanged++;
					$proposalAlreadyPresent = true;
				}
			}
			foreach ($protected as $element) {
				if ($element->fingerprint === $candidate->fingerprint()) {
					continue;
				}
				$withAlternative = $alternativeOnConflict && !$proposalAlreadyPresent;
				$changes[] = new Change(ChangeType::Conflict, $element, $candidate, $withAlternative);
				$proposalAlreadyPresent = $proposalAlreadyPresent || $withAlternative;
			}
		}

		foreach ($byKey as $key => $element) {
			if (!isset($groups[$key])) {
				$changes[] = new Change(ChangeType::Add, null, $element);
			}
		}

		return new ChangeSet($changes, $unchanged);
	}
}
