<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Domain\Sites\SiteQuery;

/** „Feedback / Angaben ergänzen“ */
final class SiteFeedbackTest extends ServiceTestCase {
	private string $csv;

	protected function setUp(): void {
		parent::setUp();
		$this->schwarzzelt->remote = (string)file_get_contents(__DIR__ . '/../../fixtures/schwarzzelt-sites.csv');
		$this->synchronizer->sync('schwarzzelt_it');
	}

	private function id(string $name): int {
		return (int)array_column($this->sites->search('anna', SiteQuery::fromArray(['q' => $name, 'closed' => '1']))['items'], 'id', 'name')[$name];
	}

	public function testEveryKindIsValidatedAndListed(): void {
		$haus = $this->id('Haus am Hang');
		$this->siteFeedback->submit('ben', $haus, 'outdated', [], 'Das Haus wurde 2025 umgebaut.');
		$this->siteFeedback->submit('ben', $haus, 'website', ['homepage' => 'https://neu.example/'], '');
		$this->siteFeedback->submit('carla', $haus, 'equipment', ['kitchen' => 'true', 'water' => 'false', 'wifi' => 'true'], '');
		$this->siteFeedback->submit('carla', $haus, 'capacity', ['capacity' => '45', 'beds' => '30'], '');
		$this->siteFeedback->submit('dora', $haus, 'closed', ['since' => '2026-10-01'], 'Steht leer.');
		$this->siteFeedback->submit('dora', null, 'new_site', ['name' => 'Wiese am Weiher', 'type' => 'tent', 'location' => 'https://www.openstreetmap.org/?mlat=50.5&mlon=9.5', 'homepage' => 'https://weiher.example/'], 'Kennt jemand?');

		$open = $this->siteFeedback->open();
		$this->assertCount(6, $open);
		$this->assertSame(6, $this->siteFeedback->countOpen());
		$byKind = array_column($open, null, 'kind');
		$this->assertSame(['water' => 'false', 'kitchen' => 'true'], $byKind['equipment']['values']);
		$this->assertSame('Haus am Hang', $byKind['capacity']['site']['name']);
		$this->assertNull($byKind['new_site']['site']);
		$this->assertSame('50.5,9.5', $byKind['new_site']['values']['location']);

		foreach ([
			['website', ['homepage' => 'keine-url']], ['equipment', []], ['capacity', ['capacity' => 'viele']],
			['closed', ['since' => '2026-10-10', 'until' => '2026-01-01']], ['closed', ['since' => '2026-99-99']], ['closed', ['since' => '2026-02-30']],
			['outdated', []], ['new_site', ['name' => 'X']], ['gibtsnicht', []],
		] as [$kind, $values]) {
			try {
				$this->siteFeedback->submit('ben', $kind === 'new_site' ? null : $haus, $kind, $values, '');
				$this->fail("Nicht abgelehnt: $kind");
			} catch (\InvalidArgumentException) {
			}
		}
	}

	public function testAcceptAppliesValuesAsManualAndFeedsTheContribution(): void {
		$haus = $this->id('Haus am Hang');
		$ids = [];
		foreach ([['website', ['homepage' => 'https://neu.example/']], ['equipment', ['kitchen' => 'true']], ['capacity', ['capacity' => '45']], ['closed', ['since' => '2026-10-01', 'until' => '2027-03-31']]] as [$kind, $values]) {
			$ids[$kind] = $this->siteFeedback->submit('ben', $haus, $kind, $values, '')['id'];
		}
		foreach ($ids as $id) {
			$this->siteFeedback->accept($id, 'anna');
		}
		$detail = $this->sites->get($haus, 'anna');
		$this->assertSame(['https://neu.example/', true, 45, 'temporarily_unavailable'], [$detail['homepage'], $detail['features']['kitchen'], $detail['capacity'], $detail['operatingStatus']]);
		$this->assertSame(['feedback', 'Rückmeldung von Ben Berg'], [$detail['facts']['capacity']['method'], $detail['facts']['capacity']['note']]);
		$this->assertSame(0, $this->siteFeedback->countOpen());

		// Nur Stammdaten-Rückmeldungen sind Korrekturen für die Campsitemap: Website und Schließung, nicht Ausstattung und Kapazität
		$upstream = $this->siteFeedback->upstream('schwarzzelt_it');
		$this->assertSame(['closed', 'website'], array_values(array_unique(array_column($upstream['items'], 'kind'))));
		$this->assertStringContainsString('Haus am Hang,house,51.2,7.2,https://neu.example/', $upstream['text']);
		$this->assertSame(2, $this->siteFeedback->markReported('schwarzzelt_it'));
		$this->assertSame([], $this->siteFeedback->upstream('schwarzzelt_it')['items']);

		// Schon bearbeitet
		$this->expectException(\InvalidArgumentException::class);
		$this->siteFeedback->accept($ids['website'], 'anna');
	}

