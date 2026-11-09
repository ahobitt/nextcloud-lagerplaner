<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Db\CatalogGame;
use OCA\Lagerplaner\Db\Material;
use OCA\Lagerplaner\Db\MaterialDismissed;
use OCA\Lagerplaner\Db\MaterialDismissedMapper;
use OCA\Lagerplaner\Db\MaterialMapper;
use OCA\Lagerplaner\Db\MaterialUse;
use OCA\Lagerplaner\Db\MaterialUseMapper;
use OCA\Lagerplaner\Db\ProgramGameMapper;
use OCA\Lagerplaner\Db\ProgramItem;
use OCA\Lagerplaner\Db\ProgramItemMapper;
use OCA\Lagerplaner\Db\Transaction;
use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Material\GameMaterialSuggestions;
use OCA\Lagerplaner\Domain\Material\MaterialCategory;
use OCA\Lagerplaner\Domain\Material\MaterialDemand;
use OCA\Lagerplaner\Domain\Material\MaterialStatus;
use OCA\Lagerplaner\Domain\Material\MaterialUseSlot;
use OCA\Lagerplaner\Domain\Wizard\Timeline;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Material und Packliste eines Lagers (Konzept 11).
 *
 * Ein Materialposten ist das, was gepackt wird; seine Einsätze sagen, wofür und wie viel.
 * Der Gesamtbedarf berücksichtigt gleichzeitige Nutzung (MaterialDemand). Ansehen dürfen
 * alle im Team; planen Lagerleitung, Material- und Programmteam; packen alle außer Lesezugriff.
 */
class MaterialService {
	public const MAX_MATERIALS = 300;

	public function __construct(
		private CampService $camps,
		private MaterialMapper $materials,
		private MaterialUseMapper $uses,
		private MaterialDismissedMapper $dismissed,
		private ProgramItemMapper $programItems,
		private ProgramGameMapper $programGames,
		private Transaction $transaction,
		private ITimeFactory $time,
	) {
	}

	/** @return array<string, mixed> */
	public function overview(int $campId, string $uid): array {
		$this->camps->require($campId, $uid, Permission::View);
		$people = array_column($this->camps->people($campId, $uid), 'displayName', 'uid');
		$program = [];
		foreach ($this->programItems->findByCamp($campId) as $item) {
			$program[(int)$item->getId()] = $item;
		}
		$usesByMaterial = [];
		$refs = [];
		foreach ($this->uses->findByCamp($campId) as $use) {
			$usesByMaterial[(int)$use->getMaterialId()][] = $use;
			if ($use->getSourceRef() !== null) {
				$refs[$use->getSourceRef()] = true;
			}
		}
		foreach ($this->dismissed->findByCamp($campId) as $row) {
			$refs[$row->getSourceRef()] = true;
		}

		$items = [];
		$counts = array_fill_keys(array_map(static fn (MaterialStatus $s): string => $s->value, MaterialStatus::cases()), 0);
		foreach ($this->materials->findByCamp($campId) as $material) {
			$items[] = $this->materialArray($material, $usesByMaterial[(int)$material->getId()] ?? [], $program, $people);
			$counts[$material->getStatus()] = ($counts[$material->getStatus()] ?? 0) + 1;
		}
		usort($items, static fn (array $a, array $b): int => [MaterialCategory::from($a['category'])->order(), mb_strtolower($a['name'])] <=> [MaterialCategory::from($b['category'])->order(), mb_strtolower($b['name'])]);

		return [
			'items' => $items,
			'suggestions' => $this->suggestions($campId, $program, $refs),
			'counts' => $counts,
			'categories' => array_map(static fn (MaterialCategory $c): array => ['key' => $c->value, 'label' => $c->label()], MaterialCategory::cases()),
			'statuses' => array_map(static fn (MaterialStatus $s): array => ['key' => $s->value, 'label' => $s->label()], MaterialStatus::cases()),
		];
	}

