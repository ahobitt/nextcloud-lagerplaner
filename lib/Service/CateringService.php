<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Lagerplaner\Db\Camp;
use OCA\Lagerplaner\Db\DietNeed;
use OCA\Lagerplaner\Db\DietNeedMapper;
use OCA\Lagerplaner\Db\Meal;
use OCA\Lagerplaner\Db\MealMapper;
use OCA\Lagerplaner\Db\Task;
use OCA\Lagerplaner\Db\TaskMapper;
use OCA\Lagerplaner\Db\Transaction;
use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Catering\DietaryCombination;
use OCA\Lagerplaner\Domain\Catering\DietaryNeedsInput;
use OCA\Lagerplaner\Domain\Catering\DietaryProfile;
use OCA\Lagerplaner\Domain\Catering\InvalidCateringInput;
use OCA\Lagerplaner\Domain\Catering\MealPlanProposal;
use OCA\Lagerplaner\Domain\Catering\MealVariantPlanInput;
use OCA\Lagerplaner\Domain\Catering\PlanIssue;
use OCA\Lagerplaner\Domain\Catering\PortionCalculator;
use OCA\Lagerplaner\Domain\Catering\Requirement;
use OCA\Lagerplaner\Domain\Catering\SevereAllergies;
use OCA\Lagerplaner\Domain\Provenance\Origin;
use OCA\Lagerplaner\Domain\Recipes\Allergen;
use OCA\Lagerplaner\Domain\Recipes\RecipeScaler;
use OCA\Lagerplaner\Domain\Provenance\ReviewState;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Küchenplanung: aggregierte Ernährungsangaben, Menüvarianten je Mahlzeit und das
 * organisatorische Allergieverfahren (Konzept 8.5, 9.8, 9.10).
 *
 * Gespeichert werden nur Anzahlen. Die personenbezogene Allergieliste bleibt in Hitobito.
 * Sehen und bearbeiten dürfen nur Lagerleitung und Küchenteam (Konzept 18.1, 18.3).
 */
class CateringService {
	public function __construct(
		private CampService $camps,
		private DietNeedMapper $needs,
		private MealMapper $meals,
		private TaskMapper $tasks,
		private Transaction $transaction,
		private ITimeFactory $time,
		private RecipeService $recipes,
	) {
	}

