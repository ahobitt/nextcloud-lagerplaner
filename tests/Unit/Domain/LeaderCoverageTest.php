<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Wizard\DraftGenerator;
use OCA\Lagerplaner\Domain\Wizard\LeaderCoverage;
use OCA\Lagerplaner\Domain\Wizard\WizardInput;
use PHPUnit\Framework\TestCase;

/** Rückmeldung aus dem Test: viele Kinder und keine Leitenden sollten auffallen. */
final class LeaderCoverageTest extends TestCase {
	public function testRules(): void {
		$this->assertNull(LeaderCoverage::hint(0, 0));
		$this->assertSame('warning', LeaderCoverage::hint(30, 0)['level']);
		$this->assertStringContainsString('keine Leitenden', LeaderCoverage::hint(30, 0)['text']);
		$this->assertStringContainsString('mindestens zwei', LeaderCoverage::hint(5, 1)['text']);
		$this->assertSame('info', LeaderCoverage::hint(30, 3)['level']);
		$this->assertStringContainsString('etwa 10 Kinder', LeaderCoverage::hint(30, 3)['text']);
		$this->assertNull(LeaderCoverage::hint(32, 4));
	}

	public function testDraftShowsTheHintFirst(): void {
		$input = Fixtures::summerCamp();
		foreach ($input['groups'] as &$group) {
			if ($group['key'] === 'leitende') {
				$group['count'] = 0;
			}
		}
		unset($group);
		$draft = (new DraftGenerator())->generate(WizardInput::fromArray($input), new \DateTimeImmutable('2027-03-01'));
		$this->assertStringContainsString('keine Leitenden', $draft->hints[0]);
		$normal = (new DraftGenerator())->generate(WizardInput::fromArray(Fixtures::summerCamp()), new \DateTimeImmutable('2027-03-01'));
		$this->assertSame([], array_filter($normal->hints, static fn (string $h): bool => str_contains($h, 'Leitende')));
	}
}
