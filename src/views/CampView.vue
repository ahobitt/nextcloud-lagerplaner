<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiAccountMultiple, mdiAutoFix, mdiCheckAll, mdiContentSaveOutline, mdiDelete, mdiPencil, mdiPlus, mdiRestore } from '@mdi/js'
import { showError, showSuccess, showUndo } from '@nextcloud/dialogs'
import { n, t } from '../l10n.js'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { computed, onMounted, ref, watch } from 'vue'
import {
	attachGame, confirmProgramItem, createProgramItem, deleteCamp, deleteProgramItem, detachGame, errorMessage,
	getCamp, getDeletedProgram, getDishes, getMaterial, getMeals, moveScheduleEntry, restoreSchedule, getProgram, getProgramConflicts, getProgramGames, getTasks, restoreProgramItem, updateProgramItem,
} from '../api.js'
import GamePicker from '../components/GamePicker.vue'
import ProgramItemForm from '../components/ProgramItemForm.vue'
import ProgramItemRow from '../components/ProgramItemRow.vue'
import TeamDialog from '../components/TeamDialog.vue'
import CateringPanel from './CateringPanel.vue'
import ShoppingPanel from './ShoppingPanel.vue'
import MaterialPanel from './MaterialPanel.vue'
import SitesPanel from './SitesPanel.vue'
import TasksPanel from '../components/TasksPanel.vue'
import ExportMenu from '../components/ExportMenu.vue'
import TemplateApplyDialog from '../components/TemplateApplyDialog.vue'
import TemplateSaveDialog from '../components/TemplateSaveDialog.vue'
import { duration, endTime, formatDay, formatRange, mealLabels, parseDay, toDay } from '../format.js'
import { navigate, route } from '../router.js'
import { roleLabels } from '../roles.js'

const props = defineProps({
	campId: { type: Number, required: true },
})
const emit = defineEmits(['deleted'])

const loading = ref(true)
const camp = ref(null)
const groups = ref([])
const program = ref([])
const deletedProgram = ref([])
const tasks = ref([])
const tab = ref('program')
const working = ref(false)
const deleteOpen = ref(false)
const showDeleted = ref(false)
const access = ref(null)
const notFound = ref(false)
const teamOpen = ref(false)
/** Rechte der angemeldeten Person in diesem Lager (Konzept 18.1) */
const can = computed(() => access.value?.can ?? {})
const programGames = ref([])
const materialItems = ref([])
/** Vorlage speichern: { kind: 'item', programItemId, defaultName } oder { kind: 'day', campId, day, defaultName } */
const templateSave = ref(null)
/** Vorlage einfügen: { day } */
const templateApply = ref(null)

/** Uhrzeit als Minuten und zurück */
const toMinutes = (time) => Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5))
const toTime = (minutes) => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
const MEAL_MINUTES = { breakfast: 30, lunch: 30, snack: 15, dinner: 45 }
const rowMinutes = (row) => (row.isMeal ? (MEAL_MINUTES[row.mealType] ?? 30) : row.durationMinutes)

/*
 * Verschieben per Ziehen oder „früher/später“: Der Server passt Folgendes an, bis eine Lücke
 * die Verschiebung auffängt, und meldet alle Änderungen zurück; „Rückgängig“ stellt sie wieder her.
 */
const dragging = ref(null)
const dropTarget = ref(null)
// Der gezogene Eintrag, unabhängig von der Anzeige: Chrome bricht das Ziehen ab, wenn sich
// die Seite noch während „dragstart“ ändert; die Anzeige folgt deshalb einen Augenblick später.
let moving = null

function dragStart(event, row) {
	moving = { kind: row.isMeal ? 'meal' : 'program', id: row.id, title: row.isMeal ? mealLabels()[row.mealType] : row.title }
	event.dataTransfer.effectAllowed = 'move'
	event.dataTransfer.setData('text/plain', row.rowKey)
	setTimeout(() => {
		dragging.value = moving
	}, 0)
}

function dragOver(event, key) {
	if (moving) {
		event.preventDefault()
		event.dataTransfer.dropEffect = 'move'
		dropTarget.value = key
	}
}

function dragEnd() {
	moving = null
	dragging.value = null
	dropTarget.value = null
}

function drop(event, day, time) {
	event.preventDefault()
	const entry = moving
	dragEnd()
	if (entry) {
		moveTo(entry, day, time)
	}
}

