<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiMagnify, mdiStar, mdiStarOutline } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, onMounted, ref } from 'vue'
import { addToShortlist, adoptSite, errorMessage, getShortlist, removeFromShortlist, updateShortlist } from '../api.js'
import SiteSearch from '../components/SiteSearch.vue'
import { t } from '../l10n.js'
import { FEATURES, ageText, distanceText, featureLabels, featureState, featureStateLabels, featureSymbol, sizeText, typeLabels } from '../sites.js'

const props = defineProps({
	campId: { type: Number, required: true },
	campType: { type: String, default: 'tent' },
	can: { type: Object, required: true },
})
const emit = defineEmits(['location-changed'])

const loading = ref(true)
const list = ref(null)
const searchOpen = ref(false)
const notes = ref({})

async function load() {
	try {
		list.value = await getShortlist(props.campId)
		notes.value = Object.fromEntries(list.value.items.map((item) => [item.entryId, item.notes ?? '']))
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		loading.value = false
	}
}
onMounted(load)

async function act(action, message = '') {
	try {
		await action()
		await load()
		if (message) {
			showSuccess(message)
		}
		return true
	} catch (error) {
		showError(errorMessage(error))
		return false
	}
}

const picked = computed(() => (list.value?.items ?? []).map((item) => item.site.id))

async function adopt(item) {
	if (await act(() => adoptSite(item.entryId), t('lagerplaner', '{name} is now the camp location.', { name: item.site.name }))) {
		emit('location-changed')
	}
}

function saveNotes(item) {
	if ((item.notes ?? '') !== notes.value[item.entryId]) {
		act(() => updateShortlist(item.entryId, { notes: notes.value[item.entryId] }))
	}
}
</script>

