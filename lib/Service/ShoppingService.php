<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use DateTimeImmutable;
use OCA\Lagerplaner\Db\Camp;
use OCA\Lagerplaner\Db\ShoppingItem;
use OCA\Lagerplaner\Db\ShoppingItemMapper;
use OCA\Lagerplaner\Db\ShoppingStock;
use OCA\Lagerplaner\Db\ShoppingStockMapper;
use OCA\Lagerplaner\Db\ShoppingTrip;
use OCA\Lagerplaner\Db\ShoppingTripMapper;
use OCA\Lagerplaner\Db\Transaction;
use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Shopping\Coverage;
use OCA\Lagerplaner\Domain\Shopping\CoverageAllocator;
use OCA\Lagerplaner\Domain\Shopping\GroceryCategory;
use OCA\Lagerplaner\Domain\Shopping\ShoppingCalculator;
use OCA\Lagerplaner\Domain\Shopping\ShoppingLine;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Einkaufslisten (Konzept 10).
 *
 * Mengen werden bei jedem Abruf aus Speiseplan, Menüvarianten und Rezepten berechnet.
 * Gespeichert werden Einkaufstermine, eigene Posten und zwei Arten von Fakten je Zutat:
 * gekaufte Mengen (mit dem Termin, an dem abgehakt wurde) und vorhandene Vorräte. Beide
 * werden den Positionen bei jedem Abruf neu zugeteilt (CoverageAllocator).
 */
class ShoppingService {
	public const MAX_TRIPS = 10;
	public const MAX_EXTRAS = 100;
	public const DEFAULT_LABEL = 'Großeinkauf';

	public function __construct(
		private CampService $camps,
		private CateringService $catering,
		private ShoppingTripMapper $trips,
		private ShoppingItemMapper $items,
		private ShoppingStockMapper $stock,
		private Transaction $transaction,
		private ITimeFactory $time,
	) {
	}

	/** Standard-Großeinkauf am Vortag; wird beim Anlegen eines Lagers erzeugt. */
	public static function defaultTrip(int $campId, string $startDate, int $now): ShoppingTrip {
		$trip = new ShoppingTrip();
		$trip->setCampId($campId);
		$trip->setTripDate((new DateTimeImmutable($startDate))->modify('-1 day')->format('Y-m-d'));
		$trip->setLabel(self::DEFAULT_LABEL);
		$trip->setOrigin('default');
		$trip->setCreatedAt($now);
		return $trip;
	}

	/** @return array<string, mixed> */
	public function overview(int $campId, string $uid): array {
		$camp = $this->camps->require($campId, $uid, Permission::ViewCatering);
		$state = $this->state($camp);
		$catering = $this->catering->dataQuality($campId);

		$trips = [];
		foreach ($state['trips'] as $index => $trip) {
			$lines = [];
			$keysHere = [];
			foreach ($state['lists'][$index] ?? [] as $line) {
				$lines[] = $this->lineArray($line, $state['coverage'][$index][$line->key]);
				$keysHere[$line->key] = true;
			}
			// Hier abgehakt, aber hier nicht mehr eingeplant: sichtbar und korrigierbar.
			$orphans = [];
			foreach ($state['items'] as $item) {
				if ($item->getKind() !== ShoppingItem::LINE || $item->getTripId() !== $trip->getId() || $trip->getId() === null) {
					continue;
				}
				if (!isset($keysHere[$item->getItemKey()])) {
					$orphans[] = [
						'key' => $item->getItemKey(),
						'name' => $item->getName() ?? '',
						'bought' => $this->amountOrNull($item->getUnitFamily(), (float)$item->getPurchasedBase()),
					];
				}
			}
			$extras = array_values(array_map(static fn (ShoppingItem $i): array => [
				'id' => $i->getId(),
				'name' => $i->getName(),
				'amount' => $i->getAmountText(),
				'category' => $i->getCategory(),
				'categoryLabel' => (GroceryCategory::tryFrom((string)$i->getCategory()) ?? GroceryCategory::Other)->label(),
				'purchased' => (bool)$i->getPurchased(),
			], array_filter($state['items'], static fn (ShoppingItem $i): bool => $i->getKind() === ShoppingItem::EXTRA && $trip->getId() !== null && $i->getTripId() === $trip->getId())));
			$trips[] = [
				'id' => $trip->getId(),
				'date' => $trip->getTripDate(),
				'label' => $trip->getLabel(),
				'lines' => $lines,
				'extras' => $extras,
				'orphans' => $orphans,
				'purchasedCount' => count(array_filter($lines, static fn (array $l): bool => $l['purchasedHere'])) + count($orphans),
			];
		}
		$reasons = [...$catering, ...$this->tripReasons($camp, $state['trips'])];

		return [
			'trips' => $trips,
			'meals' => $state['demand']['meals'],
			'mealsWithoutRecipe' => $state['demand']['mealsWithoutRecipe'],
			'provisional' => $reasons !== [],
			'reasons' => $reasons,
			'categories' => array_map(static fn (GroceryCategory $c): array => ['key' => $c->value, 'label' => $c->label()], GroceryCategory::cases()),
		];
	}

