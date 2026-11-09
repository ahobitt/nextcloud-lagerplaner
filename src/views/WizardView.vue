<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiArrowLeft, mdiArrowRight, mdiCheck } from '@mdi/js'
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
	createCamp, errorMessage, fieldErrors, isOutdated, lastInput, listTemplates, previewDraft, rerunApply, rerunPreview, wizardDefaults,
} from '../api.js'
import ChangeSummary from '../components/ChangeSummary.vue'
import { dayCount, formatDay, formatRange, mealLabels, parseDay, toDay } from '../format.js'

const props = defineProps({
	campId: { type: Number, default: null },
})
const emit = defineEmits(['created', 'applied'])

/** Je Person getrennt gespeichert; Entwürfe älter als 30 Tage werden verworfen. */
const STORAGE_KEY = `lagerplaner:wizard-draft:${getCurrentUser()?.uid ?? 'anonymous'}`
const LEGACY_STORAGE_KEY = 'lagerplaner:wizard-draft'
const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000
const SUMMARY = 6
const rerun = computed(() => props.campId !== null)

const steps = computed(() => [
	t('lagerplaner', 'Basics'),
	t('lagerplaner', 'Participants'),
	t('lagerplaner', 'Location'),
	t('lagerplaner', 'Programme'),
	t('lagerplaner', 'Catering'),
	t('lagerplaner', 'Organisation'),
	rerun.value ? t('lagerplaner', 'Check changes') : t('lagerplaner', 'Check draft'),
])

/** Welcher Schritt zu einem Fehlerfeld des Servers gehört. */
const ERROR_STEP = { name: 0, start: 0, end: 0, campType: 0, groups: 1, location: 2, program: 3, catering: 4, organisation: 5 }

const step = ref(0)
const loading = ref(true)
const busy = ref(false)
const errors = ref({})
const result = ref(null)
const restored = ref(false)
const form = reactive(blankForm([]))

function blankForm(groups) {
	return {
		name: '',
		start: '',
		end: '',
		arrivalTime: '',
		departureTime: '',
		organizer: '',
		campType: 'tent',
		groups: groups.map((g) => ({ ...g, count: 0, separateProgram: false })),
		location: { known: false, name: '', region: '' },
		program: { theme: '', hike: false, wideGame: false, freeTime: 'medium', dayTemplateId: null },
		catering: { selfCatering: true, meals: ['breakfast', 'lunch', 'dinner'], kitchenTeam: 'unknown', proposeMenu: true },
		organisation: { materialResponsible: 'unknown' },
	}
}

function replaceForm(source) {
	Object.assign(form, JSON.parse(JSON.stringify(source)))
	// Gespeichert ist „keine Uhrzeit“ als null; Textfelder brauchen einen Text.
	form.arrivalTime ??= ''
	form.departureTime ??= ''
	form.catering.proposeMenu ??= false
}

function readSaved() {
	try {
		// Version 0.1.0 speicherte ohne Benutzerbezug; diesen Stand nicht übernehmen.
		localStorage.removeItem(LEGACY_STORAGE_KEY)
		const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null')
		if (!saved?.savedAt || Date.now() - saved.savedAt > MAX_AGE_MS) {
			localStorage.removeItem(STORAGE_KEY)
			return null
		}
		return saved
	} catch {
		return null
	}
}

function clearSaved() {
	try {
		localStorage.removeItem(STORAGE_KEY)
	} catch {
		// Speichern im Browser ist nur eine Bequemlichkeit.
	}
}

let defaultGroups = []

onMounted(async () => {
	try {
		defaultGroups = (await wizardDefaults()).groups
		replaceForm(blankForm(defaultGroups))
		if (rerun.value) {
			const last = await lastInput(props.campId)
			if (last?.name) {
				replaceForm(last)
			}
		} else {
			const saved = readSaved()
			if (saved?.form?.groups) {
				replaceForm(saved.form)
				step.value = Math.min(saved.step ?? 0, SUMMARY - 1)
				restored.value = true
			}
		}
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		loading.value = false
	}
})

