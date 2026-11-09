<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Db\ProgramItem;
use OCA\Lagerplaner\Domain\Rerun\RerunPlanner;
use OCA\Lagerplaner\Domain\Wizard\DraftGenerator;
use OCA\Lagerplaner\Domain\Games\GameQuery;
use OCA\Lagerplaner\Domain\Catering\InvalidCateringInput;
use OCA\Lagerplaner\Domain\Catering\PlanIssue;
use OCA\Lagerplaner\Service\CampService;
use OCA\Lagerplaner\Service\CateringService;
use OCA\Lagerplaner\Service\GameCatalogService;
use OCA\Lagerplaner\Service\GamesUnavailableException;
use OCA\Lagerplaner\Service\NotFoundException;
use OCA\Lagerplaner\Service\PlanningService;
use OCA\Lagerplaner\Service\PreviewOutdatedException;
use OCA\Lagerplaner\Service\WizardService;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;
use PHPUnit\Framework\TestCase;

/** Ablauf Anlegen → Bestätigen/Bearbeiten → erneuter Lauf, mit In-Memory-Mappern. */
final class WizardFlowTest extends ServiceTestCase {
	public function testCreatedCampContainsTheWholeDraftAsProposals(): void {
		$preview = $this->wizard->preview(Fixtures::summerCamp());
		$camp = $this->wizard->create('anna', Fixtures::summerCamp());
		$id = (int)$camp->getId();

		$program = $this->planning->program($id, 'anna');
		$this->assertCount(count($preview->programItems), $program);
		$this->assertCount(count($preview->tasks), $this->planning->tasks($id, 'anna'));
		$this->assertCount(count($preview->meals), $this->planning->meals($id, 'anna'));
		$this->assertCount(5, $this->camps->groups($id));
		foreach ($program as $item) {
			$this->assertSame('proposal', $item->getReviewState());
			$this->assertTrue($item->provenance()->isAutoChangeable());
		}
	}

	public function testOtherUsersCannotSeeTheCamp(): void {
		$camp = $this->wizard->create('anna', Fixtures::summerCamp());

		$this->expectException(NotFoundException::class);
		$this->planning->program((int)$camp->getId(), 'ben');
	}

	public function testRerunKeepsConfirmedAndEditedItems(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$items = $this->planning->program($id, 'anna');

		$teardown = $this->first($items, 'Zeltabbau und Platzabnahme');
		$opening = $this->first($items, 'Lagereröffnung: Einstieg ins Thema „Wikinger“');
		$this->planning->confirmProgramItem((int)$teardown->getId(), 'anna');
		$this->planning->updateProgramItem((int)$opening->getId(), 'anna', ['title' => 'Eröffnung mit Drachenboot']);

		$input = Fixtures::summerCamp(['end' => '2027-08-05', 'program' => ['theme' => 'Mittelalter']]);
		$preview = $this->wizard->rerunPreview($id, 'anna', $input);
		$this->assertSame(1, $preview['program']['summary']['orphaned']);
		$this->assertSame(1, $preview['program']['summary']['conflict']);

		$this->wizard->rerunApply($id, 'anna', $input, $preview['token']);
		$after = $this->planning->program($id, 'anna');
		$titles = array_map(static fn (ProgramItem $i): string => $i->getDay() . ' ' . $i->getTitle(), $after);

		$this->assertContains('2027-08-04 Zeltabbau und Platzabnahme', $titles);
		$this->assertContains('2027-08-05 Zeltabbau und Platzabnahme', $titles);
		$this->assertContains('2027-07-24 Eröffnung mit Drachenboot', $titles);
		$alternative = $this->first($after, 'Lagereröffnung: Einstieg ins Thema „Mittelalter“');
		$this->assertSame((int)$opening->getId(), $alternative->getAlternativeTo());
		$this->assertSame('2027-08-05', $this->camps->getForUser($id, 'anna')->getEndDate());
	}

	public function testSecondRerunWithSameInputChangesNothing(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$input = Fixtures::summerCamp(['end' => '2027-08-05']);
		$first = $this->wizard->rerunPreview($id, 'anna', $input);
		$this->wizard->rerunApply($id, 'anna', $input, $first['token']);

		$second = $this->wizard->rerunPreview($id, 'anna', $input);
		$this->assertFalse($second['hasEffect']);
		$this->assertSame([], $second['camp']['changes']);
	}