function shift(row, minutes) {
	const start = toMinutes(row.startTime) + minutes
	if (start >= 0 && start < 24 * 60) {
		moveTo({ kind: row.isMeal ? 'meal' : 'program', id: row.id, title: row.isMeal ? mealLabels()[row.mealType] : row.title }, row.day, toTime(start))
	}
}

async function loadConflicts() {
	try {
		conflicts.value = await getProgramConflicts(props.campId)
	} catch {
		// Hinweise sind eine Hilfe; ohne sie bleibt die Planung bedienbar.
	}
}

async function moveTo(moving, day, time) {
	try {
		const result = await moveScheduleEntry(props.campId, moving.kind, moving.id, day, time)
		await Promise.all([reloadProgram(), loadMeals(), loadConflicts()])
		const others = result.changes.length - 1
		const message = others > 0
			? n('lagerplaner', '{title} moved to {time}; %n more item adjusted', '{title} moved to {time}; %n more items adjusted', others, { title: moving.title, time })
			: t('lagerplaner', '{title} moved to {time}', { title: moving.title, time })
		showUndo(message, async () => {
			try {
				await restoreSchedule(props.campId, result.changes)
				await Promise.all([reloadProgram(), loadMeals(), loadConflicts()])
			} catch (error) {
				showError(errorMessage(error))
			}
		})
	} catch (error) {
		showError(errorMessage(error))
	}
}

/** Programm, Spiele und Material neu laden, etwa nach dem Einfügen einer Vorlage. */
async function reloadProgram() {
	try {
		const [items, games] = await Promise.all([getProgram(props.campId), getProgramGames(props.campId)])
		program.value = items
		programGames.value = games
		await loadMaterial()
	} catch (error) {
		showError(errorMessage(error))
	}
}

/** Lagerdaten neu laden, etwa nachdem der Lagerort über die Merkliste festgelegt wurde. */
async function reloadCamp() {
	try {
		camp.value = (await getCamp(props.campId)).camp
	} catch (error) {
		showError(errorMessage(error))
	}
}

async function loadMaterial() {
	try {
		materialItems.value = (await getMaterial(props.campId)).items
	} catch {
		// Die Anzeige am Programmpunkt ist eine Hilfe; der Reiter Material bleibt nutzbar.
	}
}

/** Material je Programmpunkt, zum Beispiel „Seile (10 Stück)“ */
const materialByItem = computed(() => {
	const map = {}
	for (const material of materialItems.value) {
		for (const use of material.uses) {
			if (use.programItemId !== null) {
				const amount = use.quantity === null ? t('lagerplaner', 'quantity open') : `${use.quantity}${material.unit ? ' ' + material.unit : ''}`
				;(map[use.programItemId] ??= []).push(`${material.name} (${amount})`)
			}
		}
	}
	return map
})

const tasksByItem = computed(() => {
	const map = {}
	for (const task of tasks.value) {
		if (task.programItemId) {
			(map[task.programItemId] ??= []).push(task)
		}
	}
	return map
})
const conflicts = ref({})
/** Programmpunkt, für den gerade ein Spiel gewählt wird */
const gameTarget = ref(null)

/** Offenes Formular: { mode: 'add', day } oder { mode: 'edit', id } */
const programForm = ref(null)
/** Offenes Aufgabenformular: { mode: 'add' } oder { mode: 'edit', id } */

onMounted(async () => {
	try {
		const [detail, items, deleted, taskList, games] = await Promise.all([
			getCamp(props.campId), getProgram(props.campId), getDeletedProgram(props.campId), getTasks(props.campId),
			getProgramGames(props.campId),
		])
		programGames.value = games
		loadMeals()
		if (['tasks', 'material', 'site', 'meals', 'shopping', 'program'].includes(route.params.tab)) {
			tab.value = route.params.tab
		}
		loadMaterial()
		camp.value = detail.camp
		groups.value = detail.groups
		access.value = detail.access
		program.value = items
		deletedProgram.value = deleted
		tasks.value = taskList
	} catch (error) {
		if (error?.response?.status === 404) {
			notFound.value = true
		} else {
			showError(errorMessage(error))
		}
	} finally {
		loading.value = false
	}
})

const allDays = computed(() => {
	if (!camp.value) {
		return []
	}
	const days = []
	for (let d = parseDay(camp.value.startDate); toDay(d) <= camp.value.endDate; d.setDate(d.getDate() + 1)) {
		days.push(toDay(d))
	}
	return days
})

const byTime = (a, b) => a.day.localeCompare(b.day) || a.startTime.localeCompare(b.startTime) || a.id - b.id