	/**
	 * Abhaken: Die gerade offene Menge gilt als gekauft. Wiederholtes Abhaken nach einem
	 * Mehrbedarf kauft genau den Rest dazu. Aufheben entfernt den Einkauf dieses Termins.
	 */
	public function setPurchased(int $tripId, string $uid, string $key, bool $purchased): void {
		[$trip, $camp] = $this->trip($tripId, $uid);
		$this->transaction->run(function () use ($trip, $camp, $key, $purchased): void {
			$state = $this->state($camp);
			$item = $this->item($state, $trip, $key);
			if (!$purchased) {
				// Aufheben geht auch für Posten, die hier nicht mehr eingeplant sind.
				if ($item !== null) {
					$this->items->delete($item);
				}
				return;
			}
			[$line, $coverage] = $this->find($state, $trip, $key);
			$item ??= $this->newItem($trip, $key, ShoppingItem::LINE);
			$item->setName(mb_substr($line->name, 0, 120));
			$item->setUnitFamily($line->family);
			$item->setPurchased(true);
			$item->setPurchasedBase((float)$item->getPurchasedBase() + ($line->hasQuantity() ? $coverage->open : 0.0));
			$this->save($item);
		});
	}

	/**
	 * Vorhandene Menge für das ganze Lager. Ohne Menge oder mit 0 wird der Vorrat entfernt.
	 *
	 * @throws \InvalidArgumentException bei unpassender Einheit oder Menge
	 */
	public function setStock(int $campId, string $uid, string $key, ?float $quantity, string $unit): void {
		$camp = $this->camps->require($campId, $uid, Permission::EditCatering);
		if ($quantity !== null && ($quantity < 0 || $quantity > 1000000)) {
			throw new \InvalidArgumentException('Bitte gib eine vorhandene Menge zwischen 0 und 1.000.000 an.');
		}
		$this->transaction->run(function () use ($camp, $key, $quantity, $unit): void {
			$state = $this->state($camp);
			$line = null;
			foreach ($state['lists'] as $lines) {
				foreach ($lines as $candidate) {
					$line ??= $candidate->key === $key ? $candidate : null;
				}
			}
			if ($line === null) {
				throw new NotFoundException('Diese Position steht nicht mehr auf der Liste. Bitte neu laden.');
			}
			$existing = null;
			foreach ($this->stock->findByCamp((int)$camp->getId()) as $row) {
				$existing ??= $row->getItemKey() === $key ? $row : null;
			}
			if ($quantity === null || $quantity <= 0) {
				if ($existing !== null) {
					$this->stock->delete($existing);
				}
				return;
			}
			$base = $line->hasQuantity() ? ShoppingLine::toBase($line->family, $quantity, $unit) : 1.0;
			$row = $existing ?? new ShoppingStock();
			$row->setCampId((int)$camp->getId());
			$row->setItemKey($key);
			$row->setName(mb_substr($line->name, 0, 120));
			$row->setUnitFamily($line->family);
			$row->setBase($base);
			$row->setUpdatedAt($this->time->getTime());
			$existing === null ? $this->stock->insert($row) : $this->stock->update($row);
		});
	}

