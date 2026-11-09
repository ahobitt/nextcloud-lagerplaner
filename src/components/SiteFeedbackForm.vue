<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, reactive, ref, useId } from 'vue'
import { errorMessage, reportMissingSite, sendSiteFeedback } from '../api.js'
import { t } from '../l10n.js'
import { FEATURES, featureLabels, feedbackKindLabels, typeLabels } from '../sites.js'

/** „Feedback / Angaben ergänzen“ zu einem Platz; ohne siteId: ein fehlender Platz. */
const props = defineProps({
	siteId: { type: Number, default: null },
	siteName: { type: String, default: '' },
})
const emit = defineEmits(['done', 'cancel'])
const id = useId()

const kinds = () => {
	const { new_site: _, ...labels } = feedbackKindLabels()
	return labels
}
const kind = ref(props.siteId === null ? 'new_site' : 'outdated')
const temporary = ref(false)
const form = reactive({ homepage: '', capacity: '', beds: '', since: '', until: '', name: '', type: 'tent', location: '', postalCode: '', city: '', operator: '', message: '', features: Object.fromEntries(FEATURES.map((f) => [f, ''])) })
const busy = ref(false)
const error = ref('')

const values = computed(() => {
	switch (kind.value) {
	case 'website': return { homepage: form.homepage }
	case 'equipment': return Object.fromEntries(FEATURES.filter((f) => form.features[f] !== '').map((f) => [f, form.features[f]]))
	case 'capacity': return { capacity: form.capacity, beds: form.beds }
	case 'closed': return { since: form.since, until: temporary.value ? form.until : '' }
	case 'new_site': return { name: form.name, type: form.type, location: form.location, homepage: form.homepage }
	case 'name': return { name: form.name }
	case 'location': return { location: form.location }
	case 'address': return { postalCode: form.postalCode, city: form.city }
	case 'operator': return { operator: form.operator, homepage: form.homepage }
	default: return {}
	}
})

