<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Settings;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\AdminSettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * Verwaltungseinstellungen: Stammesheim, Spielesuche, Quellen des Lagerplatzverzeichnisses.
 * Delegierbar: Die Administration kann den Abschnitt einer Gruppe freigeben, etwa dem
 * Stammesvorstand, ohne ihr volle Administrationsrechte zu geben.
 */
class Admin implements IDelegatedSettings {
	public function __construct(
		private AdminSettingsService $settings,
		private IInitialState $initialState,
		private IL10N $l,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('admin', $this->settings->state());
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}

	public function getName(): ?string {
		return $this->l->t('Camp planner');
	}

	/** Gespeichert wird nur über die eigene Schnittstelle, nicht direkt in der App-Konfiguration. */
	public function getAuthorizedAppConfig(): array {
		return [];
	}
}
