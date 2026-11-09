<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiClose, mdiPlus } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref, useId } from 'vue'
import { copyMealPlan, errorMessage, getMealPlan, saveMealPlan } from '../api.js'
import { mealLabels } from '../format.js'
import { quantityText } from '../recipes.js'
import { n, t } from '../l10n.js'

const props = defineProps({
	mealId: { type: Number, required: true },
})
const emit = defineEmits(['changed'])

const loading = ref(true)
const saving = ref(false)
const meal = ref(null)
const needs = ref(null)
const variants = ref([])
const assignments = ref({})
const evaluation = ref(null)
const dirty = ref(false)
const recipes = ref([])
const quantities = ref([])
const severeHints = ref([])
const idPrefix = `meal-${useId()}`

const combinations = computed(() => needs.value?.combinations ?? [])
const requirements = computed(() => needs.value?.requirements ?? [])
const severeReported = computed(() => (needs.value?.severe ?? []).filter((s) => s.count > 0))

onMounted(load)

async function load() {
	loading.value = true
	try {
		apply(await getMealPlan(props.mealId))
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		loading.value = false
	}
}

function apply(result) {
	meal.value = result.meal
	needs.value = result.needs
	variants.value = result.plan.variants.map((v) => ({ ...v, satisfies: [...v.satisfies], recipeId: v.recipeId ?? null }))
	assignments.value = { ...result.plan.assignments }
	evaluation.value = result.evaluation
	recipes.value = result.recipes ?? []
	quantities.value = result.quantities ?? []
	severeHints.value = result.severeRecipeHints ?? []
	dirty.value = false
}

function nextKey() {
	for (let i = 1; i <= 8; i++) {
		if (!variants.value.some((v) => v.key === `v${i}`)) {
			return `v${i}`
		}
	}
	return null
}

function addVariant(label = '', satisfies = []) {
	const key = nextKey()
	if (key === null) {
		showError(t('lagerplaner', 'A meal can have at most 8 variants.'))
		return null
	}
	variants.value = [...variants.value, { key, label, satisfies, recipeId: null }]
	dirty.value = true
	return key
}

function removeVariant(variant) {
	variants.value = variants.value.filter((v) => v !== variant)
	assignments.value = Object.fromEntries(Object.entries(assignments.value).filter(([, key]) => key !== variant.key))
	dirty.value = true
}

/**
 * Schlägt je Bedarfskombination eine Variante vor. Die Küche muss jede Angabe prüfen:
 * Ein Haken bedeutet, dass sie die Anforderung für diese Variante sicherstellt.
 */
function suggest() {
	variants.value = []
	assignments.value = {}
	const standard = addVariant(t('lagerplaner', 'Standard'))
	for (const combination of combinations.value) {
		if (['none', 'unanswered', 'clarify'].includes(combination.key)) {
			assignments.value[combination.key] = standard
			continue
		}
		const key = addVariant(combination.label, [...combination.requirements])
		if (key === null) {
			break
		}
		assignments.value[combination.key] = key
	}
	dirty.value = true
}

async function save() {
	saving.value = true
	try {
		apply(await saveMealPlan(props.mealId, {
			variants: variants.value.map((v) => ({ key: v.key, label: v.label, satisfies: v.satisfies, recipeId: v.recipeId || null })),
			assignments: assignments.value,
		}))
		showSuccess(t('lagerplaner', 'Menu variants saved'))
		emit('changed')
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		saving.value = false
	}
}

async function copy(scope) {
	try {
		const result = await copyMealPlan(props.mealId, scope)
		showSuccess(n('lagerplaner', 'Copied to %n meal. Meals with their own variants were left unchanged.', 'Copied to %n meals. Meals with their own variants were left unchanged.', result.changed))
		emit('changed')
	} catch (error) {
		showError(errorMessage(error))
	}
}

function variantLabel(key) {
	return variants.value.find((v) => v.key === key)?.label ?? key
}
</script>

