<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Provenance\Origin;
use OCA\Lagerplaner\Domain\Provenance\Provenance;
use OCA\Lagerplaner\Domain\Provenance\ReviewState;
use PHPUnit\Framework\TestCase;

final class ProvenanceTest extends TestCase {
	public function testUntouchedAssistantProposalIsAutoChangeable(): void {
		$this->assertTrue((new Provenance(Origin::Assistant, ReviewState::Proposal))->isAutoChangeable());
		$this->assertTrue((new Provenance(Origin::Template, ReviewState::Proposal))->isAutoChangeable());
	}

	public function testConfirmedEditedOrForeignElementsAreProtected(): void {
		$this->assertFalse((new Provenance(Origin::Assistant, ReviewState::Confirmed))->isAutoChangeable());
		$this->assertFalse((new Provenance(Origin::Assistant, ReviewState::Proposal, 1_790_000_000))->isAutoChangeable());
		$this->assertFalse((new Provenance(Origin::Manual, ReviewState::Proposal))->isAutoChangeable());
		$this->assertFalse((new Provenance(Origin::Ai, ReviewState::Proposal))->isAutoChangeable());
		$this->assertFalse((new Provenance(Origin::GameImport, ReviewState::Proposal))->isAutoChangeable());
	}
}
