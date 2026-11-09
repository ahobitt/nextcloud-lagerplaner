<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Recipes\Recipe;
use OCA\Lagerplaner\Domain\Recipes\Unit;
use OCA\Lagerplaner\Domain\Shopping\CoverageAllocator;
use OCA\Lagerplaner\Domain\Shopping\Demand;
use OCA\Lagerplaner\Domain\Shopping\GroceryCategory;
use OCA\Lagerplaner\Domain\Shopping\ShoppingCalculator;
use OCA\Lagerplaner\Domain\Shopping\ShoppingLine;
use PHPUnit\Framework\TestCase;

final class ShoppingTest extends TestCase {
	/** @var array<string, Recipe> */
	private array $recipes = [];

	protected function setUp(): void {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../data/starter-recipes.json'), true, 32, JSON_THROW_ON_ERROR);
		foreach ($data['recipes'] as $entry) {
			$this->recipes[$entry['key']] = Recipe::fromArray($entry);
		}
	}

	/** @param list<ShoppingLine> $lines */
	private function line(array $lines, string $name): ?ShoppingLine {
		foreach ($lines as $line) {
			if ($line->name === $name) {
				return $line;
			}
		}
		return null;
	}

	public function testSameIngredientsAreSummedAcrossMeals(): void {
		$lists = (new ShoppingCalculator())->calculate([
			new Demand('2027-07-25', 'lunch', $this->recipes['milchreis'], 40),
			new Demand('2027-07-26', 'breakfast', $this->recipes['haferbrei'], 40),
		], ['2027-07-23']);

		// Milchreis 4 l je 10 Portionen, Haferbrei 2 l je 10: zusammen 24 l für 40 Portionen.
		$milk = $this->line($lists[0], 'Milch');
		$this->assertSame(['quantity' => 24.0, 'unit' => 'l'], ShoppingLine::buyable($milk->family, $milk->amount));
		$this->assertSame(['2027-07-25', '2027-07-26'], $milk->days);
		$this->assertNull($this->line($lists[0], 'Wasser'));
	}

	public function testCountablesAreRoundedOnlyAtTheEnd(): void {
		// 2 × 2,5 Zwiebeln ergeben 5, nicht 2 × 3.
		$chili = $this->recipes['chili_sin_carne'];
		$lists = (new ShoppingCalculator())->calculate([
			new Demand('2027-07-25', 'lunch', $chili, 6.25),
			new Demand('2027-07-28', 'lunch', $chili, 6.25),
		], ['2027-07-23']);
		$onions = $this->line($lists[0], 'Zwiebeln');

		$this->assertSame(['quantity' => 5.0, 'unit' => 'Stück'], ShoppingLine::buyable($onions->family, $onions->amount));
	}

	public function testFreshGoodsGoToTheLatestTripBeforeTheMealAndShelfStableToTheFirst(): void {
		$lists = (new ShoppingCalculator())->calculate([
			new Demand('2027-07-25', 'lunch', $this->recipes['kartoffelsuppe'], 40),
			new Demand('2027-07-30', 'lunch', $this->recipes['kartoffelsuppe'], 40),
		], ['2027-07-23', '2027-07-28']);

		$firstPotatoes = $this->line($lists[0], 'Kartoffeln, mehligkochend');
		$secondPotatoes = $this->line($lists[1], 'Kartoffeln, mehligkochend');
		$this->assertSame(['2027-07-25'], $firstPotatoes->days);
		$this->assertSame(['2027-07-30'], $secondPotatoes->days);
		$this->assertNull($this->line($lists[1], 'Gemüsebrühe'));
		$this->assertSame(['2027-07-25', '2027-07-30'], $this->line($lists[0], 'Gemüsebrühe')->days);
	}

	public function testTripOrderDoesNotDependOnInputOrder(): void {
		$lists = (new ShoppingCalculator())->calculate(
			[new Demand('2027-07-30', 'lunch', $this->recipes['kartoffelsuppe'], 10)],
			['2027-07-28', '2027-07-23'],
		);
		$this->assertNotNull($this->line($lists[0], 'Kartoffeln, mehligkochend'));
		$this->assertNotNull($this->line($lists[1], 'Gemüsebrühe'));
	}

	public function testIncompatibleUnitsAreFlaggedNotConverted(): void {
		$soup = $this->recipes['kartoffelsuppe'];
		$data = $soup->toArray();
		$data['title'] = 'Andere Suppe';
		$data['ingredients'][1] = ['name' => 'Möhren', 'quantity' => 3, 'unit' => 'Stück', 'note' => ''];
		$lists = (new ShoppingCalculator())->calculate([
			new Demand('2027-07-25', 'lunch', $soup, 10),
			new Demand('2027-07-26', 'lunch', Recipe::fromArray($data), 10),
		], ['2027-07-23']);

		$carrots = array_values(array_filter($lists[0], static fn (ShoppingLine $l): bool => $l->name === 'Möhren'));
		$this->assertCount(2, $carrots);
		$this->assertTrue($carrots[0]->needsReview);
		$this->assertTrue($carrots[1]->needsReview);
	}