/** Mahlzeiten im Programm: Art, Uhrzeit und Gericht, ohne Varianten oder Ernährungsangaben. */
const meals = ref([])
const dishes = ref({})

async function loadMeals() {
	try {
		[meals.value, dishes.value] = await Promise.all([getMeals(props.campId), getDishes(props.campId)])
	} catch {
		// Das Programm bleibt ohne Mahlzeiten bedienbar.
	}
}

const programByDay = computed(() => allDays.value.map((day) => {
	const items = program.value.filter((item) => item.day === day).sort(byTime)
	const dayMeals = meals.value.filter((meal) => meal.day === day)
		.map((meal) => ({ ...meal, isMeal: true, startTime: meal.time, rowKey: `meal-${meal.id}` }))
	// Mahlzeiten zwischen die Programmpunkte, nach Uhrzeit; bei gleicher Zeit die Mahlzeit zuerst.
	const sorted = [...dayMeals, ...items.map((item) => ({ ...item, rowKey: `item-${item.id}` }))]
		.sort((a, b) => a.startTime.localeCompare(b.startTime) || (a.isMeal ? -1 : 0) - (b.isMeal ? -1 : 0))
	// Lücken ab 30 Minuten, in denen für niemanden etwas geplant ist, als „Freizeit“ zeigen
	const rows = []
	let busyUntil = null
	for (const row of sorted) {
		const start = toMinutes(row.startTime)
		if (busyUntil !== null && start - busyUntil >= 30) {
			rows.push({ isGap: true, rowKey: `gap-${day}-${busyUntil}`, startTime: toTime(busyUntil), endTime: toTime(start), minutes: start - busyUntil })
		}
		rows.push(row)
		if (!row.cancelled) {
			busyUntil = Math.max(busyUntil ?? 0, start + rowMinutes(row))
		}
	}
	return { day, items, rows, busyUntil: busyUntil === null ? null : toTime(Math.min(busyUntil, 23 * 60 + 45)), proposals: items.filter(isProposal) }
}))

/** Erhaltene Punkte außerhalb des aktuellen Zeitraums, zum Beispiel nach einer Verkürzung. */
const outsideItems = computed(() => program.value.filter((item) => !allDays.value.includes(item.day)).sort(byTime))


const groupLabel = computed(() => Object.fromEntries(groups.value.map((g) => [g.key, g.label])))
const participantGroups = computed(() => groups.value.filter((g) => !g.isLeaders && g.count > 0))
const people = computed(() => groups.value.reduce((sum, g) => sum + (g.count ?? 0), 0))
const openTasks = computed(() => tasks.value.filter((task) => task.status === 'open'))

/** Planungsstand nach konkreten Prüfkriterien (Konzept 4.4), nur was gerade zutrifft. */
const status = computed(() => {
	const lines = []
	if (camp.value && !camp.value.locationKnown) {
		lines.push(t('lagerplaner', 'The location is not decided yet.'))
	}
	if (outsideItems.value.length > 0) {
		lines.push(n('lagerplaner', '%n programme item lies outside the camp period.', '%n programme items lie outside the camp period.', outsideItems.value.length))
	}
	const proposals = program.value.filter(isProposal).length
	if (proposals > 0) {
		lines.push(n('lagerplaner', '%n programme item is still a proposal.', '%n programme items are still proposals.', proposals))
	}
	const weather = program.value.filter((item) => item.weatherAltNeeded && !item.weatherAlternative).length
	if (weather > 0) {
		lines.push(n('lagerplaner', '%n outdoor item has no bad-weather alternative yet.', '%n outdoor items have no bad-weather alternative yet.', weather))
	}
	const next = [...openTasks.value].sort((a, b) => (a.dueDate ?? '9999').localeCompare(b.dueDate ?? '9999'))[0]
	if (next) {
		lines.push(next.dueDate
			? t('lagerplaner', 'Next task: {task}, due {date}', { task: next.title, date: formatDay(next.dueDate, { day: 'numeric', month: 'long', year: 'numeric' }) })
			: t('lagerplaner', 'Next task: {task}', { task: next.title }))
	}
	return lines
})

const gamesByItem = computed(() => {
	const map = {}
	for (const game of programGames.value) {
		(map[game.programItemId] ??= []).push(game)
	}
	return map
})

/** Gruppengröße eines Programmpunkts als Vorbelegung für die Spielesuche */
function groupSizeFor(item) {
	const relevant = item.groups.length === 0 ? participantGroups.value : participantGroups.value.filter((g) => item.groups.includes(g.key))
	const size = relevant.reduce((sum, g) => sum + (g.count ?? 0), 0)
	return size > 0 ? size : null
}

