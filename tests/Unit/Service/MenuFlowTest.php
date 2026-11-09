<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Domain\Catering\PlanIssue;
use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

/** Einfacher Speiseplan, entfallende Mahlzeiten und Gerichte im Programm. */
final class MenuFlowTest extends ServiceTestCase {
	private function camp(array $overrides = []): int {
		return (int)$this->wizard->create('anna', array_replace_recursive(Fixtures::summerCamp(), ['catering' => ['proposeMenu' => true]], $overrides))->getId();
	}

	/** @return array<string, array<string, mixed>> „Tag Mahlzeit“ => Mahlzeit aus der Küchenübersicht */
	private function meals(int $camp): array {
		$meals = [];
		foreach ($this->catering->overview($camp, 'anna')['meals'] as $meal) {
			$meals[$meal['day'] . ' ' . $meal['mealType']] = $meal;
		}
		return $meals;
	}

	public function testAssistantProposesADishForEveryMeal(): void {
		$camp = $this->camp();
		$meals = $this->meals($camp);

		$this->assertSame('Spaghetti mit Tomatensoße', $meals['2027-07-24 lunch']['dish']);
		$this->assertSame('Abendbrot mit Aufstrichen und Gemüse', $meals['2027-07-24 dinner']['dish']);
		$this->assertSame('Frühstück mit Brot und Müsli', $meals['2027-07-25 breakfast']['dish']);
		$this->assertSame('Gemüsecurry mit Kichererbsen und Reis', $meals['2027-07-25 lunch']['dish']);
		$this->assertSame([], array_filter($meals, static fn (array $m): bool => $m['dish'] === null));
		// Aufeinanderfolgende warme Mittagessen sind verschieden.
		$lunches = array_values(array_map(static fn (array $m): string => $m['dish'], array_filter($meals, static fn (array $m, string $k): bool => str_ends_with($k, 'lunch') && $m['dish'] !== 'Lunchpaket für unterwegs', ARRAY_FILTER_USE_BOTH)));
		for ($i = 1; $i < count($lunches); $i++) {
			$this->assertNotSame($lunches[$i - 1], $lunches[$i]);
		}
		// Am Wandertag gibt es ein Lunchpaket und abends warm.
		$hike = array_keys(array_filter($meals, static fn (array $m): bool => $m['dish'] === 'Lunchpaket für unterwegs'));
		$this->assertCount(1, $hike);
		$this->assertNotSame('Abendbrot mit Aufstrichen und Gemüse', $meals[explode(' ', $hike[0])[0] . ' dinner']['dish']);
	}

	public function testWithoutTheOptionNothingIsProposed(): void {
		$camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$this->assertSame([], array_filter($this->meals($camp), static fn (array $m): bool => $m['dish'] !== null));
		$this->assertSame([], $this->recipes->list($camp, 'anna'));
	}

