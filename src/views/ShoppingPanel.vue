<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiClose, mdiDelete, mdiPencil, mdiPlus, mdiPrinter } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { getLanguage } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { onMounted, reactive, ref, useId } from 'vue'
import {
	addShoppingExtra, addShoppingTrip, deleteShoppingExtra, deleteShoppingTrip, errorMessage, getShopping,
	setShoppingExtra, setShoppingLine, setShoppingStock, updateShoppingTrip,
} from '../api.js'
import { showSuccess } from '@nextcloud/dialogs'
import { formatDay, parseDay, toDay } from '../format.js'
import { n, t } from '../l10n.js'

const props = defineProps({
	campId: { type: Number, required: true },
	campName: { type: String, default: '' },
	canEdit: { type: Boolean, default: false },
})

const id = useId()
const loading = ref(true)
const overview = ref(null)
const tripForm = ref(null)
const extraForms = reactive({})
/** Vorratsformular: { key, quantity, unit, units } */
const stockForm = ref(null)
const deleteTarget = ref(null)

async function load() {
	try {
		overview.value = await getShopping(props.campId)
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		loading.value = false
	}
}
onMounted(load)

/** Führt eine Änderung aus und lädt neu. Gibt true nur bei Erfolg zurück, damit Formulare bei Fehlern offen bleiben. */
async function act(action) {
	try {
		const result = await action()
		await load()
		return result ?? true
	} catch (error) {
		showError(errorMessage(error))
		return false
	}
}

const number = new Intl.NumberFormat(getLanguage(), { maximumFractionDigits: 2 })
const plain = (entry) => (!entry ? '' : entry.quantity === null ? entry.unit : `${number.format(entry.quantity)} ${entry.unit}`)
const amount = (entry) => (entry.approx ? t('lagerplaner', '{amount} (about {volume})', { amount: plain(entry), volume: plain(entry.approx) }) : plain(entry))
const days = (list) => list.map((day) => formatDay(day, { weekday: 'short', day: 'numeric', month: 'numeric' })).join(', ')

/** Positionen und eigene Posten gemeinsam nach Warengruppe, in der Reihenfolge der Gruppen. */
function groups(trip) {
	return overview.value.categories
		.map((category) => ({
			...category,
			lines: trip.lines.filter((line) => line.category === category.key),
			extras: trip.extras.filter((extra) => extra.category === category.key),
		}))
		.filter((group) => group.lines.length + group.extras.length > 0)
}

function progress(trip) {
	const done = trip.lines.filter((line) => line.status === 'done').length + trip.extras.filter((e) => e.purchased).length
	return { done, total: trip.lines.length + trip.extras.length }
}

/** Erledigt durch Vorrat oder einen anderen Einkauf: nicht hier abhakbar. */
const coveredElsewhere = (line) => line.status === 'done' && !line.purchasedHere

function toggleLine(trip, line) {
	act(() => setShoppingLine(trip.id, line.key, line.status !== 'done'))
}

function openStock(line) {
	const units = line.family === 'g' ? ['kg', 'g'] : line.family === 'ml' ? ['l', 'ml'] : [line.family]
	const preset = line.stock ?? line.open ?? { quantity: line.quantity, unit: line.unit }
	stockForm.value = {
		key: line.key,
		name: line.name,
		units,
		unit: units.includes(preset.unit) ? preset.unit : units[0],
		quantity: preset.quantity === null ? '' : String(preset.quantity).replace('.', ','),
	}
}

async function saveStock(remove = false) {
	const form = stockForm.value
	const ok = await act(() => setShoppingStock(props.campId, form.key, remove ? null : form.quantity, form.unit))
	if (ok) {
		stockForm.value = null
	}
}

async function confirmDeleteTrip() {
	const trip = deleteTarget.value
	const result = await act(() => deleteShoppingTrip(trip.id))
	if (result) {
		deleteTarget.value = null
		if (result.movedPurchases + result.movedExtras > 0) {
			showSuccess(t('lagerplaner', 'Shopping trip deleted. Its purchases and own items now belong to “{trip}”.', { trip: result.target }))
		}
	}
}

const escapeHtml = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])

/**
 * Druckt ein eigenes, schlichtes Dokument aus den Listendaten. Die Bildschirmseite
 * eignet sich nicht, weil Nextcloud den Inhaltsbereich auf Fensterhöhe begrenzt.
 * Vorhandenes („Haben wir schon“) wird nicht gedruckt.
 */