	/**
	 * @param array<string, mixed> $data name, category, unit, reusable, note, responsible, quantity, programItemId
	 * @return array{id: int}
	 */
	public function create(int $campId, string $uid, array $data): array {
		$this->camps->require($campId, $uid, Permission::EditMaterial);
		if (count($this->materials->findByCamp($campId)) >= self::MAX_MATERIALS) {
			throw new \InvalidArgumentException('Ein Lager kann höchstens ' . self::MAX_MATERIALS . ' Materialposten haben.');
		}
		return $this->transaction->run(function () use ($campId, $uid, $data): array {
			$now = $this->time->getTime();
			$material = new Material();
			$material->setCampId($campId);
			$material->setCreatedAt($now);
			$this->applyFields($material, $data, $campId, $uid, true);
			$material = $this->materials->insert($material);
			if (array_key_exists('quantity', $data) || array_key_exists('programItemId', $data)) {
				$this->insertUse($material, $this->programItemId($campId, $data['programItemId'] ?? null), $this->quantity($data['quantity'] ?? null), null);
			}
			return ['id' => (int)$material->getId()];
		});
	}

	/** @param array<string, mixed> $data */
	public function update(int $id, string $uid, array $data): void {
		$material = $this->material($id, $uid, Permission::EditMaterial);
		$this->applyFields($material, $data, (int)$material->getCampId(), $uid, false);
		$this->materials->update($material);
	}

	public function setStatus(int $id, string $uid, string $status): void {
		$material = $this->material($id, $uid, Permission::PackMaterial);
		if (MaterialStatus::tryFrom($status) === null) {
			throw new \InvalidArgumentException('Unbekannter Materialstatus.');
		}
		$material->setStatus($status);
		$material->setUpdatedAt($this->time->getTime());
		$this->materials->update($material);
	}

	/** Löscht einen Posten samt Einsätzen; Vorschläge aus Spielen kommen danach nicht wieder. */
	public function delete(int $id, string $uid): void {
		$material = $this->material($id, $uid, Permission::EditMaterial);
		$this->transaction->run(function () use ($material): void {
			foreach ($this->uses->findByCamp((int)$material->getCampId()) as $use) {
				if ($use->getMaterialId() === $material->getId()) {
					$this->removeUseRow($use);
				}
			}
			$this->materials->delete($material);
		});
	}

	/**
	 * Material einer Vorlage an einen Programmpunkt hängen. Gleichnamiges Material im Lager
	 * bekommt einen weiteren Einsatz, sonst entsteht ein neuer Posten.
	 *
	 * @param list<array{name: string, quantity: ?float, unit: ?string, category: string, reusable: bool}> $materials
	 */
	public function attachMaterials(int $campId, string $uid, int $programItemId, array $materials): void {
		$this->camps->require($campId, $uid, Permission::EditMaterial);
		$programItemId = (int)$this->programItemId($campId, $programItemId);
		$existing = [];
		foreach ($this->materials->findByCamp($campId) as $material) {
			$existing[mb_strtolower(trim($material->getName()))] = $material;
		}
		$now = $this->time->getTime();
		foreach ($materials as $entry) {
			$key = mb_strtolower(trim($entry['name']));
			$material = $existing[$key] ?? null;
			if ($material === null) {
				$material = new Material();
				$material->setCampId($campId);
				$material->setName($entry['name']);
				$material->setCategory((MaterialCategory::tryFrom($entry['category']) ?? MaterialCategory::Other)->value);
				$material->setUnit($entry['unit']);
				$material->setReusable($entry['reusable']);
				$material->setStatus(MaterialStatus::Needed->value);
				$material->setCreatedAt($now);
				$material->setUpdatedAt($now);
				$material = $existing[$key] = $this->materials->insert($material);
			}
			$this->insertUse($material, $programItemId, $entry['quantity'], null);
		}
	}

	public function addUse(int $materialId, string $uid, mixed $programItemId, mixed $quantity): void {
		$material = $this->material($materialId, $uid, Permission::EditMaterial);
		$this->insertUse($material, $this->programItemId((int)$material->getCampId(), $programItemId), $this->quantity($quantity), null);
	}

	public function updateUse(int $useId, string $uid, mixed $quantity): void {
		$use = $this->use($useId, $uid);
		$use->setQuantity($this->quantity($quantity));
		$this->uses->update($use);
	}

	public function removeUse(int $useId, string $uid): void {
		$this->removeUseRow($this->use($useId, $uid));
	}

