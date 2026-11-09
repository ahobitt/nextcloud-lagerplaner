<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Access\Directory;
use OCA\Lagerplaner\Db\MaterialMapper;
use OCA\Lagerplaner\Db\MaterialUseMapper;
use OCA\Lagerplaner\Db\ProgramItem;
use OCA\Lagerplaner\Db\ProgramItemMapper;
use OCA\Lagerplaner\Db\Template;
use OCA\Lagerplaner\Db\TemplateMapper;
use OCA\Lagerplaner\Db\Transaction;
use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Templates\DayTemplate;
use OCA\Lagerplaner\Domain\Templates\TemplateItem;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Programm- und Tagesvorlagen (Konzept 6.4).
 *
 * Gespeichert wird nur, was den Ablauf beschreibt: Titel, Beschreibung, Zeiten, Dauer,
 * Altersstufen, Schlechtwetteralternative und Material. Verantwortliche, Aufgaben,
 * Ernährungsangaben und andere Daten aus dem Lager werden nicht übernommen. Vorlagen
 * gehören einer Person und können mit allen in der Nextcloud geteilt werden. Jede
 * Änderung erhöht die Version, damit erzeugte Elemente zurückführbar bleiben.
 */
class TemplateService {
	public const MAX_TEMPLATES = 200;

