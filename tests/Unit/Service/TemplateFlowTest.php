<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Domain\Wizard\InvalidWizardInput;
use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Service\NotFoundException;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

/** Programm- und Tagesvorlagen (Konzept 6.4) und ihre Nutzung im Assistenten. */
final class TemplateFlowTest extends ServiceTestCase {
	private int $camp;

	protected function setUp(): void {
		parent::setUp();
		$this->camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
	}

	private function itemsOn(int $camp, string $day, string $uid = 'anna'): array {
		$items = array_values(array_filter($this->planning->program($camp, $uid), static fn ($i): bool => $i->getDay() === $day));
		usort($items, static fn ($a, $b): int => strcmp($a->getStartTime(), $b->getStartTime()));
		return $items;
	}

	public function testItemTemplateWithMaterial(): void {
		$own = $this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Kompasskunde', 'day' => '2027-07-26', 'startTime' => '20:00', 'durationMinutes' => 60, 'weatherAlternative' => 'Im Gemeinschaftszelt']);
		$this->material->create($this->camp, 'anna', ['name' => 'Kompasse', 'category' => 'tools', 'unit' => 'Stück', 'quantity' => 12, 'programItemId' => (int)$own->getId()]);
		$template = $this->templates->saveItem('anna', (int)$own->getId(), 'Kompasskunde', false);
		$this->assertSame(['item', 1, 1], [$template['kind'], $template['version'], $template['itemCount']]);

		$result = $this->templates->apply('anna', $template['id'], $this->camp, '2027-07-29', '15:00');
		$this->assertSame(['created' => 1, 'materialsSkipped' => false], $result);
		$copy = array_values(array_filter($this->itemsOn($this->camp, '2027-07-29'), static fn ($i): bool => $i->getTitle() === 'Kompasskunde'))[0];
		$this->assertSame(['15:00', 60, 'Im Gemeinschaftszelt', 'user-template:' . $template['id'] . '@v1#0'], [$copy->getStartTime(), $copy->getDurationMinutes(), $copy->getWeatherAlternative(), $copy->getTemplateRef()]);
		$this->assertTrue($copy->provenance()->isAutoChangeable() === false);

		$compasses = array_column($this->material->overview($this->camp, 'anna')['items'], null, 'name')['Kompasse'];
		$this->assertCount(2, $compasses['uses']);
		$this->assertSame(12.0, $compasses['total']);
	}

	public function testDayTemplateDropsItemsForMissingAgeGroups(): void {
		$this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Roverrunde', 'day' => '2027-07-26', 'startTime' => '21:30', 'durationMinutes' => 30, 'groups' => ['rover']]);
		$template = $this->templates->saveDay('anna', $this->camp, '2027-07-26', 'Normaler Lagertag', true);
		$dayCount = count($this->itemsOn($this->camp, '2027-07-26'));
		$this->assertSame($dayCount, $template['itemCount']);

		$noRover = Fixtures::summerCamp(['name' => 'Wölflingswochenende']);
		foreach ($noRover['groups'] as $i => $group) {
			if ($group['key'] === 'rover') {
				$noRover['groups'][$i]['count'] = 0;
			}
		}
		$other = (int)$this->wizard->create('anna', $noRover)->getId();
		$before = count($this->itemsOn($other, '2027-07-27'));
		$result = $this->templates->apply('anna', $template['id'], $other, '2027-07-27');

		$this->assertSame($dayCount - 1, $result['created']);
		$this->assertSame($before + $dayCount - 1, count($this->itemsOn($other, '2027-07-27')));
	}

	public function testSharingAndOwnership(): void {
		$own = $this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Nachtwanderung', 'day' => '2027-07-27', 'startTime' => '21:30', 'durationMinutes' => 90]);
		$template = $this->templates->saveItem('anna', (int)$own->getId(), 'Nachtwanderung', false);

		$this->assertSame([], $this->templates->list('ben'));
		$this->templates->update('anna', $template['id'], ['shared' => true]);
		$this->assertSame(['Nachtwanderung', false, 'Anna Adler'], [$this->templates->list('ben')[0]['name'], $this->templates->list('ben')[0]['mine'], $this->templates->list('ben')[0]['owner']]);

		$this->directory->admins = ['carla'];
		$this->templates->update('carla', $template['id'], ['name' => 'Nachtwanderung (geprüft)']);
		$this->expectException(ForbiddenException::class);
		$this->templates->update('ben', $template['id'], ['name' => 'Meins']);
	}

	public function testReplacingRaisesTheVersion(): void {
		$own = $this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Lagerfeuer', 'day' => '2027-07-27', 'startTime' => '20:00', 'durationMinutes' => 60]);
		$template = $this->templates->saveItem('anna', (int)$own->getId(), 'Lagerfeuer', false);
		$second = $this->templates->saveItem('anna', (int)$own->getId(), 'Lagerfeuer', false, $template['id']);

		$this->assertSame([$template['id'], 2], [$second['id'], $second['version']]);
	}

	/** Konzept 6.4: Vorlagen bilden die Grundlage des Assistenten. */
	public function testAssistantUsesDayTemplateOnNormalDays(): void {
		$day = $this->templates->saveDay('anna', $this->camp, '2027-07-26', 'Unser Lagertag', false);
		$input = Fixtures::summerCamp(['name' => 'Herbstlager']);
		$input['program']['dayTemplateId'] = $day['id'];
		$camp = (int)$this->wizard->create('anna', $input)->getId();

		$program = $this->planning->program($camp, 'anna');
		$fromTemplate = array_filter($program, static fn ($i): bool => str_starts_with((string)$i->getTemplateRef(), 'user-template:' . $day['id'] . '@v1#'));
		$this->assertNotEmpty($fromTemplate);
		// Wanderung und Geländespiel bleiben eigene Tage nach den Regeln des Lagerplaners.
		$this->assertNotEmpty(array_filter($program, static fn ($i): bool => $i->getTitle() === 'Tageswanderung'));
		$this->assertSame([], $this->planning->programConflicts($camp, 'anna'));
		$this->assertSame($day['id'], $this->wizard->lastInput($camp, 'anna')['program']['dayTemplateId']);
	}

	public function testOthersPrivateTemplateIsRejectedInTheAssistant(): void {
		$day = $this->templates->saveDay('anna', $this->camp, '2027-07-26', 'Privat', false);
		$input = Fixtures::summerCamp();
		$input['program']['dayTemplateId'] = $day['id'];

		try {
			$this->wizard->preview($input, 'ben');
			$this->fail('Fremde private Vorlage nicht abgelehnt');
		} catch (InvalidWizardInput $e) {
			$this->assertArrayHasKey('program.dayTemplateId', $e->errors);
		}
		$this->expectException(NotFoundException::class);
		$this->templates->get($day['id'], 'ben');
	}

	/** Konzept 3.9: Vorschläge enthalten konkrete Schlechtwetteralternativen. */
	public function testProposalsComeWithWeatherAlternatives(): void {
		$needing = array_filter($this->planning->program($this->camp, 'anna'), static fn ($i): bool => (bool)$i->getWeatherAltNeeded());
		$this->assertNotEmpty($needing);
		foreach ($needing as $item) {
			$this->assertNotEmpty((string)$item->getWeatherAlternative(), $item->getTitle());
			// Die Oberfläche setzt „Bei schlechtem Wetter:“ davor; der Vorschlag selbst beginnt nicht damit.
			$this->assertStringNotContainsString('Bei Regen:', (string)$item->getWeatherAlternative());
		}
	}
}
