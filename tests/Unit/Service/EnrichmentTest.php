<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Domain\Sites\OperatingStatus;
use OCA\Lagerplaner\Domain\Sites\SiteQuery;

/** Angereicherte Campsitemap-Daten (data/sites/schwarzzelt_it.enrichment.json) */
final class EnrichmentTest extends ServiceTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->synchronizer->sync('schwarzzelt_it', true);
		$this->stats = $this->bundled->applyEnrichment('schwarzzelt_it');
	}

	private ?array $stats = null;

	/** @return array<string, mixed> Suchergebnis zu einem Namen, auch geschlossen */
	private function site(string $name): array {
		$found = array_column($this->sites->search('anna', SiteQuery::fromArray(['q' => $name, 'closed' => '1']))['items'], null, 'name');
		return $found[$name] ?? $this->fail("$name nicht gefunden: " . implode(', ', array_keys($found)));
	}

	public function testAllRecordsAreMatchedAndAliasIsMerged(): void {
		// Auf den aktuellen Index ausgerichtet: alle 192 zugeordnet, keine Dubletten mehr
		$this->assertSame([192, [], 0], [$this->stats['matched'], $this->stats['unmatched'], $this->stats['merged']]);
		// Zweiter Lauf: Version bekannt, nichts zu tun; erzwungen: keine Änderungen
		$this->assertNull($this->bundled->applyEnrichment('schwarzzelt_it'));
		$this->assertSame(0, $this->bundled->applyEnrichment('schwarzzelt_it', true)['attributes']);
	}

	public function testValuesAndProvenance(): void {
		$camp = $this->site('Abenteuercamp Deutschbaselitz');
		$this->assertSame([50, true, true, null, null], [$camp['capacity'], $camp['features']['sanitary'], $camp['features']['fireplace'], $camp['features']['water'], $camp['features']['kitchen']]);
		$detail = $this->sites->get($camp['id'], 'anna');
		// Ohne bewertete Sicherheit bleibt sie offen
		$this->assertSame(['website', null, 'https://aktiv-erlebnis-lausitz.de/de/unser-abenteuercamp.html'],
			[$detail['facts']['capacity']['method'], $detail['facts']['capacity']['confidence'], $detail['facts']['capacity']['sourceUrl']]);
		$this->assertSame(strtotime('2026-11-01'), $detail['facts']['capacity']['checkedAt']);
		$this->assertSame('source', $detail['facts']['name']['method']);
	}

	/** Stammdaten gehören der Campsitemap: keine automatischen Korrekturen an Name, URL, Kategorie. */
	public function testBaseDataStaysWithTheCampsiteMap(): void {
		// Name wie in der Campsitemap; deren Korrektur hat Daniel selbst übernommen
		$this->assertSame('Baden-Powell-Haus', $this->site('Baden-Powell-Haus')['name']);
		$isselburg = $this->site('Altes Wasserwerk Isselburg');
		$this->assertSame('https://hausbuchen.de/haussuche.php?id_obj=6658&pass=12036&code=vw4z', $isselburg['website']);
		$this->assertSame('source', $this->sites->get($isselburg['id'], 'anna')['facts']['website']['method']);
		// Planungsangaben kommen dagegen aus der Anreicherung
		$partial = $this->site('Scoutcentrum Blidingsholm');
		$this->assertSame('partial', $partial['features']['electricity']);
		$strict = array_column($this->sites->search('anna', SiteQuery::fromArray(['q' => 'Blidingsholm', 'features' => 'electricity', 'unknown' => '0']))['items'], 'name');
		$this->assertSame([], $strict);
		$loose = $this->sites->search('anna', SiteQuery::fromArray(['q' => 'Blidingsholm', 'features' => 'electricity', 'unknown' => '1']))['items'];
		$this->assertSame(['partial:electricity'], $loose[0]['unknown']);
		$sauloch = $this->site('Jugendzeltplatz Sauloch');
		$this->assertSame([480, 'campsites ~450, up to 480 by arrangement'], [$sauloch['capacity'], $sauloch['capacityNote']]);
	}

	public function testOperatingStatusClosedHiddenAndReviewNotes(): void {
		$this->assertSame('temporarily_unavailable', $this->site('Jugendzeltplatz Widdau')['operatingStatus']);
		$this->assertSame('unknown', $this->site('Centre of the River Scouts Pilsen')['operatingStatus']);
		$this->assertStringContainsString('Vermietung', (string)$this->site('Centre of the River Scouts Pilsen')['reviewNote']);
		// Ein geschlossener Platz verschwindet aus der Suche, mit dem Schalter kommt er zurück
		$document = $this->bundled->enrichment('schwarzzelt_it');
		foreach ($document['records'] as &$record) {
			if ($record['original_name'] === 'Centre of the River Scouts Pilsen') {
				$record['operating_status'] = 'closed';
			}
		}
		unset($record);
		$this->enricher->apply($document);
		$this->assertNotContains('Centre of the River Scouts Pilsen', array_column($this->sites->search('anna', new SiteQuery(), 300)['items'], 'name'));
		$this->assertSame('closed', $this->site('Centre of the River Scouts Pilsen')['operatingStatus']);
	}

	public function testEffectiveStatusOverTime(): void {
		$this->assertSame(OperatingStatus::Closing, OperatingStatus::effective('closing', '2026-12-31', null, '2026-11-01'));
		$this->assertSame(OperatingStatus::Closed, OperatingStatus::effective('closing', '2026-12-31', null, '2027-01-01'));
		$this->assertSame(OperatingStatus::Closing, OperatingStatus::effective('closed', '2027-03-01', null, '2026-11-01'));
		$this->assertSame(OperatingStatus::TemporarilyUnavailable, OperatingStatus::effective('temporarily_unavailable', '2026-01-01', '2026-12-31', '2026-11-01'));
		$this->assertSame(OperatingStatus::Active, OperatingStatus::effective('temporarily_unavailable', '2026-01-01', '2026-12-31', '2027-01-02'));
		$this->assertNull(OperatingStatus::effective(null, null, null, '2026-11-01'));
	}

	/** Von Hand gepflegte Angaben haben Vorrang vor der Anreicherung und dem Abgleich. */
	public function testManualValuesWin(): void {
		$this->directory->admins = ['anna'];
		$camp = $this->site('Abenteuercamp Deutschbaselitz');
		$this->sites->correct($camp['id'], 'anna', ['capacity' => 60, 'features' => ['water' => false]]);
		$this->bundled->applyEnrichment('schwarzzelt_it', true);
		$this->synchronizer->sync('schwarzzelt_it', true);
		$after = $this->site('Abenteuercamp Deutschbaselitz');
		$this->assertSame([60, false], [$after['capacity'], $after['features']['water']]);
	}

	/** PLZ, Ort und Land aus der Anreicherung; die Suche nach PLZ und Ort findet die Plätze. */
	public function testLocationFromEnrichment(): void {
		$camp = $this->site('Abenteuercamp Deutschbaselitz');
		$this->assertSame('Deutschbaselitz', $camp['city']);
		$this->assertSame('DE', $camp['country']);
		$this->assertNotNull($camp['postalCode']);
		$byPostal = array_column($this->sites->search('anna', SiteQuery::fromArray(['q' => $camp['postalCode']]))['items'], 'name');
		$this->assertContains('Abenteuercamp Deutschbaselitz', $byPostal);
		$this->assertContains('Abenteuercamp Deutschbaselitz', array_column($this->sites->search('anna', SiteQuery::fromArray(['q' => 'Deutschbaselitz']))['items'], 'name'));
		$this->assertSame('high', $this->sites->get($camp['id'], 'anna')['facts']['address']['confidence']);
		// Plätze im Ausland mit Land
		$countries = array_unique(array_filter(array_column($this->sites->search('anna', SiteQuery::fromArray(['closed' => '1']), 300)['items'], 'country')));
		$this->assertContains('AT', $countries);
	}

	/** Vorrang bei Stammdaten: Rückmeldung bis die Campsitemap den Wert ändert; Handkorrektur immer. */
	public function testSourceChangeBeatsFeedbackButNotManual(): void {
		$csv = (string)file_get_contents(\OCA\Lagerplaner\Sites\SchwarzzeltItSource::BUNDLED);
		$id = $this->site('Altes Wasserwerk Isselburg')['id'];
		$this->siteFeedback->accept($this->siteFeedback->submit('ben', $id, 'website', ['homepage' => 'https://www.schwarzzeug.net/kontakt'], '')['id'], 'anna');
		$this->assertSame(['https://www.schwarzzeug.net/kontakt', 'feedback'], [$this->site('Altes Wasserwerk Isselburg')['website'], $this->sites->get($id, 'anna')['facts']['website']['method']]);

		// Campsitemap unverändert: die Rückmeldung gilt, und nichts zählt als aktualisiert
		$this->schwarzzelt->remote = $csv . "\n";
		$this->assertSame(0, $this->synchronizer->sync('schwarzzelt_it')['updated']);
		$this->assertSame('https://www.schwarzzeug.net/kontakt', $this->site('Altes Wasserwerk Isselburg')['website']);

		// Campsitemap ändert die URL: sie gilt wieder
		$this->schwarzzelt->remote = str_replace('https://hausbuchen.de/haussuche.php?id_obj=6658&pass=12036&code=vw4z', 'https://isselburg.example/', $csv);
		$this->assertSame(1, $this->synchronizer->sync('schwarzzelt_it')['sourceWins']);
		$this->assertSame('https://isselburg.example/', $this->site('Altes Wasserwerk Isselburg')['website']);

		// Bewusste Handkorrektur bleibt auch bei einer Änderung der Quelle
		$this->directory->admins = ['anna'];
		$this->sites->correct($id, 'anna', ['homepage' => 'https://von-hand.example/']);
		$this->schwarzzelt->remote = str_replace('https://hausbuchen.de/haussuche.php?id_obj=6658&pass=12036&code=vw4z', 'https://noch-anders.example/', $csv);
		$this->synchronizer->sync('schwarzzelt_it');
		$this->assertSame('https://von-hand.example/', $this->site('Altes Wasserwerk Isselburg')['website']);
	}

	/** Die Campsitemap ist nicht für Planungsangaben zuständig, auch wenn sie welche liefern würde. */
	public function testSourceDoesNotWritePlanningData(): void {
		$camp = $this->site('Abenteuercamp Deutschbaselitz');
		$row = null;
		foreach ($this->siteMapper->findBySource('schwarzzelt_it') as $r) {
			if ((int)$r->getId() === $camp['id']) {
				$row = $r;
			}
		}
		$this->synchronizer->importEntries('schwarzzelt_it', 'x', [['sourceId' => $row->getSourceId(), 'name' => 'Abenteuercamp Deutschbaselitz', 'type' => 'both',
			'coordinates' => ['lat' => (float)$row->getLat(), 'lon' => (float)$row->getLon()], 'capacity' => 999, 'address' => ['city' => 'Falsch']]], [], $this->schwarzzelt);
		$after = $this->site('Abenteuercamp Deutschbaselitz');
		$this->assertSame([50, 'Deutschbaselitz'], [$after['capacity'], $after['city']]);
	}

	/** Ältere Korrekturen an Stammdaten aus der Anreicherung werden zurückgenommen. */
	public function testLegacyBaseCorrectionsAreRestored(): void {
		$id = $this->site('Baden-Powell-Haus')['id'];
		$row = null;
		foreach ($this->siteMapper->findBySource('schwarzzelt_it') as $r) {
			if ((int)$r->getId() === $id) {
				$row = $r;
			}
		}
		$row->setName('Baden-Powell-Haus (alt korrigiert)');
		$this->siteMapper->update($row);
		$this->sites->recordFact($id, \OCA\Lagerplaner\Domain\Sites\SiteAttribute::Name, \OCA\Lagerplaner\Domain\Sites\FactMethod::Website, null, null, null, 1);
		$stats = $this->bundled->applyEnrichment('schwarzzelt_it', true);
		$this->assertSame(1, $stats['restored']);
		$this->assertSame('Baden-Powell-Haus', $this->sites->get($id, 'anna')['name']);
		$this->assertSame('source', $this->sites->get($id, 'anna')['facts']['name']['method']);
	}

	/** Postleitzahl und Ort stammen aus Geodaten; geänderte Herkunft wird auch bei gleichem Wert übernommen. */
	public function testGeocodingProvenanceAndProvenanceOnlyUpdates(): void {
		$id = $this->site('Abenteuercamp Deutschbaselitz')['id'];
		$address = $this->sites->get($id, 'anna')['facts']['address'];
		$this->assertSame(['geocoding', null, 'high'], [$address['method'], $address['sourceUrl'], $address['confidence']]);

		$document = json_decode((string)file_get_contents(__DIR__ . '/../../../data/sites/schwarzzelt_it.enrichment.json'), true);
		foreach ($document['records'] as &$record) {
			if ($record['original_name'] === 'Abenteuercamp Deutschbaselitz') {
				$record['location_confidence'] = 'medium';
				$record['confidence'] = 'high';
			}
		}
		unset($record);
		$stats = $this->enricher->apply($document);
		$this->assertSame(0, $stats['attributes']);
		$this->assertGreaterThan(1, $stats['provenance']);
		$facts = $this->sites->get($id, 'anna')['facts'];
		$this->assertSame(['medium', 'high'], [$facts['address']['confidence'], $facts['capacity']['confidence']]);
	}

	/** Anreicherung überschreibt keine übernommene Rückmeldung. */
	public function testEnrichmentKeepsFeedback(): void {
		$id = $this->site('Abenteuercamp Deutschbaselitz')['id'];
		$this->siteFeedback->accept($this->siteFeedback->submit('ben', $id, 'capacity', ['capacity' => '70'], '')['id'], 'anna');
		$this->bundled->applyEnrichment('schwarzzelt_it', true);
		$this->assertSame(70, $this->site('Abenteuercamp Deutschbaselitz')['capacity']);
	}

	/** Merkliste: Dubletten werden auf den kanonischen Platz aufgelöst, auch nachträglich. */
	public function testShortlistUsesCanonicalSite(): void {
		// Der aktuelle Index hat keine Dubletten mehr; die Regel prüfe ich mit einer erzeugten
		$document = $this->bundled->enrichment('schwarzzelt_it');
		$document['aliases'] = [['alias_name' => 'Baden-Powell-Haus Mönchengladbach', 'canonical_name' => 'Baden-Powell-Haus']];
		$byName = [];
		foreach ($this->siteMapper->findBySource('schwarzzelt_it') as $row) {
			$byName[$row->getName()] = $row;
		}
		$alias = $byName['Baden-Powell-Haus Mönchengladbach'];
		$canonical = (int)$byName['Baden-Powell-Haus']->getId();
		$camp = (int)$this->wizard->create('anna', \OCA\Lagerplaner\Tests\Unit\Domain\Fixtures::summerCamp())->getId();
		// Eintrag aus der Zeit vor der Zusammenführung wird beim Anreichern umgehängt
		$this->shortlist->add($camp, 'anna', (int)$alias->getId());
		$this->assertSame(1, $this->enricher->apply($document)['merged']);
		$this->assertSame([$canonical], array_column(array_column($this->shortlist->list($camp, 'anna')['items'], 'site'), 'id'));
		// Hinzufügen der Dublette landet beim kanonischen Platz
		$other = (int)$this->wizard->create('anna', \OCA\Lagerplaner\Tests\Unit\Domain\Fixtures::summerCamp())->getId();
		$this->shortlist->add($other, 'anna', (int)$alias->getId());
		$this->assertSame([[$canonical, 'Baden-Powell-Haus']], array_map(static fn ($i) => [$i['site']['id'], $i['site']['name']], $this->shortlist->list($other, 'anna')['items']));
		$this->assertNotContains('Baden-Powell-Haus Mönchengladbach', array_column($this->sites->search('anna', SiteQuery::fromArray(['q' => 'Baden-Powell']))['items'], 'name'));
	}

	/** Statistik: eine reine Änderung des Prüfhinweises zählt als geänderter Platz. */
	public function testReviewNoteChangeCountsAsUpdate(): void {
		$document = json_decode((string)file_get_contents(__DIR__ . '/../../../data/sites/schwarzzelt_it.enrichment.json'), true);
		foreach ($document['records'] as &$record) {
			if ($record['original_name'] === 'Neihaischen') {
				$record['review_status'] = 'resolved';
			}
		}
		unset($record);
		$stats = $this->enricher->apply($document);
		$this->assertSame([1, 0], [$stats['updated'], $stats['attributes']]);
	}

}