	public function __construct(
		private CampService $camps,
		private TemplateMapper $templates,
		private ProgramItemMapper $programItems,
		private MaterialMapper $materials,
		private MaterialUseMapper $materialUses,
		private PlanningService $planning,
		private MaterialService $materialService,
		private Directory $directory,
		private Transaction $transaction,
		private ITimeFactory $time,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function list(string $uid): array {
		return array_map(fn (Template $t): array => $this->summary($t, $uid), $this->templates->findVisible($uid));
	}

	/** @return array<string, mixed> */
	public function get(int $id, string $uid): array {
		$template = $this->visible($id, $uid);
		return $this->summary($template, $uid) + ['data' => $template->data()];
	}

	/** Einen Programmpunkt mit seinem Material als Vorlage speichern. */
	public function saveItem(string $uid, int $programItemId, string $name, bool $shared, ?int $replaceId = null): array {
		$item = $this->programItem($programItemId, $uid);
		$data = ['item' => $this->itemData($item, false)->toArray()];
		return $this->store($uid, Template::ITEM, $name, $shared, $data, $replaceId);
	}

	/** Alle Programmpunkte eines Tages als Tagesablauf speichern. */
	public function saveDay(string $uid, int $campId, string $day, string $name, bool $shared, ?int $replaceId = null): array {
		$this->camps->require($campId, $uid, Permission::View);
		$items = array_values(array_filter($this->programItems->findByCamp($campId), static fn (ProgramItem $i): bool => $i->getDay() === $day && $i->getAlternativeTo() === null));
		usort($items, static fn (ProgramItem $a, ProgramItem $b): int => strcmp($a->getStartTime(), $b->getStartTime()));
		if ($items === []) {
			throw new \InvalidArgumentException('An diesem Tag gibt es keine Programmpunkte.');
		}
		$data = ['items' => array_map(fn (ProgramItem $i): array => $this->itemData($i, true)->toArray(), array_slice($items, 0, DayTemplate::MAX_ITEMS))];
		DayTemplate::items($data);
		return $this->store($uid, Template::DAY, $name, $shared, $data, $replaceId);
	}

	/** @param array<string, mixed> $changes name, shared */
	public function update(string $uid, int $id, array $changes): void {
		$template = $this->owned($id, $uid);
		if (array_key_exists('name', $changes)) {
			$template->setName($this->name($changes['name']));
		}
		if (array_key_exists('shared', $changes)) {
			$template->setShared((bool)$changes['shared']);
		}
		$template->setUpdatedAt($this->time->getTime());
		$this->templates->update($template);
	}

	public function delete(string $uid, int $id): void {
		$this->templates->delete($this->owned($id, $uid));
	}

	/**
	 * Vorlage in ein Lager einfügen: Tagesvorlagen mit ihren Uhrzeiten, Einzelvorlagen zur
	 * angegebenen Uhrzeit. Es entstehen eigene Programmpunkte, die der Assistent nicht ändert.
	 * Material kommt mit, wenn die Person Material planen darf.
	 *
	 * @return array{created: int, materialsSkipped: bool}
	 */
	public function apply(string $uid, int $id, int $campId, string $day, string $startTime = ''): array {
		$template = $this->visible($id, $uid);
		$this->camps->require($campId, $uid, Permission::EditProgram);
		$present = array_map(static fn ($g): string => $g->getGroupKey(), array_filter($this->camps->groups($campId), static fn ($g): bool => !$g->getIsLeaders() && (int)$g->getEstimatedCount() > 0));
		$withMaterial = $this->camps->rolesAllow($campId, $uid, Permission::EditMaterial);
		$items = $template->getKind() === Template::DAY
			? DayTemplate::items($template->data())
			: [TemplateItem::fromArray(is_array($template->data()['item'] ?? null) ? $template->data()['item'] : [], false)];

		return $this->transaction->run(function () use ($template, $items, $uid, $campId, $day, $startTime, $present, $withMaterial): array {
			$created = 0;
			$skipped = false;
			foreach ($items as $index => $item) {
				$groups = $item->groups === [] ? [] : array_values(array_intersect($item->groups, $present));
				if ($item->groups !== [] && $groups === []) {
					continue;
				}
				$programItem = $this->planning->createProgramItem($campId, $uid, [
					'title' => $item->title,
					'description' => $item->description,
					'day' => $day,
					'startTime' => $template->getKind() === Template::DAY ? $item->startTime : $startTime,
					'durationMinutes' => $item->durationMinutes,
					'groups' => $groups,
					'weatherAlternative' => $item->weatherAlternative,
				]);
				$programItem->setTemplateRef('user-template:' . $template->getId() . '@v' . $template->getVersion() . '#' . $index);
				$programItem->setCategory($item->category);
				$this->programItems->update($programItem);
				if ($item->materials !== []) {
					if ($withMaterial) {
						$this->materialService->attachMaterials($campId, $uid, (int)$programItem->getId(), $item->materials);
					} else {
						$skipped = true;
					}
				}
				$created++;
			}
			return ['created' => $created, 'materialsSkipped' => $skipped];
		});
	}

	/**
	 * Tagesvorlage für den Assistenten, sofern die Person sie sehen darf.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function dayTemplateFor(int $id, string $uid): DayTemplate {
		try {
			$template = $this->visible($id, $uid);
		} catch (NotFoundException) {
			throw new \InvalidArgumentException('Die gewählte Tagesvorlage gibt es nicht mehr.');
		}
		if ($template->getKind() !== Template::DAY) {
			throw new \InvalidArgumentException('Die gewählte Vorlage ist keine Tagesvorlage.');
		}
		return new DayTemplate((int)$template->getId(), (int)$template->getVersion(), $template->getName(), DayTemplate::items($template->data()));
	}

	private function itemData(ProgramItem $item, bool $withStart): TemplateItem {
		$materials = [];
		$byId = [];
		foreach ($this->materials->findByCamp((int)$item->getCampId()) as $material) {
			$byId[(int)$material->getId()] = $material;
		}
		foreach ($this->materialUses->findByCamp((int)$item->getCampId()) as $use) {
			$material = $byId[(int)$use->getMaterialId()] ?? null;
			if ($use->getProgramItemId() === $item->getId() && $material !== null) {
				$materials[] = ['name' => $material->getName(), 'quantity' => $use->getQuantity(), 'unit' => $material->getUnit(),
					'category' => $material->getCategory(), 'reusable' => (bool)$material->getReusable()];
			}
		}
		return TemplateItem::fromArray([
			'startTime' => $withStart ? $item->getStartTime() : '',
			'durationMinutes' => (int)$item->getDurationMinutes(),
			'title' => $item->getTitle(),
			'description' => (string)$item->getDescription(),
			'category' => $item->getCategory(),
			'weatherAlternative' => (string)$item->getWeatherAlternative(),
			'groups' => $item->groupList(),
			'materials' => $materials,
		], $withStart);
	}

	/** @param array<string, mixed> $data */
	private function store(string $uid, string $kind, string $name, bool $shared, array $data, ?int $replaceId): array {
		$now = $this->time->getTime();
		if ($replaceId !== null) {
			$template = $this->owned($replaceId, $uid);
			if ($template->getKind() !== $kind) {
				throw new \InvalidArgumentException('Eine Tagesvorlage kann nur durch einen Tag ersetzt werden, eine Programmvorlage nur durch einen Programmpunkt.');
			}
			$template->setVersion((int)$template->getVersion() + 1);
		} else {
			if (count(array_filter($this->templates->findVisible($uid), static fn (Template $t): bool => $t->getOwnerUid() === $uid)) >= self::MAX_TEMPLATES) {
				throw new \InvalidArgumentException('Es sind höchstens ' . self::MAX_TEMPLATES . ' eigene Vorlagen möglich.');
			}
			$template = new Template();
			$template->setOwnerUid($uid);
			$template->setKind($kind);
			$template->setVersion(1);
			$template->setCreatedAt($now);
		}
		$template->setName($this->name($name));
		$template->setShared($shared);
		$template->setDataJson(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$template->setUpdatedAt($now);
		$template = $template->getId() === null ? $this->templates->insert($template) : $this->templates->update($template);
		return $this->summary($template, $uid);
	}

	/** @return array<string, mixed> */
	private function summary(Template $template, string $uid): array {
		$data = $template->data();
		return [
			'id' => $template->getId(),
			'kind' => $template->getKind(),
			'name' => $template->getName(),
			'shared' => (bool)$template->getShared(),
			'mine' => $template->getOwnerUid() === $uid,
			'owner' => $this->directory->displayName(Directory::USER, $template->getOwnerUid()),
			'version' => (int)$template->getVersion(),
			'itemCount' => $template->getKind() === Template::DAY ? count($data['items'] ?? []) : 1,
			'updatedAt' => $template->getUpdatedAt(),
		];
	}

	private function name(mixed $name): string {
		$name = is_string($name) ? trim($name) : '';
		if ($name === '' || mb_strlen($name) > 120) {
			throw new \InvalidArgumentException('Die Vorlage braucht einen Namen mit höchstens 120 Zeichen.');
		}
		return $name;
	}

	private function programItem(int $id, string $uid): ProgramItem {
		try {
			$item = $this->programItems->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Programmpunkt nicht gefunden.');
		}
		$this->camps->require((int)$item->getCampId(), $uid, Permission::View);
		return $item;
	}

	private function visible(int $id, string $uid): Template {
		try {
			$template = $this->templates->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Vorlage nicht gefunden.');
		}
		if ($template->getOwnerUid() !== $uid && !$template->getShared()) {
			throw new NotFoundException('Vorlage nicht gefunden.');
		}
		return $template;
	}

	private function owned(int $id, string $uid): Template {
		$template = $this->visible($id, $uid);
		if ($template->getOwnerUid() !== $uid && !$this->directory->isAdmin($uid)) {
			throw new ForbiddenException('Ändern darf die Person, der die Vorlage gehört, oder die Administration.');
		}
		return $template;
	}
}
