<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, reactive, ref, useId, watch } from 'vue'
import { errorMessage, searchSites, updateTribeHome } from '../api.js'
import { n, t } from '../l10n.js'
import { FEATURES, ageText, distanceText, featureLabels, sizeText, typeLabels } from '../sites.js'
import SiteDetail from './SiteDetail.vue'
import SiteFeedbackForm from './SiteFeedbackForm.vue'

const props = defineProps({
	/** Vorbelegung, zum Beispiel aus dem Lager: { persons, type } */
	initial: { type: Object, default: () => ({}) },
	/** Beschriftung der Aktion je Treffer; ohne Angabe keine Aktion */
	pickLabel: { type: String, default: '' },
	/** IDs, die schon gewählt sind (etwa auf der Merkliste) */
	picked: { type: Array, default: () => [] },
})
const emit = defineEmits(['pick', 'edit', 'delete', 'can-create'])
const id = useId()

const filters = reactive({ q: '', state: '', type: props.initial.type ?? '', persons: props.initial.persons ? String(props.initial.persons) : '', beds: '', distance: '', features: [], unknown: true, closed: false })
const result = ref(null)
const loading = ref(false)
/** Gewähltes Stammesheim für Entfernungen; im Browser gemerkt, wenn jemand mehrere hat */
const HOME_KEY = 'lagerplaner-home'
const home = ref(readHome())
const homeEdit = ref(null)
const homeError = ref('')

function readHome() {
	try {
		return localStorage.getItem(HOME_KEY) ?? ''
	} catch {
		return ''
	}
}

function chooseHome(value) {
	home.value = value
	try {
		localStorage.setItem(HOME_KEY, value)
	} catch {
		// Ohne Speicher gilt die Wahl nur für diese Seite.
	}
	search()
}

async function saveHome() {
	homeError.value = ''
	try {
		await updateTribeHome(result.value.home.id, homeEdit.value)
		homeEdit.value = null
		showSuccess(t('lagerplaner', 'Location of {tribe} saved', { tribe: result.value.home.label }))
		search()
	} catch (error) {
		homeError.value = errorMessage(error)
	}
}

const currentHome = computed(() => (result.value?.homes ?? []).find((entry) => entry.id === result.value?.home?.id) ?? null)
const open = ref(null)
const missing = ref(false)
/** Betriebsstatus, wenn nicht einfach „aktiv“ */
const statusLabels = () => ({ closed: t('lagerplaner', 'closed'), closing: t('lagerplaner', 'closing soon'), temporarily_unavailable: t('lagerplaner', 'temporarily unavailable'), unknown: t('lagerplaner', 'status unclear') })
let timer = null
let requestId = 0

async function search() {
	const current = ++requestId
	loading.value = true
	try {
		const response = await searchSites({ ...filters, features: filters.features.join(','), unknown: filters.unknown ? '1' : '0', closed: filters.closed ? '1' : '0', home: home.value })
		if (current === requestId) {
			result.value = response
			emit('can-create', !!response.canCreate)
		}
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		if (current === requestId) {
			loading.value = false
		}
	}
}

watch(filters, () => {
	clearTimeout(timer)
	timer = setTimeout(search, 300)
}, { deep: true })
onMounted(search)

defineExpose({ search })
</script>

