<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ref } from 'vue'
import { errorMessage } from '../api.js'
import { n, t } from '../l10n.js'
import { FEATURES, featureLabels, feedbackKindLabels, typeLabels } from '../sites.js'

const state = ref(loadState('lagerplaner', 'admin', { home: null, groupHomes: [], games: { enabled: true, count: 0, syncedAt: 0, state: 'disabled' }, sources: [], bundled: [], autoSync: true, upstream: [], feedbackOpen: 0 }))
const homeInput = ref('')
const homeError = ref('')
const busy = ref(false)

const api = (path) => generateUrl('/apps/lagerplaner/api/admin' + path)

async function save(request, message = '') {
	busy.value = true
	try {
		state.value = (await request()).data
		if (message) {
			showSuccess(message)
		}
		return true
	} catch (error) {
		showError(errorMessage(error))
		return false
	} finally {
		busy.value = false
	}
}

async function saveHome() {
	homeError.value = ''
	busy.value = true
	try {
		state.value = (await axios.put(api('/home'), { input: homeInput.value })).data
		homeInput.value = ''
		showSuccess(state.value.home ? t('lagerplaner', 'Location of the scout hall saved') : t('lagerplaner', 'Location of the scout hall removed'))
	} catch (error) {
		homeError.value = errorMessage(error)
	} finally {
		busy.value = false
	}
}

function removeHome() {
	homeInput.value = ''
	saveHome()
}

/* Stämme: je Nextcloud-Gruppe ein Stammesheim */
const tribe = ref(null)
const groupQuery = ref('')
const groupResults = ref([])
const tribeForm = ref({ label: '', input: '' })
const tribeError = ref('')
let groupTimer

function searchGroups() {
	clearTimeout(groupTimer)
	groupTimer = setTimeout(async () => {
		if (groupQuery.value.trim() === '') {
			groupResults.value = []
			return
		}
		try {
			groupResults.value = (await axios.get(api('/groups'), { params: { q: groupQuery.value } })).data
		} catch (error) {
			showError(errorMessage(error))
		}
	}, 250)
}

function chooseGroup(group) {
	tribe.value = { id: group.id, name: group.label, editing: false }
	tribeForm.value = { label: group.label, input: '' }
	groupResults.value = []
	groupQuery.value = ''
}

function editTribe(home) {
	tribe.value = { id: home.groupId, name: home.groupName, editing: true }
	tribeForm.value = { label: home.label, input: home.text }
	tribeError.value = ''
}

async function saveTribe() {
	tribeError.value = ''
	busy.value = true
	try {
		state.value = (await axios.put(api('/homes/' + encodeURIComponent(tribe.value.id)), tribeForm.value)).data
		showSuccess(t('lagerplaner', 'Scout hall of {tribe} saved', { tribe: tribeForm.value.label || tribe.value.name }))
		tribe.value = null
	} catch (error) {
		tribeError.value = errorMessage(error)
	} finally {
		busy.value = false
	}
}

function removeTribe(home) {
	save(() => axios.delete(api('/homes/' + encodeURIComponent(home.groupId))), t('lagerplaner', '{tribe} is no longer a tribe with its own scout hall', { tribe: home.label }))
}

/* Datenstände ohne occ: mitgelieferten übernehmen oder Datei hochladen */
const uploadFile = ref(null)
const fileInput = ref(null)

const statsText = (stats) => t('lagerplaner', '{added} new, {updated} updated, {missing} no longer in the source, {skipped} skipped', stats)

