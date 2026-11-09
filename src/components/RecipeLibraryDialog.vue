<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { computed, onMounted, ref } from 'vue'
import { errorMessage, getRecipeLibrary, importRecipes } from '../api.js'
import { n, t } from '../l10n.js'
import { categoryLabels, containsText, dietLabels } from '../recipes.js'

const props = defineProps({
	campId: { type: Number, required: true },
})
const emit = defineEmits(['imported', 'close'])

const open = ref(true)
const loading = ref(true)
const library = ref([])
const selected = ref([])
const busy = ref(false)

const available = computed(() => library.value.filter((r) => !r.imported))

onMounted(async () => {
	try {
		library.value = await getRecipeLibrary(props.campId)
		selected.value = available.value.map((r) => r.key)
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		loading.value = false
	}
})

async function submit() {
	busy.value = true
	try {
		const result = await importRecipes(props.campId, selected.value)
		showSuccess(n('lagerplaner', '%n recipe added to the camp', '%n recipes added to the camp', result.imported))
		emit('imported')
		close()
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

function close() {
	open.value = false
	emit('close')
}
</script>

<template>
	<NcDialog
		:open="open"
		size="normal"
		:name="t('lagerplaner', 'Basic camp recipes')"
		@update:open="(value) => !value && close()">
		<NcLoadingIcon v-if="loading" :size="32" />
		<div v-else class="library">
			<p class="library__hint">
				{{ t('lagerplaner', 'Classic camp dishes for 10 standard portions, written for the camp planner and free to use (CC0). They are added as copies you can change.') }}
			</p>
			<ul class="library__list">
				<li v-for="recipe in library" :key="recipe.key">
					<NcCheckboxRadioSwitch v-model="selected" type="checkbox" :value="recipe.key" :disabled="recipe.imported">
						<span class="library__title">{{ recipe.title }}</span>
						<span class="library__meta">
							{{ categoryLabels()[recipe.category] }}, {{ dietLabels()[recipe.diet] }}, {{ containsText(recipe.contains) }}<template v-if="recipe.imported">, {{ t('lagerplaner', 'already in this camp') }}</template>
						</span>
					</NcCheckboxRadioSwitch>
				</li>
			</ul>
			<p class="library__hint">
				{{ t('lagerplaner', 'More ideas and tips for sustainable camp cooking:') }}
				<a href="https://dpsg-koeln.de/storage/app/media//arbeitshilfen/kochen-fur-nachhaltigkeit-arbeitshilfeleseversion.pdf" target="_blank" rel="noopener noreferrer">
					{{ t('lagerplaner', 'DPSG Köln, “Kochen für Nachhaltigkeit” (PDF)') }}
				</a>
			</p>
			<div class="library__buttons">
				<NcButton variant="primary" :disabled="busy || selected.length === 0" @click="submit">
					{{ n('lagerplaner', 'Add %n recipe', 'Add %n recipes', selected.length) }}
				</NcButton>
				<NcButton variant="tertiary" @click="close">
					{{ t('lagerplaner', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<style scoped>
.library {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.library__hint,
.library__meta {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.library__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.library__title {
	display: block;
}

.library__meta {
	display: block;
	font-size: 0.9em;
}

.library__buttons {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