	public function testOutdatedPreviewIsRejected(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$input = Fixtures::summerCamp(['end' => '2027-08-05']);
		$preview = $this->wizard->rerunPreview($id, 'anna', $input);

		// Der Abbau am bisherigen letzten Tag würde entfernt; nach der Bearbeitung ist er geschützt.
		$teardown = $this->first($this->planning->program($id, 'anna'), 'Zeltabbau und Platzabnahme');
		$this->planning->updateProgramItem((int)$teardown->getId(), 'anna', ['startTime' => '10:15']);

		$this->expectException(PreviewOutdatedException::class);
		$this->wizard->rerunApply($id, 'anna', $input, $preview['token']);
	}

	/** Jede Änderung an der Planung erhöht die Lagerversion; eine ältere Vorschau gilt nicht mehr. */
	public function testAnyEditInvalidatesPreview(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$input = Fixtures::summerCamp(['end' => '2027-08-05']);
		$preview = $this->wizard->rerunPreview($id, 'anna', $input);

		$arrival = $this->first($this->planning->program($id, 'anna'), 'Anreise und Zeltaufbau');
		$this->planning->confirmProgramItem((int)$arrival->getId(), 'anna');

		$this->expectException(PreviewOutdatedException::class);
		$this->wizard->rerunApply($id, 'anna', $input, $preview['token']);
	}

	/** Regression Review 0.1.0: Der Prüfschlüssel bindet die Übernahme an die gezeigten Eingaben. */
	public function testApplyWithDifferentInputIsRejected(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$preview = $this->wizard->rerunPreview($id, 'anna', Fixtures::summerCamp(['name' => 'Sommerlager Nord']));

		$this->expectException(PreviewOutdatedException::class);
		$this->wizard->rerunApply($id, 'anna', Fixtures::summerCamp(['name' => 'Sommerlager Süd']), $preview['token']);
	}

	/** Regression Review 0.1.0: Änderungen nur an Stammdaten sind sichtbar und lassen sich übernehmen. */
	public function testMasterDataOnlyRerunIsShownAndApplied(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$input = Fixtures::summerCamp(['name' => 'Sommerlager Nord', 'groups' => [4 => ['count' => 6]]]);
		$preview = $this->wizard->rerunPreview($id, 'anna', $input);

		$this->assertTrue($preview['hasEffect']);
		$fields = array_map(static fn (array $c): string => $c['kind'] . ':' . ($c['field'] ?? ''), $preview['camp']['changes']);
		$this->assertContains('camp:name', $fields);
		$this->assertContains('group:count', $fields);

		$this->wizard->rerunApply($id, 'anna', $input, $preview['token']);
		$this->assertSame('Sommerlager Nord', $this->camps->getForUser($id, 'anna')->getName());
	}

	/** Regression Review 0.1.0: Zwei Übernahmen derselben Vorschau – nur die erste gelingt. */
	public function testConcurrentApplyOnlySucceedsOnce(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$input = Fixtures::summerCamp(['end' => '2027-08-05']);
		$preview = $this->wizard->rerunPreview($id, 'anna', $input);

		$this->wizard->rerunApply($id, 'anna', $input, $preview['token']);
		$this->expectException(PreviewOutdatedException::class);
		$this->wizard->rerunApply($id, 'anna', $input, $preview['token']);
	}

	/** Regression Review 0.1.0: Ein gelöschter Vorschlag kommt nach einem Lauf nicht wieder. */
	public function testDeletedProposalStaysDeletedUntilRestored(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$round = $this->first($this->planning->program($id, 'anna'), 'Abendrunde');
		$this->planning->deleteProgramItem((int)$round->getId(), 'anna');

		$input = Fixtures::summerCamp(['end' => '2027-08-05']);
		$preview = $this->wizard->rerunPreview($id, 'anna', $input);
		$this->assertSame(1, $preview['program']['summary']['suppressed']);
		$this->wizard->rerunApply($id, 'anna', $input, $preview['token']);

		$active = array_filter($this->planning->program($id, 'anna'), static fn (ProgramItem $i): bool => $i->getRuleKey() === $round->getRuleKey());
		$this->assertCount(0, $active);
		$this->assertCount(1, $this->planning->deletedProgram($id, 'anna'));

		$this->planning->restoreProgramItem((int)$round->getId(), 'anna');
		$this->assertCount(0, $this->planning->deletedProgram($id, 'anna'));
	}

