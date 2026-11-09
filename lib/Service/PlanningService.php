<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Db\Camp;
use OCA\Lagerplaner\Db\Meal;
use OCA\Lagerplaner\Db\MealMapper;
use OCA\Lagerplaner\Db\MaterialUseMapper;
use OCA\Lagerplaner\Db\ProgramGame;
use OCA\Lagerplaner\Db\ProgramGameMapper;
use OCA\Lagerplaner\Db\ProgramItem;
use OCA\Lagerplaner\Db\ProgramItemMapper;
use OCA\Lagerplaner\Db\Task;
use OCA\Lagerplaner\Db\TaskMapper;
use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Planning\ScheduleConflicts;
use OCA\Lagerplaner\Domain\Planning\ScheduleEntry;
use OCA\Lagerplaner\Domain\Planning\ScheduleShifter;
use OCA\Lagerplaner\Domain\Wizard\Timeline;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Anlegen, Bearbeiten und Löschen einzelner Programmpunkte und Aufgaben.
 *
 * Jede Änderung erhöht die Versionsnummer des Lagers, damit eine offene
 * Vorschau des Assistenten nicht mehr übernommen werden kann.
 */
class PlanningService {
	public const MAX_TITLE = 255;
	public const MAX_TEXT = 2000;

	public function __construct(
		private CampService $camps,
		private ProgramItemMapper $programItems,
		private TaskMapper $tasks,
		private MealMapper $meals,
		private ITimeFactory $time,
		private ProgramGameMapper $programGames,
		private GameCatalogService $catalog,
		private TaskNotifications $notifications,
		private MaterialUseMapper $materialUses,
	) {
	}

	/**
	 * Programmpunkt oder Mahlzeit verschieben; was sich danach überschneidet, rutscht nach,
	 * bis eine Lücke die Verschiebung auffängt (ScheduleShifter). Programmpunkte schiebt nur,
	 * wer das Programm bearbeiten darf; die Küche verschiebt Mahlzeiten, ohne Programmpunkte
	 * mitzuschieben. Alle geänderten Einträge gelten danach als bearbeitet.
	 *
	 * @return array{changes: list<array{kind: string, id: int, fromDay: string, from: string, day: string, to: string, title: string}>}
	 */
	public function moveEntry(int $campId, string $uid, string $kind, int $id, string $day, string $time): array {
		if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
			throw new \InvalidArgumentException('Ungültige Uhrzeit.');
		}
		$camp = $this->camps->require($campId, $uid, Permission::View);
		$canProgram = $this->allowed($campId, $uid, Permission::EditProgram);
		if (!$canProgram && ($kind !== 'meal' || !$this->allowed($campId, $uid, Permission::EditCatering))) {
			throw new ForbiddenException('Du darfst das Programm dieses Lagers nicht ändern.');
		}
		if ($day < (string)$camp->getStartDate() || $day > (string)$camp->getEndDate()) {
			throw new \InvalidArgumentException('Der Tag liegt außerhalb des Lagers.');
		}

		$entries = $this->dayEntries($campId, $day, $canProgram);
		$moved = $this->entryFor($campId, $kind, $id);
		$result = (new ScheduleShifter())->move($entries, $moved, Timeline::minutes($time));

