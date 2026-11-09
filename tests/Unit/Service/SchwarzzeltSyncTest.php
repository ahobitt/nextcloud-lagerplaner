<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Domain\Sites\FactConfidence;
use OCA\Lagerplaner\Domain\Sites\FactMethod;
use OCA\Lagerplaner\Domain\Sites\SchwarzzeltCsv;
use OCA\Lagerplaner\Domain\Sites\SiteAttribute;
use OCA\Lagerplaner\Domain\Sites\SiteQuery;
use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Sites\SourceUnavailable;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

/** Schwarzzelt IT Campsitemap als erste Datenquelle: Abbildung, Abgleich, Herkunft, Rückmeldung. */
final class SchwarzzeltSyncTest extends ServiceTestCase {
	private string $csv;

	protected function setUp(): void {
		parent::setUp();
		$this->csv = (string)file_get_contents(__DIR__ . '/../../fixtures/schwarzzelt-sites.csv');
		$this->schwarzzelt->remote = $this->csv;
	}

	/** @return array<string, array<string, mixed>> Name => Suchergebnis */
	private function found(array $criteria = []): array {
		return array_column($this->sites->search('anna', SiteQuery::fromArray($criteria))['items'], null, 'name');
	}

	public function testMappingOfAllThreeCategoriesAndSkippedRows(): void {
		$parsed = (new SchwarzzeltCsv())->parse($this->csv);
		$this->assertSame(['tent', 'house', 'both', 'tent'], array_column($parsed['entries'], 'type'));
		$this->assertSame('Base scout "Il Pino", Toskana', $parsed['entries'][3]['name']);
		$this->assertCount(3, $parsed['skipped']);
		$this->assertStringContainsString('unbekannte Kategorie', $parsed['skipped'][0]);
		// Technische Kennung aus dem Namen, stabil und unabhängig von der Lage
		$this->assertSame(SchwarzzeltCsv::technicalId('Haus am Hang'), $parsed['entries'][1]['sourceId']);
		$this->assertSame(SchwarzzeltCsv::technicalId('  haus AM hang '), $parsed['entries'][1]['sourceId']);
		// Eine echte id-Spalte hat Vorrang
		$withId = (new SchwarzzeltCsv())->parse("id,name,category,latitude,longitude,url\nhaus-17,Haus am Hang,house,51.2,7.2,");
		$this->assertSame('haus-17', $withId['entries'][0]['sourceId']);
	}

	public function testImportWithSourceAttributionAndUnknownFeatures(): void {
		$stats = $this->synchronizer->sync('schwarzzelt_it');
		$this->assertSame([4, 3, 'online'], [$stats['added'], count($stats['skipped']), $stats['origin']]);

		$haus = $this->found()['Haus am Hang'];
		$this->assertSame(['house', 'schwarzzelt_it', 'Schwarzzelt IT Campsitemap', 'https://github.com/Schwarzzelt-IT/campsites', 'https://haus-am-hang.example/'],
			[$haus['type'], $haus['source'], $haus['sourceLabel'], $haus['sourceLink'], $haus['website']]);
		// Nicht gefundene Angaben bleiben null, nichts wird zu false
		$this->assertSame(['water' => null, 'sanitary' => null, 'kitchen' => null, 'electricity' => null, 'fireplace' => null, 'accessible' => null], $haus['features']);
		$this->assertSame([null, null, null, null], [$haus['capacity'], $haus['beds'], $haus['country'], $haus['postalCode']]);

		$detail = $this->sites->get($haus['id'], 'anna');
		$this->assertSame('source', $detail['facts']['name']['method']);
		$this->assertSame('https://github.com/Schwarzzelt-IT/campsites', $detail['facts']['location']['sourceUrl']);
		$this->assertArrayNotHasKey('capacity', $detail['facts']);
	}