watch([form, step], () => {
	if (loading.value || rerun.value) {
		return
	}
	try {
		localStorage.setItem(STORAGE_KEY, JSON.stringify({ form, step: step.value, savedAt: Date.now() }))
	} catch {
		// Speichern im Browser ist nur eine Bequemlichkeit.
	}
}, { deep: true })

function dateModel(key) {
	return computed({
		get: () => (form[key] ? parseDay(form[key]) : null),
		set: (value) => {
			form[key] = value instanceof Date && !Number.isNaN(value.getTime()) ? toDay(value) : ''
		},
	})
}
const startDate = dateModel('start')
const locationKnown = computed({
	get: () => (form.location.known ? 'yes' : 'no'),
	set: (value) => {
		form.location.known = value === 'yes'
	},
})
const endDate = dateModel('end')

const totals = computed(() => {
	const count = (g) => Number(g.count) || 0
	return {
		all: form.groups.reduce((sum, g) => sum + count(g), 0),
		leaders: form.groups.filter((g) => g.isLeaders).reduce((sum, g) => sum + count(g), 0),
	}
})

/** Gleiche Regel wie LeaderCoverage im Assistenten-Entwurf: Hinweis, kein Hindernis */
const leaderHint = computed(() => {
	const participants = totals.value.all - totals.value.leaders
	const leaders = totals.value.leaders
	if (participants <= 0) {
		return null
	}
	if (leaders <= 0) {
		return { level: 'warning', text: t('lagerplaner', 'No leaders entered for {count} children and young people. A camp needs supervision; enter the leaders so that tasks and duties can be shared as well.', { count: participants }) }
	}
	if (leaders === 1) {
		return { level: 'warning', text: t('lagerplaner', 'Only one leader for {count} children and young people. Plan at least two leaders so that someone can stay with the group in an emergency.', { count: participants }) }
	}
	if (participants / leaders > 8) {
		return { level: 'info', text: t('lagerplaner', 'About {ratio} children and young people per leader. As a rule of thumb, plan one leader for six to eight participants, more for younger children; your association’s rules apply.', { ratio: Math.round(participants / leaders) }) }
	}
	return null
})

function payload() {
	const copy = JSON.parse(JSON.stringify(form))
	copy.groups = copy.groups.map((g) => ({ ...g, count: Math.max(0, Number.parseInt(g.count, 10) || 0) }))
	// Leere Uhrzeit heißt „wie bisher“.
	const dated = copy.start && copy.end && copy.end >= copy.start
	copy.arrivalTime = dated && copy.arrivalTime ? copy.arrivalTime : null
	copy.departureTime = dated && copy.departureTime ? copy.departureTime : null
	return copy
}

function validateBasics() {
	const found = {}
	if (form.name.trim() === '') {
		found.name = t('lagerplaner', 'Give the camp a name.')
	}
	if (!form.start) {
		found.start = t('lagerplaner', 'Choose the first day.')
	}
	if (!form.end) {
		found.end = t('lagerplaner', 'Choose the last day.')
	} else if (form.start && form.end < form.start) {
		found.end = t('lagerplaner', 'The last day is before the first day.')
	}
	errors.value = found
	return Object.keys(found).length === 0
}

function firstErrorStep(found) {
	return Math.min(...Object.keys(found).map((key) => ERROR_STEP[key.split('.')[0]] ?? 0))
}

async function goTo(target) {
	if (target > 0 && step.value === 0 && !validateBasics()) {
		return
	}
	if (target > 0 && !form.start) {
		step.value = 0
		validateBasics()
		return
	}
	step.value = target
	if (target === SUMMARY) {
		await loadResult()
	}
}