	/**
	 * Übernimmt einen Vorschlag aus einem eingeplanten Spiel. Gibt es schon einen Posten mit
	 * diesem Namen, bekommt er einen weiteren Einsatz; sonst entsteht ein neuer. Die Menge
	 * bleibt offen, weil die Spieldatenbank keine Mengen liefert.
	 */
	public function takeSuggestion(int $campId, string $uid, string $ref): void {
		$this->camps->require($campId, $uid, Permission::EditMaterial);
		$suggestion = $this->findSuggestion($campId, $uid, $ref);
		$this->transaction->run(function () use ($campId, $suggestion): void {
			$material = null;
			foreach ($this->materials->findByCamp($campId) as $candidate) {
				$material ??= mb_strtolower(trim($candidate->getName())) === mb_strtolower($suggestion['name']) ? $candidate : null;
			}
			if ($material === null) {
				$now = $this->time->getTime();
				$material = new Material();
				$material->setCampId($campId);
				$material->setName($suggestion['name']);
				$material->setCategory($suggestion['category']);
				$material->setStatus(MaterialStatus::Needed->value);
				$material->setCreatedAt($now);
				$material->setUpdatedAt($now);
				$material = $this->materials->insert($material);
			}
			$this->insertUse($material, $suggestion['programItemId'], null, $suggestion['ref']);
		});
	}

	public function dismissSuggestion(int $campId, string $uid, string $ref): void {
		$this->camps->require($campId, $uid, Permission::EditMaterial);
		$this->findSuggestion($campId, $uid, $ref);
		$this->dismiss($campId, $ref);
	}

	/**
	 * @param list<MaterialUse> $uses
	 * @param array<int, ProgramItem> $program
	 * @param array<string, string> $people
	 * @return array<string, mixed>
	 */
	private function materialArray(Material $material, array $uses, array $program, array $people): array {
		$slots = [];
		$useArrays = [];
		foreach ($uses as $use) {
			$item = $use->getProgramItemId() === null ? null : ($program[(int)$use->getProgramItemId()] ?? null);
			$inactive = $use->getProgramItemId() !== null && $item === null;
			if (!$inactive) {
				$start = $item === null ? 0 : Timeline::minutes($item->getStartTime());
				$slots[] = new MaterialUseSlot($use->getQuantity(), $item?->getDay(), $start, $start + (int)$item?->getDurationMinutes());
			}
			$useArrays[] = [
				'id' => $use->getId(),
				'programItemId' => $use->getProgramItemId(),
				'programTitle' => $item?->getTitle(),
				'day' => $item?->getDay(),
				'startTime' => $item?->getStartTime(),
				'quantity' => $use->getQuantity(),
				'fromGame' => $use->getSourceRef() !== null,
				// Programmpunkt gelöscht (wiederherstellbar): zählt nicht zum Bedarf
				'inactive' => $inactive,
			];
		}
		$demand = (new MaterialDemand())->aggregate($slots, (bool)$material->getReusable());
		$responsible = $material->getResponsibleUid();
		return [
			'id' => $material->getId(),
			'name' => $material->getName(),
			'category' => $material->getCategory(),
			'unit' => $material->getUnit(),
			'reusable' => (bool)$material->getReusable(),
			'status' => $material->getStatus(),
			'responsible' => $responsible === null ? null : ['uid' => $responsible, 'displayName' => $people[$responsible] ?? $responsible],
			'note' => $material->getNote(),
			'uses' => $useArrays,
			'total' => $demand['total'],
			'openQuantities' => $demand['openQuantities'],
		];
	}

	/**
	 * @param array<int, ProgramItem> $program
	 * @param array<string, true> $refs
	 * @return list<array<string, mixed>>
	 */
	private function suggestions(int $campId, array $program, array $refs): array {
		$planned = [];
		foreach ($this->programGames->findByCamp($campId) as $link) {
			if (!isset($program[(int)$link->getProgramItemId()])) {
				continue;
			}
			$planned[] = [
				'programGameId' => (int)$link->getId(),
				'programItemId' => (int)$link->getProgramItemId(),
				'gameTitle' => $link->getTitle(),
				'materials' => CatalogGame::list($link->getMaterialsJson()),
			];
		}
		return array_map(static fn (array $s): array => $s + [
			'programTitle' => $program[$s['programItemId']]->getTitle(),
			'day' => $program[$s['programItemId']]->getDay(),
		], (new GameMaterialSuggestions())->suggest($planned, $refs));
	}

	/** @return array<string, mixed> */
	private function findSuggestion(int $campId, string $uid, string $ref): array {
		foreach ($this->overview($campId, $uid)['suggestions'] as $suggestion) {
			if ($suggestion['ref'] === $ref) {
				return $suggestion;
			}
		}
		throw new NotFoundException('Diesen Vorschlag gibt es nicht mehr.');
	}

