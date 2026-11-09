<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { n, t } from '../l10n.js'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed } from 'vue'
import { formatDay } from '../format.js'

const props = defineProps({
	/** Antwort von /wizard/preview eines bestehenden Lagers */
	result: { type: Object, required: true },
})

const rows = computed(() => [
	{ key: 'program', label: t('lagerplaner', 'Programme'), summary: props.result.program.summary },
	{ key: 'tasks', label: t('lagerplaner', 'Tasks'), summary: props.result.tasks.summary },
	{ key: 'meals', label: t('lagerplaner', 'Meals'), summary: props.result.meals.summary },
])

const fieldLabels = () => ({
	name: t('lagerplaner', 'Name'),
	start: t('lagerplaner', 'First day'),
	end: t('lagerplaner', 'Last day'),
	organizer: t('lagerplaner', 'Organising group'),
	campType: t('lagerplaner', 'Accommodation'),
	locationKnown: t('lagerplaner', 'Location decided'),
	locationName: t('lagerplaner', 'Campsite or house'),
	region: t('lagerplaner', 'Region'),
	theme: t('lagerplaner', 'Camp theme'),
	label: t('lagerplaner', 'Name'),
	count: t('lagerplaner', 'People'),
	separateProgram: t('lagerplaner', 'Own programme'),
	portionFactor: t('lagerplaner', 'Portion factor'),
	blockMinutes: t('lagerplaner', 'Programme block in minutes'),
})

function show(field, value) {
	if (value === '' || value === null || value === undefined) {
		return t('lagerplaner', 'empty')
	}
	if (typeof value === 'boolean') {
		return value ? t('lagerplaner', 'Yes') : t('lagerplaner', 'No')
	}
	if ((field === 'start' || field === 'end') && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
		return formatDay(value, { day: 'numeric', month: 'long', year: 'numeric' })
	}
	if (field === 'campType') {
		return value === 'tent' ? t('lagerplaner', 'Tent camp') : t('lagerplaner', 'Group house')
	}
	return String(value)
}

/** Lesbare Zeilen für Änderungen an Lagerdaten und Gruppen. */
const campLines = computed(() => props.result.camp.changes.filter((change) => change.kind !== 'group_items').map((change) => {
	const labels = fieldLabels()
	switch (change.kind) {
	case 'group_added':
		return t('lagerplaner', 'New group: {group} ({count})', { group: change.label, count: change.to })
	case 'group_removed':
		return t('lagerplaner', 'Group removed: {group}', { group: change.label })
	case 'shopping_trip':
		return t('lagerplaner', 'Shopping trip “{trip}” moves with the camp: {from} → {to}', {
			trip: change.label, from: show('start', change.from), to: show('start', change.to),
		})
	case 'shopping_trip_check':
		return t('lagerplaner', 'Please check: shopping trip “{trip}” on {date} is outside the new camp period and stays unchanged.', {
			trip: change.label, date: show('start', change.date),
		})
	case 'shopping_trip_late':
		return t('lagerplaner', 'Please check: no shopping trip before the new first day; the earliest is on {date}.', { date: show('start', change.date) })
	case 'group':
		return t('lagerplaner', '{group}, {field}: {from} → {to}', {
			group: change.label, field: labels[change.field] ?? change.field, from: show(change.field, change.from), to: show(change.field, change.to),
		})
	default:
		return t('lagerplaner', '{field}: {from} → {to}', {
			field: labels[change.field] ?? change.field, from: show(change.field, change.from), to: show(change.field, change.to),
		})
	}
}))

const plannedChanges = computed(() => rows.value.some((row) => row.summary.add + row.summary.update + row.summary.remove > 0))
const suppressed = computed(() => rows.value.reduce((sum, row) => sum + row.summary.suppressed, 0))

const protectedItems = computed(() => ['program', 'tasks', 'meals'].flatMap((kind) => props.result[kind].changes
	.filter((change) => change.type === 'conflict' || change.type === 'orphaned')
	.map((change) => ({ ...change, kind }))))
</script>

<template>
	<div class="changes">
		<NcNoteCard v-if="!result.hasEffect" type="success" :text="t('lagerplaner', 'Nothing changes. Your planning already matches these answers.')" />

		<template v-if="campLines.length > 0">
			<h3>{{ t('lagerplaner', 'Camp details') }}</h3>
			<ul class="changes__list">
				<li v-for="line in campLines" :key="line">
					{{ line }}
				</li>
			</ul>
		</template>

		<template v-if="plannedChanges">
			<h3>{{ t('lagerplaner', 'Planning') }}</h3>
			<table class="changes__table">
				<thead>
					<tr>
						<td />
						<th scope="col">
							{{ t('lagerplaner', 'New') }}
						</th>
						<th scope="col">
							{{ t('lagerplaner', 'Updated') }}
						</th>
						<th scope="col">
							{{ t('lagerplaner', 'Removed') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.key">
						<th scope="row">
							{{ row.label }}
						</th>
						<td>{{ row.summary.add }}</td>
						<td>{{ row.summary.update }}</td>
						<td>{{ row.summary.remove }}</td>
					</tr>
				</tbody>
			</table>
			<p class="changes__explain">
				{{ t('lagerplaner', 'Only untouched proposals are updated or removed. Confirmed or edited entries stay as they are.') }}
			</p>
		</template>

		<p v-if="suppressed > 0" class="changes__explain">
			{{ n('lagerplaner', '%n proposal you deleted stays deleted.', '%n proposals you deleted stay deleted.', suppressed) }}
		</p>

		<template v-if="protectedItems.length > 0">
			<h3>{{ t('lagerplaner', 'Protected entries') }}</h3>
			<ul class="changes__list">
				<li v-for="item in protectedItems" :key="`${item.kind}-${item.existingId}`">
					<span>{{ item.existingLabel }}</span>
					<span class="changes__note">
						<template v-if="item.type === 'orphaned'">
							{{ t('lagerplaner', 'No longer part of the new draft. You find it under "Outside the camp period" or can delete it yourself.') }}
						</template>
						<template v-else-if="item.addAsAlternative">
							{{ t('lagerplaner', 'The new proposal is added next to it as an alternative.') }}
						</template>
						<template v-else>
							{{ t('lagerplaner', 'The new draft would differ here. Your version is kept.') }}
						</template>
					</span>
				</li>
			</ul>
		</template>
	</div>
</template>

<style scoped>
.changes h3 {
	margin: calc(var(--default-grid-baseline) * 4) 0 var(--default-grid-baseline);
}

.changes__table {
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;
}

.changes__table th,
.changes__table td {
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 3);
	text-align: end;
}

.changes__table tbody th {
	text-align: start;
	font-weight: normal;
}

.changes__table thead th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.changes__explain,
.changes__note {
	color: var(--color-text-maxcontrast);
}

.changes__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.changes__list li {
	display: flex;
	flex-direction: column;
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}
</style>
