<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Command;

use OCA\Lagerplaner\Service\SiteDirectoryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Übernimmt einen Datenstand des Lagerplatzverzeichnisses als Datei im Format lagerplaner-sites/1,
 * etwa eine eigene Liste; Datenquellen wie die Campsitemap gleicht lagerplaner:sites:sync ab:
 *
 *   occ lagerplaner:sites:import /pfad/zum/datenstand.json
 *
 * Das Format ist in docs/site-import.md beschrieben.
 */
class ImportSites extends Command {
	private const MAX_BYTES = 50 * 1024 * 1024;

	public function __construct(private SiteDirectoryService $directory) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('lagerplaner:sites:import')
			->setDescription('Lagerplätze aus einem Datenstand im Format lagerplaner-sites/1 übernehmen')
			->addArgument('file', InputArgument::REQUIRED, 'JSON-Datei mit dem Datenstand');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$file = (string)$input->getArgument('file');
		if (!is_file($file) || !is_readable($file) || filesize($file) > self::MAX_BYTES) {
			$output->writeln('<error>Datei nicht lesbar oder größer als 50 MB.</error>');
			return 1;
		}
		try {
			$document = json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
			$stats = $this->directory->import(is_array($document) ? $document : []);
		} catch (\JsonException $e) {
			$output->writeln('<error>Kein gültiges JSON: ' . $e->getMessage() . '</error>');
			return 1;
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln(sprintf('Quelle %s: %d neu, %d aktualisiert, %d nicht mehr in der Quelle (gekennzeichnet), %d übersprungen.',
			$stats['source'], $stats['added'], $stats['updated'], $stats['missing'], count($stats['skipped'])));
		foreach ($stats['skipped'] as $reason) {
			$output->writeln('  übersprungen: ' . $reason);
		}
		return 0;
	}
}
