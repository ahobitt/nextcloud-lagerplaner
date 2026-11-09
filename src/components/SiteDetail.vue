<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, onMounted, ref } from 'vue'
import { correctSite, errorMessage, getSite } from '../api.js'
import { formatDay } from '../format.js'
import { t } from '../l10n.js'
import { FEATURES, featureLabels, featureState, featureStateLabels } from '../sites.js'
import SiteFeedbackForm from './SiteFeedbackForm.vue'

const props = defineProps({
	siteId: { type: Number, required: true },
})
const emit = defineEmits(['edit', 'delete'])
const site = ref(null)

onMounted(async () => {
	try {
		site.value = await getSite(props.siteId)
	} catch (error) {
		showError(errorMessage(error))
	}
})

/** Herkunft einer Angabe, wenn sie nicht aus der Quelle des Platzes stammt */
const origin = (attribute) => [
	site.value?.facts?.[attribute]?.note,
	// bei Rückmeldungen nennt der Vermerk schon die Herkunft („Rückmeldung von …“)
	site.value?.facts?.[attribute]?.method === 'feedback' && site.value?.facts?.[attribute]?.note ? null : undefined,
	({
		website: t('lagerplaner', 'found on the website'),
		geocoding: t('lagerplaner', 'determined from the coordinates'),
		feedback: site.value?.facts?.[attribute]?.note ? '' : t('lagerplaner', 'from feedback'),
		manual: site.value?.source === 'manual' ? '' : t('lagerplaner', 'added by hand'),
	})[site.value?.facts?.[attribute]?.method],
].filter(Boolean).join(', ')

const feedback = ref(false)
const statusText = computed(() => {
	const s = site.value
	if (!s?.operatingStatus || s.operatingStatus === 'active') {
		return null
	}
	return ({
		closed: s.statusFrom ? t('lagerplaner', 'Closed since {date}.', { date: formatDay(s.statusFrom, { day: 'numeric', month: 'long', year: 'numeric' }) }) : t('lagerplaner', 'This place is closed.'),
		closing: s.statusFrom ? t('lagerplaner', 'Closes on {date}.', { date: formatDay(s.statusFrom, { day: 'numeric', month: 'long', year: 'numeric' }) }) : t('lagerplaner', 'This place is closing.'),
		temporarily_unavailable: s.statusUntil ? t('lagerplaner', 'Temporarily unavailable until {date}.', { date: formatDay(s.statusUntil, { day: 'numeric', month: 'long', year: 'numeric' }) }) : t('lagerplaner', 'Temporarily unavailable.'),
		unknown: t('lagerplaner', 'It is unclear whether this place can currently be booked.'),
	})[s.operatingStatus] ?? null
})