<template>
	<div class="shortlist">
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else-if="list">
			<header class="shortlist__header">
				<p class="shortlist__location">
					<template v-if="list.location.known">
						{{ t('lagerplaner', 'Camp location: {name}', { name: list.location.name }) }}
					</template>
					<template v-else>
						{{ t('lagerplaner', 'The camp location is not decided yet. Collect candidates here, compare them and note where you have asked.') }}
					</template>
				</p>
				<NcButton v-if="can.sites" @click="searchOpen = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiMagnify" />
					</template>
					{{ t('lagerplaner', 'Find places') }}
				</NcButton>
			</header>

			<NcNoteCard type="info" :text="t('lagerplaner', 'The status is only for your own overview. The app does not book anything with the operator.')" />

			<p v-if="list.items.length === 0" class="shortlist__empty">
				{{ t('lagerplaner', 'No places on the list yet.') }}
			</p>

			<div v-else class="shortlist__scroll">
				<!-- Vergleich: je Platz eine Spalte, je Kriterium eine Zeile (Konzept 5.9) -->
				<table class="shortlist__table">
					<thead>
						<tr>
							<td />
							<th v-for="item in list.items" :key="item.entryId" scope="col" :class="{ 'shortlist__col--preferred': item.preferred }">
								<div class="shortlist__place">
									<NcButton
										v-if="can.sites"
										variant="tertiary"
										size="small"
										:aria-label="item.preferred ? t('lagerplaner', '{name} is the preferred option', { name: item.site.name }) : t('lagerplaner', 'Mark {name} as preferred option', { name: item.site.name })"
										:aria-pressed="item.preferred"
										@click="act(() => updateShortlist(item.entryId, { preferred: !item.preferred }))">
										<template #icon>
											<NcIconSvgWrapper :path="item.preferred ? mdiStar : mdiStarOutline" :size="18" />
										</template>
									</NcButton>
									<span>
										<strong>{{ item.site.name }}</strong>
										<span v-if="item.site.mergedFrom" class="shortlist__merged">{{ t('lagerplaner', 'formerly listed as {name}', { name: item.site.mergedFrom }) }}</span>
										<span class="shortlist__meta">{{ [item.site.city, item.site.state].filter(Boolean).join(', ') }}</span>
										<span v-if="item.preferred" class="shortlist__meta">{{ t('lagerplaner', 'preferred option') }}</span>
									</span>
								</div>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<th scope="row">
								{{ t('lagerplaner', 'Type') }}
							</th>
							<td v-for="item in list.items" :key="item.entryId">
								{{ typeLabels()[item.site.type] }}
							</td>
						</tr>
						<tr>
							<th scope="row">
								{{ t('lagerplaner', 'Size') }}
							</th>
							<td v-for="item in list.items" :key="item.entryId">
								{{ sizeText(item.site) }}
								<span v-if="item.tooSmall" class="shortlist__warning">{{ t('lagerplaner', 'too small for {people} people', { people: list.people }) }}</span>
							</td>
						</tr>
						<tr v-if="list.items.some((item) => item.site.distanceKm !== null)">
							<th scope="row">
								{{ t('lagerplaner', 'Distance') }}
							</th>
							<td v-for="item in list.items" :key="item.entryId">
								{{ item.site.distanceKm !== null ? distanceText(item.site.distanceKm) : t('lagerplaner', 'unknown') }}
							</td>
						</tr>
						<tr v-for="feature in FEATURES" :key="feature">
							<th scope="row">
								{{ featureLabels()[feature] }}
							</th>
							<td v-for="item in list.items" :key="item.entryId" :class="`shortlist__feature--${featureState(item.site.features[feature])}`">
								<span aria-hidden="true" class="shortlist__symbol">{{ featureSymbol[featureState(item.site.features[feature])] }}</span>
								{{ featureStateLabels()[featureState(item.site.features[feature])] }}
							</td>
						</tr>
						<tr>
							<th scope="row">
								{{ t('lagerplaner', 'Price') }}
							</th>
							<td v-for="item in list.items" :key="item.entryId">
								{{ item.site.priceText ?? t('lagerplaner', 'unknown') }}
							</td>
						</tr>
						<tr>
							<th scope="row">
								{{ t('lagerplaner', 'Information') }}
							</th>
							<td v-for="item in list.items" :key="item.entryId" class="shortlist__meta">
								{{ item.site.sourceLabel }}<template v-if="ageText(item.site)">, {{ ageText(item.site) }}</template>
								<span v-if="item.site.missing" class="shortlist__warning">{{ t('lagerplaner', 'no longer in the source') }}</span>
								<span v-else-if="item.site.old" class="shortlist__warning">{{ t('lagerplaner', 'information is old') }}</span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								{{ t('lagerplaner', 'Status') }}
							</th>
							<td v-for="item in list.items" :key="item.entryId">
								<select
									v-if="can.sites"
									:value="item.status"
									:aria-label="t('lagerplaner', 'Status of {name}', { name: item.site.name })"
									@change="act(() => updateShortlist(item.entryId, { status: $event.target.value }))">
									<option v-for="status in list.statuses" :key="status.key" :value="status.key">
										{{ status.label }}
									</option>
								</select>
								<span v-else>{{ item.statusLabel }}</span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								{{ t('lagerplaner', 'Own notes (only for this camp)') }}
							</th>
							<td v-for="item in list.items" :key="item.entryId">
								<textarea
									v-model="notes[item.entryId]"
									class="shortlist__notes"
									rows="3"
									:readonly="!can.sites"
									:aria-label="t('lagerplaner', 'Notes on {name}', { name: item.site.name })"
									:placeholder="can.sites ? t('lagerplaner', 'Experience, directions, contact for booking …') : ''"
									@blur="saveNotes(item)" />
							</td>
						</tr>
						<tr v-if="can.sites">
							<td />
							<td v-for="item in list.items" :key="item.entryId">
								<div class="shortlist__actions">
									<NcButton variant="secondary" size="small" @click="adopt(item)">
										{{ t('lagerplaner', 'Set as camp location') }}
									</NcButton>
									<NcButton variant="tertiary" size="small" @click="act(() => removeFromShortlist(item.entryId))">
										{{ t('lagerplaner', 'Remove from list') }}
									</NcButton>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<NcDialog
				v-if="searchOpen"
				:open="true"
				size="large"
				:name="t('lagerplaner', 'Find places')"
				@update:open="(value) => { if (!value) { searchOpen = false } }">
				<SiteSearch
					:initial="{ persons: list.people || null, type: campType === 'house' ? 'house' : 'tent' }"
					:pick-label="t('lagerplaner', 'Add to list')"
					:picked="picked"
					@pick="(site) => act(() => addToShortlist(campId, site.id), t('lagerplaner', '{name} added to the list.', { name: site.name }))" />
			</NcDialog>
		</template>
	</div>
</template>

<style scoped>
.shortlist {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.shortlist__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 3);
}

.shortlist__location,
.shortlist__empty {
	margin: 0;
}

.shortlist__scroll {
	overflow-x: auto;
}

.shortlist__table {
	border-collapse: collapse;
}

.shortlist__table th,
.shortlist__table td {
	min-width: 200px;
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 3);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.shortlist__table tbody th {
	min-width: 150px;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.shortlist__place {
	display: flex;
	gap: var(--default-grid-baseline);
}

.shortlist__place > span {
	display: flex;
	flex-direction: column;
}

.shortlist__col--preferred {
	background: var(--color-primary-element-light);
}

.shortlist__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	font-weight: normal;
}

.shortlist__warning {
	display: block;
	color: var(--color-warning-text);
	font-size: 0.9em;
}

.shortlist__symbol {
	display: inline-block;
	width: 1.2em;
	font-weight: bold;
}

.shortlist__feature--no,
.shortlist__feature--unknown {
	color: var(--color-text-maxcontrast);
}

.shortlist__feature--unknown {
	font-style: italic;
}

.shortlist__table select {
	min-height: var(--default-clickable-area);
	margin: 0;
}

.shortlist__notes {
	width: 100%;
	min-width: 180px;
}

.shortlist__actions {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}
</style>