	public function testResyncIsIdempotentAndUpdatesAndAddsEntries(): void {
		$this->synchronizer->sync('schwarzzelt_it');
		$id = $this->found()['Haus am Hang']['id'];
		$this->assertTrue($this->synchronizer->sync('schwarzzelt_it')['unchanged']);
		$this->assertCount(4, $this->found());

		// Website geändert, ein neuer Platz, sonst gleich
		$this->schwarzzelt->remote = str_replace('https://haus-am-hang.example/', 'https://neu.haus-am-hang.example/', $this->csv)
			. "Neuer Platz,campsite,52.00,8.00,https://neu.example/\n";
		$stats = $this->synchronizer->sync('schwarzzelt_it');
		$this->assertSame([1, 1, 0], [$stats['added'], $stats['updated'], $stats['missing']]);
		$this->assertSame($id, $this->found()['Haus am Hang']['id']);
		$this->assertSame('https://neu.haus-am-hang.example/', $this->found()['Haus am Hang']['website']);
		$this->assertCount(5, $this->found());
	}

	public function testRenamedSiteIsRecognisedAndMissingSitesAreFlagged(): void {
		$this->synchronizer->sync('schwarzzelt_it');
		$id = $this->found()['Haus am Hang']['id'];
		$camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$this->shortlist->add($camp, 'anna', $id);

		// Umbenannt (neue technische Kennung), gleiche Website; Zeltwiese fehlt im neuen Stand
		$this->schwarzzelt->remote = str_replace(['Haus am Hang', "Zeltwiese Talgrund,campsite,51.10,7.10,https://zeltwiese-talgrund.example/\n"], ['Haus am Sonnenhang', ''], $this->csv);
		$stats = $this->synchronizer->sync('schwarzzelt_it');
		$this->assertSame([0, 1, 1], [$stats['added'], $stats['updated'], $stats['missing']]);
		$this->assertSame($id, $this->found()['Haus am Sonnenhang']['id']);
		$this->assertSame($id, $this->shortlist->list($camp, 'anna')['items'][0]['site']['id']);
		$this->assertTrue($this->found()['Zeltwiese Talgrund']['missing']);
	}

	public function testManualAndWebsiteFactsSurviveSyncAndCanBeReported(): void {
		$this->synchronizer->sync('schwarzzelt_it');
		$id = $this->found()['Haus am Hang']['id'];
		$this->directory->admins = ['anna'];

		$detail = $this->sites->correct($id, 'anna', ['capacity' => 80, 'features' => ['kitchen' => true], 'name' => 'Haus am Hang (Jugendhaus)'], 'https://haus-am-hang.example/belegung');
		$this->assertSame(['manual', 'high', 'https://haus-am-hang.example/belegung'], [$detail['facts']['capacity']['method'], $detail['facts']['capacity']['confidence'], $detail['facts']['capacity']['sourceUrl']]);
		$this->assertSame('manual', $detail['facts']['kitchen']['method']);
		// Automatisch erkannte Angabe, etwa später von der Website
		$this->sites->recordFact($id, SiteAttribute::Fireplace, FactMethod::Website, null, 'https://haus-am-hang.example/', FactConfidence::Medium, 1000);

		// Neuer Stand der Quelle mit anderem Namen: Name und Kapazität bleiben, wie von Hand gesetzt
		$this->schwarzzelt->remote = str_replace('https://haus-am-hang.example/', 'https://haus-am-hang.example/start', $this->csv);
		$stats = $this->synchronizer->sync('schwarzzelt_it');
		$this->assertSame(1, $stats['protected']);
		$after = $this->sites->get($id, 'anna');
		$this->assertSame(['Haus am Hang (Jugendhaus)', 80, true, 'https://haus-am-hang.example/start'], [$after['name'], $after['capacity'], $after['features']['kitchen'], $after['homepage']]);

		// Koordinaten verlustfrei wie in der Quelle (für Zeilen im Format der sites.csv)
		$this->assertSame('Tal,campsite,51.3037944,14.1610741,', $this->schwarzzelt->describe(new \OCA\Lagerplaner\Domain\Sites\Site('x', 'Tal', \OCA\Lagerplaner\Domain\Sites\SiteType::Tent, null, null, null, null, null, 51.3037944, 14.1610741, null, null, \OCA\Lagerplaner\Domain\Sites\SiteFeatures::fromArray([]), null, null, null, null, null)));
		// Angereicherte und von Hand ergänzte Angaben gehen nicht an die Campsitemap
		$this->assertSame([], $this->siteFeedback->upstream('schwarzzelt_it')['items']);
	}