	/** Eigene Programmpunkte werden endgültig gelöscht, nicht als Ausschluss gespeichert. */
	public function testOwnProgramItemsCanBeCreatedAndDeleted(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$item = $this->planning->createProgramItem($id, 'anna', [
			'title' => 'Singewettstreit', 'day' => '2027-07-26', 'startTime' => '20:00', 'durationMinutes' => 60, 'groups' => ['rover'],
		]);
		$this->assertSame('manual', $item->getOrigin());
		$this->assertSame('confirmed', $item->getReviewState());

		$this->planning->deleteProgramItem((int)$item->getId(), 'anna');
		$this->assertSame([], $this->planning->deletedProgram($id, 'anna'));
	}

	public function testOwnProgramItemValidation(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		foreach ([
			['title' => 'X', 'day' => '2027-09-01', 'startTime' => '10:00'],
			['title' => 'X', 'day' => '2027-07-26', 'startTime' => '25:00'],
			['title' => 'X', 'day' => '2027-07-26', 'startTime' => '10:00', 'groups' => ['unbekannt']],
			['title' => 'X', 'day' => '2027-07-26', 'startTime' => '10:00', 'durationMinutes' => '60'],
			['title' => '', 'day' => '2027-07-26', 'startTime' => '10:00'],
		] as $i => $fields) {
			try {
				$this->planning->createProgramItem($id, 'anna', $fields);
				$this->fail("Nicht abgelehnt: Fall $i");
			} catch (\InvalidArgumentException) {
				$this->assertTrue(true);
			}
		}
	}

	/** Regression Review 0.1.0: Bestätigte Zahlen einer Gruppe bleiben bei einem Lauf erhalten. */
	public function testRerunKeepsGroupRecords(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$before = $this->camps->groups($id);
		$input = Fixtures::summerCamp(['groups' => [0 => ['count' => 11]]]);
		$preview = $this->wizard->rerunPreview($id, 'anna', $input);
		$this->wizard->rerunApply($id, 'anna', $input, $preview['token']);

		$after = $this->camps->groups($id);
		$this->assertSame(array_map(static fn ($g) => $g->getId(), $before), array_map(static fn ($g) => $g->getId(), $after));
		$this->assertSame(11, $after[0]->getEstimatedCount());
	}

	/** Regression Review 0.1.0: Fristen müssen gültige Kalendertage sein. */
	public function testInvalidDueDateIsRejected(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$task = $this->planning->tasks($id, 'anna')[0];

		$this->expectException(\InvalidArgumentException::class);
		$this->planning->updateTask((int)$task->getId(), 'anna', ['dueDate' => '2027-02-30']);
	}

	public function testTasksCanBeCreatedAndDeleted(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$own = $this->planning->createTask($id, 'anna', ['title' => 'Bus anfragen', 'dueDate' => '2027-05-01']);
		$generated = $this->planning->tasks($id, 'anna')[0];
		$count = count($this->planning->tasks($id, 'anna'));

		$this->planning->deleteTask((int)$own->getId(), 'anna');
		$this->planning->deleteTask((int)$generated->getId(), 'anna');
		$this->assertCount($count - 2, $this->planning->tasks($id, 'anna'));

		// Die gelöschte Assistentenaufgabe kommt bei einem erneuten Lauf nicht wieder.
		$input = Fixtures::summerCamp(['name' => 'Umbenannt']);
		$preview = $this->wizard->rerunPreview($id, 'anna', $input);
		$this->assertSame(0, $preview['tasks']['summary']['add']);
	}

