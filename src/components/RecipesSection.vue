<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiBookOpenVariant, mdiChefHat, mdiCloudUploadOutline, mdiDelete, mdiPencil, mdiPlus, mdiRefresh } from '@mdi/js'
import { getCurrentUser } from '@nextcloud/auth'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { onMounted, ref } from 'vue'
import { createRecipe, deleteRecipe, errorMessage, exportToCookbook, fieldErrors, getCookbookUpdates, getRecipe, getRecipes, refreshFromCookbook, updateRecipe } from '../api.js'
import CookbookImportDialog from './CookbookImportDialog.vue'
import { t } from '../l10n.js'
import { categoryLabels, containsText, dietLabels } from '../recipes.js'
import RecipeDetail from './RecipeDetail.vue'
import RecipeForm from './RecipeForm.vue'
import RecipeLibraryDialog from './RecipeLibraryDialog.vue'

const props = defineProps({
	campId: { type: Number, required: true },
	standardPortions: { type: Number, default: 10 },
	canEdit: { type: Boolean, default: false },
})
const emit = defineEmits(['changed'])

const recipes = ref([])
const openId = ref(null)
const form = ref(null)
const errors = ref({})
const busy = ref(false)
const libraryOpen = ref(false)
const cookbookOpen = ref(false)
/** Rezept-ID => changed | missing, für Rezepte aus der eigenen Cookbook-Sammlung */
const cookbookUpdates = ref({})
/** Prüfhinweise nach Übernahme oder Aktualisierung: { title, review } */
const reviewNotice = ref(null)
const me = getCurrentUser()?.uid ?? ''

async function load() {
	try {
		recipes.value = await getRecipes(props.campId)
	} catch (error) {
		showError(errorMessage(error))
	}
	try {
		cookbookUpdates.value = await getCookbookUpdates(props.campId)
	} catch {
		// Ohne Cookbook bleibt die Liste wie sie ist.
	}
}

async function onCookbookImported(result) {
	reviewNotice.value = { title: result.title, review: result.review }
	await load()
	emit('changed')
}

async function refresh(recipe) {
	try {
		const result = await refreshFromCookbook(recipe.id)
		reviewNotice.value = { title: recipe.title, review: result.review }
		showSuccess(t('lagerplaner', '{title} updated from Cookbook', { title: recipe.title }))
		await load()
		emit('changed')
	} catch (error) {
		showError(errorMessage(error))
	}
}

async function saveToCookbook(recipe) {
	try {
		await exportToCookbook(recipe.id)
		showSuccess(t('lagerplaner', '{title} is now in your Cookbook collection. Cookbook shows it after its next rescan.', { title: recipe.title }))
		await load()
	} catch (error) {
		showError(errorMessage(error))
	}
}
onMounted(load)

async function startEdit(recipe) {
	try {
		const detail = await getRecipe(recipe.id)
		form.value = { id: recipe.id, recipe: detail.recipe }
		openId.value = null
	} catch (error) {
		showError(errorMessage(error))
	}
}

