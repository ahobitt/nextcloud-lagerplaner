<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Lagerplaner\Db\Camp;
use OCA\Lagerplaner\Db\CampGroup;
use OCA\Lagerplaner\Db\CampGroupMapper;
use OCA\Lagerplaner\Db\CampMapper;
use OCA\Lagerplaner\Db\GenerationRun;
use OCA\Lagerplaner\Db\GenerationRunMapper;
use OCA\Lagerplaner\Db\Meal;
use OCA\Lagerplaner\Db\MealMapper;
use OCA\Lagerplaner\Db\ProgramItem;
use OCA\Lagerplaner\Db\ProgramItemMapper;
use OCA\Lagerplaner\Db\Task;
use OCA\Lagerplaner\Db\TaskMapper;
use OCA\Lagerplaner\Db\MaterialUseMapper;
use OCA\Lagerplaner\Db\ProgramGameMapper;
use OCA\Lagerplaner\Db\ShoppingItemMapper;
use OCA\Lagerplaner\Db\ShoppingTrip;
use OCA\Lagerplaner\Db\ShoppingTripMapper;
use OCA\Lagerplaner\Domain\Provenance\Provenance;
use OCA\Lagerplaner\Domain\Wizard\InvalidWizardInput;
use OCA\Lagerplaner\Domain\Rerun\ExistingElement;
use OCA\Lagerplaner\Db\Transaction;
use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Rerun\ChangeSet;
use OCA\Lagerplaner\Domain\Rerun\ChangeType;
use OCA\Lagerplaner\Domain\Rerun\RerunPlanner;
use OCA\Lagerplaner\Domain\Wizard\Draft;
use OCA\Lagerplaner\Domain\Wizard\DraftGenerator;
use OCA\Lagerplaner\Domain\Wizard\ProposedElement;
use OCA\Lagerplaner\Domain\Wizard\WizardInput;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Lagerassistent: Vorschau, Anlegen eines Lagers und erneuter Lauf (Konzept 3.8, 3.10).
 */
class WizardService {
	public function __construct(
		private DraftGenerator $generator,
		private RerunPlanner $planner,
		private CampService $campService,
		private CampMapper $camps,
		private CampGroupMapper $groups,
		private GenerationRunMapper $runs,
		private ProgramItemMapper $programItems,
		private TaskMapper $tasks,
		private MealMapper $meals,
		private Transaction $transaction,
		private ITimeFactory $time,
		private ShoppingTripMapper $shoppingTrips,
		private ProgramGameMapper $programGames,
		private ShoppingItemMapper $shoppingItems,
		private MaterialUseMapper $materialUses,
		private TemplateService $templates,
		private CateringService $catering,
	) {
	}

	/** @return array<string, mixed> */
	public function defaults(): array {
		return [
			'groups' => array_map(static fn (array $g): array => $g + ['count' => 0, 'separateProgram' => false, 'isLeaders' => $g['isLeaders'] ?? false], WizardInput::DEFAULT_GROUPS),
			'mealTypes' => WizardInput::MEAL_TYPES,
			'maxDays' => WizardInput::MAX_DAYS,
			'templateVersion' => DraftGenerator::TEMPLATE_VERSION,
		];
	}

	/** @throws InvalidWizardInput */
	public function preview(array $data, string $uid = ''): Draft {
		$input = WizardInput::fromArray($data);
		$template = null;
		if ($input->dayTemplateId !== null) {
			try {
				$template = $this->templates->dayTemplateFor($input->dayTemplateId, $uid);
			} catch (\InvalidArgumentException $e) {
				throw new InvalidWizardInput(['program.dayTemplateId' => $e->getMessage()]);
			}
		}
		return $this->generator->generate($input, $this->today(), $template);
	}