async function addGame(item, game) {
	const link = await run(() => attachGame(item.id, game.id))
	if (link) {
		programGames.value = [...programGames.value, link]
		showSuccess(t('lagerplaner', '"{game}" added to "{item}"', { game: game.title, item: item.title }))
	}
}

async function removeGame(link) {
	const ok = await run(async () => {
		await detachGame(link.id)
		return true
	})
	if (ok) {
		programGames.value = programGames.value.filter((entry) => entry.id !== link.id)
	}
}

function isProposal(item) {
	return item.reviewState === 'proposal' && !item.userModifiedAt
}

function groupsText(item) {
	return item.groups.length === 0
		? t('lagerplaner', 'Everyone')
		: item.groups.map((key) => groupLabel.value[key] ?? t('lagerplaner', 'removed group')).join(', ')
}

let conflictTimer = null
/** Überschneidungen nach jeder Änderung am Programm neu laden. */
// Gerichte können sich in der Küche ändern; beim Wechsel ins Programm frisch laden.
watch(tab, (value) => {
	if (value === 'program') {
		loadMeals()
	}
})

watch(program, () => {
	clearTimeout(conflictTimer)
	conflictTimer = setTimeout(async () => {
		try {
			conflicts.value = await getProgramConflicts(props.campId)
		} catch {
			// Hinweise sind eine Hilfe; ohne sie bleibt die Planung bedienbar.
		}
	}, 200)
}, { deep: true })

function titleOf(id) {
	return program.value.find((item) => item.id === id)?.title ?? ''
}

function replace(list, updated) {
	list.value = list.value.map((entry) => (entry.id === updated.id ? updated : entry))
}

async function run(action) {
	working.value = true
	try {
		return await action()
	} catch (error) {
		showError(errorMessage(error))
		return undefined
	} finally {
		working.value = false
	}
}

const confirm = (item) => run(async () => replace(program, await confirmProgramItem(item.id)))

async function confirmDay(entry) {
	const done = await run(async () => {
		for (const item of entry.proposals) {
			replace(program, await confirmProgramItem(item.id))
		}
		return true
	})
	if (done) {
		showSuccess(n('lagerplaner', '%n item confirmed', '%n items confirmed', entry.proposals.length))
	}
}

async function saveProgramItem({ gameId, ...fields }) {
	const form = programForm.value
	const saved = await run(() => (form.mode === 'add'
		? createProgramItem(props.campId, fields)
		: updateProgramItem(form.id, fields)))
	if (saved) {
		if (form.mode === 'add') {
			program.value = [...program.value, saved]
			if (gameId) {
				const link = await run(() => attachGame(saved.id, gameId))
				if (link) {
					programGames.value = [...programGames.value, link]
				}
			}
		} else {
			replace(program, saved)
		}
		programForm.value = null
	}
}

async function remove(item) {
	const ok = await run(async () => {
		await deleteProgramItem(item.id)
		return true
	})
	if (ok) {
		program.value = program.value.filter((entry) => entry.id !== item.id)
		// Vorschläge des Assistenten bleiben als gelöscht erhalten und können wiederhergestellt werden.
		if (item.origin !== 'manual') {
			deletedProgram.value = [...deletedProgram.value, { ...item, deletedAt: Date.now() }]
			showSuccess(t('lagerplaner', 'Deleted. The assistant will not suggest it again unless you restore it.'))
		}
	}
}

async function restore(item) {
	const restored = await run(() => restoreProgramItem(item.id))
	if (restored) {
		deletedProgram.value = deletedProgram.value.filter((entry) => entry.id !== item.id)
		program.value = [...program.value, restored]
	}
}

async function reloadTasks() {
	try {
		tasks.value = await getTasks(props.campId)
	} catch (error) {
		showError(errorMessage(error))
	}
}


const deleteButtons = computed(() => [
	{ label: t('lagerplaner', 'Keep camp'), variant: 'tertiary', callback: () => undefined },
	{ label: t('lagerplaner', 'Delete camp'), variant: 'error', callback: removeCamp },
])

async function removeCamp() {
	const ok = await run(async () => {
		await deleteCamp(props.campId)
		return true
	})
	if (ok) {
		showSuccess(t('lagerplaner', 'Camp deleted'))
		emit('deleted')
	}
}
</script>

