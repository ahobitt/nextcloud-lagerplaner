<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Migration;

use OCA\Lagerplaner\Service\BundledSites;
use OCA\Lagerplaner\Service\GameCatalogService;
use OCA\Lagerplaner\Service\GamesUnavailableException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Läuft bei Installation und jedem Update der App (Konzept 5.3, 7.4):
 *
 * - Datenquellen des Lagerplatzverzeichnisses, die noch nie abgeglichen wurden, bekommen den
 *   mitgelieferten Stand (data/sites/); danach hält der wöchentliche Abgleich sie aktuell.
 * - Der Spielekatalog von Pfadfinder-Spiele.de wird im Hintergrund geladen, damit die
 *   Spielesuche beim ersten Öffnen schon gefüllt ist.
 */
class ImportBundledData implements IRepairStep {
	public function __construct(
		private BundledSites $bundled,
		private GameCatalogService $games,
	) {
	}

	public function getName(): string {
		return 'Lagerplaner: mitgelieferte Lagerplätze übernehmen und Spielekatalog laden';
	}

	public function run(IOutput $output): void {
		foreach ($this->bundled->importNeverSynced() as $source => $result) {
			if ($result === 'current') {
				$output->info("Lagerplätze von $source sind aktuell.");
			} elseif (is_string($result)) {
				$output->warning("Lagerplätze von $source nicht übernommen: $result");
			} elseif (isset($result['matched'])) {
				$output->info(sprintf('Anreicherung %s übernommen: %d Plätze zugeordnet, %d mit neuen Angaben, %d zusammengeführt, %d nicht zugeordnet.',
					$source, $result['matched'], $result['updated'], $result['merged'], count($result['unmatched'])));
			} else {
				$output->info(sprintf('Lagerplätze von %s übernommen: %d neu, %d aktualisiert, %d nicht mehr in der Quelle, %d übersprungen.',
					$source, $result['added'], $result['updated'], $result['missing'], count($result['skipped'])));
			}
		}

		try {
			if ($this->games->state() === 'importing') {
				$output->info('Der Spielekatalog von Pfadfinder-Spiele.de wird mit dem nächsten Cron-Lauf geladen.');
			}
		} catch (GamesUnavailableException) {
			// Von der Administration abgeschaltet.
		}
	}
}