	/** Regression Review 0.1.0: Geschützte Punkte außerhalb eines verkürzten Zeitraums bleiben abrufbar. */
	public function testConfirmedItemsOutsideShortenedPeriodRemainAvailable(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$teardown = $this->first($this->planning->program($id, 'anna'), 'Zeltabbau und Platzabnahme');
		$this->planning->confirmProgramItem((int)$teardown->getId(), 'anna');

		$input = Fixtures::summerCamp(['end' => '2027-08-02']);
		$preview = $this->wizard->rerunPreview($id, 'anna', $input);
		$this->wizard->rerunApply($id, 'anna', $input, $preview['token']);

		$outside = array_filter($this->planning->program($id, 'anna'), static fn (ProgramItem $i): bool => $i->getDay() > '2027-08-02');
		$this->assertCount(1, $outside);
		// Verschieben in den Zeitraum ist möglich.
		$moved = $this->planning->updateProgramItem((int)$teardown->getId(), 'anna', ['day' => '2027-08-02']);
		$this->assertSame('2027-08-02', $moved->getDay());
	}

	public function testInvalidEditIsRejected(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$item = $this->planning->program($id, 'anna')[0];

		$this->expectException(\InvalidArgumentException::class);
		$this->planning->updateProgramItem((int)$item->getId(), 'anna', ['day' => '2027-09-01']);
	}

	public function testDoneTaskSurvivesRerun(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$task = $this->planning->tasks($id, 'anna')[0];
		$this->planning->updateTask((int)$task->getId(), 'anna', ['status' => 'done']);

		$input = Fixtures::summerCamp(['start' => '2027-07-31', 'end' => '2027-08-11']);
		$preview = $this->wizard->rerunPreview($id, 'anna', $input);
		$this->wizard->rerunApply($id, 'anna', $input, $preview['token']);

		$after = array_filter($this->planning->tasks($id, 'anna'), static fn ($t): bool => $t->getId() === $task->getId());
		$this->assertCount(1, $after);
		$this->assertSame('done', array_values($after)[0]->getStatus());
	}

	public function testDeleteRemovesEverything(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$this->camps->delete($id, 'anna');

		$this->assertSame([], $this->camps->listForUser('anna'));
	}

	public function testGamesNeedActivation(): void {
		$this->gamesConfig->enabled = false;

		$this->expectException(GamesUnavailableException::class);
		$this->catalog->search(new GameQuery());
	}

	public function testFirstSearchSyncsTheCatalogPageByPage(): void {
		$result = $this->catalog->search(new GameQuery());

		$this->assertSame(4, $result['total']);
		$this->assertSame(3, $this->source->requests);
		$this->assertSame('CC BY-NC-SA 4.0', $result['license']['name']);

		$this->catalog->search(new GameQuery(text: 'zip'));
		$this->assertSame(3, $this->source->requests);
	}

	public function testSearchFiltersAndRanksByTitle(): void {
		$result = $this->catalog->search(new GameQuery(text: 'kreis'));

		$this->assertSame('Testspiel im Kreis – Variante', $result['items'][0]['title']);
	}

	public function testStaleCatalogIsUsedWhenTheSourceFails(): void {
		$this->catalog->sync();
		$this->time->now += GameCatalogService::STALE_AFTER + 1;
		$this->source->fail = true;

		$this->assertSame(4, $this->catalog->search(new GameQuery())['total']);
	}

	public function testEmptyCatalogAndFailingSourceKeepsImporting(): void {
		$this->source->fail = true;

		$result = $this->catalog->search(new GameQuery());
		$this->assertSame(['importing', 0], [$result['state'], $result['total']]);
	}

	/** Regression Review Allgemein 0.6.1: Der Erstimport blockiert die Suche nicht. */
	public function testFirstImportRunsInTheBackground(): void {
		$this->importQueue->immediate = false;

		$this->assertSame('importing', $this->catalog->search(new GameQuery())['state']);
		$this->assertSame(['types' => [], 'environments' => []], $this->catalog->facets());
		$this->assertSame(0, $this->source->requests);
		$this->assertGreaterThanOrEqual(1, $this->importQueue->requests);

		$this->catalog->sync();
		$this->assertSame(['ready', 4], array_values(array_intersect_key($this->catalog->search(new GameQuery()), ['state' => 0, 'total' => 0])));
	}