<template>
	<div class="camp">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="notFound"
			:name="t('lagerplaner', 'Camp not available')"
			:description="t('lagerplaner', 'This camp does not exist or has not been shared with you. Ask the camp leaders to add you or your group to the team.')">
			<template #action>
				<NcButton @click="navigate('')">
					{{ t('lagerplaner', 'Back to the overview') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else-if="camp">
			<header class="camp__header">
				<div>
					<h2>{{ camp.name }}</h2>
					<p class="camp__meta">
						{{ formatRange(camp.startDate, camp.endDate) }},
						{{ camp.locationKnown ? camp.locationName : t('lagerplaner', 'location open') }},
						{{ n('lagerplaner', '%n person', '%n people', people) }}
					</p>
					<p v-if="access && !access.isOwner" class="camp__meta">
						{{ t('lagerplaner', 'Shared with you as: {roles}', { roles: access.roles.map((role) => roleLabels()[role] ?? role).join(', ') }) }}
					</p>
				</div>
				<div class="camp__actions">
					<NcButton @click="teamOpen = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiAccountMultiple" />
						</template>
						{{ t('lagerplaner', 'Team') }}
					</NcButton>
					<ExportMenu :camp-id="campId" :days="allDays" :groups="participantGroups" :can="can" />
					<NcButton v-if="can.wizard" @click="navigate(`camp/${campId}/assistant`)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiAutoFix" />
						</template>
						{{ t('lagerplaner', 'Run the assistant again') }}
					</NcButton>
					<NcActions v-if="can.delete" :aria-label="t('lagerplaner', 'More actions for this camp')">
						<NcActionButton @click="deleteOpen = true">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDelete" />
							</template>
							{{ t('lagerplaner', 'Delete camp') }}
						</NcActionButton>
					</NcActions>
				</div>
			</header>

			<ul v-if="status.length > 0" class="camp__status" :aria-label="t('lagerplaner', 'Planning status')">
				<li v-for="line in status" :key="line">
					{{ line }}
				</li>
			</ul>

			<div class="camp__tabs" role="tablist">
				<button
					v-for="entry in [
						{ key: 'program', label: t('lagerplaner', 'Programme') },
						{ key: 'tasks', label: n('lagerplaner', 'Tasks (%n open)', 'Tasks (%n open)', openTasks.length) },
						{ key: 'material', label: t('lagerplaner', 'Material') },
						{ key: 'site', label: t('lagerplaner', 'Campsite') },
						...(can.cateringView ? [{ key: 'meals', label: t('lagerplaner', 'Kitchen') }, { key: 'shopping', label: t('lagerplaner', 'Shopping') }] : []),
					]"
					:id="`tab-${entry.key}`"
					:key="entry.key"
					type="button"
					role="tab"
					class="camp__tab"
					:aria-selected="tab === entry.key"
					:aria-controls="`panel-${entry.key}`"
					@click="tab = entry.key">
					{{ entry.label }}
				</button>
			</div>

			<!-- Programm -->
			<section
				v-if="tab === 'program'"
				id="panel-program"
				role="tabpanel"
				aria-labelledby="tab-program">
				<article v-if="outsideItems.length > 0" class="day day--outside">
					<header class="day__header">
						<h3>{{ t('lagerplaner', 'Outside the camp period') }}</h3>
					</header>
					<p class="camp__hint">
						{{ t('lagerplaner', 'These items were kept because you confirmed or edited them. Move them to a camp day or delete them.') }}
					</p>
					<ol class="day__items">
						<template v-for="item in outsideItems" :key="item.id">
							<li v-if="programForm?.mode === 'edit' && programForm.id === item.id" class="day__form">
								<ProgramItemForm
									:item="item"
									:days="allDays"
									:groups="participantGroups"
									:busy="working"
									@save="saveProgramItem"
									@cancel="programForm = null" />
							</li>
							<ProgramItemRow
								v-else
								:item="item"
								show-day
								:games="gamesByItem[item.id] ?? []"
								:conflicts="conflicts[item.id] ?? []"
								:materials="materialByItem[item.id] ?? []"
								:tasks="tasksByItem[item.id] ?? []"
								:editable="!!can.program"
								:groups-text="groupsText(item)"
								:alternative-title="titleOf(item.alternativeTo)"
								@confirm="confirm(item)"
								@edit="programForm = { mode: 'edit', id: item.id }"
								@remove="remove(item)"
								@add-game="gameTarget = item"
								@remove-game="removeGame"
								@save-template="templateSave = { kind: 'item', programItemId: item.id, defaultName: item.title }" />
						</template>
					</ol>
				</article>

				<article v-for="entry in programByDay" :key="entry.day" class="day">
					<header class="day__header">
						<h3>{{ formatDay(entry.day) }}</h3>
						<div class="day__actions">
							<NcButton
								v-if="can.program && entry.proposals.length > 1"
								variant="tertiary"
								:disabled="working"
								@click="confirmDay(entry)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiCheckAll" />
								</template>
								{{ t('lagerplaner', 'Confirm all proposals of this day') }}
							</NcButton>
							<NcActions :aria-label="t('lagerplaner', 'Templates for {day}', { day: formatDay(entry.day) })">
								<template #icon>
									<NcIconSvgWrapper :path="mdiContentSaveOutline" />
								</template>
								<NcActionButton v-if="can.program" close-after-click @click="templateApply = { day: entry.day }">
									{{ t('lagerplaner', 'Insert template') }}
								</NcActionButton>
								<NcActionButton v-if="entry.items.length > 0" close-after-click @click="templateSave = { kind: 'day', campId, day: entry.day, defaultName: formatDay(entry.day, { weekday: 'long' }) }">
									{{ t('lagerplaner', 'Save day as template') }}
								</NcActionButton>
							</NcActions>
							<NcButton
								v-if="can.program"
								variant="tertiary"
								:aria-label="t('lagerplaner', 'Add programme item on {day}', { day: formatDay(entry.day) })"
								@click="programForm = { mode: 'add', day: entry.day }">
								<template #icon>
									<NcIconSvgWrapper :path="mdiPlus" />
								</template>
								{{ t('lagerplaner', 'Add') }}
							</NcButton>
						</div>
					</header>
					<div v-if="programForm?.mode === 'add' && programForm.day === entry.day" class="day__form">
						<ProgramItemForm
							:days="allDays"
							:default-day="entry.day"
							:groups="participantGroups"
							:busy="working"
							@save="saveProgramItem"
							@cancel="programForm = null" />
					</div>
					<p v-if="entry.items.length === 0" class="day__empty">
						{{ t('lagerplaner', 'Nothing planned for this day yet.') }}
					</p>
					<ol class="day__items">
						<template v-for="item in entry.rows" :key="item.rowKey">
							<li
								v-if="item.isGap"
								class="free-row"
								:class="{ 'drop-target': dropTarget === item.rowKey }"
								@dragover="dragOver($event, item.rowKey)"
								@dragleave="dropTarget = null"
								@drop="drop($event, entry.day, item.startTime)">
								<span class="meal-row__time">{{ item.startTime }}–{{ item.endTime }}</span>
								<span>{{ t('lagerplaner', 'Free time') }}, {{ duration(item.minutes) }}</span>
							</li>
							<li
								v-else-if="item.isMeal"
								class="meal-row"
								:class="{ 'meal-row--cancelled': item.cancelled, 'drop-target': dropTarget === item.rowKey }"
								:draggable="!!can.program && !item.cancelled"
								@dragstart="dragStart($event, item)"
								@dragend="dragEnd"
								@dragover="dragOver($event, item.rowKey)"
								@dragleave="dropTarget = null"
								@drop="drop($event, entry.day, item.startTime)">
								<span class="meal-row__time">{{ item.time }}</span>
								<span class="meal-row__text">
									<strong>{{ mealLabels()[item.mealType] ?? item.mealType }}</strong>
									<template v-if="dishes[item.id]">: {{ dishes[item.id] }}</template>
									<span v-if="item.cancelled" class="meal-row__note">{{ t('lagerplaner', 'cancelled') }}</span>
									<span v-else-if="item.portable" class="meal-row__note">{{ t('lagerplaner', 'on the way') }}</span>
									<span v-else-if="!item.selfCatered" class="meal-row__note">{{ t('lagerplaner', 'external catering') }}</span>
								</span>
								<span class="meal-row__actions">
									<NcActions v-if="can.program && !item.cancelled" :aria-label="t('lagerplaner', 'Move {title}', { title: mealLabels()[item.mealType] })">
										<NcActionButton close-after-click @click="shift(item, -15)">
											{{ t('lagerplaner', '15 minutes earlier') }}
										</NcActionButton>
										<NcActionButton close-after-click @click="shift(item, 15)">
											{{ t('lagerplaner', '15 minutes later') }}
										</NcActionButton>
									</NcActions>
									<NcButton v-if="can.cateringView" variant="tertiary" size="small" @click="tab = 'meals'">
										{{ t('lagerplaner', 'Kitchen') }}
									</NcButton>
								</span>
							</li>
							<li v-else-if="programForm?.mode === 'edit' && programForm.id === item.id" class="day__form">
								<ProgramItemForm
									:item="item"
									:days="allDays"
									:groups="participantGroups"
									:busy="working"
									@save="saveProgramItem"
									@cancel="programForm = null" />
							</li>
							<ProgramItemRow
								v-else
								:class="{ 'drop-target': dropTarget === item.rowKey }"
								:draggable="!!can.program"
								:item="item"
								:games="gamesByItem[item.id] ?? []"
								:conflicts="conflicts[item.id] ?? []"
								:materials="materialByItem[item.id] ?? []"
								:tasks="tasksByItem[item.id] ?? []"
								:editable="!!can.program"
								:groups-text="groupsText(item)"
								:alternative-title="titleOf(item.alternativeTo)"
								@confirm="confirm(item)"
								@edit="programForm = { mode: 'edit', id: item.id }"
								@remove="remove(item)"
								@shift="(minutes) => shift(item, minutes)"
								@dragstart="dragStart($event, item)"
								@dragend="dragEnd"
								@dragover="dragOver($event, item.rowKey)"
								@dragleave="dropTarget = null"
								@drop="drop($event, entry.day, item.startTime)"
								@add-game="gameTarget = item"
								@remove-game="removeGame"
								@save-template="templateSave = { kind: 'item', programItemId: item.id, defaultName: item.title }" />
						</template>
						<li
							v-if="dragging && entry.busyUntil"
							class="free-row drop-end"
							:class="{ 'drop-target': dropTarget === `end-${entry.day}` }"
							@dragover="dragOver($event, `end-${entry.day}`)"
							@dragleave="dropTarget = null"
							@drop="drop($event, entry.day, entry.busyUntil)">
							{{ t('lagerplaner', 'Drop here: at the end of the day, {time}', { time: entry.busyUntil }) }}
						</li>
					</ol>
				</article>

				<section v-if="can.program && deletedProgram.length > 0" class="deleted">
					<NcButton variant="tertiary" :aria-expanded="showDeleted" @click="showDeleted = !showDeleted">
						{{ n('lagerplaner', 'Show %n deleted proposal', 'Show %n deleted proposals', deletedProgram.length) }}
					</NcButton>
					<template v-if="showDeleted">
						<p class="camp__hint">
							{{ t('lagerplaner', 'The assistant does not suggest these again. Restore one if you want it back.') }}
						</p>
						<ul class="deleted__list">
							<li v-for="item in deletedProgram" :key="item.id">
								<span>{{ formatDay(item.day, { weekday: 'short', day: 'numeric', month: 'short' }) }}, {{ item.startTime }}: {{ item.title }}</span>
								<NcButton variant="tertiary" :disabled="working" @click="restore(item)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiRestore" />
									</template>
									{{ t('lagerplaner', 'Restore') }}
								</NcButton>
							</li>
						</ul>
					</template>
				</section>
			</section>

			<!-- Aufgaben -->
			<section
				v-else-if="tab === 'tasks'"
				id="panel-tasks"
				role="tabpanel"
				aria-labelledby="tab-tasks">
				<TasksPanel v-model:tasks="tasks" :camp-id="campId" :program="program" :can="can" />
			</section>

			<!-- Lagerplatz -->
			<section
				v-else-if="tab === 'site'"
				id="panel-site"
				role="tabpanel"
				aria-labelledby="tab-site">
				<SitesPanel :camp-id="campId" :camp-type="camp.campType" :can="can" @location-changed="reloadCamp" />
			</section>

			<!-- Material -->
			<section
				v-else-if="tab === 'material'"
				id="panel-material"
				role="tabpanel"
				aria-labelledby="tab-material">
				<MaterialPanel :camp-id="campId" :camp-name="camp.name" :program="program" :can="can" @changed="loadMaterial" />
			</section>

			<!-- Küche -->
			<section
				v-else-if="tab === 'meals' && can.cateringView"
				id="panel-meals"
				role="tabpanel"
				aria-labelledby="tab-meals">
				<CateringPanel :camp-id="campId" :can-edit="!!can.catering" @needs-saved="reloadTasks" />
			</section>

			<!-- Einkauf -->
			<section
				v-else-if="tab === 'shopping' && can.cateringView"
				id="panel-shopping"
				role="tabpanel"
				aria-labelledby="tab-shopping">
				<ShoppingPanel :key="`shopping-${campId}`" :camp-id="campId" :camp-name="camp.name" :can-edit="!!can.catering" />
			</section>

			<TemplateSaveDialog v-if="templateSave" :source="templateSave" :default-name="templateSave.defaultName" @close="templateSave = null" />
			<TemplateApplyDialog v-if="templateApply" :camp-id="campId" :day="templateApply.day" @applied="reloadProgram" @close="templateApply = null" />

			<TeamDialog
				v-if="teamOpen"
				:camp-id="campId"
				:camp-name="camp.name"
				:can-manage="!!can.members"
				@close="teamOpen = false" />

			<GamePicker
				v-if="gameTarget"
				:group-size="groupSizeFor(gameTarget)"
				@select="(game) => addGame(gameTarget, game)"
				@close="gameTarget = null" />

			<NcDialog
				v-if="deleteOpen"
				v-model:open="deleteOpen"
				:name="t('lagerplaner', 'Delete camp')"
				:message="t('lagerplaner', 'This deletes the camp with its programme, tasks and meals. It cannot be undone.')"
				:buttons="deleteButtons" />
		</template>
	</div>
</template>

<style scoped>
.camp {
	max-width: 960px;
	margin: 0 auto;
	/* Oben Platz für den Umschalter der App-Navigation lassen. */
	padding: calc(var(--default-clickable-area) + var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 6) calc(var(--default-grid-baseline) * 10);
}

.camp__header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 3);
}

