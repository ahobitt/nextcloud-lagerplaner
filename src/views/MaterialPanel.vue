<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiClose, mdiDelete, mdiPencil, mdiPlus, mdiPrinter } from '@mdi/js'
import { getCurrentUser } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'
import { getLanguage } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref, useId } from 'vue'
import {
	addMaterialUse, createMaterial, deleteMaterial, errorMessage, getMaterial, getPeople, removeMaterialUse,
	resolveMaterialSuggestion, setMaterialStatus, updateMaterial, updateMaterialUse,
} from '../api.js'
import { formatDay } from '../format.js'
import { n, t } from '../l10n.js'

const props = defineProps({
	campId: { type: Number, required: true },
	campName: { type: String, default: '' },
	program: { type: Array, default: () => [] },
	can: { type: Object, required: true },
})
const emit = defineEmits(['changed'])
const id = useId()
const me = getCurrentUser()?.uid ?? ''

const loading = ref(true)
const overview = ref(null)
const people = ref([])
const statusFilter = ref('all')
const onlyMine = ref(false)
const form = ref(null)
const busy = ref(false)

const number = new Intl.NumberFormat(getLanguage(), { maximumFractionDigits: 2 })
const sortedProgram = computed(() => [...props.program].sort((a, b) => `${a.day}${a.startTime}`.localeCompare(`${b.day}${b.startTime}`)))
const programLabel = (item) => `${formatDay(item.day, { weekday: 'short', day: 'numeric', month: 'numeric' })}, ${item.startTime}: ${item.title}`
const statusLabel = computed(() => Object.fromEntries((overview.value?.statuses ?? []).map((s) => [s.key, s.label])))

async function load() {
	try {
		overview.value = await getMaterial(props.campId)
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		loading.value = false
	}
}

onMounted(async () => {
	await load()
	try {
		people.value = await getPeople(props.campId)
	} catch {
		// Die Auswahl von Verantwortlichen bleibt dann leer.
	}
})

/** Änderung ausführen, neu laden; true nur bei Erfolg, damit Formulare bei Fehlern offen bleiben. */
async function act(action) {
	busy.value = true
	try {
		await action()
		await load()
		emit('changed')
		return true
	} catch (error) {
		showError(errorMessage(error))
		return false
	} finally {
		busy.value = false
	}
}

const groups = computed(() => {
	if (!overview.value) {
		return []
	}
	const items = overview.value.items.filter((item) => {
		if (onlyMine.value && item.responsible?.uid !== me) {
			return false
		}
		if (statusFilter.value === 'unpacked') {
			return ['needed', 'available'].includes(item.status)
		}
		return statusFilter.value === 'all' || item.status === statusFilter.value
	})
	return overview.value.categories
		.map((category) => ({ ...category, items: items.filter((item) => item.category === category.key) }))
		.filter((group) => group.items.length > 0)
})

const summary = computed(() => {
	const counts = overview.value?.counts ?? {}
	return Object.entries(counts).filter(([, count]) => count > 0).map(([key, count]) => `${count} ${statusLabel.value[key]}`).join(', ')
})

function amount(item) {
	const unit = item.unit ? ` ${item.unit}` : ''
	return item.total > 0 ? `${number.format(item.total)}${unit}` : '–'
}

function useLabel(use) {
	if (use.programItemId === null) {
		return t('lagerplaner', 'Whole camp')
	}
	if (use.inactive) {
		return t('lagerplaner', 'Deleted programme item')
	}
	return `${formatDay(use.day, { weekday: 'short', day: 'numeric', month: 'numeric' })}, ${use.programTitle}`
}

function openForm(item = null) {
	form.value = item
		? { id: item.id, name: item.name, category: item.category, unit: item.unit ?? '', reusable: item.reusable, note: item.note ?? '', responsible: item.responsible?.uid ?? '', uses: item.uses.map((u) => ({ ...u, quantity: u.quantity ?? '' })), newUse: { programItemId: '', quantity: '' } }
		: { id: null, name: '', category: 'games', unit: 'Stück', reusable: true, note: '', responsible: '', quantity: '', programItemId: '' }
}

async function save() {
	const draft = form.value
	const fields = { name: draft.name, category: draft.category, unit: draft.unit, reusable: draft.reusable, note: draft.note, responsible: draft.responsible || null }
	const ok = await act(async () => {
		if (draft.id) {
			await updateMaterial(draft.id, fields)
			for (const use of draft.uses) {
				await updateMaterialUse(use.id, use.quantity === '' ? null : use.quantity)
			}
		} else {
			await createMaterial(props.campId, { ...fields, quantity: draft.quantity === '' ? null : draft.quantity, programItemId: draft.programItemId === '' ? null : draft.programItemId })
		}
	})
	if (ok) {
		form.value = null
	}
}

