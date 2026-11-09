<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Db\Camp;
use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Service\NotFoundException;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

/** Lagerrollen und Teilen mit ganzen Nextcloud-Gruppen (Konzept 18.1). */
final class TeamFlowTest extends ServiceTestCase {
	private function camp(): int {
		return (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
	}

	private function forbidden(callable $action, string $what): void {
		try {
			$action();
			$this->fail('Nicht verboten: ' . $what);
		} catch (ForbiddenException) {
			$this->assertTrue(true);
		}
	}

	public function testSharingWithAGroupGivesEveryMemberAccess(): void {
		$id = $this->camp();
		$this->team->add($id, 'anna', 'group', 'leiterrunde', ['mitarbeit']);

		foreach (['ben', 'carla'] as $uid) {
			$this->assertSame([$id], array_map(static fn (Camp $c): int => (int)$c->getId(), $this->camps->listForUser($uid)), $uid);
		}
		$this->assertSame([], $this->camps->listForUser('emil'));
		$this->assertNotSame([], $this->planning->program($id, 'ben'));
	}

	public function testNewGroupMembersGetAccessImmediately(): void {
		$id = $this->camp();
		$this->team->add($id, 'anna', 'group', 'leiterrunde', ['lesen']);
		$this->directory->groups['leiterrunde'][1][] = 'emil';

		$this->assertCount(1, $this->camps->listForUser('emil'));
	}

	public function testHelpersMayTickTasksButNotEditThePlan(): void {
		$id = $this->camp();
		$this->team->add($id, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
		$item = $this->planning->program($id, 'ben')[0];
		$task = $this->planning->tasks($id, 'ben')[0];

		$this->assertSame('done', $this->planning->updateTask((int)$task->getId(), 'ben', ['status' => 'done'])->getStatus());
		$this->forbidden(fn () => $this->planning->updateTask((int)$task->getId(), 'ben', ['title' => 'Anders']), 'Aufgabe umbenennen');
		$this->forbidden(fn () => $this->planning->confirmProgramItem((int)$item->getId(), 'ben'), 'Programm bestätigen');
		$this->forbidden(fn () => $this->planning->createTask($id, 'ben', ['title' => 'X']), 'Aufgabe anlegen');
	}

	public function testReadersMayOnlyLook(): void {
		$id = $this->camp();
		$this->team->add($id, 'anna', 'user', 'emil', ['lesen']);
		$task = $this->planning->tasks($id, 'emil')[0];

		$this->forbidden(fn () => $this->planning->updateTask((int)$task->getId(), 'emil', ['status' => 'done']), 'Abhaken');
	}

	public function testProgrammeTeamEditsProgramme(): void {
		$id = $this->camp();
		$this->team->add($id, 'anna', 'user', 'carla', ['programm']);
		$item = $this->planning->program($id, 'carla')[0];

		$this->assertSame('confirmed', $this->planning->confirmProgramItem((int)$item->getId(), 'carla')->getReviewState());
		$this->forbidden(fn () => $this->catering->overview($id, 'carla'), 'Ernährungsangaben');
	}

	public function testDietaryDataOnlyForLeadersAndKitchen(): void {
		$id = $this->camp();
		$this->team->add($id, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
		$this->team->add($id, 'anna', 'group', 'kueche', ['kueche']);

		$this->forbidden(fn () => $this->catering->overview($id, 'ben'), 'Küche für Mitarbeitende');
		$this->assertSame(42, $this->catering->saveNeeds($id, 'dora', ['combinations' => [['key' => 'none', 'count' => 42]]])['needs']['total']);
	}

	public function testRolesFromUserAndGroupAreCombined(): void {
		$id = $this->camp();
		$this->team->add($id, 'anna', 'group', 'kueche', ['kueche']);
		$this->team->add($id, 'anna', 'user', 'dora', ['programm']);
		$camp = $this->camps->getForUser($id, 'dora');

		$access = $this->camps->accessFor($camp, 'dora');
		$this->assertTrue($access['can']['program']);
		$this->assertTrue($access['can']['catering']);
		$this->assertFalse($access['can']['wizard']);
		$this->assertFalse($access['isOwner']);
	}

	public function testOnlyLeadersManageTheTeamRunTheAssistantAndDelete(): void {
		$id = $this->camp();
		$this->team->add($id, 'anna', 'group', 'leiterrunde', ['programm']);

		$this->forbidden(fn () => $this->team->add($id, 'ben', 'user', 'emil', ['lesen']), 'Team verwalten');
		$this->forbidden(fn () => $this->wizard->rerunPreview($id, 'ben', Fixtures::summerCamp()), 'Assistent');
		$this->forbidden(fn () => $this->camps->delete($id, 'ben'), 'Lager löschen');

		// Eine zweite Lagerleitung darf alles.
		$this->team->add($id, 'anna', 'user', 'ben', ['leitung']);
		$this->team->add($id, 'ben', 'user', 'emil', ['lesen']);
		$this->assertCount(3, $this->team->list($id, 'ben')['members']);
	}

	public function testNonMembersSeeNothing(): void {
		$id = $this->camp();

		$this->expectException(NotFoundException::class);
		$this->planning->program($id, 'emil');
	}

	public function testRemovingTheGroupRevokesAccess(): void {
		$id = $this->camp();
		$member = $this->team->add($id, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
		$this->team->remove($member['id'], 'anna');

		$this->assertSame([], $this->camps->listForUser('ben'));
	}

	public function testAddingAgainReplacesRoles(): void {
		$id = $this->camp();
		$this->team->add($id, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
		$again = $this->team->add($id, 'anna', 'group', 'leiterrunde', ['programm', 'kueche']);

		$this->assertSame(['programm', 'kueche'], $again['roles']);
		$this->assertCount(1, $this->team->list($id, 'anna')['members']);
		$this->assertSame('Leiterrunde', $again['displayName']);
	}

	public function testInvalidMembersAndRolesAreRejected(): void {
		$id = $this->camp();
		foreach ([
			['user', 'anna', ['programm']],
			['user', 'unbekannt', ['programm']],
			['group', 'admin-gruppe', ['programm']],
			['user', 'ben', []],
			['user', 'ben', ['chef']],
			['team', 'ben', ['programm']],
		] as $i => [$type, $who, $roles]) {
			try {
				$this->team->add($id, 'anna', $type, $who, $roles);
				$this->fail("Nicht abgelehnt: Fall $i");
			} catch (\InvalidArgumentException) {
				$this->assertTrue(true);
			}
		}
	}

	/** Regression Review 0.6.0: Die Freigabe-Einstellungen gelten auch beim Hinzufügen über die API. */
	public function testSharingPolicyIsCheckedWhenAdding(): void {
		$id = $this->camp();
		$this->directory->blocked = ['user:emil', 'group:kueche'];

		foreach ([['user', 'emil'], ['group', 'kueche']] as [$type, $who]) {
			try {
				$this->team->add($id, 'anna', $type, $who, ['lesen']);
				$this->fail("Nicht abgelehnt: $type $who");
			} catch (\InvalidArgumentException $e) {
				$this->assertStringContainsString('Freigabe-Einstellungen', $e->getMessage());
			}
		}
		$this->assertCount(1, [$this->team->add($id, 'anna', 'user', 'ben', ['lesen'])]);
	}

	public function testSearchLeavesOutExistingMembersAndTheOwner(): void {
		$id = $this->camp();
		$this->team->add($id, 'anna', 'user', 'ben', ['lesen']);

		$ids = array_map(static fn (array $e): string => $e['type'] . ':' . $e['id'], $this->team->search($id, 'anna', 'e'));
		$this->assertContains('group:leiterrunde', $ids);
		$this->assertContains('user:emil', $ids);
		$this->assertNotContains('user:ben', $ids);
		$this->assertNotContains('user:anna', $ids);
	}
}
