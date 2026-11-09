<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Sites\InvalidSiteData;
use OCA\Lagerplaner\Domain\Sites\Site;
use OCA\Lagerplaner\Domain\Sites\SiteNormalizer;
use OCA\Lagerplaner\Domain\Sites\SiteQuery;
use OCA\Lagerplaner\Domain\Sites\SiteType;
use PHPUnit\Framework\TestCase;

final class SitesTest extends TestCase {
	/** @var list<array<string, mixed>> */
	private array $raw;

	protected function setUp(): void {
		$this->raw = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/sites-snapshot.json'), true, 32, JSON_THROW_ON_ERROR)['sites'];
	}

	private function site(int $index): Site {
		return (new SiteNormalizer())->normalize($this->raw[$index]);
	}

	public function testFieldsAreMappedAndContactsRemoved(): void {
		$site = $this->site(0);

		$this->assertSame(['Zeltwiese am Fuchsbau', SiteType::Tent, 'Testhausen', 120], [$site->name, $site->type, $site->city, $site->capacity]);
		$this->assertSame(false, $site->features->get('kitchen'));
		$this->assertNull($site->features->get('accessible'));
		$this->assertStringNotContainsString('platzwart@', (string)$site->description);
		$this->assertStringNotContainsString('123456', (string)$site->description);
		$this->assertSame(2, substr_count((string)$site->description, '[Kontakt entfernt]'));
	}

	/** Verschleierte Adressen wie „name(at)domain.de“ werden ebenfalls entfernt. */
	public function testObfuscatedEmailsAreRemoved(): void {
		$scrubbed = (new \OCA\Lagerplaner\Domain\Sites\ContactScrubber())->scrub('Anfragen an belegung(at)vossbarch.de oder info [at] beispiel(dot)de bzw. a.b@c.de');
		$this->assertSame('Anfragen an [Kontakt entfernt] oder [Kontakt entfernt] bzw. [Kontakt entfernt]', $scrubbed);
	}

	/** Konzept 5.5: Unklare Angaben bleiben unbekannt, nie „nicht vorhanden“. */
	public function testUnclearValuesStayUnknown(): void {
		$this->assertNull($this->site(1)->features->get('fireplace'));
		$this->assertNull($this->site(2)->capacity);
		$this->assertNull($this->site(2)->lat);
		$this->assertSame([null, null, null, null, null, null], array_values($this->site(2)->features->toArray()));
	}

	public function testInvalidEntriesAreRejected(): void {
		foreach ([4, 6] as $index) {
			try {
				$this->site($index);
				$this->fail("Eintrag $index nicht abgelehnt");
			} catch (InvalidSiteData) {
				$this->assertTrue(true);
			}
		}
	}

	public function testUnknownCapacityIsFlaggedNotExcluded(): void {
		$query = new SiteQuery(persons: 40);

		$this->assertSame([], $query->evaluate($this->site(0), null));
		$this->assertNull($query->evaluate($this->site(3), null));
		$this->assertSame(['capacity'], $query->evaluate($this->site(2), null));
		$this->assertNull((new SiteQuery(persons: 40, includeUnknown: false))->evaluate($this->site(2), null));
	}

	public function testFeaturesAndTypes(): void {
		$withKitchen = new SiteQuery(features: ['kitchen']);
		$this->assertNull($withKitchen->evaluate($this->site(0), null));
		$this->assertSame([], $withKitchen->evaluate($this->site(1), null));
		$this->assertSame(['kitchen'], $withKitchen->evaluate($this->site(2), null));

		// „both“ passt zur Suche nach Zeltplatz und nach Haus.
		$this->assertSame([], (new SiteQuery(type: SiteType::House))->evaluate($this->site(2), null));
		$this->assertNull((new SiteQuery(type: SiteType::House))->evaluate($this->site(0), null));
	}

	public function testDistance(): void {
		$this->assertEqualsWithDelta(6.6, SiteQuery::distanceKm(51.0, 7.0, 51.05, 7.05), 0.1);
		$this->assertNull((new SiteQuery(maxDistanceKm: 5))->evaluate($this->site(3), 6.6));
		$this->assertSame(['distance'], (new SiteQuery(maxDistanceKm: 5))->evaluate($this->site(2), null));
	}

	public function testQueryValidation(): void {
		$this->assertSame(['kitchen', 'water'], SiteQuery::fromArray(['features' => 'kitchen,water'])->features);
		$this->expectException(\InvalidArgumentException::class);
		SiteQuery::fromArray(['features' => 'pool']);
	}
}