	/** @throws InvalidWizardInput */
	public function create(string $uid, array $data): Camp {
		$draft = $this->preview($data, $uid);
		$now = $this->time->getTime();

		$camp = $this->transaction->run(function () use ($uid, $draft, $now): Camp {
			$camp = new Camp();
			$camp->setOwnerUid($uid);
			$camp->setCreatedAt($now);
			$this->applyInputToCamp($camp, $draft->input, $now);
			$camp = $this->camps->insert($camp);
			$campId = (int)$camp->getId();

			$this->syncGroups($campId, $draft->input);
			$run = $this->storeRun($campId, $uid, $draft, $now);
			$this->shoppingTrips->insert(ShoppingService::defaultTrip($campId, $camp->getStartDate(), $now));

			$this->insertAll($campId, $run, $draft->programItems, fn () => new ProgramItem(), $this->programItems, $now);
			$this->insertAll($campId, $run, $draft->tasks, fn () => new Task(), $this->tasks, $now);
			$this->insertAll($campId, $run, $draft->meals, fn () => new Meal(), $this->meals, $now);
			return $camp;
		});
		// Einfacher Speiseplan mit Gerichten, die fast immer gehen (auf Wunsch im Assistenten)
		if ($draft->input->proposeMenu && $draft->input->selfCatering) {
			$this->catering->proposeMealPlan((int)$camp->getId(), $uid);
		}
		return $camp;
	}

	/** @return array<string, mixed> Eingaben des letzten Laufs, zum Vorbelegen des Assistenten */
	public function lastInput(int $campId, string $uid): array {
		$this->campService->require($campId, $uid, Permission::RunWizard);
		$runs = $this->runs->findByCamp($campId);
		if ($runs === []) {
			return [];
		}
		$input = json_decode($runs[count($runs) - 1]->getInputJson(), true, 512, JSON_THROW_ON_ERROR);
		// Der Lagerort kann auch über die Merkliste festgelegt worden sein; maßgeblich ist das Lager.
		$camp = $this->campService->getForUser($campId, $uid);
		$input['location'] = [
			'known' => (bool)$camp->getLocationKnown(),
			'name' => (string)$camp->getLocationName(),
			'region' => (string)$camp->getRegion(),
		] + (is_array($input['location'] ?? null) ? $input['location'] : []);
		return $input;
	}

	/**
	 * Vorschau eines erneuten Laufs; nichts wird gespeichert.
	 *
	 * @return array<string, mixed>
	 * @throws InvalidWizardInput
	 */
	public function rerunPreview(int $campId, string $uid, array $data): array {
		$camp = $this->campService->require($campId, $uid, Permission::RunWizard);
		$plan = $this->plan($camp, $data, $uid);
		$effective = count(array_filter($plan['campChanges'], static fn (array $c): bool => !in_array($c['kind'], ['shopping_trip_check', 'shopping_trip_late', 'group_items'], true)));
		foreach ($plan['sets'] as $set) {
			$effective += $set->effectiveCount();
		}
		return [
			'camp' => ['changes' => $plan['campChanges']],
			'program' => $plan['sets']['program']->toArray(),
			'tasks' => $plan['sets']['tasks']->toArray(),
			'meals' => $plan['sets']['meals']->toArray(),
			'hasEffect' => $effective > 0,
			'token' => $plan['token'],
		];
	}