async function addUse() {
	const draft = form.value
	if (await act(() => addMaterialUse(draft.id, draft.newUse.programItemId === '' ? null : draft.newUse.programItemId, draft.newUse.quantity === '' ? null : draft.newUse.quantity))) {
		openForm(overview.value.items.find((item) => item.id === draft.id))
	}
}

async function removeUse(use) {
	const draft = form.value
	if (await act(() => removeMaterialUse(use.id))) {
		openForm(overview.value.items.find((item) => item.id === draft.id))
	}
}

const escapeHtml = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])

/** Packliste als eigenes Dokument, eine Zeile je Posten mit Kästchen zum Abhaken auf Papier. */
function printList() {
	const sections = overview.value.categories.map((category) => {
		const rows = overview.value.items.filter((item) => item.category === category.key).map((item) => {
			const done = ['packed', 'transported', 'returned'].includes(item.status)
			const note = [
				item.openQuantities > 0 ? t('lagerplaner', 'quantity still open') : '',
				item.responsible ? item.responsible.displayName : '',
				statusLabel.value[item.status],
			].filter(Boolean).join(', ')
			return `<tr><td class="box">${done ? '&#9745;' : '&#9744;'}</td><td>${escapeHtml(item.name)} <small>${escapeHtml(note)}</small></td><td class="qty">${escapeHtml(amount(item))}</td></tr>`
		})
		return rows.length ? `<h2>${escapeHtml(category.label)}</h2><table>${rows.join('')}</table>` : ''
	}).join('')
	const html = `<!doctype html><html lang="${escapeHtml(getLanguage())}"><head><meta charset="utf-8"><title>${escapeHtml(props.campName)}</title><style>
		body { font: 11pt system-ui, sans-serif; margin: 1.5cm; color: #000; }
		h1 { font-size: 16pt; margin: 0 0 0.5cm; }
		h2 { font-size: 11pt; text-transform: uppercase; letter-spacing: 0.05em; margin: 0.5cm 0 0.1cm; border-bottom: 1pt solid #000; }
		table { width: 100%; border-collapse: collapse; break-inside: auto; }
		td { padding: 2pt 4pt; border-bottom: 0.5pt solid #bbb; vertical-align: top; }
		td.box { width: 1.2em; font-size: 13pt; line-height: 1; }
		td.qty { text-align: right; white-space: nowrap; font-weight: bold; }
		small { color: #555; }
	</style></head><body><h1>${escapeHtml(t('lagerplaner', 'Packing list for {camp}', { camp: props.campName }))}</h1>${sections}</body></html>`
	const frame = document.createElement('iframe')
	frame.setAttribute('aria-hidden', 'true')
	frame.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden'
	document.body.appendChild(frame)
	frame.contentDocument.open()
	frame.contentDocument.write(html)
	frame.contentDocument.close()
	frame.contentWindow.focus()
	frame.contentWindow.print()
	setTimeout(() => frame.remove(), 1000)
}
</script>

