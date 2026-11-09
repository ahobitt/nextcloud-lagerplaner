<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Domain\Games\GameQuery;
use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

/** Druckfertige Unterlagen (Konzept 19). */
final class ExportFlowTest extends ServiceTestCase {
	private int $camp;

	protected function setUp(): void {
		parent::setUp();
		$this->camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
	}

	private function html(string $type, string $param = '', string $uid = 'anna'): string {
		return $this->exports->document($this->camp, $uid, $type, $param)['html'];
	}

	private function lunch(): int {
		foreach ($this->planning->meals($this->camp, 'anna') as $meal) {
			if ($meal->getDay() === '2027-07-26' && $meal->getMealType() === 'lunch') {
				return (int)$meal->getId();
			}
		}
		return 0;
	}

	/** Konzept 19.3: Das allgemeine Programm enthält keine Ernährungsangaben. */
	public function testProgramWithoutDietaryInformation(): void {
		$this->catering->saveNeeds($this->camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 30], ['key' => 'vegetarian', 'count' => 12]]]);
		$this->catering->saveMealPlan($this->lunch(), 'anna', ['variants' => [['key' => 'std', 'label' => 'Standard'], ['key' => 'veg', 'label' => 'Vegetarisch', 'satisfies' => ['vegetarian']]], 'assignments' => ['none' => 'std', 'vegetarian' => 'veg']]);
		$html = $this->html('program');

		$this->assertStringContainsString('Montag, 26. Juli 2027', $html);
		$this->assertStringContainsString('Mittagessen', $html);
		$this->assertStringContainsString('Bei schlechtem Wetter:', $html);
		$this->assertStringNotContainsString('Vegetarisch', $html);
		$this->assertStringNotContainsString('vegetarian', $html);
	}

	public function testTextIsEscaped(): void {
		$this->planning->createProgramItem($this->camp, 'anna', ['title' => '<script>alert(1)</script>', 'day' => '2027-07-26', 'startTime' => '20:00', 'durationMinutes' => 30]);
		$html = $this->html('day', '2027-07-26');

		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
	}

	public function testDayAndGroupDocuments(): void {
		$this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Roverrunde', 'day' => '2027-07-26', 'startTime' => '21:30', 'durationMinutes' => 30, 'groups' => ['rover']]);

		$day = $this->html('day', '2027-07-26');
		$this->assertStringNotContainsString('Dienstag, 27. Juli', $day);
		$this->assertStringContainsString('Roverrunde', $day);

		$this->assertStringContainsString('Roverrunde', $this->html('group', 'rover'));
		$this->assertStringNotContainsString('Roverrunde', $this->html('group', 'woelflinge'));
		$this->assertStringContainsString('Programm für Wölflinge', $this->html('group', 'woelflinge'));

		$this->expectException(\InvalidArgumentException::class);
		$this->html('day', '2027-09-01');
	}

	public function testGameInstructionsCarryAttribution(): void {
		$item = array_values(array_filter($this->planning->program($this->camp, 'anna'), static fn ($i): bool => $i->getTitle() === 'Kennenlernen und Lagerregeln'))[0];
		$game = $this->catalog->search(new GameQuery(text: 'fantasie'))['items'][0];
		$this->planning->attachGame((int)$item->getId(), 'anna', $game['id']);
		$html = $this->html('games');

		$this->assertStringContainsString('Fantasieball', $html);
		$this->assertStringContainsString('CC BY-NC-SA 4.0', $html);
		$this->assertStringContainsString('Dorothea Schümann', $html);
	}

	public function testTaskOverview(): void {
		$this->team->add($this->camp, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
		$this->planning->createTask($this->camp, 'anna', ['title' => 'Bus anfragen', 'assignees' => ['ben'], 'dueDate' => '2027-05-01', 'priority' => 'high']);
		$html = $this->html('tasks', '', 'ben');

		$this->assertStringContainsString('Bus anfragen', $html);
		$this->assertStringContainsString('Ben Berg', $html);
		$this->assertStringContainsString('01.05.2027', $html);
		$this->assertStringContainsString('wichtig', $html);
	}

	/** Konzept 19.3: Küchenunterlagen nur für Berechtigte. */
	public function testKitchenDocumentsAreProtected(): void {
		$this->team->add($this->camp, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
		$this->catering->saveNeeds($this->camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 30], ['key' => 'vegetarian', 'count' => 12]]]);
		$this->recipes->import($this->camp, 'anna', ['milchreis']);
		$this->catering->saveMealPlan($this->lunch(), 'anna', ['variants' => [['key' => 'std', 'label' => 'Alle', 'recipeId' => $this->recipes->list($this->camp, 'anna')[0]['id']]], 'assignments' => ['none' => 'std', 'vegetarian' => 'std']]);

		$meals = $this->html('meals');
		$this->assertStringContainsString('Vegetarisch', $meals);
		$this->assertStringContainsString('Alle: 42 Personen, Milchreis', $meals);
		$recipes = $this->html('recipes');
		$this->assertStringContainsString('Milchreis', $recipes);
		$this->assertStringContainsString('Angaben nicht geprüft', $recipes);

		foreach (['meals', 'recipes'] as $type) {
			try {
				$this->html($type, '', 'ben');
				$this->fail("$type für Mitarbeitende nicht gesperrt");
			} catch (ForbiddenException) {
				$this->assertTrue(true);
			}
		}
	}

	public function testUnknownTypeIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->html('everything');
	}
}
