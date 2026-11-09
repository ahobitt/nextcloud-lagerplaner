<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'
import { errorMessage, getCookbookLibrary, importCookbookRecipe, previewCookbookRecipe } from '../api.js'
import { n, t } from '../l10n.js'
import { dietLabels } from '../recipes.js'

const props = defineProps({
	campId: { type: Number, required: true },
})
const emit = defineEmits(['imported', 'close'])

const library = ref(null)
const query = ref('')
const selected = ref(null)
const preview = ref(null)
const busy = ref(false)

onMounted(async () => {
	try {
		library.value = await getCookbookLibrary(props.campId)
	} catch (error) {
		showError(errorMessage(error))
		emit('close')
	}
})

const visible = computed(() => (library.value?.items ?? []).filter((item) => `${item.name} ${item.category} ${item.keywords}`.toLowerCase().includes(query.value.trim().toLowerCase())))

async function choose(item) {
	selected.value = item
	preview.value = null
	try {
		preview.value = await previewCookbookRecipe(item.id)
	} catch (error) {
		showError(errorMessage(error))
	}
}

async function take() {
	busy.value = true
	try {
		const result = await importCookbookRecipe(props.campId, selected.value.id)
		emit('imported', { id: result.id, title: preview.value.recipe.title, review: result.review })
		emit('close')
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

const amount = (line) => (line.quantity === null ? line.unit : `${String(line.quantity).replace('.', ',')} ${line.unit}`)
</script>

<template>
	<NcDialog :open="true" size="large" :name="t('lagerplaner', 'Take over a recipe from Cookbook')" @update:open="(value) => { if (!value) { emit('close') } }">
		<div class="cookbook-import">
			<NcLoadingIcon v-if="!library" :size="28" />
			<NcNoteCard v-else-if="library.status === 'not_installed'" type="info" :text="t('lagerplaner', 'Nextcloud Cookbook is not installed or not enabled for you. Your administration can install it from the app store.')" />
			<NcNoteCard v-else-if="library.status === 'no_folder'" type="info" :text="t('lagerplaner', 'Cookbook has no recipe folder yet. Open Cookbook once, then it creates one.')" />
			<p v-else-if="library.items.length === 0">
				{{ t('lagerplaner', 'Your Cookbook collection is empty.') }}
			</p>
			<div v-else class="cookbook-import__layout">
				<div class="cookbook-import__list">
					<NcTextField v-model="query" :label="t('lagerplaner', 'Search your Cookbook recipes')" />
					<ul>
						<li v-for="item in visible" :key="item.id">
							<button type="button" :class="{ 'is-selected': selected?.id === item.id }" @click="choose(item)">
								<strong>{{ item.name }}</strong>
								<span class="cookbook-import__meta">
									{{ [item.category, item.servings ? n('lagerplaner', '%n serving', '%n servings', item.servings) : ''].filter(Boolean).join(', ') }}
									<template v-if="item.importedAs">, {{ t('lagerplaner', 'already in this camp') }}</template>
								</span>
							</button>
						</li>
					</ul>
				</div>
				<div class="cookbook-import__preview">
					<p v-if="!selected" class="cookbook-import__meta">
						{{ t('lagerplaner', 'Choose a recipe to see how it will look in the camp.') }}
					</p>
					<NcLoadingIcon v-else-if="!preview" :size="24" />
					<template v-else>
						<h3>{{ preview.recipe.title }}</h3>
						<p class="cookbook-import__meta">
							{{ n('lagerplaner', '%n serving', '%n servings', preview.recipe.servings) }}, {{ dietLabels()[preview.recipe.diet] }}
						</p>
						<table class="cookbook-import__lines">
							<tbody>
								<tr v-for="(line, index) in preview.lines" :key="index" :class="{ 'is-uncertain': !line.certain }">
									<td class="cookbook-import__qty">
										{{ amount(line) }}
									</td>
									<td>
										{{ line.name }}<template v-if="line.note">, {{ line.note }}</template>
										<span v-if="!line.certain" class="cookbook-import__check">{{ t('lagerplaner', 'please check, in Cookbook: “{text}”', { text: line.original }) }}</span>
									</td>
								</tr>
							</tbody>
						</table>
						<NcNoteCard type="warning" :heading="t('lagerplaner', 'Please check after taking over')">
							<ul class="cookbook-import__review">
								<li v-for="note in preview.review" :key="note">
									{{ note }}
								</li>
							</ul>
						</NcNoteCard>
						<NcButton variant="primary" :disabled="busy || !!selected.importedAs" @click="take">
							{{ selected.importedAs ? t('lagerplaner', 'Already in this camp') : t('lagerplaner', 'Take over into the camp') }}
						</NcButton>
					</template>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<style scoped>
.cookbook-import {
	padding-bottom: calc(var(--default-grid-baseline) * 3);
}

.cookbook-import__layout {
	display: grid;
	grid-template-columns: minmax(220px, 1fr) 2fr;
	gap: calc(var(--default-grid-baseline) * 4);
}

.cookbook-import__list ul {
	max-height: 55vh;
	margin: var(--default-grid-baseline) 0 0;
	padding: 0;
	overflow-y: auto;
	list-style: none;
}

.cookbook-import__list button {
	display: flex;
	flex-direction: column;
	width: 100%;
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border: none;
	border-radius: var(--border-radius-element, 8px);
	background: none;
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.cookbook-import__list button:hover,
.cookbook-import__list button.is-selected {
	background: var(--color-background-hover);
}

.cookbook-import__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.cookbook-import__preview h3 {
	margin: 0;
}

.cookbook-import__lines {
	width: 100%;
	margin: calc(var(--default-grid-baseline) * 2) 0;
	border-collapse: collapse;
}

.cookbook-import__lines td {
	padding: 2px var(--default-grid-baseline);
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.cookbook-import__qty {
	width: 8em;
	text-align: end;
	white-space: nowrap;
}

.is-uncertain td {
	background: var(--color-warning-hover, rgba(255, 196, 0, 0.12));
}

.cookbook-import__check {
	display: block;
	color: var(--color-warning-text);
	font-size: 0.9em;
}

.cookbook-import__review {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}

@media (max-width: 700px) {
	.cookbook-import__layout {
		grid-template-columns: 1fr;
	}
}
</style>