	public function testBuyableAmountsRoundUp(): void {
		$this->assertSame(['quantity' => 1.3, 'unit' => 'kg'], ShoppingLine::buyable('g', 1201));
		$this->assertSame(['quantity' => 260.0, 'unit' => 'g'], ShoppingLine::buyable('g', 251));
		$this->assertSame(['quantity' => 1.5, 'unit' => 'EL'], ShoppingLine::buyable('EL', 1.2));
		$this->assertSame(['quantity' => null, 'unit' => 'nach Geschmack'], ShoppingLine::buyable('taste', 0));
	}

	public function testLargeSpoonAmountsGetAnApproximateVolume(): void {
		$oil = new ShoppingLine('rapsöl|EL', 'Rapsöl', 'EL', 143.5, GroceryCategory::Spices, [], [], false);
		$salt = new ShoppingLine('salz|Prise', 'Salz', 'Prise', 44, GroceryCategory::Spices, [], [], false);

		$this->assertSame(['quantity' => 2.2, 'unit' => 'l'], $oil->approximateVolume());
		$this->assertNull($salt->approximateVolume());
		$this->assertNull((new ShoppingLine('zimt|TL', 'Zimt', 'TL', 8, GroceryCategory::Spices, [], [], false))->approximateVolume());
	}

	private function pasta(string $title, string $name, string $note): Recipe {
		return Recipe::fromArray(['title' => $title, 'servings' => 10, 'diet' => 'vegan', 'contains' => [],
			'ingredients' => [['name' => $name, 'quantity' => 1, 'unit' => 'kg', 'note' => $note]], 'steps' => ['Kochen']]);
	}

	/** Regression Review 0.6.0: Unterschiedliche Produkte werden nie zusammengelegt. */
	public function testProductIdentityKeepsVariantsApart(): void {
		$lists = (new ShoppingCalculator())->calculate([
			new Demand('2027-07-25', 'lunch', $this->pasta('Normal', 'Nudeln', 'glutenhaltig'), 10),
			new Demand('2027-07-25', 'lunch', $this->pasta('GF', 'Nudeln (glutenfrei)', ''), 10),
			new Demand('2027-07-26', 'lunch', $this->pasta('Normal 2', 'Nudeln', ''), 10),
			new Demand('2027-07-26', 'lunch', $this->pasta('GF-Variante', 'Nudeln', ''), 10, ['gluten_free']),
		], ['2027-07-23']);

		$this->assertCount(4, $lists[0]);
		$promised = array_values(array_filter($lists[0], static fn (ShoppingLine $l): bool => $l->promises !== []));
		$this->assertCount(1, $promised);
		$this->assertSame(['glutenfrei'], $promised[0]->promiseLabels());
		$this->assertSame(1000.0, $promised[0]->amount);
	}

	/** Regression Review 0.6.0: Ein Einkauf nach dem Bedarfstag wird erkannt. */
	public function testLateTripIsFlagged(): void {
		$late = (new ShoppingCalculator())->calculate([new Demand('2027-07-24', 'lunch', $this->recipes['kartoffelsuppe'], 10)], ['2027-07-28']);
		$this->assertTrue($late[0][0]->late);

		$onTime = (new ShoppingCalculator())->calculate([new Demand('2027-07-24', 'lunch', $this->recipes['kartoffelsuppe'], 10)], ['2027-07-23']);
		$this->assertFalse($onTime[0][0]->late);
	}

	private function rice(float $grams): ShoppingLine {
		return new ShoppingLine('reis', 'Reis', 'g', $grams, GroceryCategory::Dry, [], [], false);
	}

	/** Regression Review 0.6.0: Mehrbedarf nach dem Einkauf ist genau die fehlende Menge. */
	public function testShortfallIsTheMissingAmount(): void {
		$coverage = (new CoverageAllocator())->allocate([0 => [$this->rice(5000)]], [0], [], ['reis' => [0 => 3000.0]]);
		$rice = $coverage[0]['reis'];

		$this->assertSame(2000.0, $rice->open);
		$this->assertTrue($rice->purchasedHere);
		$this->assertFalse($rice->isDone());
	}

	/** Regression Review 0.6.0: Vorräte zählen mit ihrer Menge, nicht als „alles da“. */
	public function testStockCoversOnlyItsAmount(): void {
		$coverage = (new CoverageAllocator())->allocate([0 => [$this->rice(4000)]], [0], ['reis' => 2000.0], []);

		$this->assertSame([2000.0, 2000.0], [$coverage[0]['reis']->fromStock, $coverage[0]['reis']->open]);
	}