	/** @return array<string, mixed> Ernährungsangaben, Portionen, Hinweise und Planungsstand je Mahlzeit */
	public function overview(int $campId, string $uid): array {
		$this->camps->require($campId, $uid, Permission::ViewCatering);
		[$profile, $severe] = $this->load($campId);

		$groups = $this->camps->groups($campId);
		$participants = array_sum(array_map(static fn ($g): int => (int)$g->getEstimatedCount(), $groups));
		$counts = [];
		$factors = [];
		foreach ($groups as $group) {
			$counts[$group->getGroupKey()] = (int)$group->getEstimatedCount();
			$factors[$group->getGroupKey()] = (float)$group->getPortionFactor();
		}

		$issues = $severe->consistencyIssues($profile);
		if ($profile->total() > 0) {
			$issues = [...$profile->checkAgainstParticipants($participants), ...$issues];
		}

		$recipes = $this->recipes->recipesById($campId);
		$checked = $this->recipes->checkedRecipeIds($campId);
		$meals = [];
		foreach ($this->meals->findByCamp($campId) as $meal) {
			$plan = MealVariantPlanInput::fromJson($meal->getVariantPlan());
			$meals[] = $meal->jsonSerialize() + [
				'status' => $meal->getCancelled() ? 'cancelled' : $this->status($plan, $profile, $recipes, $checked),
				'dish' => $plan->mainRecipeId() !== null && isset($recipes[$plan->mainRecipeId()]) ? $recipes[$plan->mainRecipeId()]->title : null,
				'dishId' => $plan->mainRecipeId(),
				// Einfacher Plan: ein Gericht für alle, darunter höchstens ergänzte Varianten
				'simple' => $plan->isEmpty() || $plan->everyone !== null,
				'variantCount' => count($plan->variants),
				'recipeTitles' => array_values(array_filter(array_map(
					static fn (int $id): ?string => isset($recipes[$id]) ? $recipes[$id]->title : null,
					array_values(array_unique($plan->recipes)),
				))),
			];
		}

		return [
			'needs' => $this->needsArray($profile, $severe),
			'participants' => $participants,
			'standardPortions' => (new PortionCalculator())->standardPortions($counts, $factors),
			'issues' => array_map(static fn (PlanIssue $i): array => $i->toArray(), $issues),
			'meals' => $meals,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws InvalidCateringInput
	 */
	public function saveNeeds(int $campId, string $uid, array $data): array {
		$camp = $this->camps->require($campId, $uid, Permission::EditCatering);
		$input = DietaryNeedsInput::fromArray($data);
		$now = $this->time->getTime();

		$this->transaction->run(function () use ($camp, $campId, $input, $now): void {
			$this->needs->deleteByCamp($campId);
			foreach ($input->profile->combinations() as $combination) {
				$this->insertNeed($campId, DietNeed::COMBINATION, $combination->key, $combination->count, $now);
			}
			foreach ($input->severe->reported() as $allergen => $count) {
				$this->insertNeed($campId, DietNeed::SEVERE, $allergen, $count, $now);
			}
			$this->syncAllergyTask($camp, $input->severe, $now);
			$this->camps->touch($campId, $now);
		});

		return $this->overview($campId, $uid);
	}

	/** @return array<string, mixed> */
	public function mealPlan(int $mealId, string $uid): array {
		$meal = $this->meal($mealId, $uid, Permission::ViewCatering);
		$campId = (int)$meal->getCampId();
		[$profile, $severe] = $this->load($campId);
		$plan = MealVariantPlanInput::fromJson($meal->getVariantPlan());
		$recipes = $this->recipes->recipesById($campId);
		$checked = $this->recipes->checkedRecipeIds($campId);
		$evaluation = $plan->isEmpty() ? null : $plan->evaluate($profile, $recipes, $checked);

		return [
			'meal' => $meal->jsonSerialize(),
			'plan' => $plan->toArray(),
			'evaluation' => $evaluation,
			'needs' => $this->needsArray($profile, $severe),
			'recipes' => $this->recipes->list($campId, $uid),
			'quantities' => $evaluation === null ? [] : $this->quantities($campId, $plan, $evaluation['headcounts'], $recipes),
			'severeRecipeHints' => $this->severeRecipeHints($plan, $severe, $recipes, $checked),
		];
	}

	/**
	 * Zutatenmengen je Variante. Die Portionen einer Variante sind ein Näherungswert:
	 * Personen der Variante mal durchschnittlicher Portionsfaktor des Lagers, weil die
	 * Bedarfskombinationen nicht nach Altersstufen getrennt sind (Konzept 8.6).
	 *
	 * @param array<string, int> $headcounts
	 * @param array<int, \OCA\Lagerplaner\Domain\Recipes\Recipe> $recipes
	 * @return list<array<string, mixed>>
	 */
	private function quantities(int $campId, MealVariantPlanInput $plan, array $headcounts, array $recipes): array {
		$averageFactor = $this->averageFactor($campId);

		$result = [];
		foreach ($plan->variants as $variant) {
			$recipe = $recipes[$plan->recipes[$variant->key] ?? 0] ?? null;
			$persons = $headcounts[$variant->key] ?? 0;
			if ($recipe === null || $persons === 0) {
				continue;
			}
			$variantPortions = round($persons * $averageFactor, 1);
			$result[] = [
				'variantKey' => $variant->key,
				'recipeTitle' => $recipe->title,
				'persons' => $persons,
				'portions' => $variantPortions,
				'ingredients' => (new RecipeScaler())->scale($recipe, max(0.1, $variantPortions)),
			];
		}
		return $result;
	}

	/** Durchschnittlicher Portionsfaktor aller Personen des Lagers; ohne Personen 1,0. */
	private function averageFactor(int $campId): float {
		$people = 0;
		$portions = 0.0;
		foreach ($this->camps->groups($campId) as $group) {
			$people += (int)$group->getEstimatedCount();
			$portions += (int)$group->getEstimatedCount() * (float)$group->getPortionFactor();
		}
		return $people > 0 ? $portions / $people : 1.0;
	}

	/**
	 * Bedarf für die Einkaufsliste: je selbst gekochter Mahlzeit und Variante mit Rezept,
	 * wie viele Standardportionen gekocht werden (Konzept 10.1). Ohne Berechtigungsprüfung;
	 * der Aufrufer prüft sie.
	 *
	 * @return array{demands: list<\OCA\Lagerplaner\Domain\Shopping\Demand>, mealsWithoutRecipe: int, meals: int}
	 */
	public function demands(int $campId): array {
		[$profile] = $this->load($campId);
		$recipes = $this->recipes->recipesById($campId);
		$checked = $this->recipes->checkedRecipeIds($campId);
		$averageFactor = $this->averageFactor($campId);
		$demands = [];
		$withoutRecipe = 0;
		$count = 0;
		foreach ($this->meals->findByCamp($campId) as $meal) {
			if (!$meal->getSelfCatered() || $meal->getCancelled()) {
				continue;
			}
			$count++;
			$plan = MealVariantPlanInput::fromJson($meal->getVariantPlan());
			$headcounts = $plan->isEmpty() ? [] : $plan->evaluate($profile, $recipes, $checked)['headcounts'];
			$found = false;
			foreach ($plan->variants as $variant) {
				$recipe = $recipes[$plan->recipes[$variant->key] ?? 0] ?? null;
				$persons = $headcounts[$variant->key] ?? 0;
				if ($recipe !== null && $persons > 0) {
					$found = true;
					// Allergen-Zusagen der Variante machen ihre Zutaten zu eigenen Produkten.
					$promises = array_values(array_map(
						static fn (Requirement $r): string => $r->value,
						array_filter($variant->declared, static fn (Requirement $r): bool => $r->kind() === 'allergen'),
					));
					$demands[] = new \OCA\Lagerplaner\Domain\Shopping\Demand($meal->getDay(), $meal->getMealType(), $recipe, $persons * $averageFactor, $promises);
				}
			}
			if (!$found) {
				$withoutRecipe++;
			}
		}
		return ['demands' => $demands, 'mealsWithoutRecipe' => $withoutRecipe, 'meals' => $count];
	}

	/**
	 * Gründe, warum aus der Küchenplanung berechnete Mengen noch vorläufig sind.
	 * Ohne Berechtigungsprüfung; der Aufrufer prüft sie.
	 *
	 * @return list<string>
	 */
	public function dataQuality(int $campId): array {
		[$profile, $severe] = $this->load($campId);
		$participants = array_sum(array_map(static fn ($g): int => (int)$g->getEstimatedCount(), $this->camps->groups($campId)));
		$reasons = [];
		if ($profile->total() === 0) {
			$reasons[] = 'Es sind noch keine Ernährungsangaben erfasst. Ohne sie werden für keine Mahlzeit Mengen berechnet.';
		} elseif ($profile->total() !== $participants) {
			$reasons[] = sprintf('Die Ernährungsangaben umfassen %d Personen, angemeldet sind %d. Die Mengen beruhen auf %d Personen.',
				$profile->total(), $participants, $profile->total());
		}
		$unresolved = 0;
		foreach ($profile->combinations() as $combination) {
			$unresolved += $combination->isUnresolved() ? $combination->count : 0;
		}
		if ($unresolved > 0) {
			$reasons[] = sprintf('%d Person(en) mit ungeklärten Ernährungsangaben.', $unresolved);
		}
		// Schwere Allergien ohne passende Bedarfskombination (Konzept 9.10).
		foreach ($severe->consistencyIssues($profile) as $issue) {
			$reasons[] = $issue->message;
		}

		$recipes = $this->recipes->recipesById($campId);
		$checked = $this->recipes->checkedRecipeIds($campId);
		$withErrors = 0;
		$unplanned = 0;
		$missingMeals = 0;
		$missingPersons = 0;
		foreach ($this->meals->findByCamp($campId) as $meal) {
			if (!$meal->getSelfCatered() || $meal->getCancelled()) {
				continue;
			}
			$plan = MealVariantPlanInput::fromJson($meal->getVariantPlan());
			if ($plan->isEmpty()) {
				$unplanned++;
				continue;
			}
			$evaluation = $plan->evaluate($profile, $recipes, $checked);
			$withErrors += $evaluation['hasErrors'] ? 1 : 0;
			$missing = 0;
			foreach ($plan->variants as $variant) {
				if (!isset($recipes[$plan->recipes[$variant->key] ?? 0])) {
					$missing += $evaluation['headcounts'][$variant->key] ?? 0;
				}
			}
			if ($missing > 0) {
				$missingMeals++;
				$missingPersons += $missing;
			}
		}
		if ($unplanned > 0) {
			$reasons[] = sprintf('%d selbst gekochte Mahlzeit(en) haben noch keine Menüvarianten und fehlen in der Einkaufsliste.', $unplanned);
		}
		if ($missingMeals > 0) {
			$reasons[] = sprintf('Bei %d Mahlzeit(en) haben Menüvarianten mit zusammen %d Portionen noch kein Rezept; diese Portionen fehlen in der Einkaufsliste.', $missingMeals, $missingPersons);
		}
		if ($withErrors > 0) {
			$reasons[] = sprintf('%d Mahlzeit(en) haben Fehler in den Menüvarianten, etwa ungeprüfte Allergenangaben oder nicht zugeordnete Bedarfskombinationen.', $withErrors);
		}
		return $reasons;
	}

	/**
	 * Prüfhinweise, wenn ein Rezept der Mahlzeit ein gemeldetes schweres Allergen enthält (Konzept 9.10).
	 *
	 * @param array<int, \OCA\Lagerplaner\Domain\Recipes\Recipe> $recipes
	 * @param list<int> $checked
	 * @return list<string>
	 */
	private function severeRecipeHints(MealVariantPlanInput $plan, SevereAllergies $severe, array $recipes, array $checked): array {
		$hints = [];
		if ($severe->requiresProcedure()) {
			foreach (array_unique($plan->recipes) as $recipeId) {
				if (isset($recipes[$recipeId]) && !in_array($recipeId, $checked, true)) {
					$hints[] = sprintf('Die Allergenangaben von „%s“ sind nicht geprüft. Bei gemeldeten schweren Allergien vor dem Kochen prüfen.', $recipes[$recipeId]->title);
				}
			}
		}
		foreach ($severe->reported() as $key => $count) {
			$allergen = Allergen::fromSevereKey($key);
			foreach (array_unique($plan->recipes) as $recipeId) {
				$recipe = $recipes[$recipeId] ?? null;
				if ($allergen !== null && $recipe !== null && $recipe->containsAllergen($allergen)) {
					$hints[] = sprintf('„%s“ enthält %s. Schwere Allergie gemeldet (%d): Portion nach dem Allergieverfahren getrennt zubereiten und gekennzeichnet ausgeben.',
						$recipe->title, $allergen->label(), $count);
				}
			}
		}
		return $hints;
	}

	/**
	 * Speichert den Variantenplan. Die Mahlzeit gilt danach als bearbeitet und wird
	 * von einem erneuten Assistentenlauf nicht mehr verändert oder entfernt.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws InvalidCateringInput
	 */
	/**
	 * Einfacher Speiseplan: plant jede selbst gekochte Mahlzeit ohne Plan mit einem Gericht
	 * für alle (MealPlanProposal) und ergänzt bei vorhandenen Plänen mit Gericht für alle
	 * eigene Varianten für Personen, denen das Gericht nicht passt. Von der Küche gewählte
	 * Gerichte bleiben unverändert. Fehlende Grundrezepte werden ins Lager übernommen.
	 *
	 * @return array{planned: int, completed: int}
	 */
	public function proposeMealPlan(int $campId, string $uid): array {
		$this->camps->require($campId, $uid, Permission::EditCatering);
		$this->recipes->import($campId, $uid, MealPlanProposal::templates());
		$byTemplate = [];
		foreach ($this->recipes->list($campId, $uid) as $summary) {
			if ($summary['templateKey'] !== null) {
				$byTemplate[$summary['templateKey']] ??= (int)$summary['id'];
			}
		}
		$recipes = $this->recipes->recipesById($campId);
		$alternatives = array_values(array_filter(array_map(static fn (string $k): ?int => $byTemplate[$k] ?? null, MealPlanProposal::ALTERNATIVES)));
		[$profile] = $this->load($campId);
		$meals = array_values(array_filter($this->meals->findByCamp($campId), static fn (Meal $m): bool => $m->getSelfCatered() && !$m->getCancelled()));
		usort($meals, static fn (Meal $a, Meal $b): int => [$a->getDay(), $a->getMealTime()] <=> [$b->getDay(), $b->getMealTime()]);
		$proposal = new MealPlanProposal();
		$keys = $proposal->choose(array_map(static fn (Meal $m): array => ['day' => $m->getDay(), 'type' => $m->getMealType(), 'portable' => (bool)$m->getPortable()], $meals));

		$planned = 0;
		$completed = 0;
		$now = $this->time->getTime();
		foreach ($meals as $index => $meal) {
			$existing = MealVariantPlanInput::fromJson($meal->getVariantPlan());
			if ($existing->isEmpty()) {
				$mainId = $byTemplate[$keys[$index]] ?? null;
				if ($mainId === null || !isset($recipes[$mainId])) {
					continue;
				}
				$data = $proposal->plan($mainId, $recipes, $alternatives, $profile);
				$planned++;
			} elseif ($existing->everyone !== null && $existing->mainRecipeId() !== null && isset($recipes[$existing->mainRecipeId()])) {
				$data = $proposal->plan($existing->mainRecipeId(), $recipes, $alternatives, $profile, $existing);
				if (MealVariantPlanInput::fromArray($data)->toArray() === $existing->toArray()) {
					continue;
				}
				$completed++;
			} else {
				continue;
			}
			// Ein Vorschlag, keine Bearbeitung: Der Assistent darf die Mahlzeit weiter anpassen.
			$meal->setVariantPlan(json_encode(MealVariantPlanInput::fromArray($data)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
			$this->meals->update($meal);
		}
		if ($planned + $completed > 0) {
			$this->camps->touch($campId, $now);
		}
		return ['planned' => $planned, 'completed' => $completed];
	}

	/** Das Gericht einer Mahlzeit für alle wechseln; Varianten für Bedarfe, die es nicht abdeckt, kommen dazu. */
	public function setMainDish(int $mealId, string $uid, int $recipeId): array {
		$meal = $this->meal($mealId, $uid, Permission::EditCatering);
		$campId = (int)$meal->getCampId();
		$recipes = $this->recipes->recipesById($campId);
		if (!isset($recipes[$recipeId])) {
			throw new InvalidCateringInput(['recipeId' => 'Dieses Rezept gehört nicht zum Lager.']);
		}
		$byTemplate = [];
		foreach ($this->recipes->list($campId, $uid) as $summary) {
			if ($summary['templateKey'] !== null) {
				$byTemplate[$summary['templateKey']] ??= (int)$summary['id'];
			}
		}
		$alternatives = array_values(array_filter(array_map(static fn (string $k): ?int => $byTemplate[$k] ?? null, MealPlanProposal::ALTERNATIVES)));
		[$profile] = $this->load($campId);
		$existing = MealVariantPlanInput::fromJson($meal->getVariantPlan());
		// Ein eigener Plan ohne Gericht für alle wird durch einen einfachen ersetzt.
		$base = $existing->everyone !== null ? $existing : null;
		$data = (new MealPlanProposal())->plan($recipeId, $recipes, $alternatives, $profile, $base);
		return $this->saveMealPlan($mealId, $uid, $data);
	}

	/**
	 * Gerichte für die Programmansicht: je Mahlzeit nur der Name des Gerichts für alle,
	 * ohne Varianten oder Ernährungsangaben (Konzept 19.3).
	 *
	 * @return array<int, string> Mahlzeit-ID => Gericht
	 */
	public function dishes(int $campId, string $uid): array {
		$this->camps->require($campId, $uid, Permission::View);
		$recipes = $this->recipes->recipesById($campId);
		$dishes = [];
		foreach ($this->meals->findByCamp($campId) as $meal) {
			$main = MealVariantPlanInput::fromJson($meal->getVariantPlan())->mainRecipeId();
			if ($main !== null && isset($recipes[$main]) && $meal->getSelfCatered() && !$meal->getCancelled()) {
				$dishes[(int)$meal->getId()] = $recipes[$main]->title;
			}
		}
		return $dishes;
	}

	/**
	 * Eine Mahlzeit entfällt oder findet wieder statt. Entfallene Mahlzeiten zählen weder für
	 * Mengen noch für den Einkauf und machen die Einkaufsliste nicht vorläufig.
	 */
	public function setCancelled(int $mealId, string $uid, bool $cancelled): void {
		$meal = $this->meal($mealId, $uid, Permission::EditCatering);
		$now = $this->time->getTime();
		$meal->setCancelled($cancelled);
		// Eine bewusste Entscheidung der Küche: Der Assistent ändert die Mahlzeit danach nicht mehr.
		$meal->markUserModified($now);
		$this->meals->update($meal);
		$this->camps->touch((int)$meal->getCampId(), $now);
	}

	public function saveMealPlan(int $mealId, string $uid, array $data): array {
		$meal = $this->meal($mealId, $uid, Permission::EditCatering);
		$plan = MealVariantPlanInput::fromArray($data);
		$known = $this->recipes->recipesById((int)$meal->getCampId());
		foreach ($plan->recipes as $variantKey => $recipeId) {
			if (!isset($known[$recipeId])) {
				throw new InvalidCateringInput(["variants.$variantKey.recipeId" => 'Dieses Rezept gehört nicht zum Lager.']);
			}
		}
		$now = $this->time->getTime();
		$meal->setVariantPlan($plan->isEmpty() ? null : json_encode($plan->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$meal->markUserModified($now);
		$this->meals->update($meal);
		$this->camps->touch((int)$meal->getCampId(), $now);
		return $this->mealPlan($mealId, $uid);
	}

	/**
	 * Überträgt den Variantenplan auf weitere Mahlzeiten: gleiche Mahlzeitart oder alle.
	 * Vorhandene Pläne bleiben erhalten, außer $overwrite ist gesetzt.
	 *
	 * @return int Anzahl geänderter Mahlzeiten
	 */
	public function copyMealPlan(int $mealId, string $uid, string $scope, bool $overwrite = false): int {
		if (!in_array($scope, ['same_type', 'all'], true)) {
			throw new \InvalidArgumentException('Unbekannter Bereich.');
		}
		$source = $this->meal($mealId, $uid, Permission::EditCatering);
		if (MealVariantPlanInput::fromJson($source->getVariantPlan())->isEmpty()) {
			throw new \InvalidArgumentException('Diese Mahlzeit hat noch keine Menüvarianten.');
		}
		$now = $this->time->getTime();

		return $this->transaction->run(function () use ($source, $scope, $overwrite, $now): int {
			$changed = 0;
			foreach ($this->meals->findByCamp((int)$source->getCampId()) as $meal) {
				if ($meal->getId() === $source->getId()
					|| ($scope === 'same_type' && $meal->getMealType() !== $source->getMealType())
					|| (!$overwrite && $meal->getVariantPlan() !== null && $meal->getVariantPlan() !== '')) {
					continue;
				}
				$meal->setVariantPlan($source->getVariantPlan());
				$meal->markUserModified($now);
				$this->meals->update($meal);
				$changed++;
			}
			$this->camps->touch((int)$source->getCampId(), $now);
			return $changed;
		});
	}

	/** @return array{0: DietaryProfile, 1: SevereAllergies} */
	private function load(int $campId): array {
		$combinations = [];
		$severe = [];
		foreach ($this->needs->findByCamp($campId) as $need) {
			if ($need->getKind() === DietNeed::COMBINATION) {
				try {
					$combinations[] = DietaryCombination::fromKey($need->getNeedKey(), (int)$need->getCount());
				} catch (\InvalidArgumentException) {
					// Unbekannte Schlüssel aus älteren Versionen werden übergangen.
				}
			} elseif (isset(SevereAllergies::ALLERGENS[$need->getNeedKey()])) {
				$severe[$need->getNeedKey()] = (int)$need->getCount();
			}
		}
		return [new DietaryProfile($combinations), new SevereAllergies($severe)];
	}

	/** @return array<string, mixed> */
	private function needsArray(DietaryProfile $profile, SevereAllergies $severe): array {
		return [
			'combinations' => array_map(static fn (DietaryCombination $c): array => [
				'key' => $c->key,
				'label' => $c->label(),
				'count' => $c->count,
				'requirements' => array_map(static fn (Requirement $r): string => $r->value, $c->requirements),
			], $profile->combinations()),
			'total' => $profile->total(),
			'totalsPerRequirement' => $profile->totalsPerRequirement(),
			'severe' => array_map(
				static fn (string $key): array => ['key' => $key, 'label' => SevereAllergies::label($key), 'count' => $severe->countsByAllergen[$key] ?? 0],
				array_keys(SevereAllergies::ALLERGENS),
			),
			'requirements' => array_map(
				static fn (Requirement $r): array => ['key' => $r->value, 'label' => $r->label(), 'kind' => $r->kind()],
				Requirement::cases(),
			),
		];
	}

	/**
	 * none: kein Plan; ok; warning: nur Hinweise; error: mindestens ein Fehler
	 *
	 * @param array<int, \OCA\Lagerplaner\Domain\Recipes\Recipe> $recipes
	 * @param list<int> $checked
	 */
	private function status(MealVariantPlanInput $plan, DietaryProfile $profile, array $recipes, array $checked): string {
		if ($plan->isEmpty()) {
			return 'none';
		}
		$evaluation = $plan->evaluate($profile, $recipes, $checked);
		if ($evaluation['hasErrors']) {
			return 'error';
		}
		return $evaluation['issues'] === [] ? 'ok' : 'warning';
	}

	/**
	 * Pflichtaufgabe „Allergieverfahren einrichten“ anlegen, sobald eine schwere Allergie
	 * gemeldet ist. Entfällt die Meldung wieder, wird eine noch unbearbeitete Aufgabe entfernt.
	 */
	private function syncAllergyTask(Camp $camp, SevereAllergies $severe, int $now): void {
		$ruleKey = 'task|' . SevereAllergies::TASK_KEY;
		$existing = null;
		foreach ($this->tasks->findByCampIncludingDeleted((int)$camp->getId()) as $task) {
			if ($task->getRuleKey() === $ruleKey) {
				$existing = $task;
				break;
			}
		}

		if (!$severe->requiresProcedure()) {
			if ($existing !== null && $existing->getStatus() === Task::STATUS_OPEN && $existing->getUserModifiedAt() === null) {
				$this->tasks->delete($existing);
			}
			return;
		}
		if ($existing !== null) {
			if ($existing->getDeletedAt() !== null) {
				$existing->markRestored($now);
				$this->tasks->update($existing);
			}
			return;
		}

		$today = DateTimeImmutable::createFromMutable($this->time->getDateTime('today', new DateTimeZone('UTC')));
		$start = new DateTimeImmutable($camp->getStartDate(), new DateTimeZone('UTC'));
		$proposal = $severe->mandatoryTask($start, $today);
		if ($proposal === null) {
			return;
		}
		$task = new Task();
		$task->setCampId((int)$camp->getId());
		$task->fillFrom($proposal);
		$task->setOrigin(Origin::Template->value);
		$task->setReviewState(ReviewState::Proposal->value);
		$task->setRuleKey($proposal->ruleKey());
		$task->setFingerprint($proposal->fingerprint());
		$task->setTemplateRef($proposal->templateRef());
		$task->setCreatedAt($now);
		$task->setUpdatedAt($now);
		$this->tasks->insert($task);
	}

	private function insertNeed(int $campId, string $kind, string $key, int $count, int $now): void {
		$need = new DietNeed();
		$need->setCampId($campId);
		$need->setKind($kind);
		$need->setNeedKey($key);
		$need->setCount($count);
		$need->setUpdatedAt($now);
		$this->needs->insert($need);
	}

	private function meal(int $id, string $uid, Permission $permission): Meal {
		try {
			$meal = $this->meals->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Mahlzeit nicht gefunden.');
		}
		$this->camps->require((int)$meal->getCampId(), $uid, $permission);
		return $meal;
	}
}
