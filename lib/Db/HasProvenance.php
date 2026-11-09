<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCA\Lagerplaner\Domain\Provenance\Origin;
use OCA\Lagerplaner\Domain\Provenance\Provenance;
use OCA\Lagerplaner\Domain\Provenance\ReviewState;
use OCA\Lagerplaner\Domain\Rerun\ExistingElement;
use OCP\DB\Types;

/**
 * Herkunfts- und Bearbeitungsfelder für Planungselemente (Konzept 17.6).
 *
 * @method string getOrigin()
 * @method void setOrigin(string $origin)
 * @method string getReviewState()
 * @method void setReviewState(string $reviewState)
 * @method int|null getUserModifiedAt()
 * @method void setUserModifiedAt(?int $userModifiedAt)
 * @method int|null getGenRunId()
 * @method void setGenRunId(?int $genRunId)
 * @method string|null getTemplateRef()
 * @method void setTemplateRef(?string $templateRef)
 * @method string|null getRuleKey()
 * @method void setRuleKey(?string $ruleKey)
 * @method string|null getFingerprint()
 * @method void setFingerprint(?string $fingerprint)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $deletedAt)
 */
trait HasProvenance {
	protected $origin = 'manual';
	protected $reviewState = 'confirmed';
	protected $userModifiedAt;
	protected $genRunId;
	protected $templateRef;
	protected $ruleKey;
	protected $fingerprint;
	protected $createdAt;
	protected $updatedAt;
	/** Bewusst gelöschter Vorschlag; bleibt als Ausschluss gespeichert (Konzept 3.10). */
	protected $deletedAt;

	protected function addProvenanceTypes(): void {
		$this->addType('deletedAt', Types::BIGINT);
		$this->addType('userModifiedAt', Types::BIGINT);
		$this->addType('genRunId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}

	public function provenance(): Provenance {
		return Provenance::fromStrings($this->origin, $this->reviewState, $this->userModifiedAt, $this->deletedAt);
	}

	public function asExisting(string $label): ExistingElement {
		return new ExistingElement((int)$this->id, $this->ruleKey, (string)$this->fingerprint, $this->provenance(), $label);
	}

	/** Markiert das Element als Vorschlag eines Assistentenlaufs. */
	public function markAsProposal(string $ruleKey, string $fingerprint, string $templateRef, int $runId, int $now): void {
		$this->setOrigin(Origin::Assistant->value);
		$this->setReviewState(ReviewState::Proposal->value);
		$this->setUserModifiedAt(null);
		$this->setRuleKey($ruleKey);
		$this->setFingerprint($fingerprint);
		$this->setTemplateRef($templateRef);
		$this->setGenRunId($runId);
		$this->setUpdatedAt($now);
		if ($this->createdAt === null) {
			$this->setCreatedAt($now);
		}
	}

	public function markConfirmed(int $now): void {
		$this->setReviewState(ReviewState::Confirmed->value);
		$this->setUpdatedAt($now);
	}

	public function markDeleted(int $now): void {
		$this->setDeletedAt($now);
		$this->setUpdatedAt($now);
	}

	public function markRestored(int $now): void {
		$this->setDeletedAt(null);
		$this->setUpdatedAt($now);
	}

	/** Manuell angelegt und von Anfang an bestätigt. */
	public function markManual(int $now): void {
		$this->setOrigin(Origin::Manual->value);
		$this->setReviewState(ReviewState::Confirmed->value);
		$this->setCreatedAt($now);
		$this->setUpdatedAt($now);
	}

	/** Nach einer manuellen Änderung ist das Element vor automatischen Änderungen geschützt. */
	public function markUserModified(int $now): void {
		$this->setUserModifiedAt($now);
		$this->setUpdatedAt($now);
	}

	/** @return array<string, mixed> */
	protected function provenanceJson(): array {
		return [
			'origin' => $this->origin,
			'reviewState' => $this->reviewState,
			'userModifiedAt' => $this->userModifiedAt,
			'genRunId' => $this->genRunId,
			'templateRef' => $this->templateRef,
			'deletedAt' => $this->deletedAt,
			'autoChangeable' => $this->provenance()->isAutoChangeable(),
		];
	}
}