	/**
	 * Übernimmt einen erneuten Lauf, sofern die Vorschau noch aktuell ist.
	 *
	 * Der Prüfschlüssel umfasst die normalisierten Eingaben, die Versionsnummer des
	 * Lagers und alle geplanten Änderungen. Die Versionsnummer wird beim Übernehmen
	 * nur erhöht, wenn sie noch der gelesenen entspricht; ein gleichzeitiger zweiter
	 * Lauf scheitert dadurch sicher.
	 *
	 * @return array<string, mixed>
	 * @throws InvalidWizardInput
	 * @throws PreviewOutdatedException
	 */
	public function rerunApply(int $campId, string $uid, array $data, string $token, array $groupDecisions = []): array {
		$now = $this->time->getTime();

		$plan = $this->transaction->run(function () use ($campId, $uid, $data, $token, $groupDecisions, $now): array {
			$camp = $this->campService->require($campId, $uid, Permission::RunWizard);
			$plan = $this->plan($camp, $data, $uid);
			if (!hash_equals($plan['token'], $token)) {
				throw new PreviewOutdatedException('Die Planung hat sich seit der Vorschau geändert. Bitte prüfe die Vorschau erneut.');
			}
			// Erst prüfen, dann sperren und schreiben: Ohne vollständige Entscheidungen bleibt
			// alles unverändert und die Vorschau gültig.
			$this->applyGroupDecisions($plan['campChanges'], $groupDecisions, false, $now);
			if (!$this->camps->bumpVersion($campId, $now, (int)$camp->getVersion())) {
				throw new PreviewOutdatedException('Die Planung hat sich seit der Vorschau geändert. Bitte prüfe die Vorschau erneut.');
			}

			$draft = $plan['draft'];
			$this->applyInputToCamp($camp, $draft->input, $now);
			$this->camps->update($camp);
			$this->syncGroups($campId, $draft->input);
			$run = $this->storeRun($campId, $uid, $draft, $now);

			foreach ($plan['campChanges'] as $change) {
				if ($change['kind'] === 'shopping_trip') {
					$trip = $this->shoppingTrips->find($change['id']);
					$trip->setTripDate($change['to']);
					$this->shoppingTrips->update($trip);
				}
			}

			$this->applyChanges($campId, $run, $plan['sets']['program'], fn () => new ProgramItem(), $this->programItems, $now);
			$this->applyChanges($campId, $run, $plan['sets']['tasks'], fn () => new Task(), $this->tasks, $now);
			$this->applyChanges($campId, $run, $plan['sets']['meals'], fn () => new Meal(), $this->meals, $now);
			$this->applyGroupDecisions($plan['campChanges'], $groupDecisions, true, $now);
			return $plan;
		});

		// Neue Mahlzeiten, etwa nach einer Verlängerung, bekommen ebenfalls ein Gericht.
		$input = WizardInput::fromArray($data);
		if ($input->proposeMenu && $input->selfCatering) {
			$this->catering->proposeMealPlan($campId, $uid);
		}

		return [
			'camp' => count($plan['campChanges']),
			'program' => $plan['sets']['program']->toArray()['summary'],
			'tasks' => $plan['sets']['tasks']->toArray()['summary'],
			'meals' => $plan['sets']['meals']->toArray()['summary'],
		];
	}

