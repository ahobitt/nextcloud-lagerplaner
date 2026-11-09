<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiClose, mdiPlus } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { reactive, useId } from 'vue'
import { t } from '../l10n.js'
import { ALLERGENS, CATEGORIES, UNITS, allergenLabels, categoryLabels, dietLabels } from '../recipes.js'

const props = defineProps({
	/** Vorhandenes Rezept (Speicherform) zum Bearbeiten */
	recipe: { type: Object, default: null },
	errors: { type: Object, default: () => ({}) },
	busy: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'cancel'])
const id = useId()

const form = reactive({
	title: props.recipe?.title ?? '',
	servings: props.recipe?.servings ?? 10,
	diet: props.recipe?.diet ?? 'vegetarian',
	contains: [...(props.recipe?.contains ?? [])],
	category: props.recipe?.category ?? 'main',
	prepMinutes: props.recipe?.prepMinutes ?? '',
	ingredients: (props.recipe?.ingredients ?? [{ name: '', quantity: '', unit: 'g', note: '' }])
		.map((i) => ({ ...i, quantity: i.quantity === null ? '' : String(i.quantity).replace('.', ',') })),
	steps: (props.recipe?.steps ?? []).join('\n'),
	note: props.recipe?.note ?? '',
	// Nach jeder Änderung neu zu bestätigen, deshalb nie vorbelegt.
	allergensChecked: false,
})

function submit() {
	emit('save', {
		title: form.title,
		servings: Number.parseInt(form.servings, 10) || 0,
		diet: form.diet,
		contains: form.contains,
		category: form.category,
		prepMinutes: form.prepMinutes === '' ? null : Number.parseInt(form.prepMinutes, 10),
		ingredients: form.ingredients.filter((i) => i.name.trim() !== ''),
		steps: form.steps.split('\n').map((s) => s.trim()).filter(Boolean),
		note: form.note,
		source: props.recipe?.source ?? null,
	}, form.allergensChecked)
}
</script>

<template>
	<form class="recipe-form" @submit.prevent="submit">
		<div class="recipe-form__row">
			<NcTextField v-model="form.title" :label="t('lagerplaner', 'Title')" :error="!!errors.title" :helper-text="errors.title ?? ''" />
			<NcTextField v-model="form.servings" type="number" min="1" :label="t('lagerplaner', 'Standard portions')" :error="!!errors.servings" :helper-text="errors.servings ?? ''" />
			<NcTextField v-model="form.prepMinutes" type="number" min="1" :label="t('lagerplaner', 'Time in minutes (optional)')" />
		</div>
		<div class="recipe-form__row">
			<div class="recipe-form__select">
				<label :for="`${id}-category`">{{ t('lagerplaner', 'Category') }}</label>
				<select :id="`${id}-category`" v-model="form.category">
					<option v-for="category in CATEGORIES" :key="category" :value="category">
						{{ categoryLabels()[category] }}
					</option>
				</select>
			</div>
			<fieldset>
				<legend>{{ t('lagerplaner', 'Diet') }}</legend>
				<NcCheckboxRadioSwitch v-for="(label, key) in dietLabels()" :key="key" v-model="form.diet" type="radio" :name="`${id}-diet`" :value="key">
					{{ label }}
				</NcCheckboxRadioSwitch>
			</fieldset>
		</div>
		<fieldset>
			<legend>{{ t('lagerplaner', 'Contains') }}</legend>
			<p class="recipe-form__hint">
				{{ t('lagerplaner', 'Tick everything the dish contains, including lactose in milk products. Check the labels of the products you actually buy.') }}
			</p>
			<div class="recipe-form__choices">
				<NcCheckboxRadioSwitch v-for="allergen in ALLERGENS" :key="allergen" v-model="form.contains" type="checkbox" :value="allergen">
					{{ allergenLabels()[allergen] }}
				</NcCheckboxRadioSwitch>
			</div>
		</fieldset>

		<fieldset>
			<legend>{{ t('lagerplaner', 'Ingredients for the standard portions above') }}</legend>
			<div v-for="(ingredient, index) in form.ingredients" :key="index" class="recipe-form__ingredient">
				<NcTextField v-model="ingredient.quantity" :label="t('lagerplaner', 'Amount')" :error="!!errors[`ingredients.${index}.quantity`]" />
				<div class="recipe-form__select">
					<label :for="`${id}-unit-${index}`">{{ t('lagerplaner', 'Unit') }}</label>
					<select :id="`${id}-unit-${index}`" v-model="ingredient.unit">
						<option v-for="unit in UNITS" :key="unit" :value="unit">
							{{ unit }}
						</option>
					</select>
				</div>
				<NcTextField v-model="ingredient.name" :label="t('lagerplaner', 'Ingredient')" :error="!!errors[`ingredients.${index}.name`]" />
				<NcTextField v-model="ingredient.note" :label="t('lagerplaner', 'Note (optional)')" />
				<NcButton variant="tertiary" :aria-label="t('lagerplaner', 'Remove ingredient')" @click="form.ingredients.splice(index, 1)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" />
					</template>
				</NcButton>
			</div>
			<NcButton variant="tertiary" @click="form.ingredients.push({ name: '', quantity: '', unit: 'g', note: '' })">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ t('lagerplaner', 'Add ingredient') }}
			</NcButton>
		</fieldset>

		<div class="recipe-form__textarea">
			<label :for="`${id}-steps`">{{ t('lagerplaner', 'Steps, one per line') }}</label>
			<textarea :id="`${id}-steps`" v-model="form.steps" rows="6" />
		</div>
		<div class="recipe-form__textarea">
			<label :for="`${id}-note`">{{ t('lagerplaner', 'Note (optional)') }}</label>
			<textarea :id="`${id}-note`" v-model="form.note" rows="2" />
		</div>

		<NcCheckboxRadioSwitch v-model="form.allergensChecked" type="checkbox" class="recipe-form__checked">
			{{ t('lagerplaner', 'Allergen information checked: ingredients and the products we use were checked against the allergens above.') }}
		</NcCheckboxRadioSwitch>
		<p class="recipe-form__hint">
			{{ t('lagerplaner', 'Without this confirmation the recipe cannot be used for a variant that promises to be free of an allergen. Every change needs a new confirmation.') }}
		</p>

		<div class="recipe-form__buttons">
			<NcButton type="submit" variant="primary" :disabled="busy">
				{{ t('lagerplaner', 'Save recipe') }}
			</NcButton>
			<NcButton variant="tertiary" @click="emit('cancel')">
				{{ t('lagerplaner', 'Cancel') }}
			</NcButton>
		</div>
	</form>
</template>

<style scoped>
.recipe-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large);
}

.recipe-form fieldset legend {
	font-weight: bold;
}

.recipe-form__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 3);
}

.recipe-form__row > * {
	flex: 1 1 180px;
}

.recipe-form__choices {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 3);
}

.recipe-form__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.recipe-form__ingredient {
	display: grid;
	grid-template-columns: 90px 140px 2fr 1.5fr auto;
	align-items: end;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: var(--default-grid-baseline);
}

.recipe-form__select,
.recipe-form__textarea {
	display: flex;
	flex-direction: column;
	gap: 2px;
	color: var(--color-text-maxcontrast);
}

.recipe-form__select select {
	min-height: var(--default-clickable-area);
	margin: 0;
	color: var(--color-main-text);
}

.recipe-form__textarea textarea {
	width: 100%;
	color: var(--color-main-text);
}

.recipe-form__buttons {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}

@media (max-width: 700px) {
	.recipe-form__ingredient {
		grid-template-columns: 1fr 1fr auto;
	}
}
</style>
