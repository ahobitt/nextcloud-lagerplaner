<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { reactive, useId } from 'vue'
import { t } from '../l10n.js'
import { FEATURES, featureLabels, typeLabels } from '../sites.js'

const props = defineProps({
	site: { type: Object, default: null },
	busy: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'cancel'])
const id = useId()

const toState = (value) => (value === true ? 'yes' : value === false ? 'no' : value === 'partial' ? 'partial' : '')
const form = reactive({
	name: props.site?.name ?? '',
	type: props.site?.type ?? 'tent',
	street: props.site?.street ?? '',
	postalCode: props.site?.postalCode ?? '',
	city: props.site?.city ?? '',
	state: props.site?.state ?? '',
	capacity: props.site?.capacity ?? '',
	beds: props.site?.beds ?? '',
	priceText: props.site?.priceText ?? '',
	description: props.site?.description ?? '',
	homepage: props.site?.homepage ?? '',
	features: Object.fromEntries(FEATURES.map((key) => [key, toState(props.site?.features?.[key])])),
})

/** Ganze Zahl oder null; 0 bleibt 0 (etwa 0 Betten auf einem reinen Zeltplatz) */
const toInt = (value) => {
	if (value === '' || value === null || value === undefined) {
		return null
	}
	const parsed = Number.parseInt(value, 10)
	return Number.isNaN(parsed) ? null : parsed
}

function submit() {
	emit('save', {
		name: form.name,
		type: form.type,
		address: { street: form.street, postalCode: form.postalCode, city: form.city, state: form.state },
		capacity: toInt(form.capacity),
		beds: toInt(form.beds),
		priceText: form.priceText,
		description: form.description,
		homepage: form.homepage,
		features: Object.fromEntries(FEATURES.map((key) => [key, ({ yes: true, no: false, partial: 'partial' })[form.features[key]] ?? null])),
	})
}
</script>

<template>
	<form class="site-form" @submit.prevent="submit">
		<div class="site-form__row">
			<NcTextField v-model="form.name" :label="t('lagerplaner', 'Name')" />
			<div class="site-form__select">
				<label :for="`${id}-type`">{{ t('lagerplaner', 'Type') }}</label>
				<select :id="`${id}-type`" v-model="form.type">
					<option v-for="(label, key) in typeLabels()" :key="key" :value="key">
						{{ label }}
					</option>
				</select>
			</div>
		</div>
		<div class="site-form__row">
			<NcTextField v-model="form.street" :label="t('lagerplaner', 'Street')" />
			<NcTextField v-model="form.postalCode" :label="t('lagerplaner', 'Postcode')" />
			<NcTextField v-model="form.city" :label="t('lagerplaner', 'Town')" />
			<NcTextField v-model="form.state" :label="t('lagerplaner', 'State')" />
		</div>
		<div class="site-form__row">
			<NcTextField v-model="form.capacity" type="number" min="1" :label="t('lagerplaner', 'Capacity (people)')" />
			<NcTextField v-model="form.beds" type="number" min="0" :label="t('lagerplaner', 'Beds')" />
			<NcTextField v-model="form.priceText" :label="t('lagerplaner', 'Price, as text')" />
		</div>
		<fieldset>
			<legend>{{ t('lagerplaner', 'Facilities; leave “unknown” if you are not sure') }}</legend>
			<div class="site-form__features">
				<div v-for="feature in FEATURES" :key="feature" class="site-form__select">
					<label :for="`${id}-${feature}`">{{ featureLabels()[feature] }}</label>
					<select :id="`${id}-${feature}`" v-model="form.features[feature]">
						<option value="">
							{{ t('lagerplaner', 'unknown') }}
						</option>
						<option value="yes">
							{{ t('lagerplaner', 'available') }}
						</option>
						<option value="partial">
							{{ t('lagerplaner', 'partially available') }}
						</option>
						<option value="no">
							{{ t('lagerplaner', 'not available') }}
						</option>
					</select>
				</div>
			</div>
		</fieldset>
		<NcTextField v-model="form.homepage" :label="t('lagerplaner', 'Website (optional)')" />
		<div class="site-form__textarea">
			<label :for="`${id}-description`">{{ t('lagerplaner', 'Description (optional)') }}</label>
			<textarea :id="`${id}-description`" v-model="form.description" rows="3" />
		</div>
		<p class="site-form__hint">
			{{ t('lagerplaner', 'Visible to everyone in this Nextcloud. Please do not enter names, email addresses or phone numbers of private persons.') }}
		</p>
		<div class="site-form__row">
			<NcButton type="submit" variant="primary" :disabled="busy">
				{{ t('lagerplaner', 'Save') }}
			</NcButton>
			<NcButton variant="tertiary" @click="emit('cancel')">
				{{ t('lagerplaner', 'Cancel') }}
			</NcButton>
		</div>
	</form>
</template>

<style scoped>
.site-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large);
}

.site-form__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.site-form__row > * {
	flex: 1 1 160px;
}

.site-form__features {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
}

.site-form__select,
.site-form__textarea {
	display: flex;
	flex-direction: column;
	gap: 2px;
	color: var(--color-text-maxcontrast);
}

.site-form__select select {
	min-height: var(--default-clickable-area);
	margin: 0;
	color: var(--color-main-text);
}

.site-form__textarea textarea {
	width: 100%;
	color: var(--color-main-text);
}

.site-form__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.site-form legend {
	font-weight: bold;
}
</style>
