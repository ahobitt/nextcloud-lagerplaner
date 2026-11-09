<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Db\CampRecipe;
use OCA\Lagerplaner\Db\CampRecipeMapper;
use OCA\Lagerplaner\Db\MealMapper;
use OCA\Lagerplaner\Db\Transaction;
use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Catering\InvalidCateringInput;
use OCA\Lagerplaner\Domain\Catering\MealVariantPlanInput;
use OCA\Lagerplaner\Domain\Recipes\Allergen;
use OCA\Lagerplaner\Domain\Recipes\Recipe;
use OCA\Lagerplaner\Domain\Recipes\RecipeScaler;
use OCA\Lagerplaner\Recipes\StarterRecipes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Rezepte eines Lagers (Konzept 9.6). Ansehen dürfen alle im Team, bearbeiten
 * Lagerleitung und Küchenteam. Grundrezepte lassen sich in ein Lager übernehmen.
 */
class RecipeService {
	public const MAX_RECIPES = 200;

	public function __construct(
		private CampService $camps,
		private CampRecipeMapper $recipes,
		private MealMapper $meals,
		private StarterRecipes $starter,
		private Transaction $transaction,
		private ITimeFactory $time,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function list(int $campId, string $uid): array {
		$this->camps->require($campId, $uid, Permission::View);
		return array_map(static fn (CampRecipe $r): array => $r->summary(), $this->recipes->findByCamp($campId));
	}

	/** @return array<string, mixed> Rezept, auf Wunsch mit hochgerechneten Mengen */
	public function get(int $id, string $uid, ?float $portions = null): array {
		$record = $this->record($id, $uid, Permission::View);
		$recipe = $record->recipe();
		return [
			'id' => $record->getId(),
			'templateKey' => $record->getTemplateKey(),
			'allergensChecked' => $record->isAllergensChecked(),
			'allergensCheckedAt' => $record->getAllergensCheckedAt(),
			'recipe' => $recipe->toArray(),
			'portions' => $portions,
			'scaled' => $portions === null ? null : (new RecipeScaler())->scale($recipe, $portions),
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws InvalidCateringInput
	 */
	public function create(int $campId, string $uid, array $data): array {
		$this->camps->require($campId, $uid, Permission::EditCatering);
		if (count($this->recipes->findByCamp($campId)) >= self::MAX_RECIPES) {
			throw new \InvalidArgumentException('Ein Lager kann höchstens ' . self::MAX_RECIPES . ' Rezepte haben.');
		}
		$record = $this->newRecord($campId, $uid, Recipe::fromArray($data), null);
		$record->markAllergensChecked(($data['allergensChecked'] ?? false) === true ? $uid : null, $this->time->getTime());
		return $this->recipes->insert($record)->summary();
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws InvalidCateringInput
	 */
	public function update(int $id, string $uid, array $data): array {
		$record = $this->record($id, $uid, Permission::EditCatering);
		$record->setRecipe(Recipe::fromArray($data));
		$record->setUpdatedAt($this->time->getTime());
		// Nach jeder Änderung muss die Prüfung der Allergenangaben neu bestätigt werden.
		$record->markAllergensChecked(($data['allergensChecked'] ?? false) === true ? $uid : null, $this->time->getTime());
		return $this->recipes->update($record)->summary();
	}

	/** Die Küche bestätigt, dass Zutaten und verwendete Produkte auf Allergene geprüft sind. */
	public function confirmAllergens(int $id, string $uid): array {
		$record = $this->record($id, $uid, Permission::EditCatering);
		$record->markAllergensChecked($uid, $this->time->getTime());
		return $this->recipes->update($record)->summary();
	}

	/** Löscht das Rezept und entfernt es aus den Menüvarianten des Lagers. */
	public function delete(int $id, string $uid): void {
		$record = $this->record($id, $uid, Permission::EditCatering);
		$this->transaction->run(function () use ($record): void {
			foreach ($this->meals->findByCamp((int)$record->getCampId()) as $meal) {
				$plan = MealVariantPlanInput::fromJson($meal->getVariantPlan())->toArray();
				$changed = false;
				foreach ($plan['variants'] as $i => $variant) {
					if ($variant['recipeId'] === $record->getId()) {
						$plan['variants'][$i]['recipeId'] = null;
						$changed = true;
					}
				}
				if ($changed) {
					$meal->setVariantPlan(json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
					$this->meals->update($meal);
				}
			}
			$this->recipes->delete($record);
		});
	}

	/** @return list<array<string, mixed>> Grundrezepte mit Hinweis, ob schon im Lager */
	public function library(int $campId, string $uid): array {
		$this->camps->require($campId, $uid, Permission::View);
		$imported = [];
		foreach ($this->recipes->findByCamp($campId) as $record) {
			if ($record->getTemplateKey() !== null) {
				$imported[$record->getTemplateKey()] = true;
			}
		}
		$result = [];
		foreach ($this->starter->all() as $key => $recipe) {
			$result[] = [
				'key' => $key,
				'title' => $recipe->title,
				'diet' => $recipe->diet->value,
				'contains' => array_map(static fn (Allergen $a): string => $a->value, $recipe->contains),
				'category' => $recipe->category,
				'imported' => isset($imported[$key]),
			];
		}
		return $result;
	}

	/**
	 * Übernimmt Grundrezepte als bearbeitbare Kopie ins Lager; schon übernommene werden übersprungen.
	 *
	 * @param mixed $keys Liste von Schlüsseln
	 * @return int Anzahl übernommener Rezepte
	 */
	public function import(int $campId, string $uid, mixed $keys): int {
		$this->camps->require($campId, $uid, Permission::EditCatering);
		$all = $this->starter->all();
		if (!is_array($keys) || $keys === [] || array_diff($keys, array_keys($all)) !== []) {
			throw new \InvalidArgumentException('Bitte wähle vorhandene Grundrezepte aus.');
		}
		$existing = [];
		foreach ($this->recipes->findByCamp($campId) as $record) {
			$existing[(string)$record->getTemplateKey()] = true;
		}
		return $this->transaction->run(function () use ($campId, $uid, $keys, $all, $existing): int {
			$count = 0;
			foreach (array_unique($keys) as $key) {
				if (!isset($existing[$key])) {
					$this->recipes->insert($this->newRecord($campId, $uid, $all[$key], $key));
					$count++;
				}
			}
			return $count;
		});
	}

	/** @return list<int> Rezepte mit bestätigten Allergenangaben */
	public function checkedRecipeIds(int $campId): array {
		return array_values(array_map(
			static fn (CampRecipe $r): int => (int)$r->getId(),
			array_filter($this->recipes->findByCamp($campId), static fn (CampRecipe $r): bool => $r->isAllergensChecked()),
		));
	}

	/** @return array<int, Recipe> Rezepte eines Lagers nach ID, für die Küchenplanung */
	public function recipesById(int $campId): array {
		$result = [];
		foreach ($this->recipes->findByCamp($campId) as $record) {
			$result[(int)$record->getId()] = $record->recipe();
		}
		return $result;
	}

	private function newRecord(int $campId, string $uid, Recipe $recipe, ?string $templateKey): CampRecipe {
		$now = $this->time->getTime();
		$record = new CampRecipe();
		$record->setCampId($campId);
		$record->setRecipe($recipe);
		$record->setTemplateKey($templateKey);
		$record->setCreatedBy($uid);
		$record->setCreatedAt($now);
		$record->setUpdatedAt($now);
		return $record;
	}

	private function record(int $id, string $uid, Permission $permission): CampRecipe {
		try {
			$record = $this->recipes->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Rezept nicht gefunden.');
		}
		$this->camps->require((int)$record->getCampId(), $uid, $permission);
		return $record;
	}
}