	public function testOnlyAdministrationCorrectsImportedSites(): void {
		$this->synchronizer->sync('schwarzzelt_it');
		$this->expectException(ForbiddenException::class);
		$this->sites->correct($this->found()['Haus am Hang']['id'], 'ben', ['capacity' => 10]);
	}

	/** Filter: true erfüllt, false nie, null nur mit „auch unbekannte Angaben“. */
	public function testFeatureFilterWithTrueFalseAndNull(): void {
		$this->synchronizer->sync('schwarzzelt_it');
		$this->directory->admins = ['anna'];
		$this->sites->correct($this->found()['Haus am Hang']['id'], 'anna', ['features' => ['water' => true], 'capacity' => 40]);
		$this->sites->correct($this->found()['Zeltwiese Talgrund']['id'], 'anna', ['features' => ['water' => false], 'capacity' => 20]);

		$names = fn (array $criteria): array => array_keys($this->found($criteria));
		$this->assertSame(['Haus am Hang'], $names(['features' => 'water', 'unknown' => '0']));
		$withUnknown = $names(['features' => 'water', 'unknown' => '1']);
		$this->assertContains('Haus am Hang', $withUnknown);
		$this->assertContains('Pfadfinderzentrum Seeblick', $withUnknown);
		$this->assertNotContains('Zeltwiese Talgrund', $withUnknown);
		// Kapazität: unbekannt nur mit der Option, zu klein nie
		$this->assertSame(['Haus am Hang'], $names(['persons' => '30', 'unknown' => '0']));
		$this->assertNotContains('Zeltwiese Talgrund', $names(['persons' => '30', 'unknown' => '1']));
		// Haus / Zeltplatz
		$this->assertSame(['Haus am Hang', 'Pfadfinderzentrum Seeblick'], $names(['type' => 'house']));
	}

	/** Entfernungen auch für Plätze außerhalb Deutschlands */
	public function testInternationalSitesAndDistance(): void {
		$this->synchronizer->sync('schwarzzelt_it');
		$this->sitesConfig->homeCoordinates = [51.0, 7.0];
		$pino = $this->found()['Base scout "Il Pino", Toskana'];
		$this->assertGreaterThan(800, $pino['distanceKm']);
		$this->assertLessThan(1000, $pino['distanceKm']);
		$this->assertNull($pino['country']);
		$this->assertContains('Base scout "Il Pino", Toskana', array_keys($this->found(['distance' => '1000'])));
	}

	public function testBundledFallbackOnlyBeforeTheFirstSync(): void {
		$this->schwarzzelt->remote = null;
		$stats = $this->synchronizer->sync('schwarzzelt_it');
		$this->assertSame(['bundled', 192], [$stats['origin'], $stats['added']]);

		$this->expectException(SourceUnavailable::class);
		$this->synchronizer->sync('schwarzzelt_it');
	}

	/** Keine ScoutNet-Abhängigkeiten mehr; nur die Migration, die alte Daten entfernt, nennt ScoutNet. */
	public function testNoScoutNetReferencesRemain(): void {
		$root = dirname(__DIR__, 3);
		$found = [];
		foreach (['lib', 'src', 'tools', 'data', 'appinfo', 'l10n', 'templates', 'tests/Unit', 'tests/Integration', 'tests/fixtures'] as $dir) {
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
			foreach ($files as $file) {
				$path = substr($file->getPathname(), strlen($root) + 1);
				if (in_array($path, ['lib/Migration/Version001200Date20261031120000.php', 'tests/Unit/Service/SchwarzzeltSyncTest.php'], true)) {
					continue;
				}
				if (stripos((string)file_get_contents($file->getPathname()), 'scoutnet') !== false) {
					$found[] = $path;
				}
			}
		}
		$this->assertSame([], $found);
	}
}
