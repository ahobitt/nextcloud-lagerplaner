<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\BackgroundJob;

use OCA\Lagerplaner\Service\GameCatalogService;
use OCA\Lagerplaner\Service\GamesUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Einmaliger Abgleich des Spielekatalogs, angestoßen von der ersten Suche oder wenn der
 * Katalog veraltet ist. Läuft mit dem Nextcloud-Cron statt im Aufruf der Oberfläche.
 */
class ImportGames extends QueuedJob {
	public function __construct(ITimeFactory $time, private GameCatalogService $catalog) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		if (!$this->catalog->isEnabled()) {
			return;
		}
		try {
			$this->catalog->sync();
		} catch (GamesUnavailableException) {
			// Der nächste Abruf stellt den Abgleich erneut ein.
		}
	}
}
