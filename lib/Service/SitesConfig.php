<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Einstellungen des Lagerplatzverzeichnisses, gesetzt von der Administration:
 *
 *   occ config:app:set lagerplaner sites_disabled --value=schwarzzelt_it
 *       blendet alle Einträge einer Quelle aus (Konzept 5.3)
 *   occ config:app:set lagerplaner home_coordinates --value="50.9375,6.9603"
 *       Standort des Stammesheims für die Entfernungsangabe (Breite,Länge)
 */
class SitesConfig {
	public function __construct(private IAppConfig $config) {
	}

	/** @return list<string> */
	public function disabledSources(): array {
		$value = $this->config->getValueString(Application::APP_ID, 'sites_disabled', '');
		return array_values(array_filter(array_map('trim', explode(',', $value))));
	}

	/** @param list<string> $sources */
	public function setDisabledSources(array $sources): void {
		$this->config->setValueString(Application::APP_ID, 'sites_disabled', implode(',', array_values(array_unique($sources))));
	}

	/** @param array{0: float, 1: float}|null $coordinates null entfernt den Standort */
	public function setHome(?array $coordinates): void {
		if ($coordinates === null) {
			$this->config->deleteKey(Application::APP_ID, 'home_coordinates');
			return;
		}
		$this->config->setValueString(Application::APP_ID, 'home_coordinates', $coordinates[0] . ',' . $coordinates[1]);
	}

	/**
	 * Stammesheime je Nextcloud-Gruppe (ein Stamm je Gruppe), für Clouds mehrerer Stämme.
	 *
	 * @return array<string, array{label: string, lat: float, lon: float}>
	 */
	public function groupHomes(): array {
		$value = json_decode($this->config->getValueString(Application::APP_ID, 'home_groups', '{}'), true);
		$homes = [];
		foreach (is_array($value) ? $value : [] as $gid => $home) {
			if (is_array($home) && is_numeric($home['lat'] ?? null) && is_numeric($home['lon'] ?? null)) {
				$homes[(string)$gid] = ['label' => (string)($home['label'] ?? $gid), 'lat' => (float)$home['lat'], 'lon' => (float)$home['lon']];
			}
		}
		return $homes;
	}

	/** @param array{label: string, lat: float, lon: float}|null $home null entfernt den Stamm */
	public function setGroupHome(string $gid, ?array $home): void {
		$homes = $this->groupHomes();
		if ($home === null) {
			unset($homes[$gid]);
		} else {
			$homes[$gid] = $home;
		}
		ksort($homes);
		$this->config->setValueString(Application::APP_ID, 'home_groups', json_encode((object)$homes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
	}

	/** Version der zuletzt übernommenen mitgelieferten Anreicherung einer Quelle */
	public function enrichmentVersion(string $source): string {
		return $this->config->getValueString(Application::APP_ID, 'sites_enrichment_' . $source, '');
	}

	public function setEnrichmentVersion(string $source, string $version): void {
		$this->config->setValueString(Application::APP_ID, 'sites_enrichment_' . $source, $version);
	}

	/** Datenquellen wöchentlich abgleichen (Hintergrundjob); abschaltbar in der Verwaltung */
	public function autoSync(): bool {
		return $this->config->getValueString(Application::APP_ID, 'sites_sync', 'yes') !== 'no';
	}

	public function setAutoSync(bool $enabled): void {
		$this->config->setValueString(Application::APP_ID, 'sites_sync', $enabled ? 'yes' : 'no');
	}

	/** @return array{0: float, 1: float}|null */
	public function home(): ?array {
		$value = $this->config->getValueString(Application::APP_ID, 'home_coordinates', '');
		if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $value, $m) !== 1) {
			return null;
		}
		[$lat, $lon] = [(float)$m[1], (float)$m[2]];
		return abs($lat) <= 90 && abs($lon) <= 180 ? [$lat, $lon] : null;
	}
}
