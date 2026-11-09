<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\BackgroundJob\TaskReminders;
use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

/** Verantwortliche, Priorität und Erinnerungen für Aufgaben (Konzept 12.1). */
final class TaskFlowTest extends ServiceTestCase {
	private int $camp;

	protected function setUp(): void {
		parent::setUp();
		$this->camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$this->team->add($this->camp, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
	}

	public function testPeopleIncludeGroupMembers(): void {
		$this->assertSame(['anna', 'ben', 'carla'], array_column($this->camps->people($this->camp, 'anna'), 'uid'));
	}

	public function testAssigneesAreNotifiedButNotTheActor(): void {
		$task = $this->planning->createTask($this->camp, 'anna', ['title' => 'Bus anfragen', 'assignees' => ['anna', 'ben'], 'priority' => 'high']);

		$this->assertSame([['assigned', 'ben', 'Bus anfragen']], $this->notifications->sent);
		$this->assertSame(['anna', 'ben'], $task->assigneeList());
		$this->assertSame('high', $task->getPriority());

		// Erneutes Speichern benachrichtigt nur neu Hinzugekommene.
		$this->planning->updateTask((int)$task->getId(), 'anna', ['assignees' => ['anna', 'ben', 'carla']]);
		$this->assertSame(['carla'], array_column(array_slice($this->notifications->sent, 1), 1));
	}

	public function testOnlyPeopleWithAccessCanBeAssigned(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->planning->createTask($this->camp, 'anna', ['title' => 'X', 'assignees' => ['emil']]);
	}

	public function testHelpersCanClaimAndCompleteButNotAssignOthers(): void {
		$task = $this->planning->createTask($this->camp, 'anna', ['title' => 'Kühlschrank leihen']);

		$claimed = $this->planning->claimTask((int)$task->getId(), 'ben');
		$this->assertSame(['ben'], $claimed->assigneeList());

		$done = $this->planning->updateTask((int)$task->getId(), 'ben', ['status' => 'done']);
		$this->assertSame(['ben', 'done'], [$done->getCompletedBy(), $done->getStatus()]);
		$this->assertContains((int)$task->getId(), $this->notifications->cleared);

		$reopened = $this->planning->updateTask((int)$task->getId(), 'ben', ['status' => 'open']);
		$this->assertNull($reopened->getCompletedBy());

		$this->expectException(ForbiddenException::class);
		$this->planning->updateTask((int)$task->getId(), 'ben', ['assignees' => ['carla']]);
	}

	public function testTaskLinkedToProgrammeItem(): void {
		$own = $this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Nachtwanderung', 'day' => '2027-07-27', 'startTime' => '21:30', 'durationMinutes' => 90]);
		$task = $this->planning->createTask($this->camp, 'anna', ['title' => 'Strecke ablaufen', 'programItemId' => (int)$own->getId()]);
		$this->assertSame((int)$own->getId(), $task->getProgramItemId());

		$this->planning->deleteProgramItem((int)$own->getId(), 'anna');
		$reloaded = array_values(array_filter($this->planning->tasks($this->camp, 'anna'), static fn ($t): bool => $t->getTitle() === 'Strecke ablaufen'))[0];
		$this->assertNull($reloaded->getProgramItemId());
	}

	public function testRemindersAreSentOncePerDueDate(): void {
		// Die Testuhr steht am 4. Oktober 2026.
		$soon = $this->planning->createTask($this->camp, 'anna', ['title' => 'Zuschuss beantragen', 'dueDate' => '2026-10-05', 'assignees' => ['ben']]);
		$this->planning->createTask($this->camp, 'anna', ['title' => 'Später', 'dueDate' => '2026-11-30', 'assignees' => ['ben']]);
		$this->planning->createTask($this->camp, 'anna', ['title' => 'Niemand zuständig', 'dueDate' => '2026-10-05']);
		$this->notifications->sent = [];
		$job = new TaskReminders($this->time, $this->taskMapper, $this->campMapper, $this->notifications);

		$this->assertSame(1, $job->remind());
		$this->assertSame([['due', 'ben', 'Zuschuss beantragen']], $this->notifications->sent);
		$this->assertSame(0, $job->remind());

		// Neue Frist: erneut erinnern.
		$this->planning->updateTask((int)$soon->getId(), 'anna', ['dueDate' => '2026-10-06']);
		$this->assertSame(1, $job->remind());
	}

	public function testUnknownPriorityIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->planning->createTask($this->camp, 'anna', ['title' => 'X', 'priority' => 'urgent']);
	}
}
