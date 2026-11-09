<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Access\Role;
use OCA\Lagerplaner\Domain\Access\RolePolicy;
use OCA\Lagerplaner\Domain\Material\GameMaterialSuggestions;
use OCA\Lagerplaner\Domain\Material\MaterialCategory;
use OCA\Lagerplaner\Domain\Material\MaterialDemand;
use OCA\Lagerplaner\Domain\Material\MaterialUseSlot;
use PHPUnit\Framework\TestCase;

final class MaterialTest extends TestCase {
	private function slot(?float $quantity, ?string $day = null, string $start = '00:00', string $end = '00:00'): MaterialUseSlot {
		$minutes = static fn (string $t): int => (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2);
		return new MaterialUseSlot($quantity, $day, $minutes($start), $minutes($end));
	}

	public function testConsumablesAreAdded(): void {
		$result = (new MaterialDemand())->aggregate([
			$this->slot(20, '2027-07-25', '09:30', '12:00'),
			$this->slot(30, '2027-07-26', '09:30', '12:00'),
			$this->slot(5),
		], false);

		$this->assertSame(['total' => 55.0, 'openQuantities' => 0], $result);
	}

	/** Konzept 11.3: Wiederverwendbares nur einmal, wenn es nicht gleichzeitig gebraucht wird. */
	public function testReusableMaterialCountsOnlyConcurrentUse(): void {
		$demand = new MaterialDemand();

		// Nacheinander am selben Tag und an verschiedenen Tagen: einmal 10 Seile reichen.
		$this->assertSame(10.0, $demand->aggregate([
			$this->slot(10, '2027-07-25', '09:30', '12:00'),
			$this->slot(10, '2027-07-25', '12:00', '14:00'),
			$this->slot(8, '2027-07-26', '09:30', '12:00'),
		], true)['total']);

		// Gleichzeitig in zwei Gruppen: 10 + 6.
		$this->assertSame(16.0, $demand->aggregate([
			$this->slot(10, '2027-07-25', '09:30', '12:00'),
			$this->slot(6, '2027-07-25', '11:00', '13:00'),
		], true)['total']);

		// Lagerweiter Bedarf kommt immer hinzu; offene Mengen werden gezählt.
		$this->assertSame(['total' => 12.0, 'openQuantities' => 1], $demand->aggregate([
			$this->slot(2),
			$this->slot(10, '2027-07-25', '09:30', '12:00'),
			$this->slot(null, '2027-07-26', '09:30', '12:00'),
		], true));
	}

	public function testGameSuggestions(): void {
		$planned = [['programGameId' => 7, 'programItemId' => 3, 'gameTitle' => 'Fantasieball', 'materials' => ['Nichts', 'Papier & Stifte', 'Diverses', 'Seile']]];
		$suggestions = (new GameMaterialSuggestions())->suggest($planned, []);

		$this->assertSame(['Papier & Stifte', 'Diverses laut Spielbeschreibung', 'Seile'], array_column($suggestions, 'name'));
		$this->assertSame(['craft', 'games', 'ropes'], array_column($suggestions, 'category'));

		$taken = [GameMaterialSuggestions::ref(7, 'Seile') => true];
		$this->assertCount(2, (new GameMaterialSuggestions())->suggest($planned, $taken));
		$this->assertSame(MaterialCategory::Tents, MaterialCategory::guess('Plane 4x6 m'));
	}

	public function testMaterialPermissions(): void {
		$this->assertTrue(RolePolicy::allows([Role::Material], Permission::EditMaterial));
		$this->assertTrue(RolePolicy::allows([Role::Programm], Permission::EditMaterial));
		$this->assertFalse(RolePolicy::allows([Role::Mitarbeit], Permission::EditMaterial));
		$this->assertTrue(RolePolicy::allows([Role::Mitarbeit], Permission::PackMaterial));
		$this->assertFalse(RolePolicy::allows([Role::Lesen], Permission::PackMaterial));
	}
}
