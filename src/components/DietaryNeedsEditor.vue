<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiClose, mdiPlus } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, reactive, ref } from 'vue'
import { errorMessage, fieldErrors, saveNeeds } from '../api.js'
import { n, t } from '../l10n.js'

const props = defineProps({
	campId: { type: Number, required: true },
	/** needs aus der Übersicht der Küche */
	needs: { type: Object, required: true },
	participants: { type: Number, required: true },
})
const emit = defineEmits(['saved'])

const SPECIAL = { none: () => t('lagerplaner', 'No restrictions'), unanswered: () => t('lagerplaner', 'Question not answered'), clarify: () => t('lagerplaner', 'Needs clarification') }

const rows = ref(initialRows())
const severe = reactive(Object.fromEntries(props.needs.severe.map((s) => [s.key, s.count])))
const errors = ref({})
const saving = ref(false)
const draft = reactive({ diet: '', needs: [] })

const requirementLabels = computed(() => Object.fromEntries(props.needs.requirements.map((r) => [r.key, r.label])))
const diets = computed(() => props.needs.requirements.filter((r) => r.kind === 'diet'))
const allergens = computed(() => props.needs.requirements.filter((r) => r.kind === 'allergen'))
const total = computed(() => rows.value.reduce((sum, row) => sum + (Number.parseInt(row.count, 10) || 0), 0))

function initialRows() {
	const list = props.needs.combinations.map((c) => ({ key: c.key, count: c.count }))
	if (!list.some((row) => row.key === 'none')) {
		list.unshift({ key: 'none', count: 0 })
	}
	return list
}

function label(key) {
	if (SPECIAL[key]) {
		return SPECIAL[key]()
	}
	const text = key.split('+').map((part) => requirementLabels.value[part] ?? part).join(' + ')
	return text.charAt(0).toUpperCase() + text.slice(1)
}

function addRow(key) {
	if (rows.value.some((row) => row.key === key)) {
		showError(t('lagerplaner', '"{combination}" is already in the list.', { combination: label(key) }))
		return
	}
	rows.value = [...rows.value, { key, count: 1 }]
}

function addCombination() {
	const parts = [...draft.needs, ...(draft.diet ? [draft.diet] : [])].sort()
	addRow(parts.length === 0 ? 'none' : parts.join('+'))
	draft.diet = ''
	draft.needs = []
}

async function save() {
	saving.value = true
	errors.value = {}
	try {
		const overview = await saveNeeds(props.campId, {
			combinations: rows.value.map((row) => ({ key: row.key, count: Number.parseInt(row.count, 10) || 0 })),
			severe: Object.fromEntries(Object.entries(severe).map(([key, count]) => [key, Number.parseInt(count, 10) || 0])),
		})
		showSuccess(t('lagerplaner', 'Dietary needs saved'))
		emit('saved', overview)
	} catch (error) {
		errors.value = fieldErrors(error)
		showError(errorMessage(error))
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<form class="needs" @submit.prevent="save">
		<p class="needs__lead">
			{{ t('lagerplaner', 'Enter how many people have each combination of needs. Every person counts exactly once, so nobody is counted twice.') }}
		</p>

		<table class="needs__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('lagerplaner', 'Combination') }}
					</th>
					<th scope="col">
						{{ t('lagerplaner', 'People') }}
					</th>
					<td />
				</tr>
			</thead>
			<tbody>
				<tr v-for="(row, index) in rows" :key="row.key">
					<th scope="row">
						{{ label(row.key) }}
					</th>
					<td>
						<NcTextField
							v-model="row.count"
							type="number"
							min="0"
							:label="t('lagerplaner', 'People: {combination}', { combination: label(row.key) })"
							:error="!!errors[`combinations.${index}.count`]"
							:helper-text="errors[`combinations.${index}.count`] ?? ''" />
					</td>
					<td>
						<NcButton
							v-if="row.key !== 'none'"
							variant="tertiary"
							:aria-label="t('lagerplaner', 'Remove {combination}', { combination: label(row.key) })"
							@click="rows = rows.filter((entry) => entry !== row)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiClose" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
		<p class="needs__sum" :class="{ 'needs__sum--off': total !== participants }">
			{{ n('lagerplaner', '%n person entered', '%n people entered', total) }},
			{{ n('lagerplaner', '%n participant registered', '%n participants registered', participants) }}
		</p>

		<fieldset class="needs__add">
			<legend>{{ t('lagerplaner', 'Add a combination') }}</legend>
			<div class="needs__choices">
				<NcCheckboxRadioSwitch v-model="draft.diet" type="radio" name="needs-diet" value="">
					{{ t('lagerplaner', 'No special diet') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-for="diet in diets"
					:key="diet.key"
					v-model="draft.diet"
					type="radio"
					name="needs-diet"
					:value="diet.key">
					{{ diet.label }}
				</NcCheckboxRadioSwitch>
			</div>
			<div class="needs__choices">
				<NcCheckboxRadioSwitch
					v-for="allergen in allergens"
					:key="allergen.key"
					v-model="draft.needs"
					type="checkbox"
					:value="allergen.key">
					{{ allergen.label }}
				</NcCheckboxRadioSwitch>
			</div>
			<div class="needs__buttons">
				<NcButton @click="addCombination">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('lagerplaner', 'Add combination') }}
				</NcButton>
				<NcButton variant="tertiary" @click="addRow('unanswered')">
					{{ t('lagerplaner', 'Add "question not answered"') }}
				</NcButton>
				<NcButton variant="tertiary" @click="addRow('clarify')">
					{{ t('lagerplaner', 'Add "needs clarification"') }}
				</NcButton>
			</div>
		</fieldset>

		<fieldset class="needs__severe">
			<legend>{{ t('lagerplaner', 'Severe allergies') }}</legend>
			<NcNoteCard type="info" :text="t('lagerplaner', 'Only numbers, no names. The list of affected people stays in Hitobito and is handed only to the camp leaders and the named kitchen staff. As soon as one severe allergy is entered, the required task “Set up the allergy procedure” is created.')" />
			<div class="needs__severe-grid">
				<NcTextField
					v-for="entry in needs.severe"
					:key="entry.key"
					v-model="severe[entry.key]"
					type="number"
					min="0"
					:label="entry.label"
					:error="!!errors[`severe.${entry.key}`]"
					:helper-text="errors[`severe.${entry.key}`] ?? ''" />
			</div>
		</fieldset>

		<div class="needs__buttons">
			<NcButton type="submit" variant="primary" :disabled="saving">
				{{ t('lagerplaner', 'Save dietary needs') }}
			</NcButton>
		</div>
	</form>
</template>

<style scoped>
.needs {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.needs__lead,
.needs__sum {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.needs__sum--off {
	color: var(--color-warning-text);
}

.needs__table {
	border-collapse: collapse;
	width: 100%;
	max-width: 640px;
}

.needs__table th,
.needs__table td {
	padding: 2px calc(var(--default-grid-baseline) * 2);
	text-align: start;
	vertical-align: middle;
}

.needs__table thead th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.needs__table tbody th {
	font-weight: normal;
}

.needs__table td:nth-child(2) {
	width: 140px;
}

.needs fieldset legend {
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline);
}

.needs__choices {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 4);
}

.needs__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: var(--default-grid-baseline);
}

.needs__severe-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
