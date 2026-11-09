<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use DateTimeImmutable;
use OCA\Lagerplaner\Cookbook\CookbookLibrary;
use OCA\Lagerplaner\Db\CampRecipe;
use OCA\Lagerplaner\Db\CampRecipeMapper;
use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Cookbook\CookbookRecipeReader;
use OCA\Lagerplaner\Domain\Cookbook\CookbookRecipeWriter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Anbindung an Nextcloud Cookbook (Konzept 9.6).
 *
 * Cookbook ist die Rezeptsammlung; ein Lager arbeitet mit einer Kopie, damit Mengen,
 * Menüvarianten und Allergenprüfung gleich bleiben, auch wenn sich das Rezept in Cookbook
 * später ändert. Die Verknüpfung bleibt erhalten: Änderungen werden erkannt und auf Wunsch
 * übernommen. Gelesen wird immer die Sammlung der handelnden Person.
 */
class CookbookService {
	public function __construct(
		private CampService $camps,
		private CookbookLibrary $library,
		private CampRecipeMapper $recipes,
		private RecipeService $recipeService,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Rezepte der eigenen Cookbook-Sammlung; mit Lager auch, welche dort schon übernommen sind.
	 *
	 * @return array{status: string, items: list<array<string, mixed>>}
	 */
	public function library(string $uid, ?int $campId = null): array {
		$status = $this->library->status($uid);
		if ($status !== CookbookLibrary::READY) {
			return ['status' => $status, 'items' => []];
		}
		$imported = [];
		if ($campId !== null) {
			$this->camps->require($campId, $uid, Permission::View);
			foreach ($this->recipes->findByCamp($campId) as $record) {
				if ($record->getCookbookId() !== null && $record->getCookbookOwner() === $uid) {
					$imported[(int)$record->getCookbookId()] = (int)$record->getId();
				}
			}
		}
		return ['status' => $status, 'items' => array_map(static fn (array $r): array => $r + ['importedAs' => $imported[$r['id']] ?? null], $this->library->list($uid))];
	}

	/** Wie das Rezept im Lager aussähe, mit allem, was geprüft werden sollte. */
	public function preview(string $uid, int $cookbookId): array {
		$this->requireReady($uid);
		return (new CookbookRecipeReader())->read($this->library->read($uid, $cookbookId)['data']);
	}

	/** @return array{id: int, review: list<string>} */
	public function import(int $campId, string $uid, int $cookbookId): array {
		$this->camps->require($campId, $uid, Permission::EditCatering);
		$this->requireReady($uid);
		foreach ($this->recipes->findByCamp($campId) as $record) {
			if ($record->getCookbookId() === $cookbookId && $record->getCookbookOwner() === $uid) {
				throw new \InvalidArgumentException('Dieses Rezept ist schon im Lager. Ändert es sich in Cookbook, lässt es sich dort aktualisieren.');
			}
		}
		$source = $this->library->read($uid, $cookbookId);
		$read = (new CookbookRecipeReader())->read($source['data']);
		$summary = $this->recipeService->create($campId, $uid, $read['recipe']);
		$this->link((int)$summary['id'], $cookbookId, $source['modified'], $uid);
		return ['id' => (int)$summary['id'], 'review' => $read['review']];
	}

	/**
	 * Übernommene Rezepte, die sich in Cookbook geändert haben oder dort fehlen. Geprüft werden
	 * nur Rezepte aus der eigenen Sammlung, weil andere Sammlungen nicht lesbar sind.
	 *
	 * @return array<int, string> Rezept-ID => changed | missing
	 */
	public function updates(int $campId, string $uid): array {
		$this->camps->require($campId, $uid, Permission::View);
		if ($this->library->status($uid) !== CookbookLibrary::READY) {
			return [];
		}
		$result = [];
		foreach ($this->recipes->findByCamp($campId) as $record) {
			if ($record->getCookbookId() === null || $record->getCookbookOwner() !== $uid) {
				continue;
			}
			try {
				$modified = $this->library->read($uid, (int)$record->getCookbookId())['modified'];
				if ($modified > (int)$record->getCookbookModified()) {
					$result[(int)$record->getId()] = 'changed';
				}
			} catch (NotFoundException) {
				$result[(int)$record->getId()] = 'missing';
			}
		}
		return $result;
	}

	/**
	 * Den aktuellen Stand aus Cookbook übernehmen. Ernährungsweise und Allergene bleiben aus
	 * dem Lager, weil Cookbook sie nicht kennt; die Bestätigung der Allergene entfällt, weil
	 * sich Zutaten geändert haben können.
	 *
	 * @return array{review: list<string>}
	 */
	public function refresh(int $recipeId, string $uid): array {
		$record = $this->record($recipeId);
		$this->camps->require((int)$record->getCampId(), $uid, Permission::EditCatering);
		if ($record->getCookbookId() === null) {
			throw new \InvalidArgumentException('Dieses Rezept ist nicht mit Cookbook verknüpft.');
		}
		if ($record->getCookbookOwner() !== $uid) {
			throw new ForbiddenException('Aktualisieren kann nur, wer das Rezept aus der eigenen Cookbook-Sammlung übernommen hat.');
		}
		$current = $this->recipeService->get($recipeId, $uid)['recipe'];
		$source = $this->library->read($uid, (int)$record->getCookbookId());
		$read = (new CookbookRecipeReader())->read($source['data']);
		$data = ['diet' => $current['diet'], 'contains' => $current['contains']] + $read['recipe'];
		$this->recipeService->update($recipeId, $uid, $data);
		$this->link($recipeId, (int)$record->getCookbookId(), $source['modified'], $uid);
		$review = array_values(array_filter($read['review'], static fn (string $r): bool => !str_contains($r, 'Ernährungsweise') && !str_contains($r, 'kennt keine Allergene')));
		$review[] = 'Ernährungsweise und Allergene sind aus dem Lager übernommen. Bitte die Allergene neu bestätigen, weil sich Zutaten geändert haben können.';
		return ['review' => $review];
	}

	/**
	 * Ein Lagerrezept in die eigene Cookbook-Sammlung speichern und damit verknüpfen.
	 *
	 * @return array{cookbookId: int}
	 */
	public function export(int $recipeId, string $uid): array {
		$record = $this->record($recipeId);
		$this->camps->require((int)$record->getCampId(), $uid, Permission::EditCatering);
		if ($record->getCookbookId() !== null) {
			throw new \InvalidArgumentException('Dieses Rezept ist schon mit Cookbook verknüpft.');
		}
		$this->requireReady($uid);
		$recipe = $record->recipe();
		$json = (new CookbookRecipeWriter())->write($recipe, (new DateTimeImmutable('@' . $this->time->getTime()))->format(DATE_ATOM));
		$created = $this->library->create($uid, $recipe->title, $json);
		$this->link($recipeId, $created['id'], $created['modified'], $uid);
		return ['cookbookId' => $created['id']];
	}

	private function link(int $recipeId, int $cookbookId, int $modified, string $uid): void {
		$record = $this->record($recipeId);
		$record->setCookbookId($cookbookId);
		$record->setCookbookModified($modified);
		$record->setCookbookOwner($uid);
		$this->recipes->update($record);
	}

	private function record(int $id): CampRecipe {
		try {
			/** @var CampRecipe */
			return $this->recipes->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Rezept nicht gefunden.');
		}
	}

	private function requireReady(string $uid): void {
		match ($this->library->status($uid)) {
			CookbookLibrary::READY => null,
			CookbookLibrary::NO_FOLDER => throw new \InvalidArgumentException('Cookbook hat noch keinen Rezeptordner. Bitte Cookbook einmal öffnen, dann legt es ihn an.'),
			default => throw new \InvalidArgumentException('Nextcloud Cookbook ist nicht installiert oder für dich nicht freigegeben.'),
		};
	}
}
