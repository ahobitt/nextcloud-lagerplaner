<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Notification;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\TaskNotifications;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/** Text und Link der Benachrichtigungen des Lagerplaners. */
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urls,
		private IUserManager $users,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Camp planner');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();
		$subject = match ($notification->getSubject()) {
			TaskNotifications::ASSIGNED => $l->t('%1$s assigned you the task “%2$s” in “%3$s”.', [
				$this->users->getDisplayName((string)($p['actor'] ?? '')) ?? (string)($p['actor'] ?? ''), (string)($p['task'] ?? ''), (string)($p['camp'] ?? ''),
			]),
			TaskNotifications::DUE => $l->t('The task “%1$s” in “%2$s” is due on %3$s.', [
				(string)($p['task'] ?? ''), (string)($p['camp'] ?? ''), (string)($p['due'] ?? ''),
			]),
			default => throw new UnknownNotificationException(),
		};
		$notification->setParsedSubject($subject)
			->setLink($this->urls->linkToRouteAbsolute('lagerplaner.page.index') . '#/camp/' . (int)($p['campId'] ?? 0) . '/tasks')
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}
}