<template>
	<div class="variants">
		<NcLoadingIcon v-if="loading" :size="24" />
		<template v-else>
			<NcNoteCard v-if="severeReported.length > 0" type="warning">
				{{ t('lagerplaner', 'Severe allergy reported: {list}. Hand out a labelled portion according to the allergy procedure.', {
					list: severeReported.map((s) => `${s.label} (${s.count})`).join(', '),
				}) }}
			</NcNoteCard>

			<p v-if="combinations.length === 0" class="variants__hint">
				{{ t('lagerplaner', 'Enter the dietary needs first. Then you can assign every combination to a menu variant.') }}
			</p>

			<template v-else>
				<p class="variants__hint">
					{{ t('lagerplaner', 'A tick means the kitchen makes sure this variant meets the requirement, including ingredients and preparation. Being vegan does not mean free of traces of milk or egg.') }}
				</p>

				<div v-for="variant in variants" :key="variant.key" class="variant">
					<div class="variant__head">
						<NcTextField v-model="variant.label" :label="t('lagerplaner', 'Name of the variant')" @update:model-value="dirty = true" />
						<NcButton variant="tertiary" :aria-label="t('lagerplaner', 'Remove variant {name}', { name: variant.label })" @click="removeVariant(variant)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiClose" />
							</template>
						</NcButton>
					</div>
					<div v-if="recipes.length > 0" class="variant__recipe">
						<label :for="`${idPrefix}-recipe-${variant.key}`">{{ t('lagerplaner', 'Recipe') }}</label>
						<select :id="`${idPrefix}-recipe-${variant.key}`" v-model="variant.recipeId" @change="dirty = true">
							<option :value="null">
								{{ t('lagerplaner', 'No recipe') }}
							</option>
							<option v-for="recipe in recipes" :key="recipe.id" :value="recipe.id">
								{{ recipe.title }}
							</option>
						</select>
					</div>
					<div class="variant__requirements">
						<NcCheckboxRadioSwitch
							v-for="requirement in requirements"
							:key="requirement.key"
							v-model="variant.satisfies"
							type="checkbox"
							:value="requirement.key"
							@update:model-value="dirty = true">
							{{ requirement.label }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>

				<div class="variants__buttons">
					<NcButton @click="addVariant()">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPlus" />
						</template>
						{{ t('lagerplaner', 'Add variant') }}
					</NcButton>
					<NcButton variant="tertiary" @click="suggest">
						{{ t('lagerplaner', 'Suggest one variant per combination') }}
					</NcButton>
				</div>

				<template v-if="variants.length > 0">
					<h4>{{ t('lagerplaner', 'Who gets which variant?') }}</h4>
					<table class="variants__assign">
						<tbody>
							<tr v-for="combination in combinations" :key="combination.key">
								<th scope="row">
									<label :for="`${idPrefix}-${combination.key}`">{{ combination.label }} ({{ combination.count }})</label>
								</th>
								<td>
									<select :id="`${idPrefix}-${combination.key}`" v-model="assignments[combination.key]" @change="dirty = true">
										<option :value="undefined">
											{{ t('lagerplaner', 'Not assigned') }}
										</option>
										<option v-for="variant in variants" :key="variant.key" :value="variant.key">
											{{ variant.label || variant.key }}
										</option>
									</select>
								</td>
							</tr>
						</tbody>
					</table>
				</template>

				<div v-if="evaluation && !dirty" class="variants__result">
					<h4>{{ t('lagerplaner', 'Result') }}</h4>
					<ul class="variants__counts">
						<li v-for="(count, key) in evaluation.headcounts" :key="key">
							{{ n('lagerplaner', '{variant}: %n person', '{variant}: %n people', count, { variant: variantLabel(key) }) }}
						</li>
					</ul>
					<ul class="variants__issues">
						<li v-for="issue in evaluation.issues" :key="issue.code + (issue.combinationKey ?? '')" :class="`variants__issue--${issue.severity}`">
							{{ issue.message }}
						</li>
					</ul>
					<p v-if="evaluation.issues.length === 0" class="variants__ok">
						{{ t('lagerplaner', 'Every combination has a suitable variant.') }}
					</p>
					<NcNoteCard v-for="hint in severeHints" :key="hint" type="warning" :text="hint" />

					<div v-for="entry in quantities" :key="entry.variantKey" class="variants__quantities">
						<h5>
							{{ t('lagerplaner', '{recipe} for {variant}: about {portions} standard portions ({persons} people)', {
								recipe: entry.recipeTitle,
								variant: variantLabel(entry.variantKey),
								portions: String(entry.portions).replace('.', ','),
								persons: entry.persons,
							}) }}
						</h5>
						<ul>
							<li v-for="(ingredient, index) in entry.ingredients" :key="index">
								<span class="variants__amount">{{ quantityText(ingredient) }}</span> {{ ingredient.name }}
							</li>
						</ul>
					</div>
					<p v-if="quantities.length > 0" class="variants__hint">
						{{ t('lagerplaner', 'Portions per variant are estimates: the people of a variant times the average portion factor of the camp.') }}
					</p>
				</div>

				<div class="variants__buttons">
					<NcButton variant="primary" :disabled="saving" @click="save">
						{{ t('lagerplaner', 'Save and check variants') }}
					</NcButton>
					<template v-if="evaluation && !dirty">
						<NcButton variant="tertiary" @click="copy('same_type')">
							{{ t('lagerplaner', 'Copy to all meals of this kind: {meal}', { meal: mealLabels()[meal.mealType] ?? meal.mealType }) }}
						</NcButton>
						<NcButton variant="tertiary" @click="copy('all')">
							{{ t('lagerplaner', 'Copy to all meals') }}
						</NcButton>
					</template>
				</div>
			</template>
		</template>
	</div>
</template>

<style scoped>
.variants {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large);
}

.variants__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.variants h4 {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
}

.variant {
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.variant__head {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	max-width: 480px;
}

.variant__requirements {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 3);
}

.variants__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.variants__assign th {
	padding-inline-end: calc(var(--default-grid-baseline) * 4);
	font-weight: normal;
	text-align: start;
}

.variants__assign select {
	min-height: var(--default-clickable-area);
	margin: 2px 0;
}

.variant__recipe {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin: var(--default-grid-baseline) 0;
}

.variant__recipe select {
	min-height: var(--default-clickable-area);
	margin: 0;
}

.variants__quantities h5 {
	margin: calc(var(--default-grid-baseline) * 3) 0 var(--default-grid-baseline);
	font-size: 1em;
}

.variants__quantities ul {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
	columns: 2 260px;
}

.variants__amount {
	font-variant-numeric: tabular-nums;
	font-weight: bold;
}

.variants__counts,
.variants__issues {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}

.variants__issue--error {
	color: var(--color-error-text);
}

.variants__issue--warning {
	color: var(--color-warning-text);
}

.variants__ok {
	margin: 0;
	color: var(--color-success-text, var(--color-main-text));
}
</style>
