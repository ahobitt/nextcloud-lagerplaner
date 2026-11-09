<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiPrinter } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { computed, ref, useId } from 'vue'
import { errorMessage, getExport } from '../api.js'
import { formatDay } from '../format.js'
import { t } from '../l10n.js'
import { printHtml } from '../print.js'

const props = defineProps({
	campId: { type: Number, required: true },
	days: { type: Array, required: true },
	groups: { type: Array, required: true },
	can: { type: Object, required: true },
})
const id = useId()
const choice = ref(null)
const busy = ref(false)

const documents = computed(() => [
	{ type: 'program', label: t('lagerplaner', 'Complete programme') },
	{ type: 'day', label: t('lagerplaner', 'Programme for one day …'), choose: 'day' },
	...(props.groups.length > 0 ? [{ type: 'group', label: t('lagerplaner', 'Programme for one age group …'), choose: 'group' }] : []),
	{ type: 'games', label: t('lagerplaner', 'Game instructions') },
	{ type: 'tasks', label: t('lagerplaner', 'Task overview') },
	...(props.can.cateringView ? [
		{ type: 'meals', label: t('lagerplaner', 'Meal plan for the kitchen') },
		{ type: 'recipes', label: t('lagerplaner', 'Recipes for the kitchen') },
	] : []),
])

async function print(type, param = '') {
	busy.value = true
	try {
		const document = await getExport(props.campId, type, param)
		printHtml(document.html)
		choice.value = null
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

function pick(entry) {
	if (entry.choose) {
		choice.value = { type: entry.type, label: entry.label, value: entry.choose === 'day' ? props.days[0] : props.groups[0].key }
	} else {
		print(entry.type)
	}
}
</script>

<template>
	<div>
		<NcActions :aria-label="t('lagerplaner', 'Print or save as PDF')" :menu-name="t('lagerplaner', 'Print')" :force-name="true">
			<template #icon>
				<NcIconSvgWrapper :path="mdiPrinter" />
			</template>
			<NcActionButton v-for="entry in documents" :key="entry.type" close-after-click @click="pick(entry)">
				{{ entry.label }}
			</NcActionButton>
		</NcActions>
		<NcDialog
			v-if="choice"
			:open="true"
			:name="choice.label"
			@update:open="(value) => { if (!value) { choice = null } }">
			<div class="export-choice">
				<label :for="`${id}-choice`">{{ choice.type === 'day' ? t('lagerplaner', 'Day') : t('lagerplaner', 'Age group') }}</label>
				<select :id="`${id}-choice`" v-model="choice.value">
					<template v-if="choice.type === 'day'">
						<option v-for="day in days" :key="day" :value="day">
							{{ formatDay(day) }}
						</option>
					</template>
					<template v-else>
						<option v-for="group in groups" :key="group.key" :value="group.key">
							{{ group.label }}
						</option>
					</template>
				</select>
				<NcButton variant="primary" :disabled="busy" @click="print(choice.type, choice.value)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPrinter" />
					</template>
					{{ t('lagerplaner', 'Print') }}
				</NcButton>
			</div>
		</NcDialog>
	</div>
</template>

<style scoped>
.export-choice {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	padding-bottom: calc(var(--default-grid-baseline) * 3);
}

.export-choice label {
	width: 100%;
	color: var(--color-text-maxcontrast);
}

.export-choice select {
	min-height: var(--default-clickable-area);
	margin: 0;
}
</style>
