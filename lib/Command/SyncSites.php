<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Command;

use OCA\Lagerplaner\Service\SiteSynchronizer;
use OCA\Lagerplaner\Sites\SiteSources;
use OCA\Lagerplaner\Sites\SourceUnavailable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** occ lagerplaner:sites:sync [quelle] [--bundled] */
class SyncSites extends Command {
	public function __construct(
		private SiteSources $sources,
		private SiteSynchronizer $synchronizer,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('lagerplaner:sites:sync')
			->setDescription('Gleicht die Datenquellen des Lagerplatzverzeichnisses ab, etwa die Schwarzzelt IT Campsitemap')
			->addArgument('source', InputArgument::OPTIONAL, 'Schlüssel der Quelle; ohne Angabe alle')
			->addOption('bundled', null, InputOption::VALUE_NONE, 'Den mit der App mitgelieferten Stand verwenden, ohne Netzzugang');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$keys = $input->getArgument('source') ? [(string)$input->getArgument('source')] : array_keys($this->sources->all());
		$failed = false;
		foreach ($keys as $key) {
			try {
				$stats = $this->synchronizer->sync($key, (bool)$input->getOption('bundled'));
				$output->writeln($stats['unchanged']
					? "$key: unverändert"
					: sprintf('%s (%s): %d neu, %d aktualisiert, %d nicht mehr in der Quelle, %d mit eigenen Angaben geschützt, %d übersprungen',
						$key, $stats['origin'], $stats['added'], $stats['updated'], $stats['missing'], $stats['protected'], count($stats['skipped'])));
				foreach ($stats['skipped'] as $reason) {
					$output->writeln('  übersprungen: ' . $reason);
				}
			} catch (\Throwable $e) {
				$output->writeln("<error>$key: " . $e->getMessage() . '</error>');
				$failed = true;
			}
		}
		return $failed ? 1 : 0;
	}
}