async function importBundled(entry) {
	busy.value = true
	try {
		const result = (await axios.post(api('/sites/bundled/' + encodeURIComponent(entry.source)))).data
		state.value = result.state
		showSuccess(t('lagerplaner', '{source} imported: {stats}', { source: entry.label, stats: statsText(result.stats) }))
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

async function upload() {
	const data = new FormData()
	data.append('file', uploadFile.value)
	busy.value = true
	try {
		const result = (await axios.post(api('/sites/upload'), data)).data
		state.value = result.state
		showSuccess(t('lagerplaner', '{source} imported: {stats}', { source: result.stats.source, stats: statsText(result.stats) }))
		uploadFile.value = null
		if (fileInput.value) {
			fileInput.value.value = ''
		}
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

/* Rückmeldungen zu Lagerplätzen */
const feedbackList = ref(null)
const featureNames = featureLabels()
const valueText = (key, value) => {
	if (FEATURES.includes(key)) {
		return `${featureNames[key]}: ${value === 'true' ? t('lagerplaner', 'available') : t('lagerplaner', 'not available')}`
	}
	const labels = { postalCode: t('lagerplaner', 'Postcode'), city: t('lagerplaner', 'Town'), operator: t('lagerplaner', 'New operator'), homepage: t('lagerplaner', 'Website'), capacity: t('lagerplaner', 'Capacity (people)'), beds: t('lagerplaner', 'Beds'), since: t('lagerplaner', 'Closed since (optional)'), until: t('lagerplaner', 'Closed until'), name: t('lagerplaner', 'Name of the place'), type: t('lagerplaner', 'Type'), location: t('lagerplaner', 'Coordinates') }
	return `${labels[key] ?? key}: ${key === 'type' ? (typeLabels()[value] ?? value) : value}`
}

async function loadFeedback() {
	try {
		feedbackList.value = (await axios.get(api('/feedback'))).data
	} catch (error) {
		showError(errorMessage(error))
	}
}

async function handleFeedback(entry, action, apply = true) {
	busy.value = true
	try {
		const result = (await axios.post(api(`/feedback/${entry.id}/${action}`), action === 'accept' ? { apply, upstream: entry.upstreamRelevant } : {})).data
		state.value = result.state
		feedbackList.value = feedbackList.value.filter((item) => item.id !== entry.id)
		showSuccess(action === 'reject' ? t('lagerplaner', 'Feedback rejected') : (apply ? t('lagerplaner', 'Feedback applied') : t('lagerplaner', 'Feedback marked as done')))
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

/* Datenquellen abgleichen und Rückmeldungen vorbereiten */
const contribution = ref(null)
const formatTime = (seconds) => new Date(seconds * 1000).toLocaleString()

async function syncSource(source) {
	busy.value = true
	try {
		const result = (await axios.post(api('/sites/sync/' + encodeURIComponent(source.source)))).data
		state.value = result.state
		showSuccess(result.stats.unchanged
			? t('lagerplaner', '{source}: no changes', { source: source.label })
			: t('lagerplaner', '{source} imported: {stats}', { source: source.label, stats: statsText(result.stats) }))
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

async function showContributions(entry) {
	try {
		contribution.value = { label: entry.label, ...(await axios.get(api('/sites/contributions/' + encodeURIComponent(entry.source)))).data }
	} catch (error) {
		showError(errorMessage(error))
	}
}

async function copyContribution() {
	try {
		await navigator.clipboard.writeText(contribution.value.text)
		showSuccess(t('lagerplaner', 'Text copied'))
	} catch {
		showError(t('lagerplaner', 'Copying is not possible here; select the text and copy it yourself.'))
	}
}

async function markReported() {
	await save(() => axios.post(api('/sites/contributions/' + encodeURIComponent(contribution.value.source))), t('lagerplaner', 'Marked as reported'))
	contribution.value = null
}

const osmLink = (home) => `https://www.openstreetmap.org/?mlat=${home.lat}&mlon=${home.lon}#map=16/${home.lat}/${home.lon}`
const syncedAt = (seconds) => new Date(seconds * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
</script>

<template>
	<div class="lagerplaner-admin">
		<NcSettingsSection
			:name="t('lagerplaner', 'Location of the scout hall')"
			:description="t('lagerplaner', 'Starting point for distances in the campsite search. Paste coordinates or a map link that points to the scout hall.')">
			<p v-if="state.home" class="lagerplaner-admin__current">
				{{ state.groupHomes.length
					? t('lagerplaner', 'Default location for everyone without a tribe: {coordinates}', { coordinates: state.home.text })
					: t('lagerplaner', 'Saved: {coordinates}', { coordinates: state.home.text }) }}
				<a :href="osmLink(state.home)" target="_blank" rel="noopener noreferrer">{{ t('lagerplaner', 'Check on OpenStreetMap') }}</a>
			</p>
			<p v-else class="lagerplaner-admin__current">
				{{ t('lagerplaner', 'Not set yet; the campsite search shows no distances.') }}
			</p>
			<form class="lagerplaner-admin__row" @submit.prevent="saveHome">
				<NcTextField
					v-model="homeInput"
					:label="t('lagerplaner', 'Coordinates or map link')"
					:placeholder="t('lagerplaner', 'e.g. 51.0625, 7.0612')"
					:error="!!homeError"
					:helper-text="homeError" />
				<NcButton type="submit" variant="primary" :disabled="busy || !homeInput.trim()">
					{{ t('lagerplaner', 'Save') }}
				</NcButton>
				<NcButton v-if="state.home" variant="tertiary" :disabled="busy" @click="removeHome">
					{{ t('lagerplaner', 'Remove') }}
				</NcButton>
			</form>
			<h3 class="lagerplaner-admin__subheading">
				{{ t('lagerplaner', 'Tribes with their own scout hall') }}
			</h3>
			<p class="lagerplaner-admin__hint">
				{{ t('lagerplaner', 'For a cloud shared by several tribes: each tribe is a Nextcloud group. Members see distances from their own scout hall; the location above applies to everyone else. Group admins of a tribe can change its location themselves in the campsite search.') }}
			</p>
			<table v-if="state.groupHomes.length" class="lagerplaner-admin__tribes">
				<thead>
					<tr>
						<th>{{ t('lagerplaner', 'Tribe') }}</th>
						<th>{{ t('lagerplaner', 'Group') }}</th>
						<th>{{ t('lagerplaner', 'Location') }}</th>
						<th><span class="hidden-visually">{{ t('lagerplaner', 'Actions') }}</span></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="home in state.groupHomes" :key="home.groupId">
						<td>{{ home.label }}</td>
						<td>{{ home.groupName }}</td>
						<td>
							<a :href="osmLink(home)" target="_blank" rel="noopener noreferrer">{{ home.text }}</a>
						</td>
						<td class="lagerplaner-admin__tribe-actions">
							<NcButton variant="tertiary" size="small" :disabled="busy" @click="editTribe(home)">
								{{ t('lagerplaner', 'Change') }}
							</NcButton>
							<NcButton variant="tertiary" size="small" :disabled="busy" @click="removeTribe(home)">
								{{ t('lagerplaner', 'Remove') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<div v-if="!tribe" class="lagerplaner-admin__group-search">
				<NcTextField v-model="groupQuery" :label="t('lagerplaner', 'Add tribe: search Nextcloud group')" @update:model-value="searchGroups" />
				<ul v-if="groupResults.length" class="lagerplaner-admin__group-results">
					<li v-for="group in groupResults" :key="group.id">
						<NcButton variant="tertiary" @click="chooseGroup(group)">
							{{ group.label }}<template v-if="group.label !== group.id">
								({{ group.id }})
							</template>
						</NcButton>
					</li>
				</ul>
			</div>
			<form v-else class="lagerplaner-admin__tribe-form" @submit.prevent="saveTribe">
				<p>{{ t('lagerplaner', 'Group: {group}', { group: tribe.name }) }}</p>
				<NcTextField v-model="tribeForm.label" :label="t('lagerplaner', 'Name of the tribe (optional)')" />
				<NcTextField
					v-model="tribeForm.input"
					:label="t('lagerplaner', 'Coordinates or map link of the scout hall')"
					:placeholder="t('lagerplaner', 'e.g. 51.0625, 7.0612')"
					:error="!!tribeError"
					:helper-text="tribeError" />
				<div class="lagerplaner-admin__row">
					<NcButton type="submit" variant="primary" :disabled="busy || !tribeForm.input.trim()">
						{{ t('lagerplaner', 'Save') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="busy" @click="tribe = null">
						{{ t('lagerplaner', 'Cancel') }}
					</NcButton>
				</div>
			</form>

			<details class="lagerplaner-admin__help">
				<summary>{{ t('lagerplaner', 'How do I get the coordinates?') }}</summary>
				<ul>
					<li>{{ t('lagerplaner', 'OpenStreetMap: right-click on the scout hall, “Show address”, then copy the coordinates shown on the left.') }}</li>
					<li>{{ t('lagerplaner', 'Google Maps: right-click on the scout hall and click the coordinates at the top; they are copied. A link to the place works too.') }}</li>
					<li>{{ t('lagerplaner', 'Addresses are not converted, because that would need an external service.') }}</li>
				</ul>
			</details>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('lagerplaner', 'Game search')"
			:description="t('lagerplaner', 'Games from Pfadfinder-Spiele.de for the programme. The server loads the public catalogue; no search terms, people or camp data are sent.')">
			<NcCheckboxRadioSwitch
				:model-value="state.games.enabled"
				type="switch"
				:disabled="busy"
				@update:model-value="(value) => save(() => axios.put(api('/games'), { enabled: value }))">
				{{ t('lagerplaner', 'Offer games from Pfadfinder-Spiele.de') }}
			</NcCheckboxRadioSwitch>
			<p v-if="state.games.enabled" class="lagerplaner-admin__current">
				<template v-if="state.games.count > 0">
					{{ n('lagerplaner', '%n game in the catalogue', '%n games in the catalogue', state.games.count) }}<template v-if="state.games.syncedAt > 0">,
						{{ t('lagerplaner', 'last updated {date}', { date: syncedAt(state.games.syncedAt) }) }}</template>
				</template>
				<template v-else>
					{{ t('lagerplaner', 'The catalogue is loaded with the next cron run.') }}
				</template>
			</p>
			<p class="lagerplaner-admin__hint">
				{{ t('lagerplaner', 'Game texts: Dorothea Schümann, Pfadfinder-Spiele.de, CC BY-NC-SA 4.0.') }}
			</p>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('lagerplaner', 'Campsite directory')"
			:description="t('lagerplaner', 'Sources of the campsite search. Hidden sources keep their data and links in camps but no longer appear in the search.')">
			<NcNoteCard v-if="state.sources.length === 0" type="info" :text="t('lagerplaner', 'No campsites imported yet. The app brings the Schwarzzelt IT Campsitemap with it; it is imported when the app is installed or updated, or right away below.')" />
			<div v-else class="lagerplaner-admin__table">
				<table>
					<thead>
						<tr>
							<th>{{ t('lagerplaner', 'Source') }}</th>
							<th>{{ t('lagerplaner', 'Entries') }}</th>
							<th>{{ t('lagerplaner', 'With coordinates') }}</th>
							<th>{{ t('lagerplaner', 'No longer in the source') }}</th>
							<th>{{ t('lagerplaner', 'Last sync') }}</th>
							<th>{{ t('lagerplaner', 'Shown in the search') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="source in state.sources" :key="source.source">
							<td>
								<a v-if="source.link" :href="source.link" target="_blank" rel="noopener noreferrer">{{ source.label }}</a>
								<template v-else>
									{{ source.label }}
								</template>
							</td>
							<td>{{ source.count }}</td>
							<td>{{ source.withCoordinates }}</td>
							<td>{{ source.missing }}</td>
							<td>
								<span v-if="source.syncedAt">{{ formatTime(source.syncedAt) }}</span>
								<span v-if="source.syncResult" class="lagerplaner-admin__hint">{{ source.syncResult }}</span>
								<NcButton v-if="source.canSync" variant="tertiary" size="small" :disabled="busy" @click="syncSource(source)">
									{{ t('lagerplaner', 'Sync now') }}
								</NcButton>
							</td>
							<td>
								<NcCheckboxRadioSwitch
									:model-value="!source.disabled"
									type="switch"
									:disabled="busy"
									:aria-label="t('lagerplaner', 'Show {source} in the search', { source: source.label })"
									@update:model-value="(value) => save(() => axios.put(api('/sources/' + encodeURIComponent(source.source)), { visible: value }))" />
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<NcCheckboxRadioSwitch
				:model-value="state.autoSync"
				type="switch"
				:disabled="busy"
				@update:model-value="(value) => save(() => axios.put(api('/sites/auto-sync'), { enabled: value }), value ? t('lagerplaner', 'Weekly sync switched on') : t('lagerplaner', 'Weekly sync switched off'))">
				{{ t('lagerplaner', 'Sync the sources weekly') }}
			</NcCheckboxRadioSwitch>
			<p class="lagerplaner-admin__hint">
				{{ t('lagerplaner', 'The server loads the public list of the Schwarzzelt IT Campsitemap from GitHub. No personal, camp or search data are sent.') }}
			</p>

			<h3 class="lagerplaner-admin__subheading">
				{{ t('lagerplaner', 'Feedback from users') }}
			</h3>
			<p class="lagerplaner-admin__hint">
				{{ n('lagerplaner', '%n open feedback on campsites. Applied equipment, capacity and town stay in the camp planner. Applied index data (name, location, website) stay until the campsite map itself delivers a new value.', '%n open feedback entries on campsites. Applied equipment, capacity and town stay in the camp planner. Applied index data (name, location, website) stay until the campsite map itself delivers a new value.', state.feedbackOpen) }}
			</p>
			<NcButton v-if="feedbackList === null" variant="secondary" :disabled="state.feedbackOpen === 0" @click="loadFeedback">
				{{ t('lagerplaner', 'Show feedback') }}
			</NcButton>
			<ul v-else class="lagerplaner-admin__feedback">
				<li v-for="entry in feedbackList" :key="entry.id">
					<p>
						<strong>{{ feedbackKindLabels()[entry.kind] }}</strong>
						<template v-if="entry.site">
							– {{ entry.site.name }}
						</template>
					</p>
					<ul v-if="Object.keys(entry.values).length" class="lagerplaner-admin__feedback-values">
						<li v-for="(value, key) in entry.values" :key="key">
							{{ valueText(key, value) }}
						</li>
					</ul>
					<p v-if="entry.message" class="lagerplaner-admin__feedback-message">
						„{{ entry.message }}“
					</p>
					<p class="lagerplaner-admin__hint">
						{{ t('lagerplaner', 'From {name} on {date}', { name: entry.createdByName, date: formatTime(entry.createdAt) }) }}
					</p>
					<NcCheckboxRadioSwitch v-model="entry.upstreamRelevant" type="switch">
						{{ t('lagerplaner', 'Relevant for the campsite map (name, location, URL, closure, new place, operator)') }}
					</NcCheckboxRadioSwitch>
					<p v-if="entry.kind === 'operator' && !entry.values.homepage" class="lagerplaner-admin__hint">
						{{ t('lagerplaner', 'Changes nothing in the camp planner; it is noted as a possible correction for the campsite map.') }}
					</p>
					<div class="lagerplaner-admin__row">
						<NcButton v-if="entry.kind === 'operator' && !entry.values.homepage" variant="primary" :disabled="busy" @click="entry.upstreamRelevant = true; handleFeedback(entry, 'accept', false)">
							{{ t('lagerplaner', 'Note for the campsite map') }}
						</NcButton>
						<NcButton v-else-if="Object.keys(entry.values).length" variant="primary" :disabled="busy" @click="handleFeedback(entry, 'accept')">
							{{ t('lagerplaner', 'Apply') }}
						</NcButton>
						<NcButton variant="secondary" :disabled="busy" @click="handleFeedback(entry, 'accept', false)">
							{{ t('lagerplaner', 'Done without changes') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="busy" @click="handleFeedback(entry, 'reject')">
							{{ t('lagerplaner', 'Reject') }}
						</NcButton>
					</div>
				</li>
				<li v-if="feedbackList.length === 0">
					{{ t('lagerplaner', 'No open feedback.') }}
				</li>
			</ul>

			<h3 class="lagerplaner-admin__subheading">
				{{ t('lagerplaner', 'Possible corrections for the campsite map') }}
			</h3>
			<p class="lagerplaner-admin__hint">
				{{ t('lagerplaner', 'Only checked feedback on the index data of the campsite map ends up here: closures, new places, wrong names, locations or URLs, operator changes. Equipment, capacity and town stay in the camp planner. The app prepares the text; you submit it yourself.') }}
			</p>
			<ul class="lagerplaner-admin__bundled">
				<li v-for="entry in state.upstream" :key="entry.source">
					<span>
						<strong>{{ entry.label }}</strong>:
						{{ n('lagerplaner', '%n possible correction', '%n possible corrections', entry.count) }}
					</span>
					<NcButton variant="secondary" :disabled="busy || entry.count === 0" @click="showContributions(entry)">
						{{ t('lagerplaner', 'Prepare feedback') }}
					</NcButton>
				</li>
			</ul>
			<div v-if="contribution" class="lagerplaner-admin__tribe-form">
				<label :for="'contribution-text'">{{ t('lagerplaner', 'Text for an issue at {source}', { source: contribution.label }) }}</label>
				<textarea id="contribution-text" readonly rows="10" :value="contribution.text" />
				<div class="lagerplaner-admin__row">
					<NcButton variant="secondary" @click="copyContribution">
						{{ t('lagerplaner', 'Copy text') }}
					</NcButton>
					<NcButton v-if="contribution.feedback.issues" variant="secondary" :href="contribution.feedback.issues" target="_blank" rel="noopener noreferrer">
						{{ t('lagerplaner', 'Open issue form') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="busy" @click="markReported">
						{{ t('lagerplaner', 'Mark as reported') }}
					</NcButton>
				</div>
			</div>

			<h3 class="lagerplaner-admin__subheading">
				{{ t('lagerplaner', 'Bundled data') }}
			</h3>
			<p class="lagerplaner-admin__hint">
				{{ t('lagerplaner', 'The app brings these data with it. They are imported automatically when the app is installed or updated; here you can import them right away.') }}
			</p>
			<ul class="lagerplaner-admin__bundled">
				<li v-for="entry in state.bundled" :key="entry.source">
					<span>
						<strong>{{ entry.label }}</strong>:
						{{ n('lagerplaner', '%n place', '%n places', entry.count) }}, {{ entry.version }}
						<span class="lagerplaner-admin__hint">
							{{ entry.current ? t('lagerplaner', 'imported') : (entry.importedVersion ? t('lagerplaner', 'imported so far: {version}', { version: entry.importedVersion }) : t('lagerplaner', 'not imported yet')) }}
						</span>
						<span v-if="entry.enrichment" class="lagerplaner-admin__hint">
							{{ t('lagerplaner', 'Enrichment checked on the websites: {count} places, {version}, {state}', { count: entry.enrichment.count, version: entry.enrichment.version, state: entry.enrichment.applied ? t('lagerplaner', 'imported') : t('lagerplaner', 'not imported yet') }) }}
						</span>
					</span>
					<NcButton :variant="entry.current ? 'tertiary' : 'primary'" :disabled="busy" @click="importBundled(entry)">
						{{ entry.current ? t('lagerplaner', 'Import again') : t('lagerplaner', 'Import now') }}
					</NcButton>
				</li>
			</ul>

			<h3 class="lagerplaner-admin__subheading">
				{{ t('lagerplaner', 'Upload data') }}
			</h3>
			<p class="lagerplaner-admin__hint">
				{{ t('lagerplaner', 'A JSON file in the format “lagerplaner-sites/1”, for example a list of your own. It replaces the previous data of the same source: places missing from the file are marked “no longer in the source”, not deleted. Your own entries stay untouched.') }}
			</p>
			<form class="lagerplaner-admin__row" @submit.prevent="upload">
				<input
					ref="fileInput"
					type="file"
					accept=".json,application/json"
					:aria-label="t('lagerplaner', 'Data file')"
					:disabled="busy"
					@change="uploadFile = $event.target.files[0] ?? null">
				<NcButton type="submit" variant="secondary" :disabled="busy || !uploadFile">
					{{ t('lagerplaner', 'Upload and import') }}
				</NcButton>
			</form>
		</NcSettingsSection>
	</div>
</template>

<style scoped>
.lagerplaner-admin__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	max-width: 700px;
}

.lagerplaner-admin__row .input-field {
	flex: 1 1 280px;
}

.lagerplaner-admin__current {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.lagerplaner-admin__current a {
	margin-inline-start: var(--default-grid-baseline);
	text-decoration: underline;
}

.lagerplaner-admin__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.lagerplaner-admin__help {
	margin-top: calc(var(--default-grid-baseline) * 2);
	max-width: 700px;
}

.lagerplaner-admin__help summary {
	cursor: pointer;
}

.lagerplaner-admin__help ul {
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
	list-style: disc;
}

.lagerplaner-admin__table {
	overflow-x: auto;
}

.lagerplaner-admin__bundled {
	margin: var(--default-grid-baseline) 0;
	padding: 0;
	list-style: none;
}

.lagerplaner-admin__bundled li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.lagerplaner-admin__subheading {
	margin-top: calc(var(--default-grid-baseline) * 5);
	font-weight: bold;
}

.lagerplaner-admin__tribes {
	margin: calc(var(--default-grid-baseline) * 2) 0;
	border-collapse: collapse;
}

.lagerplaner-admin__tribes th,
.lagerplaner-admin__tribes td {
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.lagerplaner-admin__tribes a {
	text-decoration: underline;
}

.lagerplaner-admin__tribe-actions {
	white-space: nowrap;
}

.lagerplaner-admin__group-search,
.lagerplaner-admin__tribe-form {
	max-width: 500px;
}

.lagerplaner-admin__tribe-form {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 2);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
}

.lagerplaner-admin__group-results {
	margin: var(--default-grid-baseline) 0;
	padding: 0;
	list-style: none;
}

.lagerplaner-admin__table table {
	border-collapse: collapse;
}

.lagerplaner-admin__table th,
.lagerplaner-admin__table td {
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: middle;
}

.lagerplaner-admin__table .lagerplaner-admin__hint {
	display: block;
}
.lagerplaner-admin__tribe-form textarea {
	width: 100%;
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.85em;
}

.lagerplaner-admin__table a {
	text-decoration: underline;
}
.lagerplaner-admin__feedback {
	margin: var(--default-grid-baseline) 0;
	padding: 0;
	list-style: none;
	max-width: 700px;
}

.lagerplaner-admin__feedback > li {
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.lagerplaner-admin__feedback p {
	margin: 0 0 var(--default-grid-baseline);
}

.lagerplaner-admin__feedback-values {
	margin: 0 0 var(--default-grid-baseline) calc(var(--default-grid-baseline) * 4);
	list-style: disc;
}

.lagerplaner-admin__feedback-message {
	font-style: italic;
}
</style>
