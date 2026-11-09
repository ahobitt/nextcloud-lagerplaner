<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { showError, showSuccess, showUndo } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, onMounted, ref } from 'vue'
import { cancelMeal, errorMessage, getCatering, getRecipes, proposeMenu, restoreSchedule, setMealDish, setMealTimes } from '../api.js'
import DietaryNeedsEditor from '../components/DietaryNeedsEditor.vue'
import MealVariantEditor from '../components/MealVariantEditor.vue'
import RecipesSection from '../components/RecipesSection.vue'
import { formatDay, mealLabels } from '../format.js'
import { n, t } from '../l10n.js'

const props = defineProps({
	campId: { type: Number, required: true },
	canEdit: { type: Boolean, default: false },
})
const emit = defineEmits(['needs-saved'])

const loading = ref(true)
const overview = ref(null)
const editNeeds = ref(false)
const openMeal = ref(null)
const recipes = ref([])
const busy = ref(false)

onMounted(reload)

async function reload() {
	try {
		[overview.value, recipes.value] = await Promise.all([getCatering(props.campId), getRecipes(props.campId)])
		if (overview.value.needs.total === 0) {
			editNeeds.value = true
		}
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		loading.value = false
	}
}

function onNeedsSaved(result) {
	overview.value = result
	editNeeds.value = false
	emit('needs-saved')
}

const mealsByDay = computed(() => {
	const days = {}
	for (const meal of overview.value?.meals ?? []) {
		(days[meal.day] ??= []).push(meal)
	}
	return Object.entries(days)
		.sort(([a], [b]) => a.localeCompare(b))
		.map(([day, meals]) => ({ day, meals: meals.sort((a, b) => a.time.localeCompare(b.time)) }))
})

const sortedRecipes = computed(() => [...recipes.value].sort((a, b) => a.title.localeCompare(b.title)))

