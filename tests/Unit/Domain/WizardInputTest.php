<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Wizard\InvalidWizardInput;
use OCA\Lagerplaner\Domain\Wizard\WizardInput;
use PHPUnit\Framework\TestCase;

final class WizardInputTest extends TestCase {
	public function testOnlyNameAndDatesAreRequired(): void {
		$input = WizardInput::fromArray(['name' => 'Pfingstlager', 'start' => '2027-05-14', 'end' => '2027-05-17']);

		$this->assertSame('tent', $input->campType);
		$this->assertSame(4, $input->dayCount());
		$this->assertCount(5, $input->groups);
		$this->assertSame(0, $input->totalCount());
		$this->assertFalse($input->locationKnown);
		$this->assertSame(['breakfast', 'lunch', 'dinner'], $input->meals);
	}

	public function testMissingFieldsAreReportedPerField(): void {
		try {
			WizardInput::fromArray(['name' => ' ', 'start' => '2027-02-30']);
			$this->fail('Eingabe hätte abgelehnt werden müssen.');
		} catch (InvalidWizardInput $e) {
			$this->assertArrayHasKey('name', $e->errors);
			$this->assertArrayHasKey('start', $e->errors);
			$this->assertArrayHasKey('end', $e->errors);
		}
	}

	public function testEndBeforeStartIsRejected(): void {
		$this->expectException(InvalidWizardInput::class);
		WizardInput::fromArray(['name' => 'X', 'start' => '2027-07-10', 'end' => '2027-07-09']);
	}

	public function testVeryLongCampIsRejected(): void {
		$this->expectException(InvalidWizardInput::class);
		WizardInput::fromArray(['name' => 'X', 'start' => '2027-07-01', 'end' => '2027-08-15']);
	}

	public function testKnownLocationNeedsAName(): void {
		try {
			WizardInput::fromArray(['name' => 'X', 'start' => '2027-07-01', 'end' => '2027-07-03', 'location' => ['known' => true]]);
			$this->fail('Eingabe hätte abgelehnt werden müssen.');
		} catch (InvalidWizardInput $e) {
			$this->assertArrayHasKey('location.name', $e->errors);
		}
	}

	public function testGroupsUseDefaultsAndCountLeadersSeparately(): void {
		$input = WizardInput::fromArray(Fixtures::summerCamp());

		$this->assertSame(42, $input->totalCount());
		$this->assertSame(5, $input->leaderCount());
		$this->assertCount(4, $input->participantGroups());
		$this->assertSame(0.75, $input->groups[0]->portionFactor);
		$this->assertSame('Wölflinge', $input->groups[0]->label);
	}

	public function testInvalidGroupCountIsRejected(): void {
		$this->expectException(InvalidWizardInput::class);
		WizardInput::fromArray(['name' => 'X', 'start' => '2027-07-01', 'end' => '2027-07-03', 'groups' => [['key' => 'rover', 'count' => -3]]]);
	}

	/**
	 * Regression Review 0.1.0: unplausible oder zu große Eingaben werden mit Feldfehler abgelehnt.
	 */
	public function testLimitsAndTypesAreEnforced(): void {
		$base = ['name' => 'X', 'start' => '2027-07-01', 'end' => '2027-07-03'];
		$cases = [
			'groups.0.blockMinutes' => ['groups' => [['key' => 'rover', 'count' => 5, 'blockMinutes' => -300]]],
			'groups' => ['groups' => array_map(static fn (int $i): array => ['key' => "g$i", 'count' => 10], range(1, 120))],
			'groups.0.count' => ['groups' => [['key' => 'rover', 'count' => 600000]]],
			'program.theme' => ['program' => ['theme' => str_repeat('x', 121)]],
			'groups.0.label' => ['groups' => [['key' => 'rover', 'label' => str_repeat('y', 61)]]],
			'organizer' => ['organizer' => str_repeat('z', 201)],
			'location.region' => ['location' => ['region' => ['nicht', 'text']]],
			'program.hike' => ['program' => ['hike' => 'false']],
			'catering.meals' => ['catering' => ['meals' => ['breakfast', 'midnight']]],
			'name' => ['name' => str_repeat('n', 201)],
		];
		foreach ($cases as $field => $extra) {
			try {
				WizardInput::fromArray(array_replace($base, $extra));
				$this->fail('Nicht abgelehnt: ' . $field);
			} catch (InvalidWizardInput $e) {
				$this->assertArrayHasKey($field, $e->errors);
			}
		}
	}

	public function testTotalAcrossGroupsIsLimited(): void {
		$groups = array_map(static fn (int $i): array => ['key' => "g$i", 'count' => 1000], range(1, 6));
		try {
			WizardInput::fromArray(['name' => 'X', 'start' => '2027-07-01', 'end' => '2027-07-03', 'groups' => $groups]);
			$this->fail('6000 Personen hätten abgelehnt werden müssen.');
		} catch (InvalidWizardInput $e) {
			$this->assertArrayHasKey('groups', $e->errors);
		}
	}

	public function testNormalisedInputRoundTrips(): void {
		$input = WizardInput::fromArray(Fixtures::summerCamp());
		$again = WizardInput::fromArray($input->toArray());

		$this->assertSame($input->toArray(), $again->toArray());
	}
}
