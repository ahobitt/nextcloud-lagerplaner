<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Domain\Sites\SchwarzzeltCsv;
use OCA\Lagerplaner\Migration\ImportBundledData;
use OCA\Lagerplaner\Service\BundledSites;
use OCA\Lagerplaner\Sites\SchwarzzeltItSource;
use OCP\Migration\IOutput;

/** Mitgelieferter Stand der Schwarzzelt IT Campsitemap (data/sites/schwarzzelt_it.csv). */
final class BundledDataTest extends ServiceTestCase {
	public function testBundledCampsiteMapIsComplete(): void {
		$parsed = (new SchwarzzeltCsv())->parse((string)file_get_contents(SchwarzzeltItSource::BUNDLED));
		$this->assertSame([], $parsed['skipped']);
		$this->assertCount(192, $parsed['entries']);
		$types = array_count_values(array_column($parsed['entries'], 'type'));
		ksort($types);
		// „house campsite“ (Pfadfinderlandheim Stiefvater) zählt wie „campsite house“
		$this->assertSame(['both' => 97, 'house' => 40, 'tent' => 55], $types);
		$ids = array_unique(array_column($parsed['entries'], 'sourceId'));
		$this->assertCount(192, $ids);
		// Plätze außerhalb Deutschlands gehören dazu
		$outside = array_filter($parsed['entries'], static fn (array $e): bool => $e['coordinates']['lat'] < 47.2 || $e['coordinates']['lon'] < 5.8 || $e['coordinates']['lon'] > 15.1);
		$this->assertGreaterThan(20, count($outside));
	}

	public function testRepairStepImportsOnlyNeverSyncedSources(): void {
		$output = new class implements IOutput {
			public array $messages = [];
			public function debug(string $message): void {
			}
			public function info($message): void {
				$this->messages[] = $message;
			}
			public function warning($message): void {
				$this->messages[] = $message;
			}
			public function startProgress($max = 0): void {
			}
			public function advance($step = 1, $description = ''): void {
			}
			public function finishProgress(): void {
			}
		};
		$step = new ImportBundledData($this->bundled, $this->catalog);
		$step->run($output);
		$this->assertStringContainsString('192 neu', implode("\n", $output->messages));
		$this->assertStringContainsString('Anreicherung schwarzzelt_it (Anreicherung) übernommen: 192 Plätze zugeordnet', implode("\n", $output->messages));
		$this->assertStringContainsString('0 zusammengeführt, 0 nicht zugeordnet', implode("\n", $output->messages));
		$this->assertSame(192, $this->sites->search('anna', new \OCA\Lagerplaner\Domain\Sites\SiteQuery())['total']);

		$second = new $output();
		$step->run($second);
		$this->assertStringContainsString('aktuell', implode("\n", $second->messages));
	}
}
