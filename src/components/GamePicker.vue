<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '../l10n.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { errorMessage, getGame, getGameFacets, searchGames } from '../api.js'
import { formatDay } from '../format.js'
import GameAttribution from './GameAttribution.vue'

const props = defineProps({
	/** Vorbelegung, zum Beispiel die Gruppengröße des Programmpunkts */
	groupSize: { type: Number, default: null },
})
const emit = defineEmits(['select', 'close'])

const open = ref(true)
const filters = reactive({ q: '', age: '', duration: '', groupSize: props.groupSize ? String(props.groupSize) : '', environment: '', unknownAge: true })
/** Der Katalog wird beim ersten Mal im Hintergrund eingerichtet. */
const importing = ref(false)
let poll = null
const environments = ref([])
const results = ref([])
const total = ref(0)
const license = ref(null)
const syncedAt = ref(0)
const loading = ref(false)
const unavailable = ref('')
const details = ref({})
const expanded = ref(null)

let timer = null
let requestId = 0

async function search(append = false) {
	const current = ++requestId
	loading.value = true
	try {
		const response = await searchGames({ ...filters, unknownAge: filters.unknownAge ? '1' : '0', offset: append ? results.value.length : 0 })
		if (current !== requestId) {
			return
		}
		importing.value = response.state === 'importing'
		clearTimeout(poll)
		if (importing.value) {
			poll = setTimeout(() => search(), 10000)
		}
		results.value = append ? [...results.value, ...response.items] : response.items
		total.value = response.total
		license.value = response.license
		syncedAt.value = response.syncedAt
		unavailable.value = ''
	} catch (error) {
		if (current === requestId) {
			if (error?.response?.status === 503) {
				unavailable.value = errorMessage(error)
			} else {
				showError(errorMessage(error))
			}
		}
	} finally {
		if (current === requestId) {
			loading.value = false
		}
	}
}

watch(filters, () => {
	clearTimeout(timer)
	timer = setTimeout(() => search(), 300)
})

onMounted(async () => {
	await search()
	if (!unavailable.value) {
		try {
			environments.value = (await getGameFacets()).environments
		} catch {
			// Filter nach Umgebung ist eine Bequemlichkeit.
		}
	}
})

async function toggleDetails(game) {
	expanded.value = expanded.value === game.id ? null : game.id
	if (expanded.value && !details.value[game.id]) {
		try {
			details.value = { ...details.value, [game.id]: await getGame(game.id) }
		} catch (error) {
			showError(errorMessage(error))
		}
	}
}

function facts(game) {
	const parts = []
	if (game.durationMinutes) {
		parts.push(n('lagerplaner', '%n minute', '%n minutes', game.durationMinutes))
	}
	if (game.minAge) {
		parts.push(t('lagerplaner', 'from age {age}', { age: game.minAge }))
	}
	if (game.groupMin && game.groupMax) {
		parts.push(t('lagerplaner', '{min} to {max} people', { min: game.groupMin, max: game.groupMax }))
	} else if (game.groupMin) {
		parts.push(t('lagerplaner', '{min} or more people', { min: game.groupMin }))
	}
	if (game.environments.length > 0) {
		parts.push(game.environments.join(', '))
	}
	return parts.join(', ')
}

onBeforeUnmount(() => clearTimeout(poll))

function choose(game) {
	emit('select', game)
	close()
}

function close() {
	open.value = false
	emit('close')
}
</script>