async function loadResult() {
	busy.value = true
	result.value = null
	errors.value = {}
	try {
		result.value = rerun.value
			? await rerunPreview(props.campId, payload())
			: await previewDraft(payload())
	} catch (error) {
		const found = fieldErrors(error)
		if (Object.keys(found).length > 0) {
			errors.value = found
			step.value = firstErrorStep(found)
		} else {
			showError(errorMessage(error))
		}
	} finally {
		busy.value = false
	}
}

async function submit() {
	busy.value = true
	try {
		if (rerun.value) {
			await rerunApply(props.campId, payload(), result.value.token, { ...groupDecisions })
			showSuccess(t('lagerplaner', 'Changes applied'))
			emit('applied')
		} else {
			const camp = await createCamp(payload())
			clearSaved()
			showSuccess(t('lagerplaner', 'Camp created'))
			emit('created', camp)
		}
	} catch (error) {
		showError(errorMessage(error))
		if (isOutdated(error)) {
			await loadResult()
		}
	} finally {
		busy.value = false
	}
}

function startOver() {
	clearSaved()
	replaceForm(blankForm(defaultGroups))
	errors.value = {}
	step.value = 0
	restored.value = false
}

const singleDay = computed(() => !!form.start && form.start === form.end)

/** „Entwurf prüfen“: nur, was eingegeben wurde (Rückmeldung aus dem Praxistest). */
const facts = computed(() => {
	const tristate = (value) => ({ yes: t('lagerplaner', 'Yes'), no: t('lagerplaner', 'No') })[value] ?? t('lagerplaner', 'Not decided yet')
	const times = singleDay.value
		? [form.arrivalTime || '09:30', form.departureTime || '17:30'].join('–')
		: t('lagerplaner', 'arrival {arrival}, departure {departure}', { arrival: form.arrivalTime || '10:00', departure: form.departureTime || '14:00' })
	const groupCount = (group) => Number(group.count) || 0
	const groups = form.groups.filter((group) => groupCount(group) > 0).map((group) => `${groupCount(group)} ${group.label}`)
	const program = [
		form.program.theme ? t('lagerplaner', 'Theme: {theme}', { theme: form.program.theme }) : '',
		form.program.hike ? t('lagerplaner', 'Day hike') : '',
		form.program.wideGame ? t('lagerplaner', 'Large outdoor game for everyone') : '',
		singleDay.value ? '' : t('lagerplaner', 'Free time: {amount}', { amount: ({ little: t('lagerplaner', 'little'), medium: t('lagerplaner', 'medium'), much: t('lagerplaner', 'plenty') })[form.program.freeTime] }),
		dayTemplates.value.find((template) => template.id === form.program.dayTemplateId)?.name ?? '',
	].filter(Boolean)
	const catering = form.catering.selfCatering
		? [form.catering.meals.map((meal) => mealLabels()[meal]).join(', '), form.catering.proposeMenu ? t('lagerplaner', 'simple meal plan suggested') : ''].filter(Boolean).join('; ')
		: t('lagerplaner', 'We do not cook ourselves')
	return [
		[t('lagerplaner', 'Name of the camp'), form.name],
		[t('lagerplaner', 'Dates'), form.start && form.end ? `${formatRange(form.start, form.end)}, ${times}` : ''],
		[t('lagerplaner', 'Organising group'), form.organizer],
		[t('lagerplaner', 'Accommodation'), form.campType === 'house' ? t('lagerplaner', 'Group house') : t('lagerplaner', 'Tent camp')],
		[t('lagerplaner', 'Location'), form.location.known ? (form.location.name || t('lagerplaner', 'known')) : [t('lagerplaner', 'still open'), form.location.region].filter(Boolean).join(', ')],
		[t('lagerplaner', 'Participants'), groups.length ? `${groups.join(', ')} (${n('lagerplaner', '%n person', '%n people', totals.value.all)})` : t('lagerplaner', 'not entered yet')],
		[t('lagerplaner', 'Programme'), program.join(', ') || '–'],
		[t('lagerplaner', 'Catering'), catering],
		[t('lagerplaner', 'Kitchen team'), tristate(form.catering.kitchenTeam)],
		[t('lagerplaner', 'Material responsibility'), tristate(form.organisation.materialResponsible)],
	].filter(([, value]) => value)
})

