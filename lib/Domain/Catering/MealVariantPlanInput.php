<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

/**
 * Menüvarianten einer Mahlzeit und die Zuordnung der Bedarfskombinationen (Konzept 9.8).
 * Wird geprüft gespeichert; das gleiche Format dient als Speicherform.
 */
final class MealVariantPlanInput {
	public const MAX_VARIANTS = 8;
	public const MAX_LABEL = 60;

	/**
	 * @param list<MenuVariant> $variants
	 * @param array<string, string> $assignments Kombinationsschlüssel => Variantenschlüssel
	 * @param array<string, int> $recipes Variantenschlüssel => Rezept-ID
	 */
	private function __construct(
		public readonly array $variants,
		public readonly array $assignments,
		public readonly array $recipes = [],
		/** Variante für alle, die keiner anderen ausdrücklich zugeordnet sind */
		public readonly ?string $everyone = null,
	) {
	}

	public static function empty(): self {
		return new self([], []);
	}

	/**
	 * @param array<string, mixed> $data {variants: list<{key, label, satisfies}>, assignments: array<string, string>}
	 * @throws InvalidCateringInput
	 */
	public static function fromArray(array $data): self {
		$errors = [];
		$rawVariants = $data['variants'] ?? [];
		if (!is_array($rawVariants) || !array_is_list($rawVariants) || count($rawVariants) > self::MAX_VARIANTS) {
			throw new InvalidCateringInput(['variants' => 'Es sind höchstens ' . self::MAX_VARIANTS . ' Menüvarianten möglich.']);
		}

		$variants = [];
		$recipes = [];
		foreach ($rawVariants as $i => $entry) {
			$path = "variants.$i";
			$key = is_array($entry) ? ($entry['key'] ?? null) : null;
			if (!is_string($key) || preg_match('/^[a-z0-9_]{1,16}$/', $key) !== 1 || isset($variants[$key])) {
				$errors["$path.key"] = 'Ungültige oder doppelte Variantenkennung.';
				continue;
			}
			$label = is_string($entry['label'] ?? null) ? trim($entry['label']) : '';
			if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
				$errors["$path.label"] = 'Der Name der Variante muss zwischen 1 und ' . self::MAX_LABEL . ' Zeichen lang sein.';
				continue;
			}
			$satisfies = [];
			foreach (is_array($entry['satisfies'] ?? null) ? $entry['satisfies'] : [] as $value) {
				$requirement = is_string($value) ? Requirement::tryFrom($value) : null;
				if ($requirement === null) {
					$errors["$path.satisfies"] = 'Unbekannte Anforderung.';
					continue 2;
				}
				$satisfies[$requirement->value] = $requirement;
			}
			$recipeId = $entry['recipeId'] ?? null;
			if ($recipeId !== null && $recipeId !== '') {
				if (is_string($recipeId) && ctype_digit($recipeId)) {
					$recipeId = (int)$recipeId;
				}
				if (!is_int($recipeId) || $recipeId < 1) {
					$errors["$path.recipeId"] = 'Ungültiges Rezept.';
					continue;
				}
				$recipes[$key] = $recipeId;
			}
			$variants[$key] = new MenuVariant($key, $label, array_values($satisfies));
		}

		$assignments = [];
		$rawAssignments = $data['assignments'] ?? [];
		if (!is_array($rawAssignments)) {
			$errors['assignments'] = 'Ungültige Zuordnung.';
			$rawAssignments = [];
		}
		foreach ($rawAssignments as $combinationKey => $variantKey) {
			if ($variantKey === null || $variantKey === '') {
				continue;
			}
			try {
				$canonical = DietaryCombination::fromKey((string)$combinationKey, 0)->key;
			} catch (\InvalidArgumentException) {
				$errors["assignments.$combinationKey"] = 'Unbekannte Bedarfskombination.';
				continue;
			}
			if (!is_string($variantKey) || !isset($variants[$variantKey])) {
				$errors["assignments.$combinationKey"] = 'Die zugeordnete Variante gibt es nicht.';
				continue;
			}
			$assignments[$canonical] = $variantKey;
		}

		$everyone = $data['everyone'] ?? null;
		if ($everyone === '' ) {
			$everyone = null;
		}
		if ($everyone !== null && (!is_string($everyone) || !isset($variants[$everyone]))) {
			$errors['everyone'] = 'Die Variante für alle gibt es nicht.';
			$everyone = null;
		}