		$now = $this->time->getTime();
		$changes = [];
		foreach ($result as $key => $start) {
			[$entryKind, $entryId] = explode(':', $key);
			$target = (int)$entryId === $id && $entryKind === $kind ? $day : null;
			$changes[] = $this->setTime($campId, $entryKind, (int)$entryId, $target, Timeline::time($start), $now);
		}
		$this->camps->touch($campId, $now);
		return ['changes' => $changes];
	}

	/**
	 * Eine Verschiebung zurücknehmen: Tag und Uhrzeit wie vorher.
	 *
	 * @param list<array{kind: string, id: int, fromDay: string, from: string}> $changes
	 */
	public function restoreTimes(int $campId, string $uid, array $changes): void {
		$this->camps->require($campId, $uid, Permission::View);
		$canProgram = $this->allowed($campId, $uid, Permission::EditProgram);
		$now = $this->time->getTime();
		foreach ($changes as $change) {
			$kind = (string)($change['kind'] ?? '');
			if (!$canProgram && ($kind !== 'meal' || !$this->allowed($campId, $uid, Permission::EditCatering))) {
				throw new ForbiddenException('Du darfst das Programm dieses Lagers nicht ändern.');
			}
			$from = (string)($change['from'] ?? '');
			if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) !== 1) {
				throw new \InvalidArgumentException('Ungültige Uhrzeit.');
			}
			$this->setTime($campId, $kind, (int)($change['id'] ?? 0), (string)($change['fromDay'] ?? '') ?: null, $from, $now);
		}
		$this->camps->touch($campId, $now);
	}

	/**
	 * Essenszeiten: alle Mahlzeiten einer Art auf eine neue Uhrzeit, mit derselben
	 * Zeitanpassung wie beim Verschieben.
	 *
	 * @return array{changes: list<array<string, mixed>>}
	 */
	public function setMealTimes(int $campId, string $uid, string $type, string $time): array {
		if (!isset(Timeline::MEAL_MINUTES[$type])) {
			throw new \InvalidArgumentException('Unbekannte Mahlzeit.');
		}
		$changes = [];
		foreach ($this->meals->findByCamp($campId) as $meal) {
			if ($meal->getMealType() !== $type || $meal->getCancelled() || $meal->getMealTime() === $time) {
				continue;
			}
			$moved = $this->moveEntry($campId, $uid, 'meal', (int)$meal->getId(), (string)$meal->getDay(), $time);
			array_push($changes, ...$moved['changes']);
		}
		return ['changes' => $changes];
	}

	private function allowed(int $campId, string $uid, Permission $permission): bool {
		try {
			$this->camps->require($campId, $uid, $permission);
			return true;
		} catch (ForbiddenException|NotFoundException) {
			return false;
		}
	}

	/** @return list<ScheduleEntry> Einträge eines Tages; ohne Programmrecht nur Mahlzeiten */
	private function dayEntries(int $campId, string $day, bool $withProgram): array {
		$entries = [];
		if ($withProgram) {
			foreach ($this->programItems->findByCamp($campId) as $item) {
				if ($item->getDay() === $day) {
					$entries[] = new ScheduleEntry('program', (int)$item->getId(), $day, Timeline::minutes($item->getStartTime()), (int)$item->getDurationMinutes(),
						$item->groupList(), (string)$item->getTitle(), $item->getAlternativeTo() === null ? null : (int)$item->getAlternativeTo());
				}
			}
		}
		foreach ($this->meals->findByCamp($campId) as $meal) {
			if ($meal->getDay() === $day && !$meal->getCancelled()) {
				$entries[] = new ScheduleEntry('meal', (int)$meal->getId(), $day, Timeline::minutes($meal->getMealTime()),
					Timeline::MEAL_MINUTES[$meal->getMealType()] ?? 30, [], (string)$meal->getMealType(), null, (bool)$meal->getPortable());
			}
		}
		return $entries;
	}

	private function entryFor(int $campId, string $kind, int $id): ScheduleEntry {
		if ($kind === 'program') {
			$item = $this->ownItem($campId, $id);
			return new ScheduleEntry('program', $id, (string)$item->getDay(), Timeline::minutes($item->getStartTime()), (int)$item->getDurationMinutes(),
				$item->groupList(), (string)$item->getTitle());
		}
		if ($kind === 'meal') {
			$meal = $this->ownMeal($campId, $id);
			return new ScheduleEntry('meal', $id, (string)$meal->getDay(), Timeline::minutes($meal->getMealTime()),
				Timeline::MEAL_MINUTES[$meal->getMealType()] ?? 30, [], (string)$meal->getMealType());
		}
		throw new \InvalidArgumentException('Unbekannte Art.');
	}

	/** @return array{kind: string, id: int, fromDay: string, from: string, day: string, to: string, title: string} */
	private function setTime(int $campId, string $kind, int $id, ?string $day, string $time, int $now): array {
		if ($kind === 'program') {
			$entity = $this->ownItem($campId, $id);
			$before = ['fromDay' => (string)$entity->getDay(), 'from' => (string)$entity->getStartTime()];
			$entity->setStartTime($time);
			$title = (string)$entity->getTitle();
		} elseif ($kind === 'meal') {
			$entity = $this->ownMeal($campId, $id);
			$before = ['fromDay' => (string)$entity->getDay(), 'from' => (string)$entity->getMealTime()];
			$entity->setMealTime($time);
			$title = (string)$entity->getMealType();
		} else {
			throw new \InvalidArgumentException('Unbekannte Art.');
		}
		if ($day !== null) {
			$entity->setDay($day);
		}
		$entity->markUserModified($now);
		$kind === 'program' ? $this->programItems->update($entity) : $this->meals->update($entity);
		return ['kind' => $kind, 'id' => $id] + $before + ['day' => (string)$entity->getDay(), 'to' => $time, 'title' => $title];
	}

	private function ownItem(int $campId, int $id): ProgramItem {
		try {
			$item = $this->programItems->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Programmpunkt nicht gefunden.');
		}
		if ((int)$item->getCampId() !== $campId) {
			throw new NotFoundException('Programmpunkt nicht gefunden.');
		}
		return $item;
	}

	private function ownMeal(int $campId, int $id): Meal {
		try {
			$meal = $this->meals->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Mahlzeit nicht gefunden.');
		}
		if ((int)$meal->getCampId() !== $campId) {
			throw new NotFoundException('Mahlzeit nicht gefunden.');
		}
		return $meal;
	}

	/**
	 * Zeitliche Überschneidungen im Programm, als Hinweise je Programmpunkt.
	 *
	 * @return array<int, list<array<string, mixed>>>
	 */
	public function programConflicts(int $campId, string $uid): array {
		$this->camps->getForUser($campId, $uid);
		$entries = [];
		foreach ($this->programItems->findByCamp($campId) as $item) {
			$entries[] = new ScheduleEntry('program', (int)$item->getId(), $item->getDay(), Timeline::minutes($item->getStartTime()),
				(int)$item->getDurationMinutes(), $item->groupList(), $item->getTitle(), $item->getAlternativeTo() === null ? null : (int)$item->getAlternativeTo());
		}
		foreach ($this->meals->findByCamp($campId) as $meal) {
			if ($meal->getCancelled()) {
				continue;
			}
			$entries[] = new ScheduleEntry('meal', (int)$meal->getId(), $meal->getDay(), Timeline::minutes($meal->getMealTime()),
				Timeline::MEAL_MINUTES[$meal->getMealType()] ?? 30, [], $meal->getMealType(), null, (bool)$meal->getPortable());
		}
		return (new ScheduleConflicts())->forProgramItems($entries);
	}

	/** @return list<ProgramGame> */
	public function programGames(int $campId, string $uid): array {
		$this->camps->getForUser($campId, $uid);
		return $this->programGames->findByCamp($campId);
	}

	/**
	 * Plant ein Spiel aus dem Katalog an einem Programmpunkt ein. Gespeichert wird eine
	 * Kopie der Anzeigedaten mit Quelle und Lizenz, damit Namensnennung und Nutzung
	 * auch ohne Verbindung und nach Änderungen an der Quelle möglich bleiben.
	 */
	public function attachGame(int $programItemId, string $uid, int $catalogGameId): ProgramGame {
		$item = $this->programItem($programItemId, $uid);
		$game = $this->catalog->get($catalogGameId);
		foreach ($this->programGames->findByProgramItem($programItemId) as $existing) {
			if ($existing->getSource() === $game->getSource() && (int)$existing->getSourceId() === (int)$game->getSourceId()) {
				throw new \InvalidArgumentException('Dieses Spiel ist hier bereits eingeplant.');
			}
		}
		$license = $this->catalog->license();
		$now = $this->time->getTime();

		$link = new ProgramGame();
		$link->setCampId((int)$item->getCampId());
		$link->setProgramItemId($programItemId);
		$link->setSource($game->getSource());
		$link->setSourceId((int)$game->getSourceId());
		$link->setTitle($game->getTitle());
		$link->setSummary($game->getSummary());
		$link->setLink($game->getLink());
		$link->setDurationMinutes($game->getDurationMinutes());
		$link->setMaterialsJson($game->getMaterialsJson());
		$link->setLicenseName($license->name);
		$link->setLicenseUrl($license->url);
		$link->setAttribution($license->attribution);
		$link->setSourceModified($game->getSourceModified());
		$link->setCreatedAt($now);
		$link = $this->programGames->insert($link);
		// Ein eingeplantes Spiel ist eine Bearbeitung; der Assistent ändert den Punkt danach nicht mehr.
		if ($item->getUserModifiedAt() === null) {
			$item->markUserModified($now);
			$this->programItems->update($item);
		}
		$this->camps->touch((int)$item->getCampId(), $now);
		return $link;
	}

	public function detachGame(int $id, string $uid): void {
		try {
			$link = $this->programGames->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Eingeplantes Spiel nicht gefunden.');
		}
		$this->camps->require((int)$link->getCampId(), $uid, Permission::EditProgram);
		$this->programGames->delete($link);
		$this->camps->touch((int)$link->getCampId(), $this->time->getTime());
	}

	/** @return list<ProgramItem> */
	public function program(int $campId, string $uid): array {
		$this->camps->getForUser($campId, $uid);
		return $this->programItems->findByCamp($campId);
	}

	/** @return list<ProgramItem> bewusst gelöschte Vorschläge, die wiederhergestellt werden können */
	public function deletedProgram(int $campId, string $uid): array {
		$this->camps->getForUser($campId, $uid);
		return $this->programItems->findDeletedByCamp($campId);
	}

	/** @return list<Task> */
	public function tasks(int $campId, string $uid): array {
		$this->camps->getForUser($campId, $uid);
		return $this->tasks->findByCamp($campId);
	}

	/** @return list<Meal> */
	public function meals(int $campId, string $uid): array {
		$this->camps->getForUser($campId, $uid);
		return $this->meals->findByCamp($campId);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException bei ungültigen Werten
	 */
	public function createProgramItem(int $campId, string $uid, array $fields): ProgramItem {
		$camp = $this->camps->require($campId, $uid, Permission::EditProgram);
		$fields += ['durationMinutes' => 60];
		foreach (['title', 'day', 'startTime'] as $required) {
			if (!array_key_exists($required, $fields)) {
				throw new \InvalidArgumentException('Bitte gib Titel, Tag und Beginn an.');
			}
		}
		$now = $this->time->getTime();
		$item = new ProgramItem();
		$item->setCampId($campId);
		$this->applyProgramFields($item, $camp, $fields);
		$item->markManual($now);
		$item = $this->programItems->insert($item);
		$this->camps->touch($campId, $now);
		return $item;
	}

	public function confirmProgramItem(int $id, string $uid): ProgramItem {
		$item = $this->programItem($id, $uid);
		$now = $this->time->getTime();
		$item->markConfirmed($now);
		$item = $this->programItems->update($item);
		$this->camps->touch((int)$item->getCampId(), $now);
		return $item;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException bei ungültigen Werten
	 */
	public function updateProgramItem(int $id, string $uid, array $fields): ProgramItem {
		$item = $this->programItem($id, $uid);
		$camp = $this->camps->getForUser((int)$item->getCampId(), $uid);
		$this->applyProgramFields($item, $camp, $fields);
		$now = $this->time->getTime();
		$item->markUserModified($now);
		$item = $this->programItems->update($item);
		$this->camps->touch((int)$item->getCampId(), $now);
		return $item;
	}

	/**
	 * Vorschläge des Assistenten werden als Ausschluss markiert, damit ein erneuter Lauf
	 * sie nicht wieder anlegt. Eigene Programmpunkte werden endgültig gelöscht.
	 */
	public function deleteProgramItem(int $id, string $uid): void {
		$item = $this->programItem($id, $uid);
		$now = $this->time->getTime();
		if ($item->getRuleKey() === null) {
			$this->detachProgramItem($item);
			$this->programItems->delete($item);
		} else {
			// Eingeplante Spiele bleiben am gelöschten Vorschlag, falls er wiederhergestellt wird.
			$item->markDeleted($now);
			$this->programItems->update($item);
		}
		$this->camps->touch((int)$item->getCampId(), $now);
	}

	/**
	 * Entfernt alles, was an einem endgültig gelöschten Programmpunkt hängt: Spiele und
	 * Materialeinsätze werden gelöscht, Aufgaben verlieren nur den Verweis.
	 */
	public function detachProgramItem(ProgramItem $item): void {
		foreach ($this->programGames->findByProgramItem((int)$item->getId()) as $link) {
			$this->programGames->delete($link);
		}
		foreach ($this->materialUses->findByCamp((int)$item->getCampId()) as $use) {
			if ($use->getProgramItemId() === $item->getId()) {
				$this->materialUses->delete($use);
			}
		}
		foreach ($this->tasks->findByCamp((int)$item->getCampId()) as $task) {
			if ($task->getProgramItemId() === $item->getId()) {
				$task->setProgramItemId(null);
				$this->tasks->update($task);
			}
		}
	}

	/**
	 * Holt einen bewusst gelöschten Vorschlag zurück. Erlaubt mit dem Recht, das Programm zu
	 * bearbeiten (Lagerleitung und Programmteam), wie das Löschen selbst.
	 */
	public function restoreProgramItem(int $id, string $uid): ProgramItem {
		$item = $this->programItem($id, $uid);
		$now = $this->time->getTime();
		$item->markRestored($now);
		$item = $this->programItems->update($item);
		$this->camps->touch((int)$item->getCampId(), $now);
		return $item;
	}

	/** @param array<string, mixed> $fields */
	public function createTask(int $campId, string $uid, array $fields): Task {
		$this->camps->require($campId, $uid, Permission::EditTasks);
		if (!array_key_exists('title', $fields)) {
			throw new \InvalidArgumentException('Bitte gib einen Titel an.');
		}
		$now = $this->time->getTime();
		$camp = $this->camps->getForUser($campId, $uid);
		$task = new Task();
		$task->setCampId($campId);
		$this->applyTaskFields($task, $fields, $uid);
		$task->markManual($now);
		$task = $this->tasks->insert($task);
		$this->notifications->assigned($task, $camp, $uid, array_values(array_diff($task->assigneeList(), [$uid])), $now);
		$this->camps->touch($campId, $now);
		return $task;
	}

	/** @param array<string, mixed> $fields */
	public function updateTask(int $id, string $uid, array $fields): Task {
		// Abhaken dürfen alle Mitarbeitenden, Titel und Frist ändern nur die Lagerleitung.
		$onlyStatus = array_diff(array_keys($fields), ['status']) === [];
		$task = $this->task($id, $uid, $onlyStatus ? Permission::ToggleTasks : Permission::EditTasks);
		$now = $this->time->getTime();
		$before = $task->assigneeList();

		if (array_key_exists('status', $fields)) {
			$status = $fields['status'];
			if (!in_array($status, [Task::STATUS_OPEN, Task::STATUS_DONE], true)) {
				throw new \InvalidArgumentException('Unbekannter Aufgabenstatus.');
			}
			if ($status !== $task->getStatus()) {
				// Wer abgehakt hat, bleibt nachvollziehbar.
				$task->setCompletedBy($status === Task::STATUS_DONE ? $uid : null);
				$task->setCompletedAt($status === Task::STATUS_DONE ? $now : null);
			}
			$task->setStatus($status);
			// Eine bearbeitete Aufgabe soll ein erneuter Assistentenlauf nicht mehr entfernen.
			$task->markConfirmed($now);
		}
		if (array_intersect_key($fields, ['title' => true, 'dueDate' => true, 'description' => true, 'assignees' => true, 'priority' => true, 'programItemId' => true]) !== []) {
			$this->applyTaskFields($task, $fields, $uid);
			$task->markUserModified($now);
		}
		$task = $this->tasks->update($task);
		$camp = $this->camps->getForUser((int)$task->getCampId(), $uid);
		if ($task->getStatus() === Task::STATUS_DONE) {
			$this->notifications->clear($task);
		} else {
			$this->notifications->assigned($task, $camp, $uid, array_values(array_diff($task->assigneeList(), $before, [$uid])), $now);
		}
		$this->camps->touch((int)$task->getCampId(), $now);
		return $task;
	}

	/**
	 * Eine Aufgabe selbst übernehmen. Dürfen alle, die Aufgaben abhaken dürfen; so können
	 * Mitarbeitende Verantwortung übernehmen, ohne dass die Lagerleitung zuweisen muss.
	 */
	public function claimTask(int $id, string $uid): Task {
		$task = $this->task($id, $uid, Permission::ToggleTasks);
		$now = $this->time->getTime();
		$task->setAssigneeList([...$task->assigneeList(), $uid]);
		$task->markUserModified($now);
		$task = $this->tasks->update($task);
		$this->camps->touch((int)$task->getCampId(), $now);
		return $task;
	}

	/** Pflichtaufgaben können erledigt, aber nicht gelöscht werden (Konzept 12.2). */
	public function deleteTask(int $id, string $uid): void {
		$task = $this->task($id, $uid, Permission::EditTasks);
		if ($task->getMandatory()) {
			throw new \InvalidArgumentException('Pflichtaufgaben können erledigt, aber nicht gelöscht werden.');
		}
		$now = $this->time->getTime();
		$this->notifications->clear($task);
		if ($task->getRuleKey() === null) {
			$this->tasks->delete($task);
		} else {
			$task->markDeleted($now);
			$this->tasks->update($task);
		}
		$this->camps->touch((int)$task->getCampId(), $now);
	}

	/** @param array<string, mixed> $fields */
	private function applyProgramFields(ProgramItem $item, Camp $camp, array $fields): void {
		if (array_key_exists('title', $fields)) {
			$item->setTitle($this->title($fields['title']));
		}
		if (array_key_exists('description', $fields)) {
			$item->setDescription($this->optionalText($fields['description'], 'Die Beschreibung'));
		}
		if (array_key_exists('day', $fields)) {
			$day = $fields['day'];
			if (!is_string($day) || !self::isDate($day) || $day < $camp->getStartDate() || $day > $camp->getEndDate()) {
				throw new \InvalidArgumentException('Der Tag muss innerhalb des Lagerzeitraums liegen.');
			}
			$item->setDay($day);
		}
		if (array_key_exists('startTime', $fields)) {
			$time = $fields['startTime'];
			if (!is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
				throw new \InvalidArgumentException('Bitte gib die Uhrzeit als HH:MM an.');
			}
			$item->setStartTime($time);
		}
		if (array_key_exists('durationMinutes', $fields)) {
			$minutes = $fields['durationMinutes'];
			if (!is_int($minutes) || $minutes < 5 || $minutes > 1440) {
				throw new \InvalidArgumentException('Die Dauer muss zwischen 5 Minuten und 24 Stunden liegen.');
			}
			$item->setDurationMinutes($minutes);
		}
		if (array_key_exists('groups', $fields)) {
			$groups = $fields['groups'];
			$known = array_map(static fn ($g): string => $g->getGroupKey(), $this->camps->groups((int)$camp->getId()));
			if (!is_array($groups) || !array_is_list($groups) || array_diff($groups, $known) !== []) {
				throw new \InvalidArgumentException('Unbekannte Gruppe ausgewählt.');
			}
			$item->setGroupsJson(json_encode(array_values(array_unique($groups)), JSON_THROW_ON_ERROR));
		}
		if (array_key_exists('weatherAlternative', $fields)) {
			$item->setWeatherAlternative($this->optionalText($fields['weatherAlternative'], 'Die Schlechtwetteralternative'));
		}
	}

	/** @param array<string, mixed> $fields */
	private function applyTaskFields(Task $task, array $fields, string $uid): void {
		if (array_key_exists('assignees', $fields)) {
			$assignees = $fields['assignees'];
			$allowed = array_column($this->camps->people((int)$task->getCampId(), $uid), 'uid');
			if (!is_array($assignees) || !array_is_list($assignees) || count($assignees) > 10 || array_diff($assignees, $allowed) !== []) {
				throw new \InvalidArgumentException('Verantwortlich sein können nur Personen mit Zugriff auf das Lager, höchstens zehn.');
			}
			$task->setAssigneeList($assignees);
		}
		if (array_key_exists('priority', $fields)) {
			if (!in_array($fields['priority'], ['low', 'normal', 'high'], true)) {
				throw new \InvalidArgumentException('Unbekannte Priorität.');
			}
			$task->setPriority($fields['priority']);
		}
		if (array_key_exists('programItemId', $fields)) {
			$itemId = $fields['programItemId'];
			if ($itemId !== null && $itemId !== '') {
				$itemId = (int)$itemId;
				$known = array_map(static fn (ProgramItem $i): int => (int)$i->getId(), $this->programItems->findByCamp((int)$task->getCampId()));
				if (!in_array($itemId, $known, true)) {
					throw new \InvalidArgumentException('Dieser Programmpunkt gehört nicht zum Lager.');
				}
			}
			$task->setProgramItemId($itemId === '' ? null : $itemId);
		}
		if (array_key_exists('title', $fields)) {
			$task->setTitle($this->title($fields['title']));
		}
		if (array_key_exists('description', $fields)) {
			$task->setDescription($this->optionalText($fields['description'], 'Die Beschreibung'));
		}
		if (array_key_exists('dueDate', $fields)) {
			$due = $fields['dueDate'];
			if ($due === '') {
				$due = null;
			}
			if ($due !== null && (!is_string($due) || !self::isDate($due))) {
				throw new \InvalidArgumentException('Bitte gib die Frist als gültiges Datum an.');
			}
			if ($due !== $task->getDueDate()) {
				// Neue Frist: erneut erinnern.
				$task->setRemindedAt(null);
			}
			$task->setDueDate($due);
		}
	}

	private function title(mixed $value): string {
		$title = is_string($value) ? trim($value) : '';
		if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
			throw new \InvalidArgumentException('Der Titel muss zwischen 1 und ' . self::MAX_TITLE . ' Zeichen lang sein.');
		}
		return $title;
	}

	private function optionalText(mixed $value, string $subject): ?string {
		if ($value === null) {
			return null;
		}
		if (!is_string($value)) {
			throw new \InvalidArgumentException($subject . ' muss ein Text sein.');
		}
		$value = trim($value);
		if (mb_strlen($value) > self::MAX_TEXT) {
			throw new \InvalidArgumentException($subject . ' darf höchstens ' . self::MAX_TEXT . ' Zeichen lang sein.');
		}
		return $value === '' ? null : $value;
	}

	/** Gültiger Kalendertag im Format JJJJ-MM-TT; 2027-02-30 wird abgelehnt. */
	public static function isDate(string $value): bool {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date !== false && $date->format('Y-m-d') === $value;
	}

	private function programItem(int $id, string $uid, Permission $permission = Permission::EditProgram): ProgramItem {
		try {
			$item = $this->programItems->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Programmpunkt nicht gefunden.');
		}
		$this->camps->require((int)$item->getCampId(), $uid, $permission);
		return $item;
	}

	private function task(int $id, string $uid, Permission $permission): Task {
		try {
			$task = $this->tasks->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Aufgabe nicht gefunden.');
		}
		$this->camps->require((int)$task->getCampId(), $uid, $permission);
		return $task;
	}
}