	/**
	 * @return array{draft: Draft, sets: array{program: ChangeSet, tasks: ChangeSet, meals: ChangeSet}, campChanges: list<array<string, mixed>>, token: string}
	 * @throws InvalidWizardInput
	 */
	private function plan(Camp $camp, array $data, string $uid): array {
		$campId = (int)$camp->getId();
		$draft = $this->preview($data, $uid);

		// Einschließlich gelöschter Vorschläge, damit sie nicht wieder angelegt werden.
		// Ein eingeplantes Spiel, zugeordnetes Material oder eine verknüpfte Aufgabe ist eine
		// Bearbeitung: Solche Programmpunkte ändert oder entfernt der Assistent nicht.
		$withGames = [];
		foreach ($this->programGames->findByCamp($campId) as $link) {
			$withGames[(int)$link->getProgramItemId()] ??= (int)$link->getCreatedAt();
		}
		foreach ($this->materialUses->findByCamp($campId) as $use) {
			if ($use->getProgramItemId() !== null) {
				$withGames[(int)$use->getProgramItemId()] ??= (int)$use->getCreatedAt();
			}
		}
		foreach ($this->tasks->findByCamp($campId) as $task) {
			if ($task->getProgramItemId() !== null) {
				$withGames[(int)$task->getProgramItemId()] ??= (int)$task->getUpdatedAt();
			}
		}
		$program = array_map(static function (ProgramItem $i) use ($withGames): ExistingElement {
			$existing = $i->asExisting($i->label());
			$p = $existing->provenance;
			if (isset($withGames[(int)$i->getId()]) && $p->userModifiedAt === null) {
				$existing = new ExistingElement($existing->id, $existing->ruleKey, $existing->fingerprint,
					new Provenance($p->origin, $p->reviewState, $withGames[(int)$i->getId()], $p->deletedAt), $existing->label);
			}
			return $existing;
		}, $this->programItems->findByCampIncludingDeleted($campId));
		// Pflichtaufgaben (zum Beispiel das Allergieverfahren) entstehen nicht im Assistenten und bleiben unberührt.
		$tasks = array_map(
			static fn (Task $t) => $t->asExisting($t->getTitle()),
			array_values(array_filter($this->tasks->findByCampIncludingDeleted($campId), static fn (Task $t): bool => !$t->getMandatory())),
		);
		$meals = array_map(static fn (Meal $m) => $m->asExisting($m->getDay() . ' ' . $m->getMealType()), $this->meals->findByCampIncludingDeleted($campId));

		$sets = [
			'program' => $this->planner->plan($program, $draft->programItems, true),
			'tasks' => $this->planner->plan($tasks, $draft->tasks, false),
			'meals' => $this->planner->plan($meals, $draft->meals, false),
		];
		$campChanges = [
			...$this->campChanges($camp, $this->groups->findByCamp($campId), $draft->input),
			...$this->tripShifts($camp, $draft->input),
			...$this->removedGroupItems($campId, $draft->input, $sets['program']),
		];
		$token = hash('sha256', json_encode([
			'input' => $draft->input->toArray(),
			'version' => (int)$camp->getVersion(),
			'camp' => $campChanges,
			'sets' => array_map(static fn (ChangeSet $set): array => $set->toArray(), $sets),
		], JSON_THROW_ON_ERROR));

		return ['draft' => $draft, 'sets' => $sets, 'campChanges' => $campChanges, 'token' => $token];
	}

