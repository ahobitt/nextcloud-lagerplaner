<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\BackgroundJob;

use OCA\Lagerplaner\Service\SitesConfig;
use OCA\Lagerplaner\Service\SiteSynchronizer;
use OCA\Lagerplaner\Sites\SiteSources;
use OCA\Lagerplaner\Sites\SourceUnavailable;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Gleicht die Datenquellen des Lagerplatzverzeichnisses wöchentlich ab (abschaltbar). */
class SyncSites extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private SiteSources $sources,
		private SiteSynchronizer $synchronizer,
		private SitesConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(7 * 24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if (!$this->config->autoSync()) {
			return;
		}
		foreach ($this->sources->all() as $source) {
			try {
				$this->synchronizer->sync($source->key());
			} catch (SourceUnavailable $e) {
				// Beim nächsten Lauf erneut; der Fehler steht bei der Quelle in der Verwaltung.
				$this->logger->info('Lagerplätze von ' . $source->key() . ' nicht abgeglichen: ' . $e->getMessage(), ['app' => 'lagerplaner']);
			}
		}
	}
}
