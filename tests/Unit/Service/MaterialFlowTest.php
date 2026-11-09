<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Domain\Games\GameQuery;
use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

final class MaterialFlowTest extends ServiceTestCase {
	private int $camp;

	protected function setUp(): void {
		parent::setUp();
		$this->camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
	}

	private function item(string $title, string $day = '2027-07-26'): int {
		foreach ($this->planning->program($this->camp, 'anna') as $item) {
			if (str_starts_with($item->getTitle(), $title) && $item->getDay() === $day) {
				return (int)$item->getId();
			}
		}
		$this->fail('Programmpunkt fehlt: ' . $title);
	}

	/** @return array<string, mixed> */
	private function find(string $name): array {
		return array_column($this->material->overview($this->camp, 'anna')['items'], null, 'name')[$name];
	}

	public function testReusableMaterialAcrossProgrammeItems(): void {
		$id = $this->material->create($this->camp, 'anna', ['name' => 'Seile', 'category' => 'ropes', 'unit' => 'Stück', 'reusable' => true,
			'quantity' => 10, 'programItemId' => $this->item('Vormittagsprogramm')])['id'];
		$this->material->addUse($id, 'anna', $this->item('Abendprogramm'), 8);
		$this->material->addUse($id, 'anna', null, 2);

		$ropes = $this->find('Seile');
		// Vormittag und Abend überschneiden sich nicht: 10 reichen, dazu 2 für das Lager.
		$this->assertSame(12.0, $ropes['total']);
		$this->assertSame('needed', $ropes['status']);
		$this->assertCount(3, $ropes['uses']);

		$this->material->update($id, 'anna', ['reusable' => false]);
		$this->assertSame(20.0, $this->find('Seile')['total']);
	}

	public function testStatusAndPermissions(): void {
		$this->team->add($this->camp, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
		$this->team->add($this->camp, 'anna', 'user', 'dora', ['material']);
		$id = $this->material->create($this->camp, 'dora', ['name' => 'Erste-Hilfe-Tasche', 'category' => 'first_aid', 'responsible' => 'ben'])['id'];

		$this->material->setStatus($id, 'ben', 'packed');
		$tasche = $this->find('Erste-Hilfe-Tasche');
		$this->assertSame(['packed', 'Ben Berg'], [$tasche['status'], $tasche['responsible']['displayName']]);

		$this->expectException(ForbiddenException::class);
		$this->material->update($id, 'ben', ['name' => 'Anders']);
	}

	public function testResponsibleMustHaveAccess(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->material->create($this->camp, 'anna', ['name' => 'Beil', 'category' => 'tools', 'responsible' => 'emil']);
	}

	/** Konzept 11.3: Material aus eingeplanten Spielen wird vorgeschlagen und zusammengeführt. */
	public function testSuggestionsFromPlannedGames(): void {
		$game = $this->catalog->search(new GameQuery(text: 'fantasie'))['items'][0];
		$this->planning->attachGame($this->item('Kennenlernen und Lagerregeln', '2027-07-24'), 'anna', $game['id']);
		$suggestions = $this->material->overview($this->camp, 'anna')['suggestions'];
		$this->assertSame(['Papier & Stifte', 'Stühle oder Teppichfliesen'], array_column($suggestions, 'name'));

		$this->material->takeSuggestion($this->camp, 'anna', $suggestions[0]['ref']);
		$this->material->dismissSuggestion($this->camp, 'anna', $suggestions[1]['ref']);
		$this->assertSame([], $this->material->overview($this->camp, 'anna')['suggestions']);

		$paper = $this->find('Papier & Stifte');
		$this->assertSame(['craft', 1, true], [$paper['category'], $paper['openQuantities'], $paper['uses'][0]['fromGame']]);

		// Entfernt man den Einsatz, kommt der Vorschlag nicht wieder.
		$this->material->removeUse($paper['uses'][0]['id'], 'anna');
		$this->assertSame([], $this->material->overview($this->camp, 'anna')['suggestions']);
	}

	public function testSuggestionMergesIntoExistingMaterial(): void {
		$this->material->create($this->camp, 'anna', ['name' => 'Papier & Stifte', 'category' => 'craft', 'quantity' => 30]);
		$game = $this->catalog->search(new GameQuery(text: 'fantasie'))['items'][0];
		$this->planning->attachGame($this->item('Kennenlernen und Lagerregeln', '2027-07-24'), 'anna', $game['id']);
		$ref = $this->material->overview($this->camp, 'anna')['suggestions'][0]['ref'];

		$this->material->takeSuggestion($this->camp, 'anna', $ref);
		$this->assertCount(1, array_filter($this->material->overview($this->camp, 'anna')['items'], static fn (array $i): bool => $i['name'] === 'Papier & Stifte'));
		$this->assertCount(2, $this->find('Papier & Stifte')['uses']);
	}

	/** Programmpunkte mit Material sind für den Assistenten bearbeitet und bleiben erhalten. */
	public function testMaterialProtectsItemFromRerun(): void {
		$itemId = $this->item('Kennenlernen und Lagerregeln', '2027-07-24');
		$this->material->create($this->camp, 'anna', ['name' => 'Namensschilder', 'category' => 'craft', 'quantity' => 42, 'programItemId' => $itemId]);

		$moved = Fixtures::summerCamp(['start' => '2027-08-07', 'end' => '2027-08-18']);
		$preview = $this->wizard->rerunPreview($this->camp, 'anna', $moved);
		$this->wizard->rerunApply($this->camp, 'anna', $moved, $preview['token']);

		$this->assertSame($itemId, $this->find('Namensschilder')['uses'][0]['programItemId']);
	}

	public function testDeletingOwnProgrammeItemRemovesItsMaterialUses(): void {
		$own = $this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Bastelstunde', 'day' => '2027-07-26', 'startTime' => '20:00', 'durationMinutes' => 45]);
		$this->material->create($this->camp, 'anna', ['name' => 'Kleber', 'category' => 'craft', 'quantity' => 5, 'programItemId' => (int)$own->getId()]);

		$this->planning->deleteProgramItem((int)$own->getId(), 'anna');
		$this->assertSame([], $this->find('Kleber')['uses']);
	}
}