	public function testClosedSiteDisappearsFromSearchAndNewSiteBecomesOwnEntry(): void {
		$wiese = $this->id('Zeltwiese Talgrund');
		$this->siteFeedback->accept($this->siteFeedback->submit('ben', $wiese, 'closed', ['since' => '2026-01-01'], 'Abgerissen.')['id'], 'anna');
		$this->assertNotContains('Zeltwiese Talgrund', array_column($this->sites->search('anna', new SiteQuery())['items'], 'name'));

		$result = $this->siteFeedback->accept($this->siteFeedback->submit('dora', null, 'new_site', ['name' => 'Wiese am Weiher', 'type' => 'tent', 'location' => '50.5, 9.5'], 'Bei Fulda')['id'], 'anna');
		$site = $this->sites->get($result['siteId'], 'dora');
		// Von der Administration verwaltet: Dora kann den Platz nicht mehr ändern; sie steht als Herkunft dabei
		$this->assertSame(['manual', 'Wiese am Weiher', 50.5, false], [$site['source'], $site['name'], $site['lat'], $site['canEdit']]);
		$this->assertSame('Gemeldet von Dora Dorn', $site['facts']['name']['note']);
		$this->assertContains('Wiese am Weiher', array_column($this->siteFeedback->upstream('schwarzzelt_it')['items'], 'name'));
	}

	public function testRejectAndDoneWithoutChanges(): void {
		$haus = $this->id('Haus am Hang');
		$wrong = $this->siteFeedback->submit('ben', $haus, 'capacity', ['capacity' => '999'], 'Bestimmt riesig')['id'];
		$noted = $this->siteFeedback->submit('ben', $haus, 'outdated', [], 'Neue Preise seit 2026.')['id'];
		$this->siteFeedback->reject($wrong, 'anna', 'Laut Betreiber 45');
		$this->siteFeedback->accept($noted, 'anna', false);
		$this->assertNull($this->sites->get($haus, 'anna')['capacity']);
		$this->assertSame(0, $this->siteFeedback->countOpen());
	}

	/** Neue Rückmeldearten und ihre Einordnung; die Administration kann die Einordnung ändern. */
	public function testNewKindsAndUpstreamClassification(): void {
		$haus = $this->id('Haus am Hang');
		$name = $this->siteFeedback->submit('ben', $haus, 'name', ['name' => 'Haus am Sonnenhang'], '')['id'];
		$location = $this->siteFeedback->submit('ben', $haus, 'location', ['location' => '51.25, 7.25'], '')['id'];
		$address = $this->siteFeedback->submit('ben', $haus, 'address', ['postalCode' => '42999', 'city' => 'Hangdorf'], '')['id'];
		$operator = $this->siteFeedback->submit('ben', $haus, 'operator', ['operator' => 'Stamm Hangdorf'], 'Seit 2026')['id'];
		$outdated = $this->siteFeedback->submit('ben', $haus, 'outdated', [], 'Neue Preise; Kanu-Verleih neu.')['id'];
		$flags = array_column($this->siteFeedback->open(), 'upstreamRelevant', 'kind');
		$this->assertSame([true, true, false, true, false], [$flags['name'], $flags['location'], $flags['address'], $flags['operator'], $flags['outdated']]);

		foreach ([$name, $location, $address, $operator] as $id) {
			$this->siteFeedback->accept($id, 'anna');
		}
		$this->siteFeedback->accept($outdated, 'anna', false, '', true);
		$detail = $this->sites->get($haus, 'anna');
		$this->assertSame(['Haus am Sonnenhang', 51.25, '42999', 'Hangdorf', 'feedback'], [$detail['name'], $detail['lat'], $detail['postalCode'], $detail['city'], $detail['facts']['address']['method']]);
		$kinds = array_column($this->siteFeedback->upstream('schwarzzelt_it')['items'], 'kind');
		sort($kinds);
		// Postleitzahl/Ort ist Lagerplaner-Sache; „veraltet“ hat die Administration als relevant eingeordnet
		$this->assertSame(['location', 'name', 'operator', 'outdated'], $kinds);
	}

	/** Bearbeiten eines eigenen Platzes behält Koordinaten, Land und „teilweise“; 0 Betten bleibt. */
	public function testEditingManualSiteKeepsCoordinates(): void {
		$this->directory->admins = ['anna'];
		$id = $this->siteFeedback->accept($this->siteFeedback->submit('ben', null, 'new_site', ['name' => 'Wiese am Weiher', 'type' => 'tent', 'location' => '50.5, 9.5'], '')['id'], 'anna')['siteId'];
		$this->sites->updateManual($id, 'anna', ['name' => 'Wiese am Weiher', 'type' => 'tent', 'address' => ['country' => 'DE']]);
		$this->sites->updateManual($id, 'anna', ['name' => 'Wiese am Großen Weiher', 'type' => 'tent', 'beds' => 0, 'features' => ['electricity' => 'partial'],
			'address' => ['street' => '', 'postalCode' => '', 'city' => 'Weiherdorf', 'state' => '']]);
		$site = $this->sites->get($id, 'anna');
		$this->assertSame(['Wiese am Großen Weiher', 50.5, 9.5, 'DE', 'Weiherdorf', 0, 'partial'],
			[$site['name'], $site['lat'], $site['lon'], $site['country'], $site['city'], $site['beds'], $site['features']['electricity']]);
	}
}
