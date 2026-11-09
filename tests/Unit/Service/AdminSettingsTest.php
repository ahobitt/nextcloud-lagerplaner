<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Service\AdminSettingsService;

/** Einstellungen in der Nextcloud-Verwaltung statt per occ. */
final class AdminSettingsTest extends ServiceTestCase {
	private function settings(): AdminSettingsService {
		return new AdminSettingsService($this->sitesConfig, $this->gamesConfig, $this->sites, $this->catalog, $this->directory,
			$this->bundled, $this->synchronizer, $this->registry, $this->siteFeedback);
	}

	public function testHomeFromAMapLink(): void {
		$state = $this->settings()->setHome('https://www.openstreetmap.org/?mlat=51.0625&mlon=7.0612#map=17/51.06/7.06');
		$this->assertSame(['lat' => 51.0625, 'lon' => 7.0612, 'text' => '51.0625, 7.0612'], $state['home']);
		$this->assertSame([51.0625, 7.0612], $this->sitesConfig->homeCoordinates);

		$this->assertNull($this->settings()->setHome('')['home']);
		$this->assertNull($this->sitesConfig->homeCoordinates);

		$this->expectException(\InvalidArgumentException::class);
		$this->settings()->setHome('Musterstraße 1, Musterstadt');
	}

	public function testGamesAndSources(): void {
		$this->sites->import(json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/sites-snapshot.json'), true));
		$byKey = static fn (array $state): array => array_column($state['sources'], null, 'source');
		$state = $byKey($this->settings()->state());
		$this->assertSame(4, $state['testdaten']['count']);
		$this->assertFalse($state['testdaten']['disabled']);
		// Eingebundene Datenquelle erscheint auch ohne Einträge, mit Link für die Quellenangabe
		$this->assertSame(['Schwarzzelt IT Campsitemap', 'https://github.com/Schwarzzelt-IT/campsites', true],
			[$state['schwarzzelt_it']['label'], $state['schwarzzelt_it']['link'], $state['schwarzzelt_it']['canSync']]);

		$this->assertTrue($byKey($this->settings()->setSourceVisible('testdaten', false))['testdaten']['disabled']);
		$this->assertSame(0, $this->sites->search('anna', new \OCA\Lagerplaner\Domain\Sites\SiteQuery())['total']);
		$this->assertFalse($byKey($this->settings()->setSourceVisible('testdaten', true))['testdaten']['disabled']);

		$this->assertFalse($this->settings()->setGamesEnabled(false)['games']['enabled']);
		$this->importQueue->immediate = false;
		$state = $this->settings()->setGamesEnabled(true);
		$this->assertTrue($state['games']['enabled']);
		$this->assertGreaterThan(0, $this->importQueue->requests);

		$this->expectException(\InvalidArgumentException::class);
		$this->settings()->setSourceVisible('gibtsnicht', false);
	}

	/** Diözesan-Cloud: je Stamm eine Gruppe mit eigenem Stammesheim. */
	public function testHomesPerTribe(): void {
		$this->directory->groups['stamm-georg'] = ['Stamm St. Georg', ['ben', 'carla']];
		$this->directory->groups['stamm-martin'] = ['Stamm St. Martin', ['ben', 'dora']];
		$this->directory->groupAdmins['stamm-georg'] = ['carla'];
		$this->sites->import(json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/sites-snapshot.json'), true));
		$this->sitesConfig->homeCoordinates = [50.0, 8.0];

		$state = $this->settings()->setGroupHome('stamm-georg', '', '51.06, 7.06');
		$this->settings()->setGroupHome('stamm-martin', 'Heim am Bach', 'https://www.openstreetmap.org/?mlat=51.30&mlon=9.40');
		$this->assertSame(['stamm-georg' => 'Stamm St. Georg'], array_column($state['groupHomes'], 'label', 'groupId'));
		$this->assertSame(['stamm-georg', 'stamm-martin'], array_column($this->settings()->state()['groupHomes'], 'groupId'));
		$this->assertSame([['id' => 'stamm-martin', 'label' => 'Stamm St. Martin']], $this->settings()->searchGroups('martin'));

		// Ben ist in zwei Stämmen: Auswahl, erster Stamm nach Name; dann der Standard
		$homes = $this->sites->homesFor('ben');
		$this->assertSame(['Heim am Bach', 'Stamm St. Georg', 'Standard-Standort'], array_column($homes, 'label'));
		$fuchsbau = static fn (array $result): ?float => array_column($result['items'], 'distanceKm', 'name')['Zeltwiese am Fuchsbau'] ?? null;
		$fromBach = $this->sites->search('ben', new \OCA\Lagerplaner\Domain\Sites\SiteQuery());
		$fromGeorg = $this->sites->search('ben', new \OCA\Lagerplaner\Domain\Sites\SiteQuery(), 50, 0, 'stamm-georg');
		$this->assertSame('stamm-martin', $fromBach['home']['id']);
		$this->assertSame('stamm-georg', $fromGeorg['home']['id']);
		$this->assertNotSame($fuchsbau($fromBach), $fuchsbau($fromGeorg));
		$this->assertLessThan(10, $fuchsbau($fromGeorg));

		// Ohne Stamm: der Standard; ganz ohne Standort: keine Entfernung
		$this->assertSame('default', $this->sites->search('emil', new \OCA\Lagerplaner\Domain\Sites\SiteQuery())['home']['id']);
		$this->sitesConfig->homeCoordinates = null;
		$this->assertNull($this->sites->search('emil', new \OCA\Lagerplaner\Domain\Sites\SiteQuery())['home']);

		$this->assertSame(['stamm-georg'], array_column($this->settings()->removeGroupHome('stamm-martin')['groupHomes'], 'groupId'));
		$this->assertSame(['Stamm St. Georg'], array_column($this->sites->homesFor('ben'), 'label'));
	}

	/** Die Gruppenadministration eines Stammes ändert dessen Standort selbst. */
	public function testGroupAdminsEditTheirTribe(): void {
		$this->directory->groups['stamm-georg'] = ['Stamm St. Georg', ['ben', 'carla']];
		$this->directory->groupAdmins['stamm-georg'] = ['carla'];
		$this->settings()->setGroupHome('stamm-georg', '', '51.06, 7.06');

		$this->assertTrue($this->sites->homesFor('carla')[0]['editable']);
		$this->assertFalse($this->sites->homesFor('ben')[0]['editable']);
		$homes = $this->sites->updateGroupHome('carla', 'stamm-georg', 'geo:51.1,7.1');
		$this->assertSame([51.1, 7.1], [$homes[0]['lat'], $homes[0]['lon']]);
		$this->assertSame('Stamm St. Georg', $homes[0]['label']);

		try {
			$this->sites->updateGroupHome('ben', 'stamm-georg', '51.2, 7.2');
			$this->fail('Ben ist keine Gruppenadministration');
		} catch (\OCA\Lagerplaner\Service\ForbiddenException) {
		}
		$this->expectException(\OCA\Lagerplaner\Service\NotFoundException::class);
		$this->sites->updateGroupHome('carla', 'leiterrunde', '51.2, 7.2');
	}

	/** Ohne occ: mitgelieferten Stand übernehmen und eigenen Datenstand hochladen. */
	public function testImportWithoutOcc(): void {
		$state = $this->settings()->state();
		$this->assertSame('schwarzzelt_it', $state['bundled'][0]['source']);
		$this->assertFalse($state['bundled'][0]['current']);

		$result = $this->settings()->importBundled('schwarzzelt_it');
		$this->assertSame(192, $result['stats']['added']);
		$this->assertTrue($result['state']['bundled'][0]['current']);
		$sources = array_column($result['state']['sources'], null, 'source');
		$this->assertSame(192, $sources['schwarzzelt_it']['withCoordinates']);
		$this->assertTrue($this->settings()->importBundled('schwarzzelt_it')['stats']['unchanged']);

		// Eigener Datenstand unter eigener Quelle; die Campsitemap bleibt unberührt.
		$upload = $this->settings()->importUpload((string)file_get_contents(__DIR__ . '/../../fixtures/sites-snapshot.json'));
		$this->assertSame(['added' => 4, 'skipped' => 3, 'missing' => 0], ['added' => $upload['stats']['added'], 'skipped' => $upload['stats']['skipped'], 'missing' => $upload['stats']['missing']]);

		foreach (['{kaputt', json_encode(['format' => 'lagerplaner-sites/1', 'source' => 'manual', 'sites' => []]), json_encode(['format' => 'etwas', 'source' => 'x', 'sites' => []])] as $bad) {
			try {
				$this->settings()->importUpload($bad);
				$this->fail('Nicht abgelehnt: ' . $bad);
			} catch (\InvalidArgumentException) {
			}
		}
	}
}