	public function addTrip(int $campId, string $uid, string $date, string $label): void {
		$camp = $this->camps->require($campId, $uid, Permission::EditCatering);
		$this->transaction->run(function () use ($camp, $date, $label): void {
			$trips = $this->sortedTrips($camp, true);
			if (count($trips) >= self::MAX_TRIPS) {
				throw new \InvalidArgumentException('Es sind höchstens ' . self::MAX_TRIPS . ' Einkaufstermine möglich.');
			}
			$trip = new ShoppingTrip();
			$trip->setCampId((int)$camp->getId());
			$this->applyTrip($trip, $camp, $date, $label);
			$this->requireEarlyTrip($camp, [...array_map(static fn (ShoppingTrip $t): string => $t->getTripDate(), $trips), $trip->getTripDate()]);
			$trip->setCreatedAt($this->time->getTime());
			$this->trips->insert($trip);
		});
	}

	public function updateTrip(int $tripId, string $uid, string $date, string $label): void {
		[$trip, $camp] = $this->trip($tripId, $uid);
		$this->applyTrip($trip, $camp, $date, $label);
		$dates = array_map(static fn (ShoppingTrip $t): string => $t->getId() === $trip->getId() ? $trip->getTripDate() : $t->getTripDate(), $this->sortedTrips($camp));
		$this->requireEarlyTrip($camp, $dates);
		$trip->setModifiedAt($this->time->getTime());
		$this->trips->update($trip);
	}

	/**
	 * Löscht einen Termin. Gekaufte Mengen und eigene Posten wandern zum vorherigen Termin
	 * (sonst zum ersten) und bleiben dort sichtbar und korrigierbar.
	 *
	 * @return array{target: string, movedExtras: int, movedPurchases: int}
	 */
	public function deleteTrip(int $tripId, string $uid): array {
		[$trip, $camp] = $this->trip($tripId, $uid);
		return $this->transaction->run(function () use ($trip, $camp): array {
			$remaining = array_values(array_filter($this->sortedTrips($camp), static fn (ShoppingTrip $t): bool => $t->getId() !== $trip->getId()));
			if ($remaining === []) {
				throw new \InvalidArgumentException('Der letzte Einkaufstermin kann nicht gelöscht werden.');
			}
			$this->requireEarlyTrip($camp, array_map(static fn (ShoppingTrip $t): string => $t->getTripDate(), $remaining));
			$target = $remaining[0];
			foreach ($remaining as $candidate) {
				if ($candidate->getTripDate() <= $trip->getTripDate()) {
					$target = $candidate;
				}
			}
			$moved = 0;
			$purchases = 0;
			$items = $this->items->findByCamp((int)$camp->getId());
			foreach ($items as $item) {
				if ($item->getTripId() !== $trip->getId()) {
					continue;
				}
				if ($item->getKind() === ShoppingItem::EXTRA) {
					$moved++;
				} else {
					$purchases++;
					// Gibt es am Ziel schon einen Einkauf derselben Position, werden die Mengen addiert.
					foreach ($items as $existing) {
						if ($existing->getTripId() === $target->getId() && $existing->getItemKey() === $item->getItemKey()) {
							$existing->setPurchasedBase((float)$existing->getPurchasedBase() + (float)$item->getPurchasedBase());
							$this->save($existing);
							$this->items->delete($item);
							continue 2;
						}
					}
				}
				$item->setTripId((int)$target->getId());
				$this->save($item);
			}
			$this->trips->delete($trip);
			return ['target' => $target->getLabel(), 'movedExtras' => $moved, 'movedPurchases' => $purchases];
		});
	}

	/** @return array<string, mixed> */
	public function addExtra(int $tripId, string $uid, string $name, string $amount, string $category): array {
		[$trip] = $this->trip($tripId, $uid);
		$name = trim($name);
		$amount = trim($amount);
		if ($name === '' || mb_strlen($name) > 120) {
			throw new \InvalidArgumentException('Der Posten braucht einen Namen mit höchstens 120 Zeichen.');
		}
		if (mb_strlen($amount) > 60) {
			throw new \InvalidArgumentException('Die Menge darf höchstens 60 Zeichen lang sein.');
		}
		$category = GroceryCategory::tryFrom($category) ?? GroceryCategory::Other;
		$extras = array_filter($this->items->findByCamp((int)$trip->getCampId()), static fn (ShoppingItem $i): bool => $i->getTripId() === $trip->getId() && $i->getKind() === ShoppingItem::EXTRA);
		if (count($extras) >= self::MAX_EXTRAS) {
			throw new \InvalidArgumentException('Es sind höchstens ' . self::MAX_EXTRAS . ' eigene Posten je Einkauf möglich.');
		}
		$item = $this->newItem($trip, 'extra:' . bin2hex(random_bytes(8)), ShoppingItem::EXTRA);
		$item->setName($name);
		$item->setAmountText($amount === '' ? null : $amount);
		$item->setCategory($category->value);
		$item = $this->save($item);
		return ['id' => $item->getId()];
	}

