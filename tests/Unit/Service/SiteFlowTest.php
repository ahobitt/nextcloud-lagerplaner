<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Domain\Sites\SiteQuery;
use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Service\NotFoundException;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

/** Lagerplatzverzeichnis, Import und Merkliste (Konzept 5). */
final class SiteFlowTest extends ServiceTestCase {
	/** @return array<string, mixed> */
	private function snapshot(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/sites-snapshot.json'), true, 32, JSON_THROW_ON_ERROR);
	}

	/** @return array<string, array<string, mixed>> */
	private function found(SiteQuery $query = new SiteQuery()): array {
		return array_column($this->sites->search('anna', $query)['items'], null, 'name');
	}

	public function testImportReportsSkippedEntries(): void {
		$stats = $this->sites->import($this->snapshot());

		$this->assertSame([4, 0, 0], [$stats['added'], $stats['updated'], $stats['missing']]);
		$this->assertCount(3, $stats['skipped']);
		$this->assertStringContainsString('doppelt', implode(' ', $stats['skipped']));
		$this->assertSame(['testdaten', 'test-1'], [$this->found()['Zeltwiese am Fuchsbau']['sourceLabel'], $this->sites->get($this->found()['Zeltwiese am Fuchsbau']['id'], 'anna')['snapshotVersion']]);
	}

	/** Konzept 5.7, 5.12: Stabile IDs, fehlende Einträge werden gekennzeichnet statt gelöscht. */
	public function testReimportKeepsIdsAndFlagsMissingEntries(): void {
		$this->sites->import($this->snapshot());
		$id = $this->found()['Kleine Lichtung']['id'];
		$camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$this->shortlist->add($camp, 'anna', $id);

		$next = $this->snapshot();
		$next['snapshotVersion'] = 'test-2';
		$next['sites'][0]['name'] = 'Zeltwiese am Fuchsbau (neu)';
		unset($next['sites'][3]);
		$next['sites'] = array_values($next['sites']);
		$stats = $this->sites->import($next);

		// Unveränderte Plätze werden nicht neu geschrieben; nur der umbenannte zählt als aktualisiert.
		$this->assertSame([0, 1, 1], [$stats['added'], $stats['updated'], $stats['missing']]);
		$this->assertTrue($this->found()['Kleine Lichtung']['missing']);
		$this->assertSame($id, $this->shortlist->list($camp, 'anna')['items'][0]['site']['id']);
		$this->assertArrayHasKey('Zeltwiese am Fuchsbau (neu)', $this->found());
	}

	public function testEmptySnapshotIsRefused(): void {
		$this->sites->import($this->snapshot());
		$empty = $this->snapshot();
		$empty['sites'] = [['sourceId' => 'x']];

		try {
			$this->sites->import($empty);
			$this->fail('Leerer Datenstand nicht abgelehnt');
		} catch (\InvalidArgumentException) {
			$this->assertFalse($this->found()['Kleine Lichtung']['missing']);
		}
	}

	public function testSearchSortsByDistanceFromHome(): void {
		$this->sites->import($this->snapshot());
		$this->sitesConfig->homeCoordinates = [51.06, 7.06];
		$result = $this->sites->search('anna', new SiteQuery(state: 'Nordrhein-Westfalen'));

		$this->assertTrue($result['hasHome']);
		$this->assertSame(['Kleine Lichtung', 'Zeltwiese am Fuchsbau', 'Jugendzeltplatz Biberbach'], array_column($result['items'], 'name'));
		$this->assertNull($result['items'][2]['distanceKm']);
		$this->assertSame(['Hessen', 'Nordrhein-Westfalen'], $result['states']);
	}

	public function testAdministrationCanDisableASource(): void {
		$this->sites->import($this->snapshot());
		$id = $this->found()['Kleine Lichtung']['id'];
		$this->sitesConfig->disabled = ['testdaten'];

		$this->assertSame(0, $this->sites->search('anna', new SiteQuery())['total']);
		$this->expectException(NotFoundException::class);
		$this->sites->get($id, 'anna');
	}