	public function testStaleCatalogStaysUsableWhileRefreshing(): void {
		$this->catalog->sync();
		$this->time->now += GameCatalogService::STALE_AFTER + 1;
		$this->importQueue->immediate = false;
		$before = $this->importQueue->requests;

		$this->assertSame(4, $this->catalog->search(new GameQuery())['total']);
		$this->assertSame($before + 1, $this->importQueue->requests);
	}

	public function testRemovedGamesAreDeletedButASuspiciousDropIsIgnored(): void {
		$this->catalog->sync();
		array_splice($this->source->posts, 0, 1);
		$this->assertSame(1, $this->catalog->sync()['removed']);

		$this->source->posts = [];
		$this->assertSame(0, $this->catalog->sync()['removed']);
		$this->assertSame(3, $this->catalog->search(new GameQuery())['total']);
	}

	/** Regression Review Allgemein 0.6.1: Ein eingeplantes Spiel schützt den Programmpunkt. */
	public function testAttachedGameProtectsItemFromRerun(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$item = $this->first($this->planning->program($id, 'anna'), 'Kennenlernen und Lagerregeln');
		$game = $this->catalog->search(new GameQuery())['items'][0];
		$this->planning->attachGame((int)$item->getId(), 'anna', $game['id']);

		$moved = Fixtures::summerCamp(['start' => '2027-08-07', 'end' => '2027-08-18']);
		$preview = $this->wizard->rerunPreview($id, 'anna', $moved);
		$this->wizard->rerunApply($id, 'anna', $moved, $preview['token']);

		$ids = array_map(static fn ($i): int => (int)$i->getId(), $this->planning->program($id, 'anna'));
		$this->assertContains((int)$item->getId(), $ids);
		$this->assertSame((int)$item->getId(), $this->planning->programGames($id, 'anna')[0]->getProgramItemId());
	}

	/** Auch Altdaten ohne Bearbeitungsmarke (0.6.1) sind durch das Spiel geschützt. */
	public function testItemsWithGamesFromOlderVersionsAreProtected(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$item = $this->first($this->planning->program($id, 'anna'), 'Kennenlernen und Lagerregeln');
		$game = $this->catalog->search(new GameQuery())['items'][0];
		$this->planning->attachGame((int)$item->getId(), 'anna', $game['id']);
		$stored = $this->programItems->find((int)$item->getId());
		$stored->setUserModifiedAt(null);
		$this->programItems->update($stored);

		$moved = Fixtures::summerCamp(['start' => '2027-08-07', 'end' => '2027-08-18']);
		$preview = $this->wizard->rerunPreview($id, 'anna', $moved);
		$this->wizard->rerunApply($id, 'anna', $moved, $preview['token']);

		$this->assertCount(1, $this->planning->programGames($id, 'anna'));
		$this->assertContains((int)$item->getId(), array_map(static fn ($i): int => (int)$i->getId(), $this->planning->program($id, 'anna')));
	}

	public function testGameCanBeAttachedWithLicenseAndRemoved(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$item = $this->first($this->planning->program($id, 'anna'), 'Kennenlernen und Lagerregeln');
		$game = $this->catalog->search(new GameQuery(text: 'fantasie'))['items'][0];

		$link = $this->planning->attachGame((int)$item->getId(), 'anna', $game['id']);
		$data = $link->jsonSerialize();
		$this->assertSame('Fantasieball', $data['title']);
		$this->assertSame('Dorothea Schümann, Pfadfinder-Spiele.de', $data['license']['attribution']);
		$this->assertSame(['Papier & Stifte', 'Stühle oder Teppichfliesen'], $data['materials']);

		try {
			$this->planning->attachGame((int)$item->getId(), 'anna', $game['id']);
			$this->fail('Doppelt eingeplant');
		} catch (\InvalidArgumentException) {
			$this->assertTrue(true);
		}

		$this->planning->detachGame((int)$link->getId(), 'anna');
		$this->assertSame([], $this->planning->programGames($id, 'anna'));
	}