	public function setExtraPurchased(int $itemId, string $uid, bool $purchased): void {
		$item = $this->extra($itemId, $uid);
		$item->setPurchased($purchased);
		$this->save($item);
	}

	public function deleteExtra(int $itemId, string $uid): void {
		$this->items->delete($this->extra($itemId, $uid));
	}

	/**
	 * Berechnete Listen, Fakten und Zuteilung für ein Lager.
	 *
	 * @return array{trips: list<ShoppingTrip>, lists: array<int, list<ShoppingLine>>, coverage: array<int, array<string, Coverage>>, items: list<ShoppingItem>, demand: array<string, mixed>}
	 */
	private function state(Camp $camp): array {
		$campId = (int)$camp->getId();
		$trips = $this->sortedTrips($camp);
		$demand = $this->catering->demands($campId);
		$lists = (new ShoppingCalculator())->calculate($demand['demands'], array_map(static fn (ShoppingTrip $t): string => $t->getTripDate(), $trips));

		$indexById = [];
		foreach ($trips as $index => $trip) {
			if ($trip->getId() !== null) {
				$indexById[(int)$trip->getId()] = $index;
			}
		}
		$items = $this->items->findByCamp($campId);
		$purchases = [];
		foreach ($items as $item) {
			if ($item->getKind() === ShoppingItem::LINE && $item->getPurchased() && isset($indexById[(int)$item->getTripId()])) {
				$purchases[$item->getItemKey()][$indexById[(int)$item->getTripId()]] = (float)$item->getPurchasedBase();
			}
		}
		$stock = [];
		foreach ($this->stock->findByCamp($campId) as $row) {
			$stock[$row->getItemKey()] = (float)$row->getBase();
		}
		$coverage = (new CoverageAllocator())->allocate($lists, array_keys($trips), $stock, $purchases);

		return ['trips' => $trips, 'lists' => $lists, 'coverage' => $coverage, 'items' => $items, 'demand' => $demand];
	}

	/** @return array<string, mixed> */
	private function lineArray(ShoppingLine $line, Coverage $coverage): array {
		$quantity = $line->hasQuantity();
		$amount = fn (float $value): ?array => $quantity && $value > 0 ? ShoppingLine::buyable($line->family, $value) : null;
		return $line->toArray() + [
			'status' => $coverage->isDone() ? 'done' : ($coverage->covered() > 0 ? 'partial' : 'open'),
			'purchasedHere' => $coverage->purchasedHere,
			'stock' => $amount($coverage->fromStock),
			'bought' => $amount($coverage->fromOwnPurchase),
			'carried' => $amount($coverage->fromEarlierPurchases),
			'open' => $amount($coverage->open),
			'family' => $line->family,
		];
	}

	/** @return array{quantity: ?float, unit: string}|null */
	private function amountOrNull(?string $family, float $base): ?array {
		return $family === null || $base <= 0 ? null : ShoppingLine::buyable($family, $base);
	}

	/**
	 * Einkaufstermine, die nach einer Änderung des Lagerzeitraums nicht mehr passen.
	 *
	 * @param list<ShoppingTrip> $trips
	 * @return list<string>
	 */
	private function tripReasons(Camp $camp, array $trips): array {
		$reasons = [];
		$earliestAllowed = (new DateTimeImmutable($camp->getStartDate()))->modify('-14 days')->format('Y-m-d');
		foreach ($trips as $trip) {
			if ($trip->getTripDate() < $earliestAllowed || $trip->getTripDate() > $camp->getEndDate()) {
				$reasons[] = sprintf('Der Einkauf „%s“ am %s liegt außerhalb des Lagerzeitraums. Bitte Datum prüfen.', $trip->getLabel(), $trip->getTripDate());
			}
		}
		$dates = array_map(static fn (ShoppingTrip $t): string => $t->getTripDate(), $trips);
		if ($dates !== [] && min($dates) > $camp->getStartDate()) {
			$reasons[] = 'Kein Einkauf liegt vor dem ersten Lagertag. Für die ersten Mahlzeiten würde zu spät eingekauft.';
		}
		return $reasons;
	}

