<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use DateTime;
use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Db\Camp;
use OCA\Lagerplaner\Db\Task;
use OCP\Notification\IManager;

/**
 * Benachrichtigungen zu Aufgaben in der Nextcloud: bei Zuweisung und vor der Frist.
 * Der Text entsteht erst im Notifier, damit er in der Sprache der Empfangenden erscheint.
 */
class TaskNotifications {
	public const ASSIGNED = 'task_assigned';
	public const DUE = 'task_due';

	public function __construct(private IManager $manager) {
	}

	/** @param list<string> $uids neu zugewiesene Personen, ohne die handelnde Person */
	public function assigned(Task $task, Camp $camp, string $actorUid, array $uids, int $now): void {
		foreach ($uids as $uid) {
			$this->send($uid, $task, self::ASSIGNED, ['campId' => (int)$camp->getId(), 'camp' => $camp->getName(), 'task' => $task->getTitle(), 'actor' => $actorUid], $now);
		}
	}

	/** @param list<string> $uids */
	public function due(Task $task, Camp $camp, array $uids, int $now): void {
		foreach ($uids as $uid) {
			$this->send($uid, $task, self::DUE, ['campId' => (int)$camp->getId(), 'camp' => $camp->getName(), 'task' => $task->getTitle(), 'due' => (string)$task->getDueDate()], $now);
		}
	}

	/** Erledigte oder gelöschte Aufgaben: offene Benachrichtigungen dazu entfernen. */
	public function clear(Task $task): void {
		$notification = $this->manager->createNotification();
		$notification->setApp(Application::APP_ID)->setObject('task', (string)$task->getId());
		$this->manager->markProcessed($notification);
	}

	/** @param array<string, mixed> $parameters */
	private function send(string $uid, Task $task, string $subject, array $parameters, int $now): void {
		$notification = $this->manager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($uid)
			->setDateTime(new DateTime('@' . $now))
			->setObject('task', (string)$task->getId())
			->setSubject($subject, $parameters);
		$this->manager->notify($notification);
	}
}
