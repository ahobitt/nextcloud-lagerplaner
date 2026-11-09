<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiAccountArrowLeft, mdiDelete, mdiPencil, mdiPlus } from '@mdi/js'
import { getCurrentUser } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref, useId } from 'vue'
import { claimTask, createTask, deleteTask, errorMessage, getPeople, updateTask } from '../api.js'
import { formatDay, parseDay, toDay } from '../format.js'
import { t } from '../l10n.js'

const props = defineProps({
	campId: { type: Number, required: true },
	tasks: { type: Array, required: true },
	program: { type: Array, default: () => [] },
	can: { type: Object, required: true },
})
const emit = defineEmits(['update:tasks'])
const id = useId()
const me = getCurrentUser()?.uid ?? ''

const people = ref([])
const filter = ref('all')
const showDone = ref(true)
const form = ref(null)
const working = ref(false)

onMounted(async () => {
	try {
		people.value = await getPeople(props.campId)
	} catch (error) {
		showError(errorMessage(error))
	}
})

const names = computed(() => Object.fromEntries(people.value.map((p) => [p.uid, p.displayName])))
const nameOf = (uid) => names.value[uid] ?? uid
const today = toDay(new Date())
const priorityLabels = () => ({ high: t('lagerplaner', 'Important'), normal: t('lagerplaner', 'Normal'), low: t('lagerplaner', 'Can wait') })
const programLabel = (item) => `${formatDay(item.day, { weekday: 'short', day: 'numeric', month: 'numeric' })}, ${item.startTime}: ${item.title}`
const programById = computed(() => Object.fromEntries(props.program.map((item) => [item.id, item])))
const sortedProgram = computed(() => [...props.program].sort((a, b) => `${a.day}${a.startTime}`.localeCompare(`${b.day}${b.startTime}`)))

const PRIORITY_ORDER = { high: 0, normal: 1, low: 2 }
const visible = computed(() => props.tasks
	.filter((task) => showDone.value || task.status === 'open')
	.filter((task) => {
		if (filter.value === 'mine') {
			return task.assignees.includes(me)
		}
		if (filter.value === 'unassigned') {
			return task.assignees.length === 0
		}
		return filter.value === 'all' || task.assignees.includes(filter.value)
	})
	.sort((a, b) => {
		if (a.status !== b.status) {
			return a.status === 'open' ? -1 : 1
		}
		return (a.dueDate ?? '9999').localeCompare(b.dueDate ?? '9999') || PRIORITY_ORDER[a.priority] - PRIORITY_ORDER[b.priority]
	}))

async function run(action) {
	working.value = true
	try {
		return await action()
	} catch (error) {
		showError(errorMessage(error))
		return null
	} finally {
		working.value = false
	}
}

function replace(saved) {
	emit('update:tasks', props.tasks.map((task) => (task.id === saved.id ? saved : task)))
}

function openForm(task = null) {
	form.value = {
		id: task?.id ?? null,
		title: task?.title ?? '',
		description: task?.description ?? '',
		dueDate: task?.dueDate ? parseDay(task.dueDate) : null,
		priority: task?.priority ?? 'normal',
		assignees: [...(task?.assignees ?? [])],
		programItemId: task?.programItemId ?? '',
	}
}

async function save() {
	const draft = form.value
	const fields = {
		title: draft.title,
		description: draft.description,
		dueDate: draft.dueDate instanceof Date && !Number.isNaN(draft.dueDate.getTime()) ? toDay(draft.dueDate) : null,
		priority: draft.priority,
		assignees: draft.assignees,
		programItemId: draft.programItemId === '' ? null : draft.programItemId,
	}
	const saved = await run(() => (draft.id ? updateTask(draft.id, fields) : createTask(props.campId, fields)))
	if (saved) {
		emit('update:tasks', draft.id ? props.tasks.map((task) => (task.id === saved.id ? saved : task)) : [...props.tasks, saved])
		form.value = null
	}
}

async function toggle(task, done) {
	const saved = await run(() => updateTask(task.id, { status: done ? 'done' : 'open' }))
	if (saved) {
		replace(saved)
	}
}

async function claim(task) {
	const saved = await run(() => claimTask(task.id))
	if (saved) {
		replace(saved)
	}
}

async function remove(task) {
	if (await run(async () => { await deleteTask(task.id); return true })) {
		emit('update:tasks', props.tasks.filter((entry) => entry.id !== task.id))
	}
}
</script>