	/** Regression Review 0.6.1: Gekauftes bleibt bei seinem Einkaufstermin. */
	public function testPurchaseStaysWithItsTrip(): void {
		$coverage = (new CoverageAllocator())->allocate([0 => [$this->rice(10000)], 1 => [$this->rice(5000)]], [0, 1], [], ['reis' => [1 => 5000.0]]);

		$this->assertSame(10000.0, $coverage[0]['reis']->open);
		$this->assertTrue($coverage[1]['reis']->isDone());
		$this->assertSame(5000.0, $coverage[1]['reis']->fromOwnPurchase);
	}

	/** Überschuss wandert nur zu späteren Terminen: Später Gekauftes hilft nicht für früher. */
	public function testSurplusOnlyCarriesForward(): void {
		// Eine Position ist vom späteren auf den früheren Termin gewandert; gekauft wurde am späteren.
		$back = (new CoverageAllocator())->allocate([0 => [$this->rice(3000)], 1 => []], [0, 1], [], ['reis' => [1 => 3000.0]]);
		$this->assertSame(3000.0, $back[0]['reis']->open);

		// Am früheren Termin zu viel gekauft: Der Überschuss deckt den späteren und ist dort sichtbar.
		$forward = (new CoverageAllocator())->allocate([0 => [$this->rice(3000)], 1 => [$this->rice(6000)]], [0, 1], [], ['reis' => [0 => 4000.0]]);
		$this->assertTrue($forward[0]['reis']->isDone());
		$this->assertSame([1000.0, 5000.0], [$forward[1]['reis']->fromEarlierPurchases, $forward[1]['reis']->open]);
	}

	/** Regression Review 0.6.1: Keine prozentuale Toleranz; 800 g fehlen wirklich. */
	public function testMissingAmountsAreNotHiddenByTolerance(): void {
		$coverage = (new CoverageAllocator())->allocate([0 => [$this->rice(50000)]], [0], [], ['reis' => [0 => 49200.0]]);
		$this->assertEqualsWithDelta(800.0, $coverage[0]['reis']->open, 0.0001);

		// Nur Rechenungenauigkeiten werden übergangen.
		$exact = (new CoverageAllocator())->allocate([0 => [$this->rice(0.1 + 0.2)]], [0], [], ['reis' => [0 => 0.3]]);
		$this->assertTrue($exact[0]['reis']->isDone());
	}

	/** Regression Review 0.6.1: Schlüssel sind kurz und Trennzeichen erzeugen keine Verwechslung. */
	public function testKeysAreShortAndUnambiguous(): void {
		$recipe = static fn (string $name, string $note): Recipe => Recipe::fromArray(['title' => 'X', 'servings' => 10, 'diet' => 'vegan', 'contains' => [],
			'ingredients' => [['name' => $name, 'quantity' => 10, 'unit' => 'g', 'note' => $note]], 'steps' => ['x']]);
		$lists = (new ShoppingCalculator())->calculate([
			new Demand('2027-07-25', 'lunch', $recipe('a|b', 'c'), 10),
			new Demand('2027-07-25', 'lunch', $recipe('a', 'b|c'), 20),
			new Demand('2027-07-25', 'lunch', $recipe(str_repeat('Bio-Vollkornspaghetti aus Hartweizen ', 2), str_repeat('je 500 g Packung, ', 4)), 10,
				['egg_free', 'gluten_free', 'lactose_free', 'milk_free', 'peanut_free', 'soy_free', 'tree_nut_free']),
		], ['2027-07-23']);

		$this->assertCount(3, $lists[0]);
		foreach ($lists[0] as $line) {
			$this->assertSame(1, preg_match('/^[0-9a-f]{40}$/', $line->key));
		}
	}

	public function testSafeUnitConversionOnly(): void {
		$this->assertSame(2000.0, ShoppingLine::toBase('g', 2, 'kg'));
		$this->assertSame(500.0, ShoppingLine::toBase('ml', 500, 'ml'));
		$this->assertSame(3.0, ShoppingLine::toBase('Dose', 3, 'Dose'));
		$this->expectException(\InvalidArgumentException::class);
		ShoppingLine::toBase('Stück', 2, 'kg');
	}

	public function testCategories(): void {
		$cases = [
			'Milchreis' => GroceryCategory::Dry, 'Milch' => GroceryCategory::Dairy, 'Kokosmilch' => GroceryCategory::Canned,
			'Gemüsebrühe' => GroceryCategory::Spices, 'Paprikaschoten' => GroceryCategory::Produce, 'Rapsöl' => GroceryCategory::Spices,
			'Apfelmus' => GroceryCategory::Canned, 'Eier' => GroceryCategory::Dairy, 'Gummibärchen' => GroceryCategory::Other,
			'Kartoffeln, mehligkochend' => GroceryCategory::Produce, 'Kreuzkümmel, gemahlen' => GroceryCategory::Spices,
		];
		foreach ($cases as $name => $expected) {
			$this->assertSame($expected, GroceryCategory::classify($name, Unit::Gram), $name);
		}
		$this->assertSame(GroceryCategory::Canned, GroceryCategory::classify('Bambussprossen', Unit::Can));
	}
}
