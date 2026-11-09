<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

final class ShoppingFlowTest extends ServiceTestCase {
	private int $camp;

	protected function setUp(): void {
		parent::setUp();
		$this->camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$this->catering->saveNeeds($this->camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 42]]]);
		$this->recipes->import($this->camp, 'anna', ['milchreis', 'kartoffelsuppe', 'chili_sin_carne', 'spaghetti_tomatensosse']);
	}

	private function recipe(string $key): int {
		foreach ($this->recipes->list($this->camp, 'anna') as $recipe) {
			if ($recipe['templateKey'] === $key) {
				return $recipe['id'];
			}
		}
		return 0;
	}

	private function plan(string $day, string $type, string $recipe, array $extra = []): void {
		foreach ($this->planning->meals($this->camp, 'anna') as $meal) {
			if ($meal->getDay() === $day && $meal->getMealType() === $type) {
				$this->catering->saveMealPlan((int)$meal->getId(), 'anna', $extra ?: [
					'variants' => [['key' => 'std', 'label' => 'Alle', 'recipeId' => $this->recipe($recipe)]],
					'assignments' => ['none' => 'std'],
				]);
			}
		}
	}

	/** @return array<string, array<string, mixed>> */
	private function lines(int $trip = 0): array {
		return array_column($this->shopping->overview($this->camp, 'anna')['trips'][$trip]['lines'], null, 'name');
	}

	/** Technischer Schlüssel einer Position über ihren Namen */
	private function key(string $name, int $trip = 0): string {
		return $this->lines($trip)[$name]['key'];
	}

	private function tripId(int $index = 0): int {
		return $this->shopping->overview($this->camp, 'anna')['trips'][$index]['id'];
	}

	/** Regression Review 0.6.0: Der Standard-Einkauf entsteht mit dem Lager, nicht beim Lesen. */
	public function testDefaultTripIsCreatedWithTheCamp(): void {
		$overview = $this->shopping->overview($this->camp, 'anna');

		$this->assertNotNull($overview['trips'][0]['id']);
		$this->assertSame(['2027-07-23', 'Großeinkauf'], [$overview['trips'][0]['date'], $overview['trips'][0]['label']]);
		$this->assertSame($overview['trips'][0]['id'], $this->shopping->overview($this->camp, 'anna')['trips'][0]['id']);
	}

	public function testListIsBuiltFromRecipesInMealPlans(): void {
		$this->plan('2027-07-26', 'lunch', 'milchreis');
		$milk = $this->lines()['Milch'];

		// 39,85 Standardportionen: 4 l Milch je 10 Portionen ergeben 15,94 l, gekauft werden 16 l.
		$this->assertSame([16.0, 'l', 'open'], [$milk['quantity'], $milk['unit'], $milk['status']]);
		$this->assertSame('dairy', $milk['category']);
	}

	/** Regression Review 0.6.0: Nach mehr Anmeldungen erscheint genau der Rest als offener Bedarf. */
	public function testAdditionalDemandAfterPurchaseIsOpen(): void {
		$this->plan('2027-07-26', 'lunch', 'milchreis');
		$this->shopping->setPurchased($this->tripId(), 'anna', $this->key('Milch'), true);
		$this->assertSame('done', $this->lines()['Milch']['status']);

		$this->catering->saveNeeds($this->camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 60]]]);
		$milk = $this->lines()['Milch'];
		$this->assertSame('partial', $milk['status']);
		$this->assertTrue($milk['purchasedHere']);
		$this->assertSame(['quantity' => 16.0, 'unit' => 'l'], $milk['bought']);
		$this->assertSame(['quantity' => 6.9, 'unit' => 'l'], $milk['open']);

		// Erneut abhaken kauft genau den Rest dazu.
		$this->shopping->setPurchased($this->tripId(), 'anna', $this->key('Milch'), true);
		$this->assertSame('done', $this->lines()['Milch']['status']);
	}

	/** Regression Review 0.6.0: Vorräte werden mit Menge erfasst; Restbedarf bleibt offen. */
	public function testStockWithAmount(): void {
		$this->plan('2027-07-26', 'lunch', 'milchreis');
		$this->shopping->setStock($this->camp, 'anna', $this->key('Milchreis'), 2, 'kg');
		$rice = $this->lines()['Milchreis'];

		$this->assertSame('partial', $rice['status']);
		$this->assertSame(['quantity' => 2.0, 'unit' => 'kg'], $rice['stock']);
		$this->assertSame(['quantity' => 2.0, 'unit' => 'kg'], $rice['open']);

		try {
			$this->shopping->setStock($this->camp, 'anna', $this->key('Milchreis'), 2, 'Stück');
			$this->fail('Unpassende Einheit nicht abgelehnt');
		} catch (\InvalidArgumentException) {
			$this->assertTrue(true);
		}
		$this->shopping->setStock($this->camp, 'anna', $this->key('Milchreis'), null, 'kg');
		$this->assertSame('open', $this->lines()['Milchreis']['status']);
	}

	/** Regression Review 0.6.0: Glutenfreie und normale Produkte bleiben getrennte Positionen. */
	public function testGlutenFreeProductsAreSeparateLines(): void {
		$this->catering->saveNeeds($this->camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 40], ['key' => 'gluten_free', 'count' => 2]]]);
		$own = $this->recipes->create($this->camp, 'anna', ['title' => 'Glutenfreie Spaghetti', 'servings' => 10, 'diet' => 'vegan', 'contains' => [],
			'ingredients' => [['name' => 'Spaghetti', 'quantity' => 1.3, 'unit' => 'kg']], 'steps' => ['Kochen'], 'allergensChecked' => true]);
		$this->plan('2027-07-26', 'lunch', '', [
			'variants' => [
				['key' => 'std', 'label' => 'Alle', 'recipeId' => $this->recipe('spaghetti_tomatensosse')],
				['key' => 'gf', 'label' => 'Glutenfrei', 'satisfies' => ['gluten_free'], 'recipeId' => $own['id']],
			],
			'assignments' => ['none' => 'std', 'gluten_free' => 'gf'],
		]);

		$spaghetti = array_values(array_filter($this->shopping->overview($this->camp, 'anna')['trips'][0]['lines'], static fn (array $l): bool => $l['name'] === 'Spaghetti'));
		$this->assertCount(2, $spaghetti);
		$this->assertSame([[], ['glutenfrei']], array_map(static fn (array $l): array => $l['promises'], $spaghetti));
	}

	/** Regression Review 0.6.0: Verschieben und Löschen eines Termins verliert keine Einkäufe. */
	public function testTripChangesKeepPurchases(): void {
		$this->plan('2027-07-30', 'lunch', 'kartoffelsuppe');
		$this->shopping->addTrip($this->camp, 'anna', '2027-07-28', 'Frischeeinkauf');
		$fresh = $this->tripId(1);
		$this->shopping->setPurchased($fresh, 'anna', $this->key('Kartoffeln, mehligkochend', 1), true);
		$this->shopping->addExtra($fresh, 'anna', 'Brötchen', '40 Stück', 'bakery');

		// Verschieben: Die Kartoffeln bleiben gekauft.
		$this->shopping->updateTrip($fresh, 'anna', '2027-07-29', 'Frischeeinkauf');
		$this->assertSame('done', $this->lines(1)['Kartoffeln, mehligkochend']['status']);

		// Löschen: Einkauf und eigener Posten wandern zum verbleibenden Termin und bleiben korrigierbar.
		$result = $this->shopping->deleteTrip($fresh, 'anna');
		$this->assertSame(['target' => 'Großeinkauf', 'movedExtras' => 1, 'movedPurchases' => 1], $result);
		$trip = $this->shopping->overview($this->camp, 'anna')['trips'][0];
		$potatoes = array_column($trip['lines'], null, 'name')['Kartoffeln, mehligkochend'];
		$this->assertSame('done', $potatoes['status']);
		$this->assertTrue($potatoes['purchasedHere']);
		$this->assertSame('Brötchen', $trip['extras'][0]['name']);

		// Regression Review 0.6.1: Der übernommene Einkauf lässt sich aufheben.
		$this->shopping->setPurchased($trip['id'], 'anna', $potatoes['key'], false);
		$this->assertFalse(array_column($this->shopping->overview($this->camp, 'anna')['trips'][0]['lines'], null, 'name')['Kartoffeln, mehligkochend']['purchasedHere']);
	}

	/** Regression Review 0.6.1: Hier gekauft, aber nicht mehr eingeplant: sichtbar und korrigierbar. */
	public function testOrphanedPurchaseIsVisibleAndRemovable(): void {
		$this->plan('2027-07-30', 'lunch', 'kartoffelsuppe');
		$this->shopping->addTrip($this->camp, 'anna', '2027-07-28', 'Frischeeinkauf');
		$fresh = $this->tripId(1);
		$key = $this->key('Kartoffeln, mehligkochend', 1);
		$this->shopping->setPurchased($fresh, 'anna', $key, true);

		// Termin auf nach dem Bedarfstag verschoben: Die Kartoffeln gehören jetzt zum Großeinkauf.
		$this->shopping->updateTrip($fresh, 'anna', '2027-07-31', 'Frischeeinkauf');
		$trips = $this->shopping->overview($this->camp, 'anna')['trips'];
		$this->assertSame('open', array_column($trips[0]['lines'], null, 'name')['Kartoffeln, mehligkochend']['status']);
		$this->assertSame('Kartoffeln, mehligkochend', $trips[1]['orphans'][0]['name']);

		$this->shopping->setPurchased($fresh, 'anna', $key, false);
		$this->assertSame([], $this->shopping->overview($this->camp, 'anna')['trips'][1]['orphans']);
	}

	/** Regression Review 0.6.1: Fehlende Rezepte für Varianten machen die Liste vorläufig. */
	public function testVariantWithoutRecipeMakesListProvisional(): void {
		$this->catering->saveNeeds($this->camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 30], ['key' => 'vegetarian', 'count' => 12]]]);
		$this->plan('2027-07-26', 'lunch', '', [
			'variants' => [['key' => 'std', 'label' => 'Standard', 'recipeId' => $this->recipe('milchreis')], ['key' => 'veg', 'label' => 'Vegetarisch', 'satisfies' => ['vegetarian']]],
			'assignments' => ['none' => 'std', 'vegetarian' => 'veg'],
		]);
		$reasons = implode(' ', $this->shopping->overview($this->camp, 'anna')['reasons']);

		$this->assertStringContainsString('zusammen 12 Portionen noch kein Rezept', $reasons);
	}

	/** Regression Review 0.6.1: Widersprüche bei schweren Allergien machen die Liste vorläufig. */
	public function testSevereAllergyInconsistencyMakesListProvisional(): void {
		$this->catering->saveNeeds($this->camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 42]], 'severe' => ['peanuts' => 1]]);
		$overview = $this->shopping->overview($this->camp, 'anna');

		$this->assertTrue($overview['provisional']);
		$this->assertStringContainsString('Erdnüsse', implode(' ', $overview['reasons']));
	}

	/** Regression Review 0.6.1: Der Standard-Einkauf zieht bei verschobenem Lager mit. */
	public function testDefaultTripMovesWithTheCamp(): void {
		$input = Fixtures::summerCamp(['start' => '2027-08-07', 'end' => '2027-08-18']);
		$preview = $this->wizard->rerunPreview($this->camp, 'anna', $input);
		$moves = array_values(array_filter($preview['camp']['changes'], static fn (array $c): bool => $c['kind'] === 'shopping_trip'));
		$this->assertSame(['2027-07-23', '2027-08-06'], [$moves[0]['from'], $moves[0]['to']]);

		$this->wizard->rerunApply($this->camp, 'anna', $input, $preview['token']);
		$this->assertSame('2027-08-06', $this->shopping->overview($this->camp, 'anna')['trips'][0]['date']);
	}

	public function testEditedTripStaysButIsReported(): void {
		$this->shopping->updateTrip($this->tripId(), 'anna', '2027-07-22', 'Großeinkauf beim Großmarkt');
		$input = Fixtures::summerCamp(['start' => '2027-08-14', 'end' => '2027-08-25']);
		$preview = $this->wizard->rerunPreview($this->camp, 'anna', $input);
		$kinds = array_column($preview['camp']['changes'], 'kind');

		$this->assertNotContains('shopping_trip', $kinds);
		$this->assertContains('shopping_trip_check', $kinds);
		$this->wizard->rerunApply($this->camp, 'anna', $input, $preview['token']);
		$overview = $this->shopping->overview($this->camp, 'anna');
		$this->assertSame('2027-07-22', $overview['trips'][0]['date']);
		$this->assertStringContainsString('außerhalb des Lagerzeitraums', implode(' ', $overview['reasons']));
	}

	/** Regression Review 0.6.0: Kein Einkauf erst nach dem ersten Lagertag. */
	public function testFirstTripMustNotBeAfterTheStart(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->shopping->updateTrip($this->tripId(), 'anna', '2027-07-27', 'Großeinkauf');
	}

	public function testEarlyTripCannotBeDeletedWhenOnlyLateOnesRemain(): void {
		$this->shopping->addTrip($this->camp, 'anna', '2027-07-28', 'Frischeeinkauf');
		$this->expectException(\InvalidArgumentException::class);
		$this->shopping->deleteTrip($this->tripId(0), 'anna');
	}

	/** Regression Review 0.6.0: Unvollständige Angaben machen die Liste ausdrücklich vorläufig. */
	public function testIncompleteDataMarksListAsProvisional(): void {
		// Ohne geplante Mahlzeiten ist die Liste unvollständig und damit vorläufig.
		$initial = $this->shopping->overview($this->camp, 'anna');
		$this->assertTrue($initial['provisional']);
		$this->assertStringContainsString('noch keine Menüvarianten', implode(' ', $initial['reasons']));

		$this->catering->saveNeeds($this->camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 30], ['key' => 'unanswered', 'count' => 2]]]);
		$overview = $this->shopping->overview($this->camp, 'anna');
		$this->assertTrue($overview['provisional']);
		$this->assertStringContainsString('32 Personen, angemeldet sind 42', implode(' ', $overview['reasons']));
		$this->assertStringContainsString('ungeklärten', implode(' ', $overview['reasons']));
	}

	public function testExtras(): void {
		$extra = $this->shopping->addExtra($this->tripId(), 'anna', 'Brötchen', '70 Stück', 'bakery');
		$this->shopping->setExtraPurchased($extra['id'], 'anna', true);
		$trip = $this->shopping->overview($this->camp, 'anna')['trips'][0];
		$this->assertSame([['Brötchen', '70 Stück', 'Brot und Backwaren', true]], array_map(
			static fn (array $e): array => [$e['name'], $e['amount'], $e['categoryLabel'], $e['purchased']], $trip['extras']));

		$this->shopping->deleteExtra($extra['id'], 'anna');
		$this->assertSame([], $this->shopping->overview($this->camp, 'anna')['trips'][0]['extras']);
	}

	public function testHelpersDoNotSeeTheShoppingList(): void {
		$this->team->add($this->camp, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
		$this->team->add($this->camp, 'anna', 'group', 'kueche', ['kueche']);

		$this->assertCount(1, $this->shopping->overview($this->camp, 'dora')['trips']);
		$this->expectException(ForbiddenException::class);
		$this->shopping->overview($this->camp, 'ben');
	}
}