.camp__header h2 {
	margin-bottom: var(--default-grid-baseline);
}

.camp__meta,
.camp__hint,
.day__empty,
.task__meta,
.task__description {
	color: var(--color-text-maxcontrast);
}

.camp__actions,
.day__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.camp__status {
	margin: calc(var(--default-grid-baseline) * 4) 0 0;
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);
	padding-inline-start: calc(var(--default-grid-baseline) * 8);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	list-style: disc outside;
}

.camp__tabs {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 4);
	margin: calc(var(--default-grid-baseline) * 6) 0 calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}

.camp__tab {
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border: none;
	border-bottom: 3px solid transparent;
	border-radius: 0;
	margin: 0 0 -1px;
	background: none;
	color: var(--color-text-maxcontrast);
	font-weight: bold;
	cursor: pointer;
}

.camp__tab[aria-selected='true'] {
	border-bottom-color: var(--color-primary-element);
	color: var(--color-main-text);
}

.camp__tab:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.day {
	margin-top: calc(var(--default-grid-baseline) * 6);
}

.day--outside {
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px dashed var(--color-warning-text);
	border-radius: var(--border-radius-large);
}

.day__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	position: sticky;
	top: 0;
	z-index: 1;
	background: var(--color-main-background);
}

.day__header h3,
.day > h3 {
	margin: calc(var(--default-grid-baseline) * 2) 0;
}

