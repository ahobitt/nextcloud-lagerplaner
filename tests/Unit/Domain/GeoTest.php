<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Geo\CoordinateParser;
use PHPUnit\Framework\TestCase;

final class GeoTest extends TestCase {
	/** Was Menschen typischerweise einfügen */
	public function testCoordinatesAndMapLinks(): void {
		$expected = [51.0625, 7.0612];
		$inputs = [
			'51.0625, 7.0612', '51,0625 7,0612', '51.0625;7.0612', 'N 51.0625° E 7.0612°', '  51.0625 7.0612 ',
			'https://www.openstreetmap.org/?mlat=51.0625&mlon=7.0612#map=17/51.06/7.06',
			'https://www.openstreetmap.org/#map=17/51.06250/7.06120',
			// Google: die Ortsmarke zählt, nicht die Kartenmitte nach @
			'https://www.google.com/maps/place/Stammesheim/@51.0601,7.0590,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d51.0625!4d7.0612',
			'https://www.google.com/maps/@51.0625,7.0612,15z', 'https://maps.google.com/?q=51.0625,7.0612',
			'https://maps.apple.com/?ll=51.0625,7.0612&q=Heim', 'geo:51.0625,7.0612?z=17',
		];
		$parser = new CoordinateParser();
		foreach ($inputs as $input) {
			$this->assertSame($expected, $parser->parse($input), $input);
		}
	}

	public function testUnclearInputIsRejected(): void {
		$parser = new CoordinateParser();
		foreach (['', 'Musterstraße 1, 12345 Musterstadt', '95.1, 7.0', '51.0, 190.0', '0, 0', 'https://www.google.com/maps/place/Stammesheim'] as $input) {
			$this->assertNull($parser->parse($input), $input);
		}
	}

	public function testFormat(): void {
		$this->assertSame('51.0625, 7.0612', CoordinateParser::format(51.0625, 7.0612));
		$this->assertSame('-33.86, 151.2', CoordinateParser::format(-33.86, 151.2));
	}
}
