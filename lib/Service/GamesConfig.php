<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Einstellungen der Spieleanbindung.
 *
 * Seit 0.12.0 eingeschaltet, bis die Administration abschaltet (Konzept 18.4). Abgerufen wird
 * nur der öffentliche Spielekatalog; Such- oder Lagerdaten gehen nicht hinaus.
 */
class GamesConfig {
	public function __construct(private IAppConfig $config) {
	}

	/**
	 * Die Spielesuche ist eingeschaltet, solange die Administration sie nicht ausdrücklich
	 * abschaltet: occ config:app:set lagerplaner games_enabled --value=no
	 */
	public function isEnabled(): bool {
		return !in_array(strtolower($this->config->getValueString(Application::APP_ID, 'games_enabled', 'yes')), ['no', '0', 'false'], true);
	}

	public function setEnabled(bool $enabled): void {
		$this->config->setValueString(Application::APP_ID, 'games_enabled', $enabled ? 'yes' : 'no');
	}

	public function syncedAt(): int {
		return (int)$this->config->getValueString(Application::APP_ID, 'games_synced_at', '0');
	}

	public function setSyncedAt(int $time): void {
		$this->config->setValueString(Application::APP_ID, 'games_synced_at', (string)$time);
	}
}