	/** Eigene Plätze legt die Administration an; alle anderen melden fehlende Plätze. */
	public function testManualSites(): void {
		$this->directory->admins = ['anna'];
		try {
			$this->sites->createManual('ben', ['name' => 'Wiese bei Bauer Klein', 'type' => 'tent']);
			$this->fail('Ben darf keine globalen Plätze anlegen');
		} catch (ForbiddenException) {
		}
		$this->assertFalse($this->sites->search('ben', new \OCA\Lagerplaner\Domain\Sites\SiteQuery())['canCreate']);
		$this->assertTrue($this->sites->search('anna', new \OCA\Lagerplaner\Domain\Sites\SiteQuery())['canCreate']);

		// Zahlen aus Formularen kommen als Text; 0 Betten ist eine echte Angabe
		$id = $this->sites->createManual('anna', ['name' => 'Wiese bei Bauer Klein', 'type' => 'tent', 'capacity' => '30', 'beds' => '0', 'address' => ['city' => 'Testhausen']])['id'];
		$this->assertSame([30, 0], [$this->sites->get($id, 'anna')['capacity'], $this->sites->get($id, 'anna')['beds']]);
		$this->sites->updateManual($id, 'anna', ['name' => 'Wiese bei Bauer Klein', 'type' => 'tent', 'capacity' => 35]);
		$this->assertSame(35, $this->sites->get($id, 'carla')['capacity']);
		$this->assertFalse($this->sites->get($id, 'carla')['canEdit']);

		$this->expectException(ForbiddenException::class);
		$this->sites->updateManual($id, 'emil', ['name' => 'X', 'type' => 'tent']);
	}

	public function testImportedEntriesCannotBeEditedHere(): void {
		$this->sites->import($this->snapshot());
		$this->expectException(ForbiddenException::class);
		$this->sites->updateManual($this->found()['Kleine Lichtung']['id'], 'anna', ['name' => 'X', 'type' => 'tent']);
	}

	/** Konzept 5.9: Merkliste mit Status, bevorzugter Option, Notizen und Lagerort. */
	public function testShortlistAndAdoptAsLocation(): void {
		$this->sites->import($this->snapshot());
		$camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$this->shortlist->add($camp, 'anna', $this->found()['Kleine Lichtung']['id']);
		$this->shortlist->add($camp, 'anna', $this->found()['Zeltwiese am Fuchsbau']['id']);
		$this->shortlist->add($camp, 'anna', $this->found()['Zeltwiese am Fuchsbau']['id']);

		$list = array_column($this->shortlist->list($camp, 'anna')['items'], null, 'entryId');
		$this->assertCount(2, $list);
		$small = array_values(array_filter($list, static fn (array $i): bool => $i['site']['name'] === 'Kleine Lichtung'))[0];
		$big = array_values(array_filter($list, static fn (array $i): bool => $i['site']['name'] === 'Zeltwiese am Fuchsbau'))[0];
		$this->assertTrue($small['tooSmall']);
		$this->assertFalse($big['tooSmall']);

		$this->shortlist->update($small['entryId'], 'anna', ['preferred' => true]);
		$this->shortlist->update($big['entryId'], 'anna', ['preferred' => true, 'status' => 'booked', 'notes' => 'Platzwart sehr nett, Anreise über Waldweg']);
		$items = $this->shortlist->list($camp, 'anna')['items'];
		$this->assertSame(['Zeltwiese am Fuchsbau', true, 'Verbindlich gebucht'], [$items[0]['site']['name'], $items[0]['preferred'], $items[0]['statusLabel']]);
		$this->assertFalse($items[1]['preferred']);

		$this->shortlist->adopt($big['entryId'], 'anna');
		$location = $this->shortlist->list($camp, 'anna')['location'];
		$this->assertSame([true, 'Zeltwiese am Fuchsbau, Testhausen', 'Nordrhein-Westfalen'], [$location['known'], $location['name'], $location['region']]);

		// Ein erneuter Assistentenlauf sieht den festgelegten Ort und ändert ihn nicht zurück.
		$input = $this->wizard->lastInput($camp, 'anna');
		$this->assertSame(['known' => true, 'name' => 'Zeltwiese am Fuchsbau, Testhausen', 'region' => 'Nordrhein-Westfalen'], array_intersect_key($input['location'], ['known' => 0, 'name' => 0, 'region' => 0]));
		$preview = $this->wizard->rerunPreview($camp, 'anna', $input);
		$this->assertNotContains('location', array_column($preview['camp']['changes'], 'field'));
	}

	public function testHelpersSeeButDoNotManageTheShortlist(): void {
		$this->sites->import($this->snapshot());
		$camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$this->team->add($camp, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
		$this->shortlist->add($camp, 'anna', $this->found()['Kleine Lichtung']['id']);

		$this->assertCount(1, $this->shortlist->list($camp, 'ben')['items']);
		$this->expectException(ForbiddenException::class);
		$this->shortlist->add($camp, 'ben', $this->found()['Zeltwiese am Fuchsbau']['id']);
	}

	public function testManualSiteOnShortlistCannotBeDeleted(): void {
		$this->directory->admins = ['anna'];
		$id = $this->sites->createManual('anna', ['name' => 'Eigener Platz', 'type' => 'tent'])['id'];
		$camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$this->shortlist->add($camp, 'anna', $id);

		$this->expectException(\InvalidArgumentException::class);
		$this->sites->deleteManual($id, 'anna');
	}
}