<template>
	<NcDialog
		:open="open"
		size="large"
		:name="t('lagerplaner', 'Choose a game from Pfadfinder-Spiele.de')"
		@update:open="(value) => !value && close()">
		<div class="picker">
			<NcNoteCard v-if="unavailable" type="info" :text="unavailable" />
			<NcNoteCard
				v-else-if="importing"
				type="info"
				:text="t('lagerplaner', 'The game catalogue is being set up in the background. The first time this can take a few minutes; this window updates by itself.')" />

			<template v-else>
				<div class="picker__filters">
					<NcTextField v-model="filters.q" class="picker__search" :label="t('lagerplaner', 'Search by name or type')" />
					<NcTextField v-model="filters.age" type="number" min="0" :label="t('lagerplaner', 'Youngest age')" />
					<NcTextField v-model="filters.duration" type="number" min="0" :label="t('lagerplaner', 'Max. minutes')" />
					<NcTextField v-model="filters.groupSize" type="number" min="0" :label="t('lagerplaner', 'Group size')" />
					<div v-if="environments.length > 0" class="picker__select">
						<label for="picker-environment">{{ t('lagerplaner', 'Setting') }}</label>
						<select id="picker-environment" v-model="filters.environment">
							<option value="">
								{{ t('lagerplaner', 'Any') }}
							</option>
							<option v-for="environment in environments" :key="environment" :value="environment">
								{{ environment }}
							</option>
						</select>
					</div>
				</div>

				<NcCheckboxRadioSwitch v-if="filters.age" v-model="filters.unknownAge" type="checkbox">
					{{ t('lagerplaner', 'Also show games without an age recommendation') }}
				</NcCheckboxRadioSwitch>

				<p class="picker__count" aria-live="polite">
					{{ n('lagerplaner', '%n game found', '%n games found', total) }}
					<NcLoadingIcon v-if="loading" :size="16" />
				</p>

				<NcNoteCard
					v-if="!loading && total === 0"
					type="info"
					:text="filters.groupSize
						? t('lagerplaner', 'No matching games. The group size is preset from the programme item; clear it or another filter to see more.')
						: t('lagerplaner', 'No matching games. Remove a filter to see more.')" />

				<ul class="picker__results">
					<li v-for="game in results" :key="game.id" class="game">
						<div class="game__head">
							<div>
								<span class="game__title">{{ game.title }}</span>
								<span v-if="game.knownAs.length > 0" class="game__known">
									{{ t('lagerplaner', 'also known as {names}', { names: game.knownAs.join(', ') }) }}
								</span>
								<span class="game__facts">{{ facts(game) }}</span>
								<span v-if="filters.age && !game.minAge" class="game__unknown">{{ t('lagerplaner', 'Age suitability unknown, please check') }}</span>
							</div>
							<div class="game__actions">
								<NcButton variant="tertiary" :aria-expanded="expanded === game.id" @click="toggleDetails(game)">
									{{ expanded === game.id ? t('lagerplaner', 'Hide description') : t('lagerplaner', 'Description') }}
								</NcButton>
								<NcButton variant="primary" @click="choose(game)">
									{{ t('lagerplaner', 'Use this game') }}
								</NcButton>
							</div>
						</div>
						<p v-if="game.summary" class="game__summary">
							{{ game.summary }}
						</p>
						<div v-if="expanded === game.id" class="game__details">
							<NcLoadingIcon v-if="!details[game.id]" :size="20" />
							<template v-else>
								<!-- Serverseitig auf Absätze, Listen und Hervorhebungen reduziert (HtmlSanitizer). -->
								<div class="game__description" v-html="details[game.id].description" />
								<p v-if="details[game.id].materials.length > 0">
									{{ t('lagerplaner', 'Material: {material}', { material: details[game.id].materials.join(', ') }) }}
								</p>
								<GameAttribution :title="game.title" :link="game.link" :license="details[game.id].license" />
							</template>
						</div>
					</li>
				</ul>

				<NcButton v-if="results.length < total" variant="secondary" :disabled="loading" @click="search(true)">
					{{ t('lagerplaner', 'Show more') }}
				</NcButton>

				<p v-if="license" class="picker__source">
					{{ t('lagerplaner', 'Games by {author}, licensed under {license}. Catalogue updated {date}.', {
						author: license.attribution,
						license: license.name,
						date: syncedAt ? formatDay(new Date(syncedAt * 1000).toISOString().slice(0, 10), { day: 'numeric', month: 'long', year: 'numeric' }) : '–',
					}) }}
				</p>
			</template>
		</div>
	</NcDialog>
</template>

<style scoped>
.picker {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	min-height: 50vh;
}

.picker__filters {
	display: grid;
	grid-template-columns: 2fr repeat(4, minmax(110px, 1fr));
	align-items: end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.picker__select {
	display: flex;
	flex-direction: column;
	gap: 2px;
	color: var(--color-text-maxcontrast);
}

.picker__select select {
	min-height: var(--default-clickable-area);
	margin: 0;
	color: var(--color-main-text);
}

.picker__count,
.picker__source,
.game__facts,
.game__known,
.game__summary {
	color: var(--color-text-maxcontrast);
}

.picker__count {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.picker__results {
	margin: 0;
	padding: 0;
	list-style: none;
}

.game {
	padding: calc(var(--default-grid-baseline) * 3) 0;
	border-bottom: 1px solid var(--color-border);
}

.game__head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.game__head > div:first-child {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.game__title {
	font-weight: bold;
}

.game__unknown {
	color: var(--color-warning-text);
	font-size: 0.9em;
}

.game__actions {
	display: flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.game__summary {
	margin: var(--default-grid-baseline) 0 0;
}

.game__details {
	margin-top: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.game__description :deep(p),
.game__description :deep(ul) {
	margin: 0 0 var(--default-grid-baseline);
}

.picker__source {
	margin-top: auto;
	font-size: 0.9em;
}

@media (max-width: 700px) {
	.picker__filters {
		grid-template-columns: 1fr 1fr;
	}

	.picker__search {
		grid-column: 1 / -1;
	}
}
</style>