.day__form {
	margin: calc(var(--default-grid-baseline) * 2) 0;
	list-style: none;
}

.day__items,
.tasks,
.deleted__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.deleted {
	margin-top: calc(var(--default-grid-baseline) * 8);
}

.deleted__list li {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.task-form {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) 0;
}

.task-form > :first-child {
	flex: 1 1 240px;
}

.task {
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.task__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.task--done :deep(.checkbox-content__text) {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.task__meta,
.task__description {
	display: block;
	margin: 2px 0 0 calc(var(--default-grid-baseline) * 8);
}

.task__required {
	margin-inline-start: calc(var(--default-grid-baseline) * 2);
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-warning-text);
	border-radius: var(--border-radius);
	color: var(--color-warning-text);
}


@media (max-width: 700px) {
	.camp {
		padding-inline: calc(var(--default-grid-baseline) * 3);
	}
}
.meal-row {
	display: grid;
	grid-template-columns: 7em 1fr auto;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
}

.meal-row__time {
	font-variant-numeric: tabular-nums;
}

.meal-row__text strong {
	color: var(--color-main-text);
	font-weight: normal;
}

.meal-row__note {
	margin-inline-start: var(--default-grid-baseline);
	font-size: 0.9em;
	font-style: italic;
}

.meal-row--cancelled .meal-row__text strong,
.meal-row--cancelled .meal-row__time {
	text-decoration: line-through;
}
.free-row {
	display: grid;
	grid-template-columns: 7em 1fr;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: 2px calc(var(--default-grid-baseline) * 2);
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-element, 8px);
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.drop-end {
	display: block;
	text-align: center;
}

.meal-row[draggable='true'],
.item[draggable='true'] {
	cursor: grab;
}

.drop-target {
	box-shadow: inset 0 3px 0 var(--color-primary-element);
}

.meal-row__actions {
	display: flex;
	align-items: center;
}
</style>