	/** Verknüpfungen eines endgültig entfernten Programmpunkts nicht verwaist zurücklassen. */
	private function detach(ProgramItem $item): void {
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
	 * Bestätigte oder manuelle Programmpunkte, die auf eine entfernte Altersstufe verweisen.
	 * Als entfernt gilt eine Altersstufe, die bisher Teilnehmende hatte und künftig keine mehr
	 * hat, ob sie aus der Eingabe fehlt oder auf 0 Personen gesetzt wurde.
	 * Der Assistent ändert diese Punkte nicht selbst; die Lagerleitung entscheidet je Punkt.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function removedGroupItems(int $campId, WizardInput $input, ChangeSet $program): array {
		$remaining = array_map(static fn ($g): string => $g->key, $input->participantGroups());
		$before = array_map(
			static fn (CampGroup $g): string => $g->getGroupKey(),
			array_filter($this->groups->findByCamp($campId), static fn (CampGroup $g): bool => !$g->getIsLeaders() && (int)$g->getEstimatedCount() > 0),
		);
		$removed = array_values(array_diff($before, $remaining));
		if ($removed === []) {
			return [];
		}
		// Was der Assistent ohnehin anpasst oder entfernt, braucht keine Entscheidung.
		$handled = [];
		foreach ($program->changes as $change) {
			if ($change->existing !== null && in_array($change->type, [ChangeType::Update, ChangeType::Remove], true)) {
				$handled[$change->existing->id] = true;
			}
		}
		$items = [];
		foreach ($this->programItems->findByCamp($campId) as $item) {
			$hit = array_values(array_intersect($item->groupList(), $removed));
			if ($hit !== [] && !isset($handled[(int)$item->getId()])) {
				$items[] = ['id' => (int)$item->getId(), 'title' => $item->getTitle(), 'day' => $item->getDay(), 'startTime' => $item->getStartTime(), 'removed' => $hit];
			}
		}
		if ($items === []) {
			return [];
		}
		$options = array_map(static fn ($g): array => ['key' => $g->key, 'label' => $g->label], $input->participantGroups());
		return [['kind' => 'group_items', 'items' => $items, 'options' => $options]];
	}

	/**
	 * Entscheidungen zu Programmpunkten entfernter Altersstufen prüfen und umsetzen.
	 *
	 * @param array<string, mixed> $decisions Programmpunkt-ID => remove | all | Gruppenschlüssel
	 * @throws InvalidWizardInput wenn eine Entscheidung fehlt oder ungültig ist
	 */
	private function applyGroupDecisions(array $campChanges, array $decisions, bool $write, int $now): void {
		$errors = [];
		foreach ($campChanges as $change) {
			if ($change['kind'] !== 'group_items') {
				continue;
			}
			$valid = ['remove', 'all', ...array_column($change['options'], 'key')];
			foreach ($change['items'] as $entry) {
				$decision = $decisions[(string)$entry['id']] ?? null;
				if (!is_string($decision) || !in_array($decision, $valid, true)) {
					$errors['groupDecisions.' . $entry['id']] = sprintf('Bitte entscheide, was mit „%s“ geschehen soll.', $entry['title']);
					continue;
				}
				if (!$write) {
					continue;
				}
				$item = $this->programItems->find($entry['id']);
				if ($decision === 'remove') {
					if ($item->getRuleKey() === null) {
						$this->detach($item);
						$this->programItems->delete($item);
					} else {
						$item->markDeleted($now);
						$this->programItems->update($item);
					}
					continue;
				}
				$groups = array_values(array_diff($item->groupList(), $entry['removed']));
				$item->setGroupList($decision === 'all' ? [] : [...$groups, $decision]);
				$item->markUserModified($now);
				$this->programItems->update($item);
			}
		}
		if ($errors !== []) {
			throw new InvalidWizardInput($errors);
		}
	}

	/**
	 * Einkaufstermine bei geändertem Lagerzeitraum: Ein unbearbeiteter Standard-Großeinkauf
	 * ohne Einkäufe und eigene Posten wandert mit auf den Vortag des neuen Lagerbeginns.
	 * Alle anderen Termine bleiben, werden aber in der Vorschau genannt, wenn sie nicht mehr passen.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function tripShifts(Camp $camp, WizardInput $input): array {
		$trips = $this->shoppingTrips->findByCamp((int)$camp->getId());
		if ($trips === []) {
			return [];
		}
		$newStart = $input->start->format('Y-m-d');
		$newEnd = $input->end->format('Y-m-d');
		$earliestAllowed = $input->start->modify('-14 days')->format('Y-m-d');
		$used = [];
		foreach ($this->shoppingItems->findByCamp((int)$camp->getId()) as $item) {
			$used[(int)$item->getTripId()] = true;
		}
		usort($trips, static fn (ShoppingTrip $a, ShoppingTrip $b): int => [$a->getTripDate(), (int)$a->getId()] <=> [$b->getTripDate(), (int)$b->getId()]);

		$changes = [];
		$dates = [];
		foreach ($trips as $trip) {
			$date = $trip->getTripDate();
			if ($camp->getStartDate() !== $newStart && $trip->getOrigin() === 'default' && $trip->getModifiedAt() === null && !isset($used[(int)$trip->getId()])) {
				$to = $input->start->modify('-1 day')->format('Y-m-d');
				if ($to !== $date) {
					$changes[] = ['kind' => 'shopping_trip', 'id' => (int)$trip->getId(), 'label' => $trip->getLabel(), 'from' => $date, 'to' => $to];
					$date = $to;
				}
			}
			$dates[] = $date;
			if ($date < $earliestAllowed || $date > $newEnd) {
				$changes[] = ['kind' => 'shopping_trip_check', 'label' => $trip->getLabel(), 'date' => $date];
			}
		}
		if (min($dates) > $newStart) {
			$changes[] = ['kind' => 'shopping_trip_late', 'date' => min($dates)];
		}
		return $changes;
	}

	/**
	 * Änderungen an Lagerdaten und Gruppen, damit die Vorschau sie zeigen kann.
	 *
	 * @param list<CampGroup> $groups
	 * @return list<array<string, mixed>>
	 */
	private function campChanges(Camp $camp, array $groups, WizardInput $input): array {
		$changes = [];
		$fields = [
			'name' => [$camp->getName(), $input->name],
			'start' => [$camp->getStartDate(), $input->start->format('Y-m-d')],
			'end' => [$camp->getEndDate(), $input->end->format('Y-m-d')],
			'organizer' => [(string)$camp->getOrganizer(), $input->organizer],
			'campType' => [$camp->getCampType(), $input->campType],
			'locationKnown' => [(bool)$camp->getLocationKnown(), $input->locationKnown],
			'locationName' => [(string)$camp->getLocationName(), $input->locationName],
			'region' => [(string)$camp->getRegion(), $input->region],
			'theme' => [(string)$camp->getTheme(), $input->theme],
		];
		foreach ($fields as $field => [$from, $to]) {
			if ($from !== $to) {
				$changes[] = ['kind' => 'camp', 'field' => $field, 'from' => $from, 'to' => $to];
			}
		}

		$existing = [];
		foreach ($groups as $group) {
			$existing[$group->getGroupKey()] = $group;
		}
		foreach ($input->groups as $g) {
			$old = $existing[$g->key] ?? null;
			unset($existing[$g->key]);
			if ($old === null) {
				$changes[] = ['kind' => 'group_added', 'key' => $g->key, 'label' => $g->label, 'to' => $g->count];
				continue;
			}
			$groupFields = [
				'label' => [$old->getLabel(), $g->label],
				'count' => [(int)$old->getEstimatedCount(), $g->count],
				'separateProgram' => [(bool)$old->getSeparateProgram(), $g->separateProgram],
				'portionFactor' => [round((float)$old->getPortionFactor(), 2), $g->portionFactor],
				'blockMinutes' => [(int)$old->getBlockMinutes(), $g->blockMinutes],
			];
			foreach ($groupFields as $field => [$from, $to]) {
				if ($from !== $to) {
					$changes[] = ['kind' => 'group', 'key' => $g->key, 'label' => $g->label, 'field' => $field, 'from' => $from, 'to' => $to];
				}
			}
		}
		foreach ($existing as $old) {
			$changes[] = ['kind' => 'group_removed', 'key' => $old->getGroupKey(), 'label' => $old->getLabel(), 'from' => (int)$old->getEstimatedCount()];
		}
		return $changes;
	}

	/**
	 * @param list<ProposedElement> $proposals
	 * @param callable(): (ProgramItem|Task|Meal) $factory
	 */
	private function insertAll(int $campId, GenerationRun $run, array $proposals, callable $factory, \OCP\AppFramework\Db\QBMapper $mapper, int $now): void {
		foreach ($proposals as $proposal) {
			$mapper->insert($this->newFromProposal($campId, $run, $proposal, $factory, $now));
		}
	}

	/** @param callable(): (ProgramItem|Task|Meal) $factory */
	private function applyChanges(int $campId, GenerationRun $run, ChangeSet $set, callable $factory, \OCA\Lagerplaner\Db\CampScopedMapper $mapper, int $now): void {
		foreach ($set->changes as $change) {
			switch ($change->type) {
				case ChangeType::Add:
					$mapper->insert($this->newFromProposal($campId, $run, $change->proposed, $factory, $now));
					break;
				case ChangeType::Update:
					/** @var ProgramItem|Task|Meal $entity */
					$entity = $mapper->find($change->existing->id);
					$entity->fillFrom($change->proposed);
					$entity->markAsProposal($change->proposed->ruleKey(), $change->proposed->fingerprint(), $change->proposed->templateRef(), (int)$run->getId(), $now);
					$mapper->update($entity);
					break;
				case ChangeType::Remove:
					$entity = $mapper->find($change->existing->id);
					if ($entity instanceof ProgramItem) {
						$this->detach($entity);
					}
					$mapper->delete($entity);
					break;
				case ChangeType::Conflict:
					if ($change->addAsAlternative) {
						$alternative = $this->newFromProposal($campId, $run, $change->proposed, $factory, $now);
						if ($alternative instanceof ProgramItem) {
							$alternative->setAlternativeTo($change->existing->id);
						}
						$mapper->insert($alternative);
					}
					break;
				case ChangeType::Orphaned:
					break;
			}
		}
	}

	/** @param callable(): (ProgramItem|Task|Meal) $factory */
	private function newFromProposal(int $campId, GenerationRun $run, ProposedElement $proposal, callable $factory, int $now): Entity {
		$entity = $factory();
		$entity->setCampId($campId);
		$entity->fillFrom($proposal);
		$entity->markAsProposal($proposal->ruleKey(), $proposal->fingerprint(), $proposal->templateRef(), (int)$run->getId(), $now);
		return $entity;
	}

	private function storeRun(int $campId, string $uid, Draft $draft, int $now): GenerationRun {
		$run = new GenerationRun();
		$run->setCampId($campId);
		$run->setKind('assistant');
		$run->setTemplateVersion($draft->templateVersion);
		$run->setInputJson(json_encode($draft->input->toArray(), JSON_THROW_ON_ERROR));
		$run->setCreatedBy($uid);
		$run->setCreatedAt($now);
		return $this->runs->insert($run);
	}

	private function applyInputToCamp(Camp $camp, WizardInput $input, int $now): void {
		$camp->setName($input->name);
		$camp->setStartDate($input->start->format('Y-m-d'));
		$camp->setEndDate($input->end->format('Y-m-d'));
		$camp->setOrganizer($input->organizer === '' ? null : $input->organizer);
		$camp->setCampType($input->campType);
		$camp->setLocationKnown($input->locationKnown);
		$camp->setLocationName($input->locationName === '' ? null : $input->locationName);
		$camp->setRegion($input->region === '' ? null : $input->region);
		$camp->setTheme($input->theme === '' ? null : $input->theme);
		$camp->setUpdatedAt($now);
	}

	/**
	 * Gleicht Gruppen anhand ihrer Kennung ab. Bestehende Datensätze bleiben erhalten,
	 * damit zum Beispiel bestätigte Teilnehmendenzahlen nicht verloren gehen.
	 */
	private function syncGroups(int $campId, WizardInput $input): void {
		$existing = [];
		foreach ($this->groups->findByCamp($campId) as $group) {
			$existing[$group->getGroupKey()] = $group;
		}
		foreach ($input->groups as $i => $g) {
			$group = $existing[$g->key] ?? null;
			unset($existing[$g->key]);
			$isNew = $group === null;
			if ($isNew) {
				$group = new CampGroup();
				$group->setCampId($campId);
				$group->setGroupKey($g->key);
			}
			$group->setLabel($g->label);
			$group->setEstimatedCount($g->count);
			$group->setPortionFactor($g->portionFactor);
			$group->setSeparateProgram($g->separateProgram);
			$group->setIsLeaders($g->isLeaders);
			$group->setBlockMinutes($g->blockMinutes);
			$group->setSortOrder($i);
			if ($isNew) {
				$this->groups->insert($group);
			} else {
				$this->groups->update($group);
			}
		}
		foreach ($existing as $removed) {
			$this->groups->delete($removed);
		}
	}

	private function today(): DateTimeImmutable {
		return DateTimeImmutable::createFromMutable($this->time->getDateTime('today', new DateTimeZone('UTC')));
	}
}
