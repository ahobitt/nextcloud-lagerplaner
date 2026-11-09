<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { t } from '../l10n.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, reactive, ref, useId } from 'vue'
import GamePicker from './GamePicker.vue'
import { formatDay } from '../format.js'

const props = defineProps({
	/** Bestehender Programmpunkt zum Bearbeiten; leer zum Anlegen */
	item: { type: Object, default: null },
	days: { type: Array, required: true },
	defaultDay: { type: String, default: '' },
	/** Gruppen mit Teilnehmenden: [{ key, label }] */
	groups: { type: Array, default: () => [] },
	busy: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'cancel'])
const selectId = `item-form-day-${useId()}`

const form = reactive({
	title: props.item?.title ?? '',
	day: props.item?.day ?? props.defaultDay ?? props.days[0],
	startTime: props.item?.startTime ?? '10:00',
	durationMinutes: props.item?.durationMinutes ?? 60,
	description: props.item?.description ?? '',
	groups: [...(props.item?.groups ?? [])],
	weatherAlternative: props.item?.weatherAlternative ?? '',
})

/** Liegt der Punkt außerhalb des Zeitraums, bleibt sein Tag wählbar, bis er verschoben wird. */
const dayOptions = computed(() => (props.days.includes(form.day) ? props.days : [form.day, ...props.days]))

/** Beim Anlegen: aus der Spieledatenbank übernommenes Spiel */
const chosenGame = ref(null)
const pickerOpen = ref(false)

function useGame(game) {
	chosenGame.value = game
	if (form.title.trim() === '') {
		form.title = game.title
	}
	if (game.durationMinutes) {
		form.durationMinutes = game.durationMinutes
	}
}

function submit() {
	emit('save', {
		gameId: chosenGame.value?.id ?? null,
		title: form.title,
		day: form.day,
		startTime: form.startTime,
		durationMinutes: Number.parseInt(form.durationMinutes, 10) || 0,
		description: form.description,
		groups: form.groups,
		weatherAlternative: form.weatherAlternative,
	})
}
</script>

<template>
	<form class="item-form" @submit.prevent="submit">
		<div v-if="!item" class="item-form__game">
			<NcButton variant="secondary" @click="pickerOpen = true">
				{{ chosenGame ? t('lagerplaner', 'Change game') : t('lagerplaner', 'Take a game from Pfadfinder-Spiele.de') }}
			</NcButton>
			<span v-if="chosenGame">{{ t('lagerplaner', 'Game: {title}', { title: chosenGame.title }) }}</span>
		</div>
		<GamePicker v-if="pickerOpen" @select="useGame" @close="pickerOpen = false" />
		<NcTextField v-model="form.title" :label="t('lagerplaner', 'Title')" />
		<div class="item-form__row">
			<div class="item-form__select">
				<label :for="selectId">{{ t('lagerplaner', 'Day') }}</label>
				<select :id="selectId" v-model="form.day">
					<option v-for="day in dayOptions" :key="day" :value="day">
						{{ formatDay(day) }}{{ days.includes(day) ? '' : ` (${t('lagerplaner', 'outside the camp period')})` }}
					</option>
				</select>
			</div>
			<NcTextField v-model="form.startTime" :label="t('lagerplaner', 'Start (HH:MM)')" />
			<NcTextField v-model="form.durationMinutes" type="number" min="5" :label="t('lagerplaner', 'Duration in minutes')" />
		</div>
		<fieldset v-if="groups.length > 1" class="item-form__groups">
			<legend>{{ t('lagerplaner', 'For whom? Nothing selected means everyone.') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="group in groups"
				:key="group.key"
				v-model="form.groups"
				type="checkbox"
				:value="group.key">
				{{ group.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<NcTextField v-model="form.description" :label="t('lagerplaner', 'Description (optional)')" />
		<NcTextField v-model="form.weatherAlternative" :label="t('lagerplaner', 'Bad-weather alternative')" />
		<div class="item-form__row">
			<NcButton type="submit" variant="primary" :disabled="busy">
				{{ item ? t('lagerplaner', 'Save') : t('lagerplaner', 'Add programme item') }}
			</NcButton>
			<NcButton variant="tertiary" @click="emit('cancel')">
				{{ t('lagerplaner', 'Cancel') }}
			</NcButton>
		</div>
	</form>
</template>

<style scoped>
.item-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.item-form__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.item-form__row > * {
	flex: 1 1 160px;
}

.item-form__row > button {
	flex: 0 0 auto;
}

.item-form__select {
	display: flex;
	flex-direction: column;
	gap: 2px;
	color: var(--color-text-maxcontrast);
}

.item-form__select select {
	min-height: var(--default-clickable-area);
	margin: 0;
	color: var(--color-main-text);
}

.item-form__game {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.item-form__groups {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 4);
}

.item-form__groups legend {
	color: var(--color-text-maxcontrast);
}
</style>