/* Angaben ergänzen (Administration, importierte Plätze) */
const correction = ref(null)
const busy = ref(false)
function startCorrection() {
	correction.value = {
		capacity: site.value.capacity ?? '',
		beds: site.value.beds ?? '',
		priceText: site.value.priceText ?? '',
		features: Object.fromEntries(FEATURES.map((f) => [f, site.value.features[f] === null ? '' : String(site.value.features[f])])),
		evidenceUrl: '',
	}
}
async function saveCorrection() {
	// Zahlen kommen dank v-model.number als Zahl; leere Felder als ''
	const value = (v) => (v === '' || v === null || Number.isNaN(v) ? null : v)
	const data = {
		capacity: value(correction.value.capacity),
		beds: value(correction.value.beds),
		priceText: value(correction.value.priceText),
		features: Object.fromEntries(FEATURES.map((f) => [f, ({ '': null, true: true, false: false, partial: 'partial' })[correction.value.features[f]] ?? null])),
	}
	busy.value = true
	try {
		site.value = await correctSite(site.value.id, data, correction.value.evidenceUrl)
		correction.value = null
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

const day = (timestamp) => formatDay(new Date(timestamp * 1000).toISOString().slice(0, 10), { day: 'numeric', month: 'long', year: 'numeric' })
</script>

<template>
	<div class="site-detail">
		<NcLoadingIcon v-if="!site" :size="24" />
		<template v-else>
			<NcNoteCard v-if="statusText" :type="site.operatingStatus === 'closed' ? 'error' : 'warning'" :text="statusText" />
			<NcNoteCard v-if="site.reviewNote" type="info" :text="t('lagerplaner', 'The information is still being checked: {reason}', { reason: site.reviewNote })" />
			<NcNoteCard v-if="site.missing" type="warning" :text="t('lagerplaner', 'This entry is no longer in the latest data from the source. It may no longer exist; please check before planning.')" />
			<NcNoteCard v-else-if="site.old" type="warning" :text="t('lagerplaner', 'This information is more than a year old. Please confirm it with the operator.')" />
			<p v-if="site.street || site.city" class="site-detail__address">
				{{ [site.street, [site.postalCode, site.city].filter(Boolean).join(' '), site.state].filter(Boolean).join(', ') }}<span v-if="origin('address')" class="site-detail__origin"> ({{ origin('address') }})</span>
			</p>
			<ul class="site-detail__features">
				<li v-for="feature in FEATURES" :key="feature" :class="`site-detail__feature--${featureState(site.features[feature])}`">
					{{ featureLabels()[feature] }}: {{ featureStateLabels()[featureState(site.features[feature])] }}<span v-if="origin(feature)" class="site-detail__origin"> ({{ origin(feature) }})</span>
				</li>
			</ul>
			<p v-if="site.capacity !== null || site.beds !== null">
				<template v-if="site.capacity !== null">
					{{ t('lagerplaner', 'Capacity: {count} people', { count: site.capacity }) }}<span v-if="origin('capacity')" class="site-detail__origin"> ({{ origin('capacity') }})</span>
					<span v-if="site.capacityNote" class="site-detail__origin">– {{ site.capacityNote }}</span>
				</template>
				<template v-if="site.beds !== null">
					<template v-if="site.capacity !== null">
						·
					</template>
					{{ t('lagerplaner', 'Beds: {count}', { count: site.beds }) }}<span v-if="origin('beds')" class="site-detail__origin"> ({{ origin('beds') }})</span>
				</template>
			</p>
			<p v-if="site.priceText">
				{{ t('lagerplaner', 'Price according to the source (not binding): {price}', { price: site.priceText }) }}
			</p>
			<p v-if="site.description" class="site-detail__description">
				{{ site.description }}
			</p>
			<p class="site-detail__links">
				<a v-if="site.homepage" :href="site.homepage" target="_blank" rel="noopener noreferrer">{{ t('lagerplaner', 'Website of the operator') }}</a>
				<a v-if="site.osmUrl" :href="site.osmUrl" target="_blank" rel="noopener noreferrer">{{ t('lagerplaner', 'Show on OpenStreetMap') }}</a>
			</p>
			<p class="site-detail__availability">
				{{ t('lagerplaner', 'A directory entry says nothing about availability on your dates. Ask the operator.') }}
			</p>
			<dl v-if="site.source !== 'manual'" class="site-detail__source">
				<dt>{{ t('lagerplaner', 'Source') }}</dt>
				<dd>
					<a v-if="site.sourceLink" :href="site.sourceLink" target="_blank" rel="noopener noreferrer">{{ site.sourceLabel }}</a>
					<template v-else>
						{{ site.sourceLabel }}
					</template>
					<template v-if="site.sourceUrl">
						, <a :href="site.sourceUrl" target="_blank" rel="noopener noreferrer">{{ t('lagerplaner', 'original entry') }}</a>
					</template>
				</dd>
				<dt>{{ t('lagerplaner', 'As of according to the source') }}</dt>
				<dd>{{ site.sourceDate ? formatDay(site.sourceDate, { day: 'numeric', month: 'long', year: 'numeric' }) : t('lagerplaner', 'unknown') }}</dd>
				<dt>{{ t('lagerplaner', 'Imported') }}</dt>
				<dd>{{ site.importedAt ? day(site.importedAt) : t('lagerplaner', 'unknown') }}<template v-if="site.snapshotVersion">, {{ t('lagerplaner', 'data version {version}', { version: site.snapshotVersion }) }}</template></dd>
				<dt>{{ t('lagerplaner', 'Confirmed by the operator') }}</dt>
				<dd>{{ site.operatorConfirmedAt ? day(site.operatorConfirmedAt) : t('lagerplaner', 'no') }}</dd>
			</dl>
			<p v-else class="site-detail__source">
				{{ t('lagerplaner', 'Own entry of this Nextcloud, not published anywhere else.') }}
			</p>
			<div class="site-detail__buttons">
				<NcButton v-if="!feedback" variant="secondary" @click="feedback = true">
					{{ t('lagerplaner', 'Feedback / add details') }}
				</NcButton>
				<NcButton v-if="site.canCorrect && !correction" variant="tertiary" @click="startCorrection">
					{{ t('lagerplaner', 'Correct directly') }}
				</NcButton>
			</div>
			<SiteFeedbackForm v-if="feedback" :site-id="site.id" :site-name="site.name" @done="feedback = false" @cancel="feedback = false" />
			<form v-if="correction" class="site-detail__correction" @submit.prevent="saveCorrection">
				<p class="site-detail__availability">
					{{ t('lagerplaner', 'These details belong to the camp planner and are kept when the source is updated. Leave a field empty if it is unknown.') }}
				</p>
				<label>{{ t('lagerplaner', 'Capacity (people)') }} <input v-model.number="correction.capacity" type="number" min="1"></label>
				<label>{{ t('lagerplaner', 'Beds') }} <input v-model.number="correction.beds" type="number" min="0"></label>
				<label v-for="feature in FEATURES" :key="feature">
					{{ featureLabels()[feature] }}
					<select v-model="correction.features[feature]">
						<option value="">{{ t('lagerplaner', 'unknown') }}</option>
						<option value="true">{{ t('lagerplaner', 'Yes') }}</option>
						<option value="partial">{{ t('lagerplaner', 'partially') }}</option>
						<option value="false">{{ t('lagerplaner', 'No') }}</option>
					</select>
				</label>
				<label>{{ t('lagerplaner', 'Price') }} <input v-model="correction.priceText" type="text" maxlength="255"></label>
				<label>{{ t('lagerplaner', 'Evidence (link, optional)') }} <input v-model="correction.evidenceUrl" type="url"></label>
				<div class="site-detail__buttons">
					<NcButton type="submit" variant="primary" :disabled="busy">
						{{ t('lagerplaner', 'Save') }}
					</NcButton>
					<NcButton variant="tertiary" @click="correction = null">
						{{ t('lagerplaner', 'Cancel') }}
					</NcButton>
				</div>
			</form>
			<div v-if="site.canEdit" class="site-detail__buttons">
				<NcButton variant="secondary" @click="emit('edit', site)">
					{{ t('lagerplaner', 'Edit') }}
				</NcButton>
				<NcButton variant="tertiary" @click="emit('delete', site)">
					{{ t('lagerplaner', 'Delete') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<style scoped>
.site-detail {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 3);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.site-detail p {
	margin: 0;
}

.site-detail__features {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 4);
	margin: 0;
	padding: 0;
	list-style: none;
}

.site-detail__feature--no {
	color: var(--color-text-maxcontrast);
}

.site-detail__feature--unknown {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.site-detail__description {
	white-space: pre-line;
}

.site-detail__links {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 4);
}

.site-detail__links a,
.site-detail__source a {
	text-decoration: underline;
}

.site-detail__availability,
.site-detail__source {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.site-detail__source {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px calc(var(--default-grid-baseline) * 3);
	margin: var(--default-grid-baseline) 0 0;
}

.site-detail__source dd {
	margin: 0;
}

.site-detail__buttons {
	display: flex;
	gap: var(--default-grid-baseline);
}
.site-detail__origin {
	color: var(--color-text-maxcontrast);
}

.site-detail__correction {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(14em, 1fr));
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 3);
}

.site-detail__correction label {
	display: flex;
	flex-direction: column;
}

.site-detail__correction p,
.site-detail__correction .site-detail__buttons {
	grid-column: 1 / -1;
}
</style>
