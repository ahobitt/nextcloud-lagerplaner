<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Cookbook\CookbookLibrary;
use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

/** Anbindung an Nextcloud Cookbook (Konzept 9.6). */
final class CookbookFlowTest extends ServiceTestCase {
	private int $camp;
	private int $dal;

	protected function setUp(): void {
		parent::setUp();
		$this->camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$this->dal = $this->cookbookLibrary->add('anna', [
			'name' => 'Linsen-Dal', 'recipeYield' => 4, 'keywords' => 'vegan',
			'recipeIngredient' => ['250 g rote Linsen', '1 Dose Kokosmilch', 'Salz'],
			'recipeInstructions' => ['Alles kochen.'],
		]);
	}

	private function recipe(int $id): array {
		return $this->recipes->get($id, 'anna');
	}

	public function testImportCreatesAnUncheckedCopy(): void {
		$result = $this->cookbook->import($this->camp, 'anna', $this->dal);
		$detail = $this->recipe($result['id']);

		$this->assertSame(['Linsen-Dal', 4, 'vegan'], [$detail['recipe']['title'], $detail['recipe']['servings'], $detail['recipe']['diet']]);
		$this->assertFalse($detail['allergensChecked']);
		$this->assertSame($this->dal, $this->recipes->list($this->camp, 'anna')[0]['cookbook']['id']);
		$this->assertNotEmpty($result['review']);

		$library = $this->cookbook->library('anna', $this->camp);
		$this->assertSame($result['id'], $library['items'][0]['importedAs']);

		$this->expectException(\InvalidArgumentException::class);
		$this->cookbook->import($this->camp, 'anna', $this->dal);
	}

	public function testChangesInCookbookAreDetectedAndRefreshKeepsDietAndAllergens(): void {
		$id = $this->cookbook->import($this->camp, 'anna', $this->dal)['id'];
		$data = $this->recipe($id)['recipe'];
		$this->recipes->update($id, 'anna', ['contains' => ['soy'], 'allergensChecked' => true] + $data);
		$this->assertSame([], $this->cookbook->updates($this->camp, 'anna'));

		$this->cookbookLibrary->recipes['anna'][$this->dal] = ['data' => [
			'name' => 'Linsen-Dal', 'recipeYield' => 6, 'keywords' => '',
			'recipeIngredient' => ['300 g rote Linsen', '1 Dose Kokosmilch', 'Salz'], 'recipeInstructions' => ['Alles kochen.'],
		], 'modified' => 3000];
		$this->assertSame([$id => 'changed'], $this->cookbook->updates($this->camp, 'anna'));

		$this->cookbook->refresh($id, 'anna');
		$detail = $this->recipe($id);
		$this->assertSame([6, 300.0, 'vegan', ['soy'], false], [$detail['recipe']['servings'], $detail['recipe']['ingredients'][0]['quantity'], $detail['recipe']['diet'], $detail['recipe']['contains'], $detail['allergensChecked']]);
		$this->assertSame([], $this->cookbook->updates($this->camp, 'anna'));

		unset($this->cookbookLibrary->recipes['anna'][$this->dal]);
		$this->assertSame([$id => 'missing'], $this->cookbook->updates($this->camp, 'anna'));
	}

	public function testOnlyTheOwnerOfTheCollectionCanRefresh(): void {
		$this->team->add($this->camp, 'anna', 'group', 'kueche', ['kueche']);
		$id = $this->cookbook->import($this->camp, 'anna', $this->dal)['id'];

		$this->assertSame([], $this->cookbook->updates($this->camp, 'dora'));
		$this->expectException(ForbiddenException::class);
		$this->cookbook->refresh($id, 'dora');
	}

	public function testHelpersCannotImport(): void {
		$this->team->add($this->camp, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
		$this->cookbookLibrary->add('ben', ['name' => 'Bens Chili', 'recipeYield' => 4, 'recipeIngredient' => ['1 kg Bohnen']]);

		$this->expectException(ForbiddenException::class);
		$this->cookbook->import($this->camp, 'ben', array_key_first($this->cookbookLibrary->recipes['ben']));
	}

	/** Lagerrezepte in die Sammlung übernehmen, damit sie für künftige Lager bereitstehen. */
	public function testExportToCookbook(): void {
		$this->recipes->import($this->camp, 'anna', ['stockbrot']);
		$id = $this->recipes->list($this->camp, 'anna')[0]['id'];

		$created = $this->cookbook->export($id, 'anna');
		$json = $this->cookbookLibrary->recipes['anna'][$created['cookbookId']]['data'];
		$this->assertSame('Stockbrot', $json['name']);
		$this->assertContains('1 kg Weizenmehl', $json['recipeIngredient']);
		$this->assertSame($created['cookbookId'], $this->recipes->list($this->camp, 'anna')[0]['cookbook']['id']);

		$this->expectException(\InvalidArgumentException::class);
		$this->cookbook->export($id, 'anna');
	}

	public function testWithoutCookbook(): void {
		$this->cookbookLibrary->statuses['anna'] = CookbookLibrary::NO_FOLDER;
		$this->assertSame(['status' => 'no_folder', 'items' => []], $this->cookbook->library('anna', $this->camp));

		try {
			$this->cookbook->import($this->camp, 'anna', $this->dal);
			$this->fail('Import ohne Rezeptordner nicht abgelehnt');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('Cookbook einmal öffnen', $e->getMessage());
		}
	}
}
