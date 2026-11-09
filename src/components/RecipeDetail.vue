<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { onMounted, ref, watch } from 'vue'
import { confirmRecipeAllergens, errorMessage, getRecipe } from '../api.js'
import { n, t } from '../l10n.js'
import { categoryLabels, containsText, dietLabels, quantityText } from '../recipes.js'

const props = defineProps({
	recipeId: { type: Number, required: true },
	/** Vorbelegung: Standardportionen des Lagers */
	defaultPortions: { type: Number, default: 10 },
	canEdit: { type: Boolean, default: false },
})
const emit = defineEmits(['confirmed'])

async function confirm() {
	try {
		await confirmRecipeAllergens(props.recipeId)
		await load()
		emit('confirmed')
	} catch (error) {
		showError(errorMessage(error))
	}
}

const detail = ref(null)
const portions = ref(String(Math.round(props.defaultPortions * 10) / 10).replace('.', ','))

async function load() {
	const value = Number.parseFloat(String(portions.value).replace(',', '.'))
	try {
		detail.value = await getRecipe(props.recipeId, value > 0 ? value : null)
	} catch (error) {
		showError(errorMessage(error))
	}
}

let timer = null
watch(portions, () => {
	clearTimeout(timer)
	timer = setTimeout(load, 300)
})
onMounted(load)
</script>

<template>
	<div class="recipe">
		<NcLoadingIcon v-if="!detail" :size="24" />
		<template v-else>
			<p class="recipe__meta">
				{{ categoryLabels()[detail.recipe.category] }}, {{ dietLabels()[detail.recipe.diet] }}, {{ containsText(detail.recipe.contains) }}<template v-if="detail.recipe.prepMinutes">, {{ n('lagerplaner', 'about %n minute', 'about %n minutes', detail.recipe.prepMinutes) }}</template>
			</p>
			<NcNoteCard v-if="!detail.allergensChecked" type="warning">
				<p>{{ t('lagerplaner', 'The allergen information of this recipe has not been checked yet. Check the ingredients and the products you actually use, then confirm.') }}</p>
				<NcButton v-if="canEdit" variant="secondary" @click="confirm">
					{{ t('lagerplaner', 'Confirm allergen information') }}
				</NcButton>
			</NcNoteCard>
			<div class="recipe__portions">
				<NcTextField v-model="portions" :label="t('lagerplaner', 'Standard portions')" />
				<span class="recipe__meta">{{ t('lagerplaner', 'Written for {count} standard portions', { count: detail.recipe.servings }) }}</span>
			</div>
			<h4>{{ t('lagerplaner', 'Ingredients') }}</h4>
			<table class="recipe__ingredients">
				<tbody>
					<tr v-for="(ingredient, index) in (detail.scaled ?? detail.recipe.ingredients)" :key="index">
						<td class="recipe__amount">
							{{ detail.scaled ? quantityText(ingredient) : `${ingredient.quantity ?? ''} ${ingredient.unit}` }}
						</td>
						<td>
							{{ ingredient.name }}
							<span v-if="ingredient.note" class="recipe__meta">({{ ingredient.note }})</span>
						</td>
					</tr>
				</tbody>
			</table>
			<h4>{{ t('lagerplaner', 'Steps') }}</h4>
			<ol class="recipe__steps">
				<li v-for="(step, index) in detail.recipe.steps" :key="index">
					{{ step }}
				</li>
			</ol>
			<p v-if="detail.recipe.note" class="recipe__note">
				{{ detail.recipe.note }}
			</p>
			<p v-if="detail.recipe.source?.name" class="recipe__meta">
				{{ t('lagerplaner', 'Source: {name}', { name: detail.recipe.source.name }) }}<template v-if="detail.recipe.source.license">, {{ detail.recipe.source.license }}</template>
			</p>
		</template>
	</div>
</template>

<style scoped>
.recipe {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 3);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.recipe h4 {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
}

.recipe__meta {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.recipe__portions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
	max-width: 480px;
}

.recipe__ingredients td {
	padding: 2px calc(var(--default-grid-baseline) * 3) 2px 0;
	vertical-align: top;
}

.recipe__amount {
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}

.recipe__steps {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}

.recipe__steps li {
	margin-bottom: var(--default-grid-baseline);
}

.recipe__note {
	margin: var(--default-grid-baseline) 0 0;
}
</style>