	/** @param array<string, mixed> $data */
	private function applyFields(Material $material, array $data, int $campId, string $uid, bool $creating): void {
		if ($creating || array_key_exists('name', $data)) {
			$name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
			if ($name === '' || mb_strlen($name) > 120) {
				throw new \InvalidArgumentException('Der Name muss zwischen 1 und 120 Zeichen lang sein.');
			}
			$material->setName($name);
		}
		if ($creating || array_key_exists('category', $data)) {
			$category = MaterialCategory::tryFrom((string)($data['category'] ?? 'other'));
			if ($category === null) {
				throw new \InvalidArgumentException('Unbekannte Materialgruppe.');
			}
			$material->setCategory($category->value);
		}
		if (array_key_exists('unit', $data)) {
			$unit = is_string($data['unit']) ? trim($data['unit']) : '';
			if (mb_strlen($unit) > 20) {
				throw new \InvalidArgumentException('Die Einheit darf höchstens 20 Zeichen lang sein.');
			}
			$material->setUnit($unit === '' ? null : $unit);
		}
		if (array_key_exists('reusable', $data)) {
			$material->setReusable((bool)$data['reusable']);
		}
		if (array_key_exists('note', $data)) {
			$note = is_string($data['note']) ? trim($data['note']) : '';
			if (mb_strlen($note) > 500) {
				throw new \InvalidArgumentException('Die Notiz darf höchstens 500 Zeichen lang sein.');
			}
			$material->setNote($note === '' ? null : $note);
		}
		if (array_key_exists('responsible', $data)) {
			$responsible = $data['responsible'];
			if ($responsible !== null && $responsible !== '' && !in_array($responsible, array_column($this->camps->people($campId, $uid), 'uid'), true)) {
				throw new \InvalidArgumentException('Verantwortlich sein kann nur eine Person mit Zugriff auf das Lager.');
			}
			$material->setResponsibleUid($responsible === '' ? null : $responsible);
		}
		if ($creating) {
			$material->setStatus(MaterialStatus::Needed->value);
		}
		$material->setUpdatedAt($this->time->getTime());
	}

	private function insertUse(Material $material, ?int $programItemId, ?float $quantity, ?string $ref): void {
		$use = new MaterialUse();
		$use->setCampId((int)$material->getCampId());
		$use->setMaterialId((int)$material->getId());
		$use->setProgramItemId($programItemId);
		$use->setQuantity($quantity);
		$use->setSourceRef($ref);
		$use->setCreatedAt($this->time->getTime());
		$this->uses->insert($use);
	}

	/** Ein entfernter Einsatz aus einem Spielvorschlag wird als verworfen gemerkt. */
	private function removeUseRow(MaterialUse $use): void {
		if ($use->getSourceRef() !== null) {
			$this->dismiss((int)$use->getCampId(), $use->getSourceRef());
		}
		$this->uses->delete($use);
	}

	private function dismiss(int $campId, string $ref): void {
		foreach ($this->dismissed->findByCamp($campId) as $row) {
			if ($row->getSourceRef() === $ref) {
				return;
			}
		}
		$row = new MaterialDismissed();
		$row->setCampId($campId);
		$row->setSourceRef($ref);
		$row->setCreatedAt($this->time->getTime());
		$this->dismissed->insert($row);
	}

	private function programItemId(int $campId, mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		foreach ($this->programItems->findByCamp($campId) as $item) {
			if ((int)$item->getId() === (int)$value) {
				return (int)$value;
			}
		}
		throw new \InvalidArgumentException('Dieser Programmpunkt gehört nicht zum Lager.');
	}

	private function quantity(mixed $value): ?float {
		if ($value === null || $value === '') {
			return null;
		}
		if (is_string($value)) {
			$value = str_replace(',', '.', trim($value));
		}
		if (!is_numeric($value) || (float)$value <= 0 || (float)$value > 100000) {
			throw new \InvalidArgumentException('Bitte gib eine Menge größer als 0 an oder lass sie offen.');
		}
		return (float)$value;
	}

	private function material(int $id, string $uid, Permission $permission): Material {
		try {
			$material = $this->materials->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Material nicht gefunden.');
		}
		$this->camps->require((int)$material->getCampId(), $uid, $permission);
		return $material;
	}

	private function use(int $id, string $uid): MaterialUse {
		try {
			$use = $this->uses->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Einsatz nicht gefunden.');
		}
		$this->camps->require((int)$use->getCampId(), $uid, Permission::EditMaterial);
		return $use;
	}
}