async function save(data, allergensChecked) {
	busy.value = true
	errors.value = {}
	try {
		if (form.value?.id) {
			await updateRecipe(form.value.id, data, allergensChecked)
		} else {
			await createRecipe(props.campId, data, allergensChecked)
		}
		showSuccess(t('lagerplaner', 'Recipe saved'))
		form.value = null
		await load()
		emit('changed')
	} catch (error) {
		errors.value = fieldErrors(error)
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

async function remove(recipe) {
	try {
		await deleteRecipe(recipe.id)
		recipes.value = recipes.value.filter((r) => r.id !== recipe.id)
		emit('changed')
	} catch (error) {
		showError(errorMessage(error))
	}
}

async function onImported() {
	await load()
	emit('changed')
}
</script>

<template>
	<section class="recipes">
		<header class="recipes__header">
			<h3>{{ t('lagerplaner', 'Recipes') }}</h3>
			<div v-if="canEdit" class="recipes__buttons">
				<NcButton @click="libraryOpen = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiBookOpenVariant" />
					</template>
					{{ t('lagerplaner', 'Add basic recipes') }}
				</NcButton>
				<NcButton @click="cookbookOpen = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiChefHat" />
					</template>
					{{ t('lagerplaner', 'From Cookbook') }}
				</NcButton>
				<NcButton variant="tertiary" @click="form = { id: null, recipe: null }; errors = {}">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('lagerplaner', 'New recipe') }}
				</NcButton>
			</div>
		</header>

		<NcNoteCard v-if="reviewNotice" type="warning" :heading="t('lagerplaner', 'Please check “{title}”', { title: reviewNotice.title })">
			<ul class="recipes__review">
				<li v-for="note in reviewNotice.review" :key="note">
					{{ note }}
				</li>
			</ul>
			<NcButton variant="tertiary" size="small" @click="reviewNotice = null">
				{{ t('lagerplaner', 'Done') }}
			</NcButton>
		</NcNoteCard>

		<RecipeForm
			v-if="form"
			:key="form.id ?? 'new'"
			:recipe="form.recipe"
			:errors="errors"
			:busy="busy"
			@save="save"
			@cancel="form = null" />

		<p v-if="recipes.length === 0 && !form" class="recipes__hint">
			{{ t('lagerplaner', 'No recipes yet. Start with the basic camp recipes or write your own.') }}
		</p>
		<ul class="recipes__list">
			<li v-for="recipe in recipes" :key="recipe.id">
				<div class="recipes__row">
					<button type="button" class="recipes__title" :aria-expanded="openId === recipe.id" @click="openId = openId === recipe.id ? null : recipe.id">
						{{ recipe.title }}
					</button>
					<span class="recipes__meta">
						{{ categoryLabels()[recipe.category] }}, {{ dietLabels()[recipe.diet] }}, {{ containsText(recipe.contains) }}
						<span v-if="!recipe.allergensChecked" class="recipes__unchecked">{{ t('lagerplaner', 'Allergens not checked') }}</span>
						<span v-if="recipe.cookbook" class="recipes__cookbook">{{ t('lagerplaner', 'from Cookbook') }}</span>
						<span v-if="cookbookUpdates[recipe.id] === 'changed'" class="recipes__unchecked">{{ t('lagerplaner', 'changed in Cookbook') }}</span>
						<span v-if="cookbookUpdates[recipe.id] === 'missing'" class="recipes__unchecked">{{ t('lagerplaner', 'no longer in Cookbook') }}</span>
					</span>
					<NcButton
						v-if="canEdit && cookbookUpdates[recipe.id] === 'changed' && recipe.cookbook?.owner === me"
						variant="secondary"
						size="small"
						@click="refresh(recipe)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiRefresh" :size="18" />
						</template>
						{{ t('lagerplaner', 'Update') }}
					</NcButton>
					<NcActions v-if="canEdit" :aria-label="t('lagerplaner', 'Actions for {title}', { title: recipe.title })">
						<NcActionButton close-after-click @click="startEdit(recipe)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPencil" />
							</template>
							{{ t('lagerplaner', 'Edit') }}
						</NcActionButton>
						<NcActionButton v-if="!recipe.cookbook" close-after-click @click="saveToCookbook(recipe)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiCloudUploadOutline" />
							</template>
							{{ t('lagerplaner', 'Save in Cookbook') }}
						</NcActionButton>
						<NcActionButton close-after-click @click="remove(recipe)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDelete" />
							</template>
							{{ t('lagerplaner', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</div>
				<RecipeDetail
					v-if="openId === recipe.id"
					:recipe-id="recipe.id"
					:default-portions="standardPortions"
					:can-edit="canEdit"
					@confirmed="load(); emit('changed')" />
			</li>
		</ul>

		<CookbookImportDialog v-if="cookbookOpen" :camp-id="campId" @imported="onCookbookImported" @close="cookbookOpen = false" />
		<RecipeLibraryDialog v-if="libraryOpen" :camp-id="campId" @imported="onImported" @close="libraryOpen = false" />
	</section>
</template>

<style scoped>
.recipes__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.recipes__header h3 {
	margin: calc(var(--default-grid-baseline) * 2) 0;
}

.recipes__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.recipes__hint,
.recipes__meta {
	color: var(--color-text-maxcontrast);
}

.recipes__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.recipes__list > li {
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.recipes__row {
	display: grid;
	grid-template-columns: minmax(200px, 1fr) 2fr auto;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.recipes__title {
	padding: var(--default-grid-baseline) 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font-weight: bold;
	text-align: start;
	cursor: pointer;
}

.recipes__title:hover,
.recipes__title:focus-visible {
	text-decoration: underline;
}

.recipes__meta {
	font-size: 0.9em;
}

.recipes__cookbook {
	margin-inline-start: var(--default-grid-baseline);
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 12px);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.recipes__review {
	margin: 0 0 var(--default-grid-baseline);
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}

.recipes__unchecked {
	margin-inline-start: var(--default-grid-baseline);
	color: var(--color-warning-text);
	font-weight: bold;
}

@media (max-width: 700px) {
	.recipes__row {
		grid-template-columns: 1fr auto;
	}

	.recipes__meta {
		grid-column: 1 / -1;
		grid-row: 2;
	}
}
</style>