/** Tagesvorlagen, die der Assistent für normale Lagertage nutzen kann (Konzept 6.4). */
const dayTemplates = ref([])
onMounted(async () => {
	try {
		dayTemplates.value = (await listTemplates()).filter((template) => template.kind === 'day')
	} catch {
		// Ohne Liste bleibt es beim Vorschlag des Lagerplaners.
	}
})

const hasChanges = computed(() => !rerun.value || !result.value || result.value.hasEffect === true)

/** Programmpunkte entfernter Altersstufen: Die Lagerleitung entscheidet je Punkt (Review 0.6.1). */
const groupDecisions = reactive({})
const groupItems = computed(() => (result.value?.camp?.changes ?? []).find((change) => change.kind === 'group_items') ?? null)
const decisionsComplete = computed(() => !groupItems.value || groupItems.value.items.every((item) => groupDecisions[item.id]))
</script>

<template>
	<div class="wizard">
		<header class="wizard__header">
			<h2>{{ rerun ? t('lagerplaner', 'Run the assistant again') : t('lagerplaner', 'New camp') }}</h2>
			<p v-if="rerun" class="wizard__lead">
				{{ t('lagerplaner', 'Change your answers. You will see what changes before anything is saved.') }}
			</p>
			<p v-else class="wizard__lead">
				{{ t('lagerplaner', 'Only name and dates are required. Skip anything you do not know yet.') }}
			</p>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<NcNoteCard v-if="restored && step < SUMMARY" type="info">
				<p>{{ t('lagerplaner', 'Your unfinished entries were restored.') }}</p>
				<NcButton variant="tertiary" @click="startOver">
					{{ t('lagerplaner', 'Start over') }}
				</NcButton>
			</NcNoteCard>

			<nav class="wizard__steps" :aria-label="t('lagerplaner', 'Steps of the assistant')">
				<ol>
					<li v-for="(label, index) in steps" :key="index">
						<button
							type="button"
							class="wizard__step"
							:class="{ 'wizard__step--current': index === step, 'wizard__step--done': index < step }"
							:aria-current="index === step ? 'step' : undefined"
							@click="goTo(index)">
							<span class="wizard__step-number">{{ index + 1 }}</span>
							<span class="wizard__step-label">{{ label }}</span>
						</button>
					</li>
				</ol>
			</nav>

			<section class="wizard__body">
				<!-- Schritt 1: Grunddaten -->
				<template v-if="step === 0">
					<NcTextField
						v-model="form.name"
						:label="t('lagerplaner', 'Name of the camp')"
						:error="!!errors.name"
						:helper-text="errors.name ?? ''" />
					<div class="wizard__row">
						<div>
							<NcDateTimePickerNative v-model="startDate" type="date" :label="t('lagerplaner', 'First day')" />
							<p v-if="errors.start" class="wizard__error">
								{{ errors.start }}
							</p>
						</div>
						<div>
							<NcDateTimePickerNative v-model="endDate" type="date" :label="t('lagerplaner', 'Last day')" />
							<p v-if="errors.end" class="wizard__error">
								{{ errors.end }}
							</p>
						</div>
					</div>
					<p v-if="form.start && form.end && form.end >= form.start" class="wizard__hint">
						{{ n('lagerplaner', '%n day', '%n days', dayCount(form.start, form.end)) }}
					</p>
					<div v-if="form.start && form.end && form.end >= form.start" class="wizard__row">
						<div>
							<NcTextField v-model="form.arrivalTime" type="time" :label="singleDay ? t('lagerplaner', 'Start') : t('lagerplaner', 'Arrival on the first day')" />
							<p class="wizard__hint">
								{{ errors.arrivalTime ?? (singleDay ? t('lagerplaner', 'Empty: 9:30') : t('lagerplaner', 'Empty: 10:00. A weekend camp often starts Friday at 17:00.')) }}
							</p>
						</div>
						<div>
							<NcTextField v-model="form.departureTime" type="time" :label="singleDay ? t('lagerplaner', 'End') : t('lagerplaner', 'Departure on the last day')" />
							<p class="wizard__hint">
								{{ errors.departureTime ?? (singleDay ? t('lagerplaner', 'Empty: 17:30') : t('lagerplaner', 'Empty: 14:00')) }}
							</p>
						</div>
					</div>
					<NcTextField v-model="form.organizer" :label="t('lagerplaner', 'Organising group (optional)')" />
					<fieldset>
						<legend>{{ t('lagerplaner', 'Accommodation') }}</legend>
						<NcCheckboxRadioSwitch v-model="form.campType" type="radio" name="campType" value="tent">
							{{ t('lagerplaner', 'Tent camp') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="form.campType" type="radio" name="campType" value="house">
							{{ t('lagerplaner', 'Group house') }}
						</NcCheckboxRadioSwitch>
					</fieldset>
				</template>

				<!-- Schritt 2: Teilnehmende -->
				<template v-else-if="step === 1">
					<p class="wizard__hint">
						{{ t('lagerplaner', 'Estimates are fine. Confirmed numbers can be added later.') }}
					</p>
					<table class="wizard__groups">
						<thead>
							<tr>
								<th scope="col">
									{{ t('lagerplaner', 'Group') }}
								</th>
								<th scope="col">
									{{ t('lagerplaner', 'People') }}
								</th>
								<th scope="col">
									{{ t('lagerplaner', 'Own programme') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(group, index) in form.groups" :key="group.key">
								<th scope="row">
									{{ group.label }}
								</th>
								<td>
									<NcTextField
										v-model="group.count"
										type="number"
										min="0"
										:label="t('lagerplaner', 'People in {group}', { group: group.label })"
										:error="!!errors[`groups.${index}.count`]"
										:helper-text="errors[`groups.${index}.count`] ?? ''" />
								</td>
								<td>
									<NcCheckboxRadioSwitch
										v-if="!group.isLeaders"
										v-model="group.separateProgram"
										type="switch"
										:aria-label="t('lagerplaner', 'Own programme for {group}', { group: group.label })" />
								</td>
							</tr>
						</tbody>
					</table>
					<p class="wizard__hint">
						{{ t('lagerplaner', '{all} people in total, {leaders} of them leaders', totals) }}
					</p>
					<NcNoteCard v-if="leaderHint" :type="leaderHint.level" :text="leaderHint.text" />
				</template>

				<!-- Schritt 3: Lagerort -->
				<template v-else-if="step === 2">
					<fieldset>
						<legend>{{ t('lagerplaner', 'Do you already know where the camp takes place?') }}</legend>
						<NcCheckboxRadioSwitch v-model="locationKnown" type="radio" name="locationKnown" value="yes">
							{{ t('lagerplaner', 'Yes') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="locationKnown" type="radio" name="locationKnown" value="no">
							{{ t('lagerplaner', 'Not yet') }}
						</NcCheckboxRadioSwitch>
					</fieldset>
					<NcTextField
						v-if="form.location.known"
						v-model="form.location.name"
						:label="t('lagerplaner', 'Campsite or house')"
						:error="!!errors['location.name']"
						:helper-text="errors['location.name'] ?? ''" />
					<template v-else>
						<NcTextField v-model="form.location.region" :label="t('lagerplaner', 'Region or maximum distance (optional)')" />
						<p class="wizard__hint">
							{{ t('lagerplaner', 'The assistant adds the task "Find a campsite". You can search later without blocking the rest of the planning.') }}
						</p>
					</template>
				</template>

				<!-- Schritt 4: Programm -->
				<template v-else-if="step === 3">
					<NcTextField v-model="form.program.theme" :label="t('lagerplaner', 'Camp theme (optional)')" />
					<NcCheckboxRadioSwitch v-model="form.program.hike" type="checkbox">
						{{ t('lagerplaner', 'Day hike') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="form.program.wideGame" type="checkbox">
						{{ t('lagerplaner', 'Large outdoor game for everyone') }}
					</NcCheckboxRadioSwitch>
					<div v-if="dayTemplates.length > 0" class="wizard__select">
						<label for="wizard-day-template">{{ t('lagerplaner', 'Daily routine on normal camp days') }}</label>
						<select id="wizard-day-template" v-model="form.program.dayTemplateId">
							<option :value="null">
								{{ t('lagerplaner', 'Suggestion of the camp planner') }}
							</option>
							<option v-for="template in dayTemplates" :key="template.id" :value="template.id">
								{{ t('lagerplaner', 'Template: {name}', { name: template.name }) }}
							</option>
						</select>
						<p class="wizard__hint">
							{{ t('lagerplaner', 'Hike, large game and free afternoon keep their own days. Meals are planned as usual.') }}
						</p>
					</div>
					<fieldset>
						<legend>{{ t('lagerplaner', 'Free time') }}</legend>
						<NcCheckboxRadioSwitch v-model="form.program.freeTime" type="radio" name="freeTime" value="little">
							{{ t('lagerplaner', 'Little (1 hour after lunch)') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="form.program.freeTime" type="radio" name="freeTime" value="medium">
							{{ t('lagerplaner', 'Medium (1.5 hours after lunch)') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="form.program.freeTime" type="radio" name="freeTime" value="much">
							{{ t('lagerplaner', 'Plenty (2 hours after lunch and one free afternoon)') }}
						</NcCheckboxRadioSwitch>
					</fieldset>
				</template>

				<!-- Schritt 5: Verpflegung -->
				<template v-else-if="step === 4">
					<NcCheckboxRadioSwitch v-model="form.catering.selfCatering" type="switch">
						{{ t('lagerplaner', 'We cook ourselves') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-if="form.catering.selfCatering" v-model="form.catering.proposeMenu" type="checkbox">
						{{ t('lagerplaner', 'Suggest a simple meal plan with dishes that almost always work (changeable afterwards)') }}
					</NcCheckboxRadioSwitch>
					<fieldset>
						<legend>{{ t('lagerplaner', 'Meals per day') }}</legend>
						<NcCheckboxRadioSwitch v-model="form.catering.meals" type="checkbox" value="breakfast">
							{{ t('lagerplaner', 'Breakfast') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="form.catering.meals" type="checkbox" value="lunch">
							{{ t('lagerplaner', 'Lunch') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="form.catering.meals" type="checkbox" value="snack">
							{{ t('lagerplaner', 'Afternoon snack') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="form.catering.meals" type="checkbox" value="dinner">
							{{ t('lagerplaner', 'Dinner') }}
						</NcCheckboxRadioSwitch>
					</fieldset>
					<fieldset v-if="form.catering.selfCatering">
						<legend>{{ t('lagerplaner', 'Is there a kitchen team?') }}</legend>
						<NcCheckboxRadioSwitch v-model="form.catering.kitchenTeam" type="radio" name="kitchenTeam" value="yes">
							{{ t('lagerplaner', 'Yes') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="form.catering.kitchenTeam" type="radio" name="kitchenTeam" value="no">
							{{ t('lagerplaner', 'No') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="form.catering.kitchenTeam" type="radio" name="kitchenTeam" value="unknown">
							{{ t('lagerplaner', 'Not decided yet') }}
						</NcCheckboxRadioSwitch>
					</fieldset>
					<p class="wizard__hint">
						{{ t('lagerplaner', 'Dietary needs are entered later as combinations, so nobody is counted twice.') }}
					</p>
				</template>

				<!-- Schritt 6: Organisation -->
				<template v-else-if="step === 5">
					<fieldset>
						<legend>{{ t('lagerplaner', 'Is someone responsible for material?') }}</legend>
						<NcCheckboxRadioSwitch v-model="form.organisation.materialResponsible" type="radio" name="material" value="yes">
							{{ t('lagerplaner', 'Yes') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="form.organisation.materialResponsible" type="radio" name="material" value="no">
							{{ t('lagerplaner', 'No') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="form.organisation.materialResponsible" type="radio" name="material" value="unknown">
							{{ t('lagerplaner', 'Not decided yet') }}
						</NcCheckboxRadioSwitch>
					</fieldset>
					<p class="wizard__hint">
						{{ t('lagerplaner', 'Open responsibilities become tasks with a suggested deadline.') }}
					</p>
				</template>

				<!-- Zusammenfassung -->
				<template v-else>
					<NcLoadingIcon v-if="busy && !result" :size="32" />

					<template v-else-if="result && !rerun">
						<dl class="wizard__facts">
							<template v-for="[label, value] in facts" :key="label">
								<dt>{{ label }}</dt>
								<dd>{{ value }}</dd>
							</template>
						</dl>
						<NcNoteCard v-for="hint in result.hints" :key="hint" type="info" :text="hint" />
						<p class="wizard__hint">
							{{ t('lagerplaner', 'Programme, meals and tasks are created as proposals that you can change afterwards.') }}
						</p>
					</template>

					<template v-else-if="result && rerun">
						<ChangeSummary :result="result" />
						<section v-if="groupItems" class="wizard__decisions">
							<h3>{{ t('lagerplaner', 'Programme items of removed age groups') }}</h3>
							<p>{{ t('lagerplaner', 'These items were confirmed or added by hand, so the assistant does not change them. Decide for each one what should happen.') }}</p>
							<div v-for="item in groupItems.items" :key="item.id" class="wizard__decision">
								<label :for="`decision-${item.id}`">
									{{ formatDay(item.day, { weekday: 'short', day: 'numeric', month: 'numeric' }) }}, {{ item.startTime }}: {{ item.title }}
								</label>
								<select :id="`decision-${item.id}`" v-model="groupDecisions[item.id]" :aria-invalid="!groupDecisions[item.id]">
									<option :value="undefined" disabled>
										{{ t('lagerplaner', 'Please choose') }}
									</option>
									<option v-for="option in groupItems.options" :key="option.key" :value="option.key">
										{{ t('lagerplaner', 'Assign to {group}', { group: option.label }) }}
									</option>
									<option value="all">
										{{ t('lagerplaner', 'For all groups') }}
									</option>
									<option value="remove">
										{{ t('lagerplaner', 'Remove item') }}
									</option>
								</select>
							</div>
							<p v-if="!decisionsComplete" class="wizard__decision-missing">
								{{ t('lagerplaner', 'Please decide for every item before applying the changes.') }}
							</p>
						</section>
					</template>
				</template>
			</section>

			<footer class="wizard__footer">
				<NcButton v-if="step > 0" variant="tertiary" @click="goTo(step - 1)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowLeft" directional />
					</template>
					{{ t('lagerplaner', 'Back') }}
				</NcButton>
				<span class="wizard__spacer" />
				<NcButton v-if="step < SUMMARY" variant="primary" @click="goTo(step + 1)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowRight" directional />
					</template>
					{{ step === SUMMARY - 1 ? steps[SUMMARY] : t('lagerplaner', 'Next') }}
				</NcButton>
				<NcButton
					v-else
					variant="primary"
					:disabled="busy || !result || !hasChanges || !decisionsComplete"
					@click="submit">
					<template #icon>
						<NcLoadingIcon v-if="busy" />
						<NcIconSvgWrapper v-else :path="mdiCheck" />
					</template>
					{{ rerun ? t('lagerplaner', 'Apply changes') : t('lagerplaner', 'Create camp') }}
				</NcButton>
			</footer>
			<p v-if="!rerun && step < SUMMARY" class="wizard__hint wizard__saved">
				{{ t('lagerplaner', 'Your entries are kept in this browser, so you can stop and continue later.') }}
			</p>
		</template>
	</div>
</template>

<style scoped>
.wizard {
	max-width: 760px;
	margin: 0 auto;
	/* Oben Platz für den Umschalter der App-Navigation lassen. */
	padding: calc(var(--default-clickable-area) + var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 6) calc(var(--default-grid-baseline) * 10);
}

.wizard__header h2 {
	margin-bottom: var(--default-grid-baseline);
}

.wizard__lead,
.wizard__hint {
	color: var(--color-text-maxcontrast);
}

.wizard__steps ol {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
	margin: calc(var(--default-grid-baseline) * 4) 0;
	padding: 0;
	list-style: none;
}

.wizard__step {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	min-height: var(--default-clickable-area);
	padding: 0 calc(var(--default-grid-baseline) * 3) 0 var(--default-grid-baseline);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element, var(--border-radius-large));
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.wizard__step:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.wizard__step-number {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border-radius: 50%;
	background: var(--color-background-dark);
	font-weight: bold;
}

.wizard__step--done .wizard__step-number {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.wizard__step--current {
	border-color: var(--color-primary-element);
}

.wizard__step--current .wizard__step-number {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.wizard__body {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	min-height: 280px;
}

.wizard__body fieldset legend {
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline);
}

.wizard__row {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: calc(var(--default-grid-baseline) * 3);
}

.wizard__error {
	color: var(--color-error-text);
}

.wizard__groups {
	width: 100%;
	border-collapse: collapse;
}

.wizard__groups th,
.wizard__groups td {
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	text-align: start;
	vertical-align: middle;
}

.wizard__groups thead th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.wizard__groups tbody th {
	font-weight: normal;
}

.wizard__summary-lead {
	font-size: 1.1em;
}

.wizard__sample ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

.wizard__sample li {
	display: grid;
	grid-template-columns: 7.5em 1fr;
	padding: calc(var(--default-grid-baseline) / 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.wizard__time {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.wizard__footer {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 6);
}

.wizard__spacer {
	flex: 1;
}

.wizard__saved {
	margin-top: calc(var(--default-grid-baseline) * 2);
	text-align: end;
	font-size: 0.9em;
}

@media (max-width: 600px) {
	.wizard {
		padding-inline: calc(var(--default-grid-baseline) * 3);
	}

	.wizard__step-label {
		display: none;
	}

	.wizard__step--current .wizard__step-label {
		display: inline;
	}
}
.wizard__decisions {
	margin-top: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius-large);
}

.wizard__decisions h3 {
	margin: 0 0 var(--default-grid-baseline);
}

.wizard__decision {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.wizard__decision select {
	min-height: var(--default-clickable-area);
	margin: 0;
}

.wizard__decision-missing {
	color: var(--color-warning-text);
}
.wizard__select {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: calc(var(--default-grid-baseline) * 2) 0;
}

.wizard__select select {
	min-height: var(--default-clickable-area);
	max-width: 100%;
	margin: 0;
}

.wizard__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
.wizard__facts {
	display: grid;
	grid-template-columns: minmax(10em, max-content) 1fr;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 4);
	margin: 0 0 calc(var(--default-grid-baseline) * 3);
}

.wizard__facts dt {
	color: var(--color-text-maxcontrast);
}

.wizard__facts dd {
	margin: 0;
}

@media (max-width: 600px) {
	.wizard__facts {
		grid-template-columns: 1fr;
	}

	.wizard__facts dd {
		margin-bottom: var(--default-grid-baseline);
	}
}
</style>