<template>
	<div class="tasks-panel">
		<div class="tasks-panel__bar">
			<div class="tasks-panel__filter">
				<label :for="`${id}-filter`">{{ t('lagerplaner', 'Show') }}</label>
				<select :id="`${id}-filter`" v-model="filter">
					<option value="all">
						{{ t('lagerplaner', 'All tasks') }}
					</option>
					<option value="mine">
						{{ t('lagerplaner', 'My tasks') }}
					</option>
					<option value="unassigned">
						{{ t('lagerplaner', 'Without anyone responsible') }}
					</option>
					<option v-for="person in people" :key="person.uid" :value="person.uid">
						{{ t('lagerplaner', 'Tasks of {name}', { name: person.displayName }) }}
					</option>
				</select>
			</div>
			<NcCheckboxRadioSwitch v-model="showDone" type="switch">
				{{ t('lagerplaner', 'Show completed tasks') }}
			</NcCheckboxRadioSwitch>
			<NcButton v-if="can.tasks && !form" variant="secondary" @click="openForm()">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ t('lagerplaner', 'Add task') }}
			</NcButton>
		</div>

		<form v-if="form" class="task-editor" @submit.prevent="save">
			<NcTextField v-model="form.title" :label="t('lagerplaner', 'Title')" />
			<div class="task-editor__textarea">
				<label :for="`${id}-description`">{{ t('lagerplaner', 'Description (optional)') }}</label>
				<textarea :id="`${id}-description`" v-model="form.description" rows="2" />
			</div>
			<div class="task-editor__row">
				<NcDateTimePickerNative v-model="form.dueDate" type="date" :label="t('lagerplaner', 'Deadline (optional)')" />
				<div class="task-editor__select">
					<label :for="`${id}-priority`">{{ t('lagerplaner', 'Priority') }}</label>
					<select :id="`${id}-priority`" v-model="form.priority">
						<option v-for="(label, key) in priorityLabels()" :key="key" :value="key">
							{{ label }}
						</option>
					</select>
				</div>
				<div class="task-editor__select">
					<label :for="`${id}-program`">{{ t('lagerplaner', 'For programme item (optional)') }}</label>
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
			<fieldset>
				<legend>{{ t('lagerplaner', 'Responsible') }}</legend>
				<div class="task-editor__people">
					<NcCheckboxRadioSwitch v-for="person in people" :key="person.uid" v-model="form.assignees" type="checkbox" :value="person.uid">
						{{ person.displayName }}
					</NcCheckboxRadioSwitch>
				</div>
			</fieldset>
			<div class="task-editor__buttons">
				<NcButton type="submit" variant="primary" :disabled="working">
					{{ form.id ? t('lagerplaner', 'Save') : t('lagerplaner', 'Add task') }}
				</NcButton>
				<NcButton variant="tertiary" @click="form = null">
					{{ t('lagerplaner', 'Cancel') }}
				</NcButton>
			</div>
		</form>

		<p v-if="visible.length === 0" class="tasks-panel__empty">
			{{ t('lagerplaner', 'No tasks match this filter.') }}
		</p>
		<ul class="tasks">
			<li v-for="task in visible" :key="task.id" class="task" :class="{ 'task--done': task.status === 'done' }">
				<div class="task__row">
					<NcCheckboxRadioSwitch
						:model-value="task.status === 'done'"
						type="checkbox"
						:disabled="!can.toggleTasks || working"
						@update:model-value="toggle(task, $event)">
						{{ task.title }}
					</NcCheckboxRadioSwitch>
					<NcButton
						v-if="can.toggleTasks && task.status === 'open' && !task.assignees.includes(me)"
						variant="tertiary"
						size="small"
						:disabled="working"
						@click="claim(task)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiAccountArrowLeft" :size="18" />
						</template>
						{{ t('lagerplaner', 'Take on') }}
					</NcButton>
					<NcActions v-if="can.tasks" :aria-label="t('lagerplaner', 'Actions for {title}', { title: task.title })">
						<NcActionButton close-after-click @click="openForm(task)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPencil" />
							</template>
							{{ t('lagerplaner', 'Edit') }}
						</NcActionButton>
						<NcActionButton v-if="!task.mandatory" close-after-click @click="remove(task)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDelete" />
							</template>
							{{ t('lagerplaner', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</div>
				<span class="task__meta">
					<span v-if="task.dueDate" :class="{ 'task__overdue': task.status === 'open' && task.dueDate < today }">
						{{ t('lagerplaner', 'Due {date}', { date: formatDay(task.dueDate, { day: 'numeric', month: 'short', year: 'numeric' }) }) }}
					</span>
					<span v-if="task.priority !== 'normal'" :class="`task__priority--${task.priority}`">{{ priorityLabels()[task.priority] }}</span>
					<span v-if="task.mandatory" class="task__required">{{ t('lagerplaner', 'Required') }}</span>
					<span v-if="task.assignees.length">{{ t('lagerplaner', 'Responsible: {names}', { names: task.assignees.map(nameOf).join(', ') }) }}</span>
					<span v-else-if="task.status === 'open'" class="task__nobody">{{ t('lagerplaner', 'Nobody responsible yet') }}</span>
					<span v-if="task.programItemId && programById[task.programItemId]">{{ t('lagerplaner', 'For: {item}', { item: programLabel(programById[task.programItemId]) }) }}</span>
					<span v-if="task.status === 'done' && task.completedBy">{{ t('lagerplaner', 'Done by {name}', { name: nameOf(task.completedBy) }) }}</span>
				</span>
				<p v-if="task.description" class="task__description">
					{{ task.description }}
				</p>
			</li>
		</ul>
	</div>
</template>

<style scoped>
.tasks-panel {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.tasks-panel__bar,
.task-editor__row,
.task-editor__buttons {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 3);
}

.tasks-panel__filter,
.task-editor__select,
.task-editor__textarea {
	display: flex;
	flex-direction: column;
	gap: 2px;
	color: var(--color-text-maxcontrast);
}

.tasks-panel__filter select,
.task-editor__select select {
	min-height: var(--default-clickable-area);
	margin: 0;
	color: var(--color-main-text);
}

.task-editor__textarea textarea {
	width: 100%;
	color: var(--color-main-text);
}

.task-editor {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large);
}

.task-editor legend {
	font-weight: bold;
}

.task-editor__people {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 3);
}

.tasks {
	margin: 0;
	padding: 0;
	list-style: none;
}

.task {
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.task__row {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.task__row > :first-child {
	flex: 1;
}

.task__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 3);
	padding-inline-start: calc(var(--default-grid-baseline) * 9);
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.task__description,
.tasks-panel__empty {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 9);
	color: var(--color-text-maxcontrast);
}

.task--done :deep(.checkbox-content__text) {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.task__overdue,
.task__priority--high,
.task__required {
	color: var(--color-warning-text);
	font-weight: bold;
}

.task__nobody {
	font-style: italic;
}
</style>