	/** Wer zum Gericht nicht passt, bekommt eine eigene Variante mit passendem Gericht. */
	public function testSpecialNeedsGetTheirOwnVariant(): void {
		$camp = $this->camp();
		$this->catering->saveNeeds($camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 30], ['key' => 'vegan', 'count' => 6], ['key' => 'gluten_free', 'count' => 2]]]);
		$lunch = $this->meals($camp)['2027-07-24 lunch'];
		$plan = $this->catering->mealPlan($lunch['id'], 'anna');
		// Vor dem Ergänzen fallen die glutenfreien Personen in „Alle“ (Spaghetti) und das ist ein Fehler.
		$this->assertContains(PlanIssue::NOT_SATISFIED, array_column($plan['evaluation']['issues'], 'code'));

		$result = $this->catering->proposeMealPlan($camp, 'anna');
		$this->assertGreaterThan(0, $result['completed']);
		$plan = $this->catering->mealPlan($lunch['id'], 'anna');
		$variants = array_column($plan['plan']['variants'], null, 'label');
		$this->assertSame(['Alle', 'Glutenfrei'], array_keys($variants));
		$this->assertSame(36, $plan['evaluation']['headcounts']['alle']);
		$titles = array_column($this->recipes->list($camp, 'anna'), 'title', 'id');
		$this->assertSame('Reis mit Gemüsepfanne', $titles[$variants['Glutenfrei']['recipeId']]);
		// Ungeprüfte Allergene bleiben ein Fehler, bis die Küche sie bestätigt.
		$this->assertContains(PlanIssue::RECIPE_CONFLICT, array_column($plan['evaluation']['issues'], 'code'));
		$this->assertNotContains(PlanIssue::NOT_SATISFIED, array_column($plan['evaluation']['issues'], 'code'));
	}

	public function testDishCanBeChangedQuickly(): void {
		$camp = $this->camp();
		$lunch = $this->meals($camp)['2027-07-26 lunch'];
		$chili = array_values(array_filter($this->recipes->list($camp, 'anna'), static fn (array $r): bool => $r['templateKey'] === 'chili_sin_carne'))[0]['id'];

		$this->catering->setMainDish($lunch['id'], 'anna', $chili);
		$this->assertSame('Chili sin Carne mit Reis', $this->meals($camp)['2027-07-26 lunch']['dish']);
		// Erneutes Vorschlagen ändert gewählte Gerichte nicht.
		$this->catering->proposeMealPlan($camp, 'anna');
		$this->assertSame('Chili sin Carne mit Reis', $this->meals($camp)['2027-07-26 lunch']['dish']);
	}

	public function testCancelledMealDoesNotCount(): void {
		$camp = $this->camp();
		$this->catering->saveNeeds($camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 42]]]);
		$lunch = $this->meals($camp)['2027-07-24 lunch'];
		$spaghetti = static fn (array $lines): float => (float)(array_column($lines, null, 'name')['Spaghetti']['quantity'] ?? 0);
		$before = $spaghetti($this->shopping->overview($camp, 'anna')['trips'][0]['lines']);

		$this->catering->setCancelled($lunch['id'], 'anna', true);
		$this->assertSame('cancelled', $this->meals($camp)['2027-07-24 lunch']['status']);
		$this->assertArrayNotHasKey($lunch['id'], $this->catering->dishes($camp, 'anna'));
		// Spaghetti stehen noch an einem späteren Tag auf dem Plan, aber in kleinerer Menge.
		$after = $spaghetti($this->shopping->overview($camp, 'anna')['trips'][0]['lines']);
		$this->assertGreaterThan(0, $after);
		$this->assertGreaterThan($after, $before);
		$this->assertStringNotContainsString('Mahlzeit', implode(' ', $this->shopping->overview($camp, 'anna')['reasons']));
		$this->assertStringContainsString('(entfällt)', $this->exports->document($camp, 'anna', 'day', '2027-07-24')['html']);
	}

	public function testDishesAreVisibleToHelpersWithoutDietaryData(): void {
		$camp = $this->camp();
		$this->team->add($camp, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
		$this->catering->saveNeeds($camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 40], ['key' => 'gluten_free', 'count' => 2]]]);
		$this->catering->proposeMealPlan($camp, 'anna');

		$dishes = $this->catering->dishes($camp, 'ben');
		$this->assertContains('Spaghetti mit Tomatensoße', $dishes);
		$this->assertStringNotContainsString('Glutenfrei', implode(' ', $dishes));

		$this->expectException(ForbiddenException::class);
		$this->catering->setCancelled(array_key_first($dishes), 'ben', true);
	}

	public function testWeekendWithFridayEveningArrival(): void {
		$camp = $this->camp(['start' => '2027-05-07', 'end' => '2027-05-09', 'arrivalTime' => '17:00', 'departureTime' => '14:00', 'program' => ['hike' => false, 'wideGame' => false]]);
		$meals = $this->meals($camp);

		$this->assertSame(['2027-05-07 dinner'], array_values(array_filter(array_keys($meals), static fn (string $k): bool => str_starts_with($k, '2027-05-07'))));
		// Freitagabend ohne Mittagessen davor: warmes Abendessen.
		$this->assertSame('Spaghetti mit Tomatensoße', $meals['2027-05-07 dinner']['dish']);
	}
}