	/** @return array{0: ShoppingLine, 1: Coverage} */
	private function find(array $state, ShoppingTrip $trip, string $key): array {
		foreach ($state['trips'] as $index => $candidate) {
			if ($candidate->getId() === $trip->getId()) {
				foreach ($state['lists'][$index] ?? [] as $line) {
					if ($line->key === $key) {
						return [$line, $state['coverage'][$index][$key]];
					}
				}
			}
		}
		throw new NotFoundException('Diese Position steht nicht mehr auf der Liste. Bitte neu laden.');
	}

	private function item(array $state, ShoppingTrip $trip, string $key): ?ShoppingItem {
		foreach ($state['items'] as $item) {
			if ($item->getTripId() === $trip->getId() && $item->getItemKey() === $key) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * @param bool $materialize fehlenden Standard-Einkauf anlegen (nur in schreibenden Vorgängen)
	 * @return list<ShoppingTrip> nach Datum
	 */
	private function sortedTrips(Camp $camp, bool $materialize = false): array {
		$trips = $this->trips->findByCamp((int)$camp->getId());
		if ($trips === []) {
			// Lager werden mit Standard-Einkauf angelegt; ältere hat die Migration ergänzt.
			// Fehlt er trotzdem, wird er nur in schreibenden Vorgängen gespeichert.
			$default = self::defaultTrip((int)$camp->getId(), $camp->getStartDate(), $this->time->getTime());
			$trips = [$materialize ? $this->trips->insert($default) : $default];
		}
		usort($trips, static fn (ShoppingTrip $a, ShoppingTrip $b): int => [$a->getTripDate(), (int)$a->getId()] <=> [$b->getTripDate(), (int)$b->getId()]);
		return $trips;
	}

	private function applyTrip(ShoppingTrip $trip, Camp $camp, string $date, string $label): void {
		$label = trim($label);
		if ($label === '' || mb_strlen($label) > 80) {
			throw new \InvalidArgumentException('Der Einkauf braucht einen Namen mit höchstens 80 Zeichen.');
		}
		$earliest = (new DateTimeImmutable($camp->getStartDate()))->modify('-14 days')->format('Y-m-d');
		if (!PlanningService::isDate($date) || $date < $earliest || $date > $camp->getEndDate()) {
			throw new \InvalidArgumentException('Der Einkauf muss zwischen zwei Wochen vor Lagerbeginn und dem letzten Lagertag liegen.');
		}
		$trip->setTripDate($date);
		$trip->setLabel($label);
	}

	/**
	 * Mindestens ein Einkauf muss spätestens am ersten Lagertag stattfinden, sonst würde für
	 * die ersten Mahlzeiten erst danach eingekauft.
	 *
	 * @param list<string> $dates
	 */
	private function requireEarlyTrip(Camp $camp, array $dates): void {
		if ($dates === [] || min($dates) > $camp->getStartDate()) {
			throw new \InvalidArgumentException('Mindestens ein Einkauf muss spätestens am ersten Lagertag stattfinden, sonst fehlt das Essen für die ersten Mahlzeiten.');
		}
	}

	private function newItem(ShoppingTrip $trip, string $key, string $kind): ShoppingItem {
		$item = new ShoppingItem();
		$item->setCampId((int)$trip->getCampId());
		$item->setTripId((int)$trip->getId());
		$item->setItemKey($key);
		$item->setKind($kind);
		return $item;
	}

	private function save(ShoppingItem $item): ShoppingItem {
		$item->setUpdatedAt($this->time->getTime());
		return $item->getId() === null ? $this->items->insert($item) : $this->items->update($item);
	}

	/** @return array{0: ShoppingTrip, 1: Camp} */
	private function trip(int $id, string $uid): array {
		try {
			$trip = $this->trips->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Einkauf nicht gefunden.');
		}
		return [$trip, $this->camps->require((int)$trip->getCampId(), $uid, Permission::EditCatering)];
	}

	private function extra(int $id, string $uid): ShoppingItem {
		try {
			$item = $this->items->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Posten nicht gefunden.');
		}
		if ($item->getKind() !== ShoppingItem::EXTRA) {
			throw new NotFoundException('Posten nicht gefunden.');
		}
		$this->camps->require((int)$item->getCampId(), $uid, Permission::EditCatering);
		return $item;
	}
}
