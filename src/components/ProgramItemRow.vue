<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiCheck, mdiClose, mdiContentSaveOutline, mdiDelete, mdiDice5, mdiPencil } from '@mdi/js'
import { t } from '../l10n.js'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { computed } from 'vue'
import { duration, endTime, formatDay, mealLabels } from '../format.js'
import GameAttribution from './GameAttribution.vue'

const props = defineProps({
	item: { type: Object, required: true },
	groupsText: { type: String, required: true },
	alternativeTitle: { type: String, default: '' },
	showDay: { type: Boolean, default: false },
	/** Eingeplante Spiele dieses Programmpunkts */
	games: { type: Array, default: () => [] },
	/** Darf die Person das Programm bearbeiten? */
	editable: { type: Boolean, default: true },
	/** Zeitliche Überschneidungen mit anderen Einträgen derselben Gruppe */
	conflicts: { type: Array, default: () => [] },
	/** Material für diesen Programmpunkt, als lesbare Texte */
	materials: { type: Array, default: () => [] },
	/** Aufgaben, die diesem Programmpunkt zugeordnet sind */
	tasks: { type: Array, default: () => [] },
})
const emit = defineEmits(['confirm', 'edit', 'remove', 'add-game', 'remove-game', 'save-template', 'shift'])

/** Eingeplante Spiele dauern zusammen länger als der Programmpunkt (nur Spiele mit bekannter Dauer) */
const gamesMinutes = computed(() => props.games.reduce((sum, game) => sum + (game.durationMinutes ?? 0), 0))
const gamesTooLong = computed(() => gamesMinutes.value > props.item.durationMinutes)

const isProposal = computed(() => props.item.reviewState === 'proposal' && !props.item.userModifiedAt)
const state = computed(() => {
	if (props.item.userModifiedAt) {
		return t('lagerplaner', 'Edited')
	}
	if (props.item.origin === 'manual') {
		return t('lagerplaner', 'Own')
	}
	return isProposal.value ? t('lagerplaner', 'Proposal') : t('lagerplaner', 'Confirmed')
})
</script>