<template>
	<div class="material">
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else-if="overview">
			<header class="material__header">
				<p class="material__summary">
					{{ n('lagerplaner', '%n item', '%n items', overview.items.length) }}<template v-if="summary">: {{ summary }}</template>
				</p>
				<div class="material__buttons">
					<NcButton v-if="can.material && !form" @click="openForm()">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPlus" />
						</template>
						{{ t('lagerplaner', 'Add material') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="overview.items.length === 0" @click="printList">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPrinter" />
						</template>
						{{ t('lagerplaner', 'Print packing list') }}
					</NcButton>
				</div>
			</header>

			<NcNoteCard v-if="can.material && overview.suggestions.length > 0" type="info" :heading="t('lagerplaner', 'Material from planned games')">
				<ul class="material__suggestions">
					<li v-for="suggestion in overview.suggestions" :key="suggestion.ref">
						<span>
							<strong>{{ suggestion.name }}</strong>
							{{ t('lagerplaner', 'from “{game}”, {item}', { game: suggestion.gameTitle, item: `${formatDay(suggestion.day, { weekday: 'short', day: 'numeric', month: 'numeric' })} ${suggestion.programTitle}` }) }}
						</span>
						<NcButton variant="secondary" size="small" :disabled="busy" @click="act(() => resolveMaterialSuggestion(campId, suggestion.ref, true))">
							{{ t('lagerplaner', 'Add to list') }}
						</NcButton>
						<NcButton variant="tertiary" size="small" :disabled="busy" @click="act(() => resolveMaterialSuggestion(campId, suggestion.ref, false))">
							{{ t('lagerplaner', 'Not needed') }}
						</NcButton>
					</li>
				</ul>
			</NcNoteCard>

			<form v-if="form" class="material-form" @submit.prevent="save">
				<div class="material-form__row">
					<NcTextField v-model="form.name" :label="t('lagerplaner', 'Material')" />
					<div class="material-form__select">
						<label :for="`${id}-category`">{{ t('lagerplaner', 'Category') }}</label>
						<select :id="`${id}-category`" v-model="form.category">
							<option v-for="category in overview.categories" :key="category.key" :value="category.key">
								{{ category.label }}
							</option>
						</select>
					</div>
					<NcTextField v-model="form.unit" :label="t('lagerplaner', 'Unit, e.g. pieces or metres')" />
				</div>
				<div class="material-form__row">
					<NcCheckboxRadioSwitch v-model="form.reusable" type="checkbox">
						{{ t('lagerplaner', 'Reusable (counted once if not used at the same time)') }}
					</NcCheckboxRadioSwitch>
					<div class="material-form__select">
						<label :for="`${id}-responsible`">{{ t('lagerplaner', 'Responsible') }}</label>
						<select :id="`${id}-responsible`" v-model="form.responsible">
							<option value="">
								{{ t('lagerplaner', 'Nobody yet') }}
							</option>
							<option v-for="person in people" :key="person.uid" :value="person.uid">
								{{ person.displayName }}
							</option>
						</select>
					</div>
				</div>
				<div v-if="!form.id" class="material-form__row">
					<NcTextField v-model="form.quantity" :label="t('lagerplaner', 'Quantity (optional)')" />
					<div class="material-form__select">
						<label :for="`${id}-program`">{{ t('lagerplaner', 'Needed for') }}</label>
						<select :id="`${id}-program`" v-model="form.programItemId">
							<option value="">
								{{ t('lagerplaner', 'Whole camp') }}
							</option>
							<option v-for="item in sortedProgram" :key="item.id" :value="item.id">
								{{ programLabel(item) }}
							</option>
						</select>
					</div>
				</div>
				<fieldset v-else>
					<legend>{{ t('lagerplaner', 'Needed for') }}</legend>
					<div v-for="use in form.uses" :key="use.id" class="material-form__use">
						<span>{{ useLabel(use) }}</span>
						<NcTextField v-model="use.quantity" :label="t('lagerplaner', 'Quantity')" />
						<NcButton variant="tertiary" :aria-label="t('lagerplaner', 'Remove use')" @click="removeUse(use)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiClose" />
							</template>
						</NcButton>
					</div>
					<div class="material-form__use">
						<select v-model="form.newUse.programItemId" :aria-label="t('lagerplaner', 'Needed for')">
							<option value="">
								{{ t('lagerplaner', 'Whole camp') }}
							</option>
							<option v-for="item in sortedProgram" :key="item.id" :value="item.id">
								{{ programLabel(item) }}
							</option>
						</select>
						<NcTextField v-model="form.newUse.quantity" :label="t('lagerplaner', 'Quantity')" />
						<NcButton variant="secondary" :disabled="busy" @click="addUse">
							{{ t('lagerplaner', 'Add use') }}
						</NcButton>
					</div>
				</fieldset>
				<NcTextField v-model="form.note" :label="t('lagerplaner', 'Note (optional)')" />
				<div class="material-form__row">
					<NcButton type="submit" variant="primary" :disabled="busy">
						{{ t('lagerplaner', 'Save') }}
					</NcButton>
					<NcButton variant="tertiary" @click="form = null">
						{{ t('lagerplaner', 'Cancel') }}
					</NcButton>
				</div>
			</form>

			<div class="material__filters">
				<div class="material-form__select">
					<label :for="`${id}-status-filter`">{{ t('lagerplaner', 'Show') }}</label>
					<select :id="`${id}-status-filter`" v-model="statusFilter">
						<option value="all">
							{{ t('lagerplaner', 'All material') }}
						</option>
						<option value="unpacked">
							{{ t('lagerplaner', 'Not packed yet') }}
						</option>
						<option v-for="status in overview.statuses" :key="status.key" :value="status.key">
							{{ status.label }}
						</option>
					</select>
				</div>
				<NcCheckboxRadioSwitch v-model="onlyMine" type="switch">
					{{ t('lagerplaner', 'Only what I am responsible for') }}
				</NcCheckboxRadioSwitch>
			</div>

			<p v-if="overview.items.length === 0" class="material__empty">
				{{ t('lagerplaner', 'No material yet. Add items or take over material from planned games.') }}
			</p>

			<section v-for="group in groups" :key="group.key" class="material__group">
				<h3>{{ group.label }}</h3>
				<ul>
					<li v-for="item in group.items" :key="item.id" class="material-item" :class="`material-item--${item.status}`">
						<div class="material-item__row">
							<span class="material-item__name">{{ item.name }}</span>
							<span class="material-item__amount">{{ amount(item) }}</span>
							<select
								v-if="can.pack"
								:value="item.status"
								:aria-label="t('lagerplaner', 'Status of {name}', { name: item.name })"
								:disabled="busy"
								@change="act(() => setMaterialStatus(item.id, $event.target.value))">
								<option v-for="status in overview.statuses" :key="status.key" :value="status.key">
									{{ status.label }}
								</option>
							</select>
							<span v-else class="material-item__status">{{ statusLabel[item.status] }}</span>
							<NcActions v-if="can.material" :aria-label="t('lagerplaner', 'Actions for {title}', { title: item.name })">
								<NcActionButton close-after-click @click="openForm(item)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiPencil" />
									</template>
									{{ t('lagerplaner', 'Edit') }}
								</NcActionButton>
								<NcActionButton close-after-click @click="act(() => deleteMaterial(item.id))">
									<template #icon>
										<NcIconSvgWrapper :path="mdiDelete" />
									</template>
									{{ t('lagerplaner', 'Delete') }}
								</NcActionButton>
							</NcActions>
						</div>
						<span class="material-item__details">
							<span v-if="item.openQuantities > 0" class="material-item__warning">{{ n('lagerplaner', 'Quantity open for %n use', 'Quantity open for %n uses', item.openQuantities) }}</span>
							<span v-if="item.responsible">{{ t('lagerplaner', 'Responsible: {names}', { names: item.responsible.displayName }) }}</span>
							<span v-else class="material-item__nobody">{{ t('lagerplaner', 'Nobody responsible yet') }}</span>
							<span v-if="item.uses.length">{{ t('lagerplaner', 'For: {item}', { item: item.uses.map((use) => use.quantity === null ? useLabel(use) : `${useLabel(use)} (${number.format(use.quantity)})`).join('; ') }) }}</span>
							<span v-if="!item.reusable">{{ t('lagerplaner', 'consumable') }}</span>
							<span v-if="item.note">{{ item.note }}</span>
						</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<style scoped>
.material {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.material__header,
.material__buttons,
.material__filters,
.material-form__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 3);
}

.material__buttons,
.material__filters,
.material-form__row {
	justify-content: flex-start;
}

.material__summary,
.material__empty,
.material-item__details,
.material-item__status {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.material__suggestions {
	margin: var(--default-grid-baseline) 0 0;
	padding: 0;
	list-style: none;
}

.material__suggestions li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding: 2px 0;
}

.material__suggestions li > span {
	flex: 1 1 280px;
}

.material-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large);
}