	public function testOtherUsersCannotAttachGames(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$item = $this->planning->program($id, 'anna')[0];
		$game = $this->catalog->search(new GameQuery())['items'][0];

		$this->expectException(NotFoundException::class);
		$this->planning->attachGame((int)$item->getId(), 'ben', $game['id']);
	}

	public function testDeletingOwnItemRemovesItsGames(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$own = $this->planning->createProgramItem($id, 'anna', ['title' => 'Spieleblock', 'day' => '2027-07-26', 'startTime' => '20:00', 'durationMinutes' => 45]);
		$game = $this->catalog->search(new GameQuery())['items'][0];
		$this->planning->attachGame((int)$own->getId(), 'anna', $game['id']);

		$this->planning->deleteProgramItem((int)$own->getId(), 'anna');
		$this->assertSame([], $this->planning->programGames($id, 'anna'));
	}

	/** Beispieldaten aus Konzept 8.5 */
	private function conceptNeeds(array $severe = []): array {
		return ['combinations' => [
			['key' => 'none', 'count' => 27], ['key' => 'vegetarian', 'count' => 6], ['key' => 'vegan', 'count' => 3],
			['key' => 'vegetarian+gluten_free', 'count' => 2], ['key' => 'vegan+gluten_free', 'count' => 1],
			['key' => 'lactose_free', 'count' => 2], ['key' => 'unanswered', 'count' => 1],
		], 'severe' => $severe];
	}

	/** Beispiel aus Konzept 9.8 */
	private function conceptPlan(): array {
		return [
			'variants' => [
				['key' => 'std', 'label' => 'Standard, Käse separat', 'satisfies' => ['lactose_free']],
				['key' => 'veggie', 'label' => 'Vegetarisch', 'satisfies' => ['vegetarian']],
				['key' => 'vegan', 'label' => 'Vegan', 'satisfies' => ['vegan']],
				['key' => 'vegan_gf', 'label' => 'Vegan und glutenfrei', 'satisfies' => ['vegan', 'gluten_free']],
				['key' => 'open', 'label' => 'Ungeklärt', 'satisfies' => []],
			],
			'assignments' => [
				'none' => 'std', 'lactose_free' => 'std', 'vegetarian' => 'veggie', 'vegan' => 'vegan',
				'gluten_free+vegetarian' => 'vegan_gf', 'gluten_free+vegan' => 'vegan_gf', 'unanswered' => 'open',
			],
		];
	}

	public function testDietaryNeedsAndPortionsInOverview(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$overview = $this->catering->saveNeeds($id, 'anna', $this->conceptNeeds());

		$this->assertSame(42, $overview['needs']['total']);
		$this->assertSame(42, $overview['participants']);
		$this->assertSame(39.85, $overview['standardPortions']);
		$this->assertSame([], $overview['issues']);
		$this->assertSame('none', $overview['meals'][0]['status']);
	}

	public function testMismatchWithParticipantsIsShown(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$overview = $this->catering->saveNeeds($id, 'anna', ['combinations' => [['key' => 'none', 'count' => 40]]]);

		$this->assertSame(PlanIssue::TOTAL_MISMATCH, $overview['issues'][0]['code']);
	}

	public function testInvalidNeedsAreRejected(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();

		$this->expectException(InvalidCateringInput::class);
		$this->catering->saveNeeds($id, 'anna', ['combinations' => [['key' => 'vegan', 'count' => -2]]]);
	}

	public function testSevereAllergyCreatesOneMandatoryTask(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$needs = $this->conceptNeeds(['peanuts' => 1]);
		$needs['combinations'][0]['count'] = 26;
		$needs['combinations'][] = ['key' => 'peanut_free', 'count' => 1];

		$overview = $this->catering->saveNeeds($id, 'anna', $needs);
		$this->catering->saveNeeds($id, 'anna', $needs);

		$this->assertSame([], $overview['issues']);
		$mandatory = array_values(array_filter($this->planning->tasks($id, 'anna'), static fn ($t): bool => (bool)$t->getMandatory()));
		$this->assertCount(1, $mandatory);
		$this->assertSame('Allergieverfahren einrichten', $mandatory[0]->getTitle());
		$this->assertSame('2027-07-10', $mandatory[0]->getDueDate());

		try {
			$this->planning->deleteTask((int)$mandatory[0]->getId(), 'anna');
			$this->fail('Pflichtaufgabe gelöscht');
		} catch (\InvalidArgumentException) {
			$this->assertTrue(true);
		}

		// Ohne schwere Allergie entfällt die noch unbearbeitete Aufgabe wieder.
		$this->catering->saveNeeds($id, 'anna', $this->conceptNeeds());
		$this->assertSame([], array_filter($this->planning->tasks($id, 'anna'), static fn ($t): bool => (bool)$t->getMandatory()));
	}