<template>
	<div class="site-search">
		<div class="site-search__filters">
			<NcTextField v-model="filters.q" class="site-search__text" :label="t('lagerplaner', 'Name, town or postcode')" />
			<div v-if="result?.states?.length || filters.state" class="site-search__select">
				<label :for="`${id}-state`">{{ t('lagerplaner', 'State') }}</label>
				<select :id="`${id}-state`" v-model="filters.state">
					<option value="">
						{{ t('lagerplaner', 'All') }}
					</option>
					<option v-for="state in result?.states ?? []" :key="state" :value="state">
						{{ state }}
					</option>
				</select>
			</div>
			<div class="site-search__select">
				<label :for="`${id}-type`">{{ t('lagerplaner', 'Type') }}</label>
				<select :id="`${id}-type`" v-model="filters.type">
					<option value="">
						{{ t('lagerplaner', 'All') }}
					</option>
					<option value="tent">
						{{ typeLabels().tent }}
					</option>
					<option value="house">
						{{ typeLabels().house }}
					</option>
				</select>
			</div>
			<NcTextField v-model="filters.persons" type="number" min="1" :label="t('lagerplaner', 'People')" />
			<NcTextField v-model="filters.beds" type="number" min="1" :label="t('lagerplaner', 'Beds')" />
			<NcTextField v-if="result?.hasHome" v-model="filters.distance" type="number" min="1" :label="t('lagerplaner', 'Max. km from home')" />
		</div>
		<fieldset class="site-search__features">
			<legend>{{ t('lagerplaner', 'Must have') }}</legend>
			<NcCheckboxRadioSwitch v-for="feature in FEATURES" :key="feature" v-model="filters.features" type="checkbox" :value="feature">
				{{ featureLabels()[feature] }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<NcCheckboxRadioSwitch v-model="filters.closed" type="switch">
			{{ t('lagerplaner', 'Also show closed places') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch v-model="filters.unknown" type="switch">
			{{ t('lagerplaner', 'Also show places where this information is unknown') }}
		</NcCheckboxRadioSwitch>

		<div v-if="result?.home" class="site-search__home">
			<template v-if="result.homes.length > 1">
				<label :for="`${id}-home`">{{ t('lagerplaner', 'Distance from') }}</label>
				<select :id="`${id}-home`" :value="result.home.id" @change="chooseHome($event.target.value)">
					<option v-for="entry in result.homes" :key="entry.id" :value="entry.id">
						{{ entry.label }}
					</option>
				</select>
			</template>
			<span v-else>{{ t('lagerplaner', 'Distance from {home}', { home: result.home.label }) }}</span>
			<NcButton v-if="currentHome?.editable && homeEdit === null" variant="tertiary" size="small" @click="homeEdit = ''; homeError = ''">
				{{ t('lagerplaner', 'Change location') }}
			</NcButton>
			<form v-if="homeEdit !== null" class="site-search__home-form" @submit.prevent="saveHome">
				<NcTextField
					v-model="homeEdit"
					:label="t('lagerplaner', 'Coordinates or map link of the scout hall')"
					:placeholder="t('lagerplaner', 'e.g. 51.0625, 7.0612')"
					:error="!!homeError"
					:helper-text="homeError" />
				<NcButton type="submit" variant="primary" :disabled="!homeEdit.trim()">
					{{ t('lagerplaner', 'Save') }}
				</NcButton>
				<NcButton variant="tertiary" @click="homeEdit = null">
					{{ t('lagerplaner', 'Cancel') }}
				</NcButton>
			</form>
		</div>
		<div class="site-search__missing">
			<NcButton v-if="!missing" variant="tertiary" @click="missing = true">
				{{ t('lagerplaner', 'Campsite missing? Report it') }}
			</NcButton>
			<SiteFeedbackForm v-else @done="missing = false" @cancel="missing = false" />
		</div>
		<p class="site-search__count" aria-live="polite">
			{{ result ? n('lagerplaner', '%n place found', '%n places found', result.total) : '' }}
			<NcLoadingIcon v-if="loading" :size="16" />
		</p>
		<NcNoteCard v-if="result?.notice" type="info" :text="result.notice" />
		<p v-if="result && result.total === 0 && !loading" class="site-search__empty">
			{{ t('lagerplaner', 'No places match. Remove a filter, search by the first digits of a postcode, or add a place you know yourself.') }}
		</p>

		<ul v-if="result" class="site-search__results">
			<li v-for="site in result.items" :key="site.id" class="site">
				<div class="site__head">
					<button type="button" class="site__name" :aria-expanded="open === site.id" @click="open = open === site.id ? null : site.id">
						{{ site.name }}
					</button>
					<span v-if="statusLabels()[site.operatingStatus]" class="site__status" :class="`site__status--${site.operatingStatus}`">{{ statusLabels()[site.operatingStatus] }}</span>
					<span class="site__facts">
						{{ typeLabels()[site.type] }}, {{ [site.street, [site.postalCode, site.city].filter(Boolean).join(' '), site.state].filter(Boolean).join(', ') || (site.distanceKm !== null ? t('lagerplaner', 'address unknown') : t('lagerplaner', 'location unknown')) }}<template v-if="site.distanceKm !== null">, {{ distanceText(site.distanceKm) }}</template>
					</span>
					<NcButton
						v-if="pickLabel"
						class="site__pick"
						:variant="picked.includes(site.id) ? 'tertiary' : 'secondary'"
						:disabled="picked.includes(site.id)"
						@click="emit('pick', site)">
						{{ picked.includes(site.id) ? t('lagerplaner', 'On the list') : pickLabel }}
					</NcButton>
				</div>
				<span class="site__details">
					{{ sizeText(site) }}
					<span v-if="site.unknown.some((key) => !key.startsWith('partial:'))" class="site__unknown">{{ t('lagerplaner', 'Unknown: {list}', { list: site.unknown.filter((key) => !key.startsWith('partial:')).map((key) => featureLabels()[key] ?? key).join(', ') }) }}</span>
					<span v-if="site.unknown.some((key) => key.startsWith('partial:'))" class="site__unknown">{{ t('lagerplaner', 'Only partially: {list}', { list: site.unknown.filter((key) => key.startsWith('partial:')).map((key) => featureLabels()[key.slice(8)] ?? key).join(', ') }) }}</span>
					<span v-if="site.missing" class="site__warning">{{ t('lagerplaner', 'no longer in the source') }}</span>
					<span v-else-if="site.old" class="site__warning">{{ t('lagerplaner', 'information is old') }}</span>
					<span>
						{{ t('lagerplaner', 'Source:') }}
						<a v-if="site.sourceLink" :href="site.sourceLink" target="_blank" rel="noopener noreferrer" @click.stop>{{ site.sourceLabel }}</a>
						<template v-else>
							{{ site.sourceLabel }}
						</template>
						<template v-if="ageText(site)">
							, {{ ageText(site) }}
						</template>
					</span>
				</span>
				<SiteDetail v-if="open === site.id" :site-id="site.id" @edit="(s) => emit('edit', s)" @delete="(s) => emit('delete', s)" />
			</li>
		</ul>
	</div>
</template>

<style scoped>
.site-search {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.site-search__filters {
	display: grid;
	grid-template-columns: 2fr repeat(5, minmax(100px, 1fr));
	align-items: end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.site-search__select {
	display: flex;
	flex-direction: column;
	gap: 2px;
	color: var(--color-text-maxcontrast);
}

.site-search__select select {
	min-height: var(--default-clickable-area);
	margin: 0;
	color: var(--color-main-text);
}

.site-search__features {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 3);
}

.site-search__features legend {
	width: 100%;
	color: var(--color-text-maxcontrast);
}

.site-search__count,
.site-search__empty,
.site__facts,
.site__details {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.site-search__count {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.site-search__results {
	margin: 0;
	padding: 0;
	list-style: none;
}

.site {
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.site__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0 calc(var(--default-grid-baseline) * 3);
}

.site__name {
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font-weight: bold;
	font-size: 1.05em;
	text-align: start;
	cursor: pointer;
}

.site__name:hover,
.site__name:focus-visible {
	text-decoration: underline;
}

.site__pick {
	margin-inline-start: auto;
}

.site__details {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 3);
	font-size: 0.9em;
}

.site__unknown {
	font-style: italic;
}

.site__warning {
	color: var(--color-warning-text);
}

@media (max-width: 800px) {
	.site-search__filters {
		grid-template-columns: 1fr 1fr;
	}

	.site-search__text {
		grid-column: 1 / -1;
	}
}
.site-search__home {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin: var(--default-grid-baseline) 0;
}

.site-search__home select {
	margin: 0;
}

.site-search__home-form {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	width: 100%;
	max-width: 600px;
}
.site-search__missing {
	margin: var(--default-grid-baseline) 0;
}

.site__status {
	margin-inline-start: var(--default-grid-baseline);
	padding: 0 calc(var(--default-grid-baseline) * 1.5);
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-warning);
	color: var(--color-warning-text, #000);
	font-size: 0.8em;
}

.site__status--closed {
	background: var(--color-error);
	color: var(--color-primary-element-text, #fff);
}
</style>