.material-form legend {
	font-weight: bold;
}

.material-form__select {
	display: flex;
	flex-direction: column;
	gap: 2px;
	color: var(--color-text-maxcontrast);
}

.material-form__select select,
.material-form__use select,
.material-item__row select {
	min-height: var(--default-clickable-area);
	margin: 0;
	color: var(--color-main-text);
}

.material-form__use {
	display: grid;
	grid-template-columns: minmax(200px, 2fr) minmax(100px, 1fr) auto;
	align-items: end;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: var(--default-grid-baseline);
}

.material__group h3 {
	margin: calc(var(--default-grid-baseline) * 2) 0 var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
	font-size: 0.95em;
	text-transform: uppercase;
	letter-spacing: 0.03em;
}

.material__group ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

.material-item {
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.material-item__row {
	display: grid;
	grid-template-columns: minmax(160px, 1fr) 8em 13em auto;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.material-item__name {
	font-weight: bold;
}

.material-item__amount {
	text-align: end;
	font-variant-numeric: tabular-nums;
}

.material-item__details {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 3);
	font-size: 0.9em;
}

.material-item__warning,
.material-item--missing .material-item__name {
	color: var(--color-warning-text);
}

.material-item__nobody {
	font-style: italic;
}

.material-item--packed .material-item__name,
.material-item--transported .material-item__name,
.material-item--returned .material-item__name {
	color: var(--color-text-maxcontrast);
}

@media (max-width: 700px) {
	.material-item__row {
		grid-template-columns: 1fr auto;
	}

	.material-form__use {
		grid-template-columns: 1fr auto;
	}
}
</style>