async function submit() {
	error.value = ''
	busy.value = true
	try {
		if (props.siteId === null) {
			await reportMissingSite(values.value, form.message)
		} else {
			await sendSiteFeedback(props.siteId, kind.value, values.value, form.message)
		}
		showSuccess(t('lagerplaner', 'Thank you! The administration will check your feedback.'))
		emit('done')
	} catch (e) {
		error.value = errorMessage(e)
		showError(error.value)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<form class="feedback" @submit.prevent="submit">
		<h4 class="feedback__title">
			{{ siteId === null ? t('lagerplaner', 'Report a missing campsite') : t('lagerplaner', 'Feedback on {name}', { name: siteName }) }}
		</h4>
		<fieldset v-if="siteId !== null" class="feedback__kinds">
			<legend class="hidden-visually">
				{{ t('lagerplaner', 'What do you want to report?') }}
			</legend>
			<NcCheckboxRadioSwitch
				v-for="(label, key) in kinds()"
				:key="key"
				v-model="kind"
				type="radio"
				:value="key"
				:name="`${id}-kind`">
				{{ label }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<template v-if="kind === 'website'">
			<NcTextField v-model="form.homepage" type="url" :label="t('lagerplaner', 'New website')" placeholder="https://" />
		</template>
		<div v-else-if="kind === 'equipment'" class="feedback__grid">
			<label v-for="feature in FEATURES" :key="feature">
				{{ featureLabels()[feature] }}
				<select v-model="form.features[feature]">
					<option value="">{{ t('lagerplaner', 'no statement') }}</option>
					<option value="true">{{ t('lagerplaner', 'available') }}</option>
					<option value="partial">{{ t('lagerplaner', 'partially available') }}</option>
					<option value="false">{{ t('lagerplaner', 'not available') }}</option>
				</select>
			</label>
		</div>
		<div v-else-if="kind === 'capacity'" class="feedback__grid">
			<NcTextField v-model="form.capacity" type="number" min="1" :label="t('lagerplaner', 'Capacity (people)')" />
			<NcTextField v-model="form.beds" type="number" min="0" :label="t('lagerplaner', 'Beds')" />
		</div>
		<div v-else-if="kind === 'closed'" class="feedback__grid">
			<NcCheckboxRadioSwitch v-model="temporary" type="switch">
				{{ t('lagerplaner', 'Only temporarily') }}
			</NcCheckboxRadioSwitch>
			<NcTextField v-model="form.since" type="date" :label="t('lagerplaner', 'Closed since (optional)')" />
			<NcTextField v-if="temporary" v-model="form.until" type="date" :label="t('lagerplaner', 'Closed until')" />
		</div>
		<div v-else-if="kind === 'name'" class="feedback__grid">
			<NcTextField v-model="form.name" :label="t('lagerplaner', 'Correct name')" />
		</div>
		<div v-else-if="kind === 'location'" class="feedback__grid">
			<NcTextField v-model="form.location" :label="t('lagerplaner', 'Coordinates or map link')" :placeholder="t('lagerplaner', 'e.g. 51.0625, 7.0612')" />
		</div>
		<div v-else-if="kind === 'address'" class="feedback__grid">
			<NcTextField v-model="form.postalCode" :label="t('lagerplaner', 'Postcode')" />
			<NcTextField v-model="form.city" :label="t('lagerplaner', 'Town')" />
		</div>
		<div v-else-if="kind === 'operator'" class="feedback__grid">
			<p class="feedback__hint">
				{{ t('lagerplaner', 'A change of operator is passed on to the campsite map after checking.') }}
			</p>
			<NcTextField v-model="form.operator" :label="t('lagerplaner', 'New operator')" />
			<NcTextField v-model="form.homepage" type="url" :label="t('lagerplaner', 'Website (optional)')" placeholder="https://" />
		</div>
		<div v-else-if="kind === 'new_site'" class="feedback__grid">
			<NcTextField v-model="form.name" :label="t('lagerplaner', 'Name of the place')" />
			<label>
				{{ t('lagerplaner', 'Type') }}
				<select v-model="form.type">
					<option v-for="(label, key) in typeLabels()" :key="key" :value="key">{{ label }}</option>
				</select>
			</label>
			<NcTextField v-model="form.location" :label="t('lagerplaner', 'Coordinates or map link (optional)')" :placeholder="t('lagerplaner', 'e.g. 51.0625, 7.0612')" />
			<NcTextField v-model="form.homepage" type="url" :label="t('lagerplaner', 'Website (optional)')" placeholder="https://" />
		</div>

		<label class="feedback__message">
			{{ kind === 'outdated' ? t('lagerplaner', 'What is no longer correct?') : t('lagerplaner', 'Message (optional)') }}
			<textarea v-model="form.message" rows="3" maxlength="2000" />
		</label>
		<p class="feedback__hint">
			{{ t('lagerplaner', 'The administration checks your feedback before anything changes. Corrections can be passed on to the source of the data.') }}
		</p>
		<div class="feedback__buttons">
			<NcButton type="submit" variant="primary" :disabled="busy">
				{{ t('lagerplaner', 'Send feedback') }}
			</NcButton>
			<NcButton variant="tertiary" @click="emit('cancel')">
				{{ t('lagerplaner', 'Cancel') }}
			</NcButton>
		</div>
	</form>
</template>

<style scoped>
.feedback {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
	background: var(--color-main-background);
}

.feedback__title {
	margin: 0;
	font-weight: bold;
}

.feedback__kinds {
	border: 0;
	margin: 0;
	padding: 0;
}

.feedback__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(14em, 1fr));
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 3);
	align-items: end;
}

.feedback__grid label,
.feedback__message {
	display: flex;
	flex-direction: column;
}

.feedback__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.feedback__buttons {
	display: flex;
	gap: var(--default-grid-baseline);
}
</style>