	public function testSevereAllergyWithoutCombinationIsFlagged(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$overview = $this->catering->saveNeeds($id, 'anna', $this->conceptNeeds(['peanuts' => 1]));

		$this->assertSame(PlanIssue::SEVERE_NOT_COVERED, $overview['issues'][0]['code']);
	}

	public function testMealPlanMatchesConceptExampleAndProtectsTheMeal(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$this->catering->saveNeeds($id, 'anna', $this->conceptNeeds());
		$lunch = array_values(array_filter($this->planning->meals($id, 'anna'), static fn ($m): bool => $m->getDay() === '2027-07-26' && $m->getMealType() === 'lunch'))[0];

		$result = $this->catering->saveMealPlan((int)$lunch->getId(), 'anna', $this->conceptPlan());
		$this->assertSame(['std' => 29, 'veggie' => 6, 'vegan' => 3, 'vegan_gf' => 3, 'open' => 1], $result['evaluation']['headcounts']);
		$this->assertFalse($result['evaluation']['hasErrors']);

		// Eine Änderung der Verpflegung im Assistenten lässt die geplante Mahlzeit unverändert.
		$input = Fixtures::summerCamp(['catering' => ['selfCatering' => false]]);
		$preview = $this->wizard->rerunPreview($id, 'anna', $input);
		$this->assertGreaterThanOrEqual(1, $preview['meals']['summary']['conflict']);
		$this->wizard->rerunApply($id, 'anna', $input, $preview['token']);
		$this->assertNotSame('none', $this->mealStatus($id, (int)$lunch->getId()));
	}

	public function testMealPlanCanBeCopiedToSameMealType(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
		$this->catering->saveNeeds($id, 'anna', $this->conceptNeeds());
		$lunches = array_values(array_filter($this->planning->meals($id, 'anna'), static fn ($m): bool => $m->getMealType() === 'lunch'));
		$this->catering->saveMealPlan((int)$lunches[0]->getId(), 'anna', $this->conceptPlan());
		$this->catering->saveMealPlan((int)$lunches[1]->getId(), 'anna', ['variants' => [['key' => 'x', 'label' => 'Eigener Plan']]]);

		$changed = $this->catering->copyMealPlan((int)$lunches[0]->getId(), 'anna', 'same_type');

		$this->assertSame(count($lunches) - 2, $changed);
		$this->assertSame('Eigener Plan', $this->catering->mealPlan((int)$lunches[1]->getId(), 'anna')['plan']['variants'][0]['label']);
		$this->assertSame('none', $this->mealStatus($id, $this->breakfastId($id)));
	}

	public function testOtherUsersCannotSeeDietaryData(): void {
		$id = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();

		$this->expectException(NotFoundException::class);
		$this->catering->overview($id, 'ben');
	}

	private function mealStatus(int $campId, int $mealId): string {
		foreach ($this->catering->overview($campId, 'anna')['meals'] as $meal) {
			if ($meal['id'] === $mealId) {
				return $meal['status'];
			}
		}
		return 'missing';
	}

	private function breakfastId(int $campId): int {
		foreach ($this->planning->meals($campId, 'anna') as $meal) {
			if ($meal->getMealType() === 'breakfast') {
				return (int)$meal->getId();
			}
		}
		return 0;
	}

	/** @param list<ProgramItem> $items */
	private function first(array $items, string $title): ProgramItem {
		foreach ($items as $item) {
			if ($item->getTitle() === $title) {
				return $item;
			}
		}
		$this->fail('Programmpunkt nicht gefunden: ' . $title);
	}
}
