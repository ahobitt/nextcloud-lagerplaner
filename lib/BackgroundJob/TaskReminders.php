<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Lagerplaner\Db\CampMapper;
use OCA\Lagerplaner\Db\TaskMapper;
use OCA\Lagerplaner\Service\TaskNotifications;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Erinnert die Verantwortlichen einmal je Frist an offene Aufgaben, die in den nächsten
 * zwei Tagen fällig sind. Aufgaben ohne Verantwortliche bleiben ohne Erinnerung.
 */
class TaskReminders extends TimedJob {
	public const DAYS_AHEAD = 2;

	public function __construct(
		ITimeFactory $time,
		private TaskMapper $tasks,
		private CampMapper $camps,
		private TaskNotifications $notifications,
	) {
		parent::__construct($time);
		$this->setInterval(6 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->remind();
	}

	/** @return int Anzahl erinnerter Aufgaben */
	public function remind(): int {
		$now = $this->time->getTime();
		$today = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('UTC'));
		$count = 0;
		foreach ($this->tasks->findDueForReminder($today->format('Y-m-d'), $today->modify('+' . self::DAYS_AHEAD . ' days')->format('Y-m-d')) as $task) {
			if ($task->assigneeList() === []) {
				continue;
			}
			try {
				$camp = $this->camps->find((int)$task->getCampId());
			} catch (DoesNotExistException) {
				continue;
			}
			$this->notifications->due($task, $camp, $task->assigneeList(), $now);
			$task->setRemindedAt($now);
			$this->tasks->update($task);
			$count++;
		}
		return $count;
	}
}
