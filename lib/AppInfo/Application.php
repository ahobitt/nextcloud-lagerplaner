<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\AppInfo;

use OCA\Lagerplaner\Access\Directory;
use OCA\Lagerplaner\Access\NextcloudDirectory;
use OCA\Lagerplaner\Cookbook\CookbookLibrary;
use OCA\Lagerplaner\Cookbook\NextcloudCookbookLibrary;
use OCA\Lagerplaner\Games\GameSource;
use OCA\Lagerplaner\Notification\Notifier;
use OCA\Lagerplaner\Games\WordPressGameSource;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'lagerplaner';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(GameSource::class, WordPressGameSource::class);
		$context->registerServiceAlias(Directory::class, NextcloudDirectory::class);
		$context->registerServiceAlias(CookbookLibrary::class, NextcloudCookbookLibrary::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