function printLists() {
	const sections = overview.value.trips.map((trip) => {
		const groupHtml = groups(trip).map((group) => {
			const rows = []
			for (const line of group.lines) {
				const name = line.promises.length ? `${line.name} – ${t('lagerplaner', 'only for: {list}', { list: line.promises.join(', ') })}` : line.name
				const note = [line.notes.join('; '), line.late ? t('lagerplaner', 'Trip is after the day it is needed') : ''].filter(Boolean).join('. ')
				const coveredParts = [
					line.bought ? t('lagerplaner', 'bought {amount}', { amount: plain(line.bought) }) : '',
					line.carried ? t('lagerplaner', 'from an earlier trip {amount}', { amount: plain(line.carried) }) : '',
					line.stock ? t('lagerplaner', 'in stock {amount}', { amount: plain(line.stock) }) : '',
				].filter(Boolean).join(', ')
				if (line.status === 'done') {
					// Nur hier Gekauftes erscheint abgehakt; durch Vorrat oder andere Einkäufe Gedecktes entfällt.
					if (line.purchasedHere) {
						rows.push([true, name, amount(line), note])
					}
				} else if (line.status === 'partial') {
					rows.push([false, `${name} (${t('lagerplaner', 'still needed')})`, plain(line.open), [coveredParts, note].filter(Boolean).join('. ')])
				} else {
					rows.push([false, name, amount(line), note])
				}
			}
			for (const extra of group.extras) {
				rows.push([extra.purchased, extra.name, extra.amount ?? '', ''])
			}
			const rowHtml = rows.map(([done, name, qty, note]) => `<tr><td class="box">${done ? '&#9745;' : '&#9744;'}</td><td>${escapeHtml(name)}${note ? ` <small>${escapeHtml(note)}</small>` : ''}</td><td class="qty">${escapeHtml(qty)}</td></tr>`)
			return rowHtml.length ? `<h3>${escapeHtml(group.label)}</h3><table>${rowHtml.join('')}</table>` : ''
		}).join('')
		return `<section><h2>${escapeHtml(trip.label)}, ${escapeHtml(formatDay(trip.date, { weekday: 'long', day: 'numeric', month: 'long' }))}</h2>${groupHtml || `<p>${escapeHtml(t('lagerplaner', 'Nothing to buy on this trip yet.'))}</p>`}</section>`
	}).join('')
	const provisional = overview.value.provisional
		? `<div class="provisional"><strong>${escapeHtml(t('lagerplaner', 'PROVISIONAL'))}</strong>: ${overview.value.reasons.map(escapeHtml).join(' ')}</div>`
		: ''
	const html = `<!doctype html><html lang="${escapeHtml(getLanguage())}"><head><meta charset="utf-8"><title>${escapeHtml(props.campName)}</title><style>
		body { font: 11pt system-ui, sans-serif; margin: 1.5cm; color: #000; }
		h1 { font-size: 16pt; margin: 0 0 0.5cm; }
		h2 { font-size: 13pt; margin: 0.8cm 0 0.2cm; border-bottom: 1.5pt solid #000; }
		h3 { font-size: 10pt; text-transform: uppercase; letter-spacing: 0.05em; margin: 0.4cm 0 0.1cm; color: #444; }
		section + section { break-before: page; }
		table { width: 100%; border-collapse: collapse; }
		td { padding: 2pt 4pt; border-bottom: 0.5pt solid #bbb; vertical-align: top; }
		td.box { width: 1.2em; font-size: 13pt; line-height: 1; }
		td.qty { text-align: right; white-space: nowrap; font-weight: bold; }
		small { color: #555; }
		.provisional { border: 2pt solid #000; padding: 4pt 8pt; margin-bottom: 0.4cm; }
	</style></head><body><h1>${escapeHtml(t('lagerplaner', 'Shopping lists for {camp}', { camp: props.campName }))}</h1>${provisional}${sections}</body></html>`

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

function openTripForm(trip = null) {
	tripForm.value = { id: trip?.id ?? null, label: trip?.label ?? t('lagerplaner', 'Fresh food'), date: trip ? parseDay(trip.date) : null }
}

async function saveTrip() {
	const form = tripForm.value
	const date = form.date instanceof Date && !Number.isNaN(form.date.getTime()) ? toDay(form.date) : ''
	if (await act(() => (form.id ? updateShoppingTrip(form.id, date, form.label) : addShoppingTrip(props.campId, date, form.label)))) {
		tripForm.value = null
	}
}

function extraForm(trip) {
	extraForms[trip.id] ??= { name: '', amount: '', category: 'other' }
	return extraForms[trip.id]
}

async function addExtra(trip) {
	const form = extraForm(trip)
	if (await act(() => addShoppingExtra(trip.id, form.name, form.amount, form.category))) {
		form.name = ''
		form.amount = ''
	}
}
</script>

<template>
	<div class="shopping">
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else-if="overview">
			<header class="shopping__header">
				<p class="shopping__intro">
					{{ t('lagerplaner', 'The lists are calculated from the meal plan, the menu variants and their recipes. Long-life goods are bought on the first trip, fresh food on the last trip before the day it is needed.') }}
				</p>
				<div class="shopping__actions">
					<NcButton v-if="canEdit" @click="openTripForm()">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPlus" />
						</template>
						{{ t('lagerplaner', 'Add shopping trip') }}
					</NcButton>
					<NcButton variant="tertiary" @click="printLists">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPrinter" />
						</template>
						{{ t('lagerplaner', 'Print shopping lists') }}
					</NcButton>
				</div>
			</header>

			<NcNoteCard v-if="overview.provisional" type="warning" :heading="t('lagerplaner', 'Provisional list')">
				<ul class="shopping__reasons">
					<li v-for="reason in overview.reasons" :key="reason">
						{{ reason }}
					</li>
				</ul>
				<p>{{ t('lagerplaner', 'Clarify these points before the big shopping trip.') }}</p>
			</NcNoteCard>

			<NcNoteCard
				v-if="overview.mealsWithoutRecipe > 0"
				type="info"
				:text="n('lagerplaner', '%n of {meals} self-catered meals has no recipe yet and is missing from the lists.', '%n of {meals} self-catered meals have no recipe yet and are missing from the lists.', overview.mealsWithoutRecipe, { meals: overview.meals })" />

			<form v-if="tripForm" class="shopping__trip-form" @submit.prevent="saveTrip">
				<NcTextField v-model="tripForm.label" :label="t('lagerplaner', 'Name of the shopping trip')" />
				<NcDateTimePickerNative v-model="tripForm.date" type="date" :label="t('lagerplaner', 'Date')" />
				<NcButton type="submit" variant="primary">
					{{ t('lagerplaner', 'Save') }}
				</NcButton>
				<NcButton variant="tertiary" @click="tripForm = null">
					{{ t('lagerplaner', 'Cancel') }}
				</NcButton>
			</form>

			<section v-for="trip in overview.trips" :key="trip.id" class="trip">
				<header class="trip__header">
					<h3>{{ trip.label }}, {{ formatDay(trip.date, { weekday: 'long', day: 'numeric', month: 'long' }) }}</h3>
					<span class="trip__progress">{{ t('lagerplaner', '{done} of {total} done', progress(trip)) }}</span>
					<NcActions v-if="canEdit" :aria-label="t('lagerplaner', 'Actions for {title}', { title: trip.label })">
						<NcActionButton close-after-click @click="openTripForm(trip)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPencil" />
							</template>
							{{ t('lagerplaner', 'Edit') }}
						</NcActionButton>
						<NcActionButton v-if="overview.trips.length > 1" close-after-click @click="deleteTarget = trip">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDelete" />
							</template>
							{{ t('lagerplaner', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</header>

				<p v-if="trip.lines.length + trip.extras.length === 0" class="trip__empty">
					{{ t('lagerplaner', 'Nothing to buy on this trip yet.') }}
				</p>

				<div v-for="group in groups(trip)" :key="group.key" class="group">
					<h4>{{ group.label }}</h4>
					<ul class="group__list">
						<li
							v-for="line in group.lines"
							:key="line.key"
							class="line"
							:class="{ 'line--done': line.status === 'done' }">
							<NcCheckboxRadioSwitch
								:model-value="line.status === 'done'"
								type="checkbox"
								:disabled="!canEdit || !trip.id || coveredElsewhere(line)"
								@update:model-value="toggleLine(trip, line)">
								{{ line.name }}
								<span v-if="line.promises.length" class="line__promise">{{ t('lagerplaner', 'only for: {list}', { list: line.promises.join(', ') }) }}</span>
							</NcCheckboxRadioSwitch>
							<span class="line__amount">{{ amount(line) }}</span>
							<span class="line__details">
								<span v-if="line.notes.length">{{ line.notes.join('; ') }}. </span>
								{{ t('lagerplaner', 'For {days}', { days: days(line.days) }) }}
								<span v-if="line.stock" class="line__flag">{{ t('lagerplaner', 'In stock: {amount}', { amount: plain(line.stock) }) }}</span>
								<span v-if="line.bought" class="line__flag">{{ t('lagerplaner', 'Bought: {amount}', { amount: plain(line.bought) }) }}</span>
								<span v-if="line.carried" class="line__flag">{{ t('lagerplaner', 'From an earlier trip: {amount}', { amount: plain(line.carried) }) }}</span>
								<span v-if="coveredElsewhere(line) && !line.stock" class="line__flag">{{ t('lagerplaner', 'covered by another trip') }}</span>
								<span v-if="line.needsReview" class="line__warning">{{ t('lagerplaner', 'Appears in different units; please check the amount.') }}</span>
								<span v-if="line.late" class="line__warning">{{ t('lagerplaner', 'Trip is after the day it is needed') }}</span>
							</span>
							<span v-if="line.status === 'partial'" class="line__shortfall">
								{{ line.purchasedHere
									? t('lagerplaner', 'Needed in addition: {amount}', { amount: plain(line.open) })
									: t('lagerplaner', 'Still to buy: {amount}', { amount: plain(line.open) }) }}
							</span>
							<NcButton
								v-if="canEdit && line.quantity !== null && (line.status !== 'done' || line.stock)"
								class="line__stock"
								variant="tertiary"
								size="small"
								@click="openStock(line)">
								{{ line.stock ? t('lagerplaner', 'Change stock') : t('lagerplaner', 'We have some') }}
							</NcButton>
							<form v-if="stockForm?.key === line.key" class="line__stock-form" @submit.prevent="saveStock()">
								<NcTextField v-model="stockForm.quantity" :label="t('lagerplaner', 'Amount in stock')" />
								<select v-model="stockForm.unit" :aria-label="t('lagerplaner', 'Unit')">
									<option v-for="unit in stockForm.units" :key="unit" :value="unit">
										{{ unit }}
									</option>
								</select>
								<NcButton type="submit" variant="primary" size="small">
									{{ t('lagerplaner', 'Save') }}
								</NcButton>
								<NcButton v-if="line.stock" variant="tertiary" size="small" @click="saveStock(true)">
									{{ t('lagerplaner', 'No stock') }}
								</NcButton>
								<NcButton variant="tertiary" size="small" @click="stockForm = null">
									{{ t('lagerplaner', 'Cancel') }}
								</NcButton>
							</form>
						</li>
						<li v-for="extra in group.extras" :key="extra.id" class="line" :class="{ 'line--done': extra.purchased }">
							<NcCheckboxRadioSwitch
								:model-value="extra.purchased"
								type="checkbox"
								:disabled="!canEdit"
								@update:model-value="(value) => act(() => setShoppingExtra(extra.id, value))">
								{{ extra.name }}
							</NcCheckboxRadioSwitch>
							<span class="line__amount">{{ extra.amount }}</span>
							<span class="line__details">{{ t('lagerplaner', 'Added by hand') }}</span>
							<NcButton
								v-if="canEdit"
								class="line__stock"
								variant="tertiary"
								size="small"
								:aria-label="t('lagerplaner', 'Remove {name}', { name: extra.name })"
								@click="act(() => deleteShoppingExtra(extra.id))">
								<template #icon>
									<NcIconSvgWrapper :path="mdiClose" :size="16" />
								</template>
							</NcButton>
						</li>
					</ul>
				</div>

				<div v-if="trip.orphans.length > 0" class="group">
					<h4>{{ t('lagerplaner', 'Bought here, no longer planned for this trip') }}</h4>
					<p class="trip__empty">
						{{ t('lagerplaner', 'These amounts count for later trips. Undo the tick if it was a mistake.') }}
					</p>
					<ul class="group__list">
						<li v-for="orphan in trip.orphans" :key="orphan.key" class="line line--done">
							<span>{{ orphan.name }}</span>
							<span class="line__amount">{{ plain(orphan.bought) }}</span>
							<NcButton
								v-if="canEdit"
								class="line__stock"
								variant="tertiary"
								size="small"
								@click="act(() => setShoppingLine(trip.id, orphan.key, false))">
								{{ t('lagerplaner', 'Undo tick') }}
							</NcButton>
						</li>
					</ul>
				</div>

				<form v-if="canEdit" class="trip__extra" @submit.prevent="addExtra(trip)">
					<NcTextField v-model="extraForm(trip).name" :label="t('lagerplaner', 'Add item, e.g. bread rolls')" />
					<NcTextField v-model="extraForm(trip).amount" :label="t('lagerplaner', 'Amount (optional)')" />
					<div class="trip__select">
						<label :for="`${id}-cat-${trip.id}`">{{ t('lagerplaner', 'Category') }}</label>
						<select :id="`${id}-cat-${trip.id}`" v-model="extraForm(trip).category">
							<option v-for="category in overview.categories" :key="category.key" :value="category.key">
								{{ category.label }}
							</option>
						</select>
					</div>
					<NcButton type="submit" :disabled="!extraForm(trip).name.trim()">
						{{ t('lagerplaner', 'Add') }}
					</NcButton>
				</form>
			</section>

			<NcDialog
				v-if="deleteTarget"
				:open="true"
				:name="t('lagerplaner', 'Delete shopping trip?')"
				:message="t('lagerplaner', '{trip}: {purchases} bought item(s) and {extras} own item(s) move to the previous shopping trip, where you can still change them. The lists are recalculated.', { trip: deleteTarget.label, purchases: deleteTarget.purchasedCount, extras: deleteTarget.extras.length })"
				:buttons="[
					{ label: t('lagerplaner', 'Cancel'), callback: () => { deleteTarget = null } },
					{ label: t('lagerplaner', 'Delete trip'), variant: 'error', callback: () => { confirmDeleteTrip() } },
				]"
				@update:open="(value) => !value && (deleteTarget = null)" />
		</template>
	</div>
</template>

<style scoped>
.shopping {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.shopping__header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 3);
}

.shopping__intro,
.trip__progress,
.trip__empty,
.line__details {
	color: var(--color-text-maxcontrast);
}

.shopping__intro {
	flex: 1 1 400px;
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
}

.shopping__actions,
.shopping__trip-form,
.trip__extra {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.trip {
	padding-top: calc(var(--default-grid-baseline) * 2);
	border-top: 2px solid var(--color-border-dark, var(--color-border));
}

.trip__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
}

.trip__header h3 {
	margin: 0;
}

.group h4 {
	margin: calc(var(--default-grid-baseline) * 3) 0 var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
	font-size: 0.95em;
	text-transform: uppercase;
	letter-spacing: 0.03em;
}

.group__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.line {
	display: grid;
	grid-template-columns: minmax(200px, 1fr) 9em auto;
	grid-template-areas:
		'name amount action'
		'details details details';
	align-items: center;
	column-gap: calc(var(--default-grid-baseline) * 3);
	padding: 2px 0;
	border-bottom: 1px solid var(--color-border);
}

.line > :first-child {
	grid-area: name;
}

.line__amount {
	grid-area: amount;
	text-align: end;
	font-variant-numeric: tabular-nums;
	font-weight: bold;
}

.line__stock {
	grid-area: action;
}

.line__details {
	grid-area: details;
	padding-inline-start: calc(var(--default-grid-baseline) * 8);
	font-size: 0.9em;
}

.line__shortfall {
	grid-column: 1 / -1;
	padding-inline-start: calc(var(--default-grid-baseline) * 8);
	color: var(--color-warning-text);
	font-weight: bold;
}

.line__warning {
	margin-inline-start: var(--default-grid-baseline);
	color: var(--color-warning-text);
}

.line__promise {
	margin-inline-start: var(--default-grid-baseline);
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 12px);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 0.85em;
}

.line__stock-form {
	display: flex;
	grid-column: 1 / -1;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline) 0 var(--default-grid-baseline) calc(var(--default-grid-baseline) * 8);
}

.line__stock-form select {
	min-height: var(--default-clickable-area);
	margin: 0;
}

.shopping__reasons {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}

.line__flag {
	margin-inline-start: var(--default-grid-baseline);
}

.line--done .line__amount,
.line--done :deep(.checkbox-content__text) {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}

.trip__extra {
	margin-top: calc(var(--default-grid-baseline) * 3);
}

.trip__extra > :first-child {
	flex: 2 1 220px;
}

.trip__select {
	display: flex;
	flex-direction: column;
	gap: 2px;
	color: var(--color-text-maxcontrast);
}

.trip__select select {
	min-height: var(--default-clickable-area);
	margin: 0;
	color: var(--color-main-text);
}
</style>
