<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\BackgroundJob;

use OCA\Lagerplaner\Service\GameCatalogService;
use OCA\Lagerplaner\Service\GamesUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Gleicht den Spielekatalog einmal täglich ab, wenn die Anbindung eingeschaltet ist. */
class SyncGames extends TimedJob {
	public function __construct(ITimeFactory $time, private GameCatalogService $catalog) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if (!$this->catalog->isEnabled()) {
			return;
		}
		try {
			$this->catalog->sync();
		} catch (GamesUnavailableException) {
			// Nächster Versuch beim nächsten Lauf; der vorhandene Katalog bleibt nutzbar.
		}
	}
}