async function act(action, message = '') {
	busy.value = true
	try {
		const result = await action()
		await reload()
		if (message) {
			showSuccess(typeof message === 'function' ? message(result) : message)
		}
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

/** Essenszeiten je Art: die häufigste Uhrzeit als Vorschlag im Feld */
const mealTimes = ref({})
const mealTypes = computed(() => {
	const counts = {}
	for (const meal of overview.value?.meals ?? []) {
		if (!meal.cancelled) {
			counts[meal.mealType] ??= {}
			counts[meal.mealType][meal.time] = (counts[meal.mealType][meal.time] ?? 0) + 1
		}
	}
	return ['breakfast', 'lunch', 'snack', 'dinner'].filter((type) => counts[type]).map((type) => ({
		type,
		usual: Object.entries(counts[type]).sort((a, b) => b[1] - a[1])[0][0],
	}))
})

async function applyMealTime(type, usual) {
	const time = mealTimes.value[type] || usual
	busy.value = true
	try {
		const result = await setMealTimes(props.campId, type, time)
		await reload()
		showUndo(n('lagerplaner', '{meal} now at {time}; %n entry adjusted', '{meal} now at {time}; %n entries adjusted', result.changes.length, { meal: mealLabels()[type], time }), async () => {
			await restoreSchedule(props.campId, result.changes)
			await reload()
		})
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

function propose() {
	act(() => proposeMenu(props.campId), (result) => (result.planned + result.completed === 0
		? t('lagerplaner', 'All meals already have a dish.')
		: n('lagerplaner', '%n meal planned or completed', '%n meals planned or completed', result.planned + result.completed)))
}

const statusLabels = () => ({
	cancelled: t('lagerplaner', 'Cancelled'),
	none: t('lagerplaner', 'No menu variants yet'),
	ok: t('lagerplaner', 'Variants fit'),
	warning: t('lagerplaner', 'Check notes'),
	error: t('lagerplaner', 'Needs attention'),
})

const portions = computed(() => new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(overview.value?.standardPortions ?? 0))
</script>

<template>
	<div class="catering">
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else-if="overview">
			<p class="catering__privacy">
				{{ t('lagerplaner', 'Dietary needs are only numbers without names. Only camp leaders and the kitchen team can see them.') }}
			</p>

			<section class="catering__section">
				<header class="catering__header">
					<h3>{{ t('lagerplaner', 'Dietary needs') }}</h3>
					<NcButton v-if="!editNeeds" variant="tertiary" @click="editNeeds = true">
						{{ t('lagerplaner', 'Edit') }}
					</NcButton>
				</header>
				<NcNoteCard v-for="issue in overview.issues" :key="issue.code + issue.message" :type="issue.severity === 'error' ? 'error' : 'warning'" :text="issue.message" />
				<DietaryNeedsEditor
					v-if="editNeeds"
					:camp-id="campId"
					:needs="overview.needs"
					:participants="overview.participants"
					@saved="onNeedsSaved" />
				<ul v-else class="catering__combinations">
					<li v-for="combination in overview.needs.combinations" :key="combination.key">
						<span>{{ combination.label }}</span>
						<span class="catering__count">{{ combination.count }}</span>
					</li>
					<li v-for="entry in overview.needs.severe.filter((s) => s.count > 0)" :key="entry.key" class="catering__severe">
						<span>{{ t('lagerplaner', 'Severe allergy: {allergen}', { allergen: entry.label }) }}</span>
						<span class="catering__count">{{ entry.count }}</span>
					</li>
				</ul>
				<p class="catering__portions">
					{{ t('lagerplaner', '{portions} standard portions for {people} people, based on the portion factors of the groups.', { portions, people: overview.participants }) }}
				</p>
			</section>

			<RecipesSection
				:camp-id="campId"
				:standard-portions="overview.standardPortions || 10"
				:can-edit="canEdit"
				@changed="reload" />

			<section class="catering__section">
				<div class="catering__menu-head">
					<h3>{{ t('lagerplaner', 'Meal plan') }}</h3>
					<NcButton v-if="canEdit" variant="secondary" :disabled="busy" @click="propose">
						{{ t('lagerplaner', 'Suggest meal plan') }}
					</NcButton>
				</div>
				<details v-if="canEdit && mealTypes.length" class="catering__times">
					<summary>{{ t('lagerplaner', 'Meal times') }}</summary>
					<p class="catering__hint">
						{{ t('lagerplaner', 'Changes the time for all days. Programme items that would overlap move along, as far as you may edit the programme.') }}
					</p>
					<div v-for="entry in mealTypes" :key="entry.type" class="catering__time-row">
						<label :for="`meal-time-${entry.type}`">{{ mealLabels()[entry.type] }}</label>
						<input :id="`meal-time-${entry.type}`" v-model="mealTimes[entry.type]" type="time" :placeholder="entry.usual" :disabled="busy">
						<span class="catering__note">{{ t('lagerplaner', 'now mostly {time}', { time: entry.usual }) }}</span>
						<NcButton size="small" :disabled="busy || !mealTimes[entry.type] || mealTimes[entry.type] === entry.usual" @click="applyMealTime(entry.type, entry.usual)">
							{{ t('lagerplaner', 'Apply to all days') }}
						</NcButton>
					</div>
				</details>
				<p class="catering__hint">
					{{ t('lagerplaner', 'Each meal has one dish for everyone; change it right here. Whoever needs something else gets their own variant. “Suggest meal plan” fills open meals with simple dishes that almost always work and adds missing variants. The shopping lists are built from it.') }}
				</p>
				<article v-for="entry in mealsByDay" :key="entry.day" class="catering__day">
					<h4>{{ formatDay(entry.day) }}</h4>
					<ul class="catering__meals">
						<li v-for="meal in entry.meals" :key="meal.id">
							<div class="catering__meal" :class="{ 'catering__meal--cancelled': meal.cancelled }">
								<span class="catering__time">{{ meal.time }}</span>
								<span class="catering__meal-name">
									{{ mealLabels()[meal.mealType] ?? meal.mealType }}
									<span v-if="meal.note" class="catering__note">{{ meal.note }}</span>
									<span v-if="!meal.selfCatered" class="catering__note">{{ t('lagerplaner', 'external catering') }}</span>
								</span>
								<span class="catering__dish">
									<select
										v-if="canEdit && meal.selfCatered && !meal.cancelled && meal.simple"
										:value="meal.dishId ?? ''"
										:aria-label="t('lagerplaner', 'Dish for {meal} on {day}', { meal: mealLabels()[meal.mealType], day: formatDay(meal.day) })"
										:disabled="busy"
										@change="act(() => setMealDish(meal.id, Number($event.target.value)))">
										<option value="" disabled>
											{{ t('lagerplaner', 'Choose a dish') }}
										</option>
										<option v-for="recipe in sortedRecipes" :key="recipe.id" :value="recipe.id">
											{{ recipe.title }}
										</option>
									</select>
									<span v-else-if="meal.dish">{{ meal.dish }}</span>
									<span v-if="meal.variantCount > 1" class="catering__note">{{ n('lagerplaner', 'plus %n variant', 'plus %n variants', meal.variantCount - 1) }}</span>
								</span>
								<span class="catering__status" :class="`catering__status--${meal.status}`">{{ statusLabels()[meal.status] }}</span>
								<span class="catering__actions">
									<NcButton
										v-if="canEdit && meal.selfCatered"
										variant="tertiary"
										size="small"
										:disabled="busy"
										@click="act(() => cancelMeal(meal.id, !meal.cancelled))">
										{{ meal.cancelled ? t('lagerplaner', 'Plan again') : t('lagerplaner', 'Cancel meal') }}
									</NcButton>
									<NcButton
										v-if="!meal.cancelled"
										variant="tertiary"
										size="small"
										:aria-expanded="openMeal === meal.id"
										@click="openMeal = openMeal === meal.id ? null : meal.id">
										{{ openMeal === meal.id ? t('lagerplaner', 'Close') : t('lagerplaner', 'Variants') }}
									</NcButton>
								</span>
							</div>
							<MealVariantEditor v-if="openMeal === meal.id" :meal-id="meal.id" @changed="reload" />
						</li>
					</ul>
				</article>
			</section>
		</template>
	</div>
</template>

<style scoped>
.catering {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
}

.catering__privacy,
.catering__hint,
.catering__portions,
.catering__note,
.catering__time {
	color: var(--color-text-maxcontrast);
}

.catering__privacy {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
	font-size: 0.9em;
}

.catering__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.catering__section h3 {
	margin: calc(var(--default-grid-baseline) * 2) 0;
}

.catering__combinations,
.catering__times {
	margin: var(--default-grid-baseline) 0 calc(var(--default-grid-baseline) * 2);
}

.catering__times summary {
	cursor: pointer;
}

.catering__time-row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin: var(--default-grid-baseline) 0;
}

.catering__time-row label {
	min-width: 8em;
}

.catering__menu-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.catering__dish select {
	min-height: var(--default-clickable-area);
	max-width: 100%;
	margin: 0;
}

.catering__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 2px;
}

.catering__meal--cancelled .catering__meal-name,
.catering__meal--cancelled .catering__time {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.catering__meals {
	margin: 0;
	padding: 0;
	list-style: none;
}

.catering__combinations {
	max-width: 480px;
}

.catering__combinations li {
	display: flex;
	justify-content: space-between;
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.catering__count {
	font-variant-numeric: tabular-nums;
}

.catering__severe {
	color: var(--color-warning-text);
}

.catering__day h4 {
	margin: calc(var(--default-grid-baseline) * 3) 0 var(--default-grid-baseline);
}

.catering__meals > li {
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.catering__meal {
	display: grid;
	grid-template-columns: 5em minmax(7em, 1fr) minmax(12em, 2fr) auto auto;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.catering__time {
	font-variant-numeric: tabular-nums;
}

.catering__meal-name {
	display: flex;
	flex-direction: column;
}

.catering__note {
	font-size: 0.9em;
}

/* Planungsstand als Text, die Farbe unterstützt nur. */
.catering__status {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.catering__status--ok {
	color: var(--color-success-text, var(--color-main-text));
}

.catering__status--warning {
	color: var(--color-warning-text);
}

.catering__status--error {
	color: var(--color-error-text);
	font-weight: bold;
}

@media (max-width: 700px) {
	.catering__meal {
		grid-template-columns: 4em 1fr;
	}

	.catering__dish,
	.catering__status,
	.catering__actions {
		grid-column: 2;
	}
}
</style>