		if ($errors !== []) {
			throw new InvalidCateringInput($errors);
		}
		return new self(array_values($variants), $assignments, $recipes, $everyone);
	}

	public static function fromJson(?string $json): self {
		if ($json === null || $json === '') {
			return self::empty();
		}
		try {
			$data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
			return is_array($data) ? self::fromArray($data) : self::empty();
		} catch (\JsonException|InvalidCateringInput) {
			return self::empty();
		}
	}

	/**
	 * Zuordnungen einschließlich der Variante für alle: Wer keiner Variante ausdrücklich
	 * zugeordnet ist, isst die Variante für alle. Ob das passt, prüft die Auswertung wie bei
	 * jeder anderen Zuordnung.
	 *
	 * @return array<string, string>
	 */
	public function effectiveAssignments(DietaryProfile $profile): array {
		$assignments = $this->assignments;
		if ($this->everyone !== null) {
			foreach ($profile->combinations() as $combination) {
				$assignments[$combination->key] ??= $this->everyone;
			}
		}
		return $assignments;
	}

	/** Rezept der Variante für alle, sonst der ersten Variante: das Gericht der Mahlzeit. */
	public function mainRecipeId(): ?int {
		if ($this->everyone !== null && isset($this->recipes[$this->everyone])) {
			return $this->recipes[$this->everyone];
		}
		foreach ($this->variants as $variant) {
			if (isset($this->recipes[$variant->key])) {
				return $this->recipes[$variant->key];
			}
		}
		return null;
	}

	public function isEmpty(): bool {
		return $this->variants === [];
	}

	/** @return array{variants: list<array{key: string, label: string, satisfies: list<string>}>, assignments: array<string, string>} */
	public function toArray(): array {
		return [
			'variants' => array_map(fn (MenuVariant $v): array => [
				'key' => $v->key,
				'label' => $v->label,
				// Gespeichert wird die ausdrückliche Angabe der Küche, nicht die abgeleitete Hülle.
				'satisfies' => array_map(static fn (Requirement $r): string => $r->value, $v->declared),
				'recipeId' => $this->recipes[$v->key] ?? null,
			], $this->variants),
			'assignments' => $this->assignments,
			'everyone' => $this->everyone,
		];
	}

	/**
	 * Bewertung gegen die Bedarfskombinationen des Lagers und, falls vorhanden, gegen
	 * die Angaben der zugeordneten Rezepte (Konzept 9.8, 9.9).
	 *
	 * @param array<int, \OCA\Lagerplaner\Domain\Recipes\Recipe> $recipesById
	 * @param list<int> $checkedRecipeIds Rezepte, deren Allergenangaben die Küche bestätigt hat
	 * @return array{headcounts: array<string, int>, issues: list<array<string, ?string>>, hasErrors: bool}
	 */
	public function evaluate(DietaryProfile $profile, array $recipesById = [], array $checkedRecipeIds = []): array {
		$result = (new MenuVariantPlan())->evaluate($profile, $this->variants, $this->effectiveAssignments($profile));
		$issues = $result->issues;
		$compatibility = new \OCA\Lagerplaner\Domain\Recipes\RecipeCompatibility();
		foreach ($this->variants as $variant) {
			$recipeId = $this->recipes[$variant->key] ?? null;
			if ($recipeId === null) {
				// Ohne Rezept fehlen diese Portionen in Mengen und Einkaufsliste.
				$persons = $result->headcounts[$variant->key] ?? 0;
				if ($persons > 0) {
					$issues[] = new PlanIssue(PlanIssue::RECIPE_NOT_ASSIGNED, PlanIssue::WARNING,
						sprintf('„%s“ (%d Person%s) hat noch kein Rezept. Diese Portionen fehlen in Mengen und Einkaufsliste.',
							$variant->label, $persons, $persons === 1 ? '' : 'en'), null, $variant->key);
				}
				continue;
			}
			$recipe = $recipesById[$recipeId] ?? null;
			if ($recipe === null) {
				$issues[] = new PlanIssue(PlanIssue::RECIPE_MISSING, PlanIssue::WARNING,
					'Das Rezept für „' . $variant->label . '“ gibt es nicht mehr.', null, $variant->key);
				continue;
			}
			foreach ($compatibility->conflicts($recipe, $variant, in_array($recipeId, $checkedRecipeIds, true)) as $message) {
				$issues[] = new PlanIssue(PlanIssue::RECIPE_CONFLICT, PlanIssue::ERROR, $message, null, $variant->key);
			}
		}
		$hasErrors = false;
		foreach ($issues as $issue) {
			$hasErrors = $hasErrors || $issue->severity === PlanIssue::ERROR;
		}
		return [
			'headcounts' => $result->headcounts,
			'issues' => array_map(static fn (PlanIssue $i): array => $i->toArray(), $issues),
			'hasErrors' => $hasErrors,
		];
	}
}