<template>
	<li class="item" :class="{ 'item--proposal': isProposal }">
		<span class="item__time">
			<template v-if="showDay">{{ formatDay(item.day, { day: 'numeric', month: 'short' }) }}<br></template>
			{{ item.startTime }}–{{ endTime(item.startTime, item.durationMinutes) }}
		</span>
		<div class="item__main">
			<span class="item__title">{{ item.title }}</span>
			<span class="item__details">{{ duration(item.durationMinutes) }}, {{ groupsText }}</span>
			<span v-if="item.description" class="item__details">{{ item.description }}</span>
			<span v-if="item.alternativeTo" class="item__details">
				{{ t('lagerplaner', 'Alternative proposal to "{title}"', { title: alternativeTitle }) }}
			</span>
			<span v-if="item.weatherAlternative" class="item__details">
				{{ t('lagerplaner', 'Bad weather: {alternative}', { alternative: item.weatherAlternative }) }}
			</span>
			<span v-else-if="item.weatherAltNeeded" class="item__missing">
				{{ t('lagerplaner', 'Bad-weather alternative missing') }}
			</span>
			<span v-if="conflicts.length > 0" class="item__conflict">
				{{ t('lagerplaner', 'Overlaps with: {list}', {
					list: conflicts.map((c) => `${c.kind === 'meal' ? (mealLabels()[c.title] ?? c.title) : c.title} ${c.start}–${c.end}`).join(', '),
				}) }}
			</span>
			<span v-if="materials.length > 0" class="item__details">
				{{ t('lagerplaner', 'Planned material: {material}', { material: materials.join(', ') }) }}
			</span>
			<span v-if="tasks.length > 0" class="item__details">
				{{ t('lagerplaner', 'Tasks: {list}', { list: tasks.map((task) => task.status === 'done' ? `${task.title} ✓` : task.title).join(', ') }) }}
			</span>
			<p v-if="gamesTooLong" class="item__games-warning">
				{{ t('lagerplaner', 'The planned games take about {games} together, but this item has {slot}.', { games: duration(gamesMinutes), slot: duration(item.durationMinutes) }) }}
			</p>
			<ul v-if="games.length > 0" class="item__games">
				<li v-for="game in games" :key="game.id">
					<span class="item__game-title">
						{{ t('lagerplaner', 'Game: {title}', { title: game.title }) }}<template v-if="game.durationMinutes">, {{ duration(game.durationMinutes) }}</template>
						<NcButton
							v-if="editable"
							variant="tertiary"
							size="small"
							:aria-label="t('lagerplaner', 'Remove game {title}', { title: game.title })"
							@click="emit('remove-game', game)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiClose" :size="16" />
							</template>
						</NcButton>
					</span>
					<span v-if="game.materials.length > 0" class="item__details">
						{{ t('lagerplaner', 'Material: {material}', { material: game.materials.join(', ') }) }}
					</span>
					<GameAttribution :title="game.title" :link="game.link" :license="game.license" />
				</li>
			</ul>
		</div>
		<span class="item__state">{{ state }}</span>
		<div v-if="editable" class="item__actions">
			<NcButton
				v-if="isProposal"
				variant="tertiary"
				:aria-label="t('lagerplaner', 'Confirm {title}', { title: item.title })"
				@click="emit('confirm')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCheck" />
				</template>
			</NcButton>
			<NcActions :aria-label="t('lagerplaner', 'Actions for {title}', { title: item.title })">
				<NcActionButton close-after-click @click="emit('edit')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPencil" />
					</template>
					{{ t('lagerplaner', 'Edit') }}
				</NcActionButton>
				<NcActionButton v-if="editable" close-after-click @click="emit('shift', -15)">
					{{ t('lagerplaner', '15 minutes earlier') }}
				</NcActionButton>
				<NcActionButton v-if="editable" close-after-click @click="emit('shift', 15)">
					{{ t('lagerplaner', '15 minutes later') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="emit('add-game')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDice5" />
					</template>
					{{ t('lagerplaner', 'Add game') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="emit('save-template')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiContentSaveOutline" />
					</template>
					{{ t('lagerplaner', 'Save as template') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="emit('remove')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDelete" />
					</template>
					{{ t('lagerplaner', 'Delete') }}
				</NcActionButton>
			</NcActions>
		</div>
	</li>
</template>

<style scoped>
/*
 * Bestätigte Punkte haben eine durchgehende Kante, Vorschläge eine gestrichelte.
 * So ist auf einen Blick erkennbar, was die Lagerleitung schon übernommen hat.
 */
.item {
	display: grid;
	grid-template-columns: 7.5em 1fr auto auto;
	align-items: start;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
	border-inline-start: 3px solid var(--color-primary-element);
	border-bottom: 1px solid var(--color-border);
}

.item--proposal {
	border-inline-start-style: dashed;
	border-inline-start-color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
}

.item__time,
.item__details,
.item__state {
	color: var(--color-text-maxcontrast);
}

.item__time {
	font-variant-numeric: tabular-nums;
	padding-top: 2px;
}

.item__main {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.item__title {
	font-weight: bold;
}

.item__missing,
.item__conflict {
	color: var(--color-warning-text);
}

.item__games {
	margin: var(--default-grid-baseline) 0 0;
	padding: 0;
	list-style: none;
}

.item__games li {
	display: flex;
	flex-direction: column;
	padding-top: var(--default-grid-baseline);
}

.item__game-title {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.item__state {
	padding-top: 2px;
	font-size: 0.9em;
	white-space: nowrap;
}

.item__actions {
	display: flex;
	align-items: center;
}

@media (max-width: 700px) {
	.item {
		grid-template-columns: 1fr auto;
	}

	.item__time {
		grid-column: 1 / -1;
	}

	.item__state {
		display: none;
	}
}
.item__games-warning {
	margin: 2px 0;
	color: var(--color-warning-text);
	font-size: 0.9em;
}
</style>
