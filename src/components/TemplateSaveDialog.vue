<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref, useId } from 'vue'
import { errorMessage, listTemplates, saveDayTemplate, saveItemTemplate } from '../api.js'
import { t } from '../l10n.js'

/** Programmpunkt ({ kind: 'item', programItemId }) oder Tag ({ kind: 'day', campId, day }) als Vorlage speichern. */
const props = defineProps({
	source: { type: Object, required: true },
	defaultName: { type: String, default: '' },
})
const emit = defineEmits(['close'])
const id = useId()

const name = ref(props.defaultName)
const shared = ref(false)
const replaceId = ref('')
const mine = ref([])
const busy = ref(false)

onMounted(async () => {
	try {
		mine.value = (await listTemplates()).filter((template) => template.mine && template.kind === props.source.kind)
	} catch {
		// Ohne Liste lässt sich nur neu speichern.
	}
})

const replacing = computed(() => mine.value.find((template) => template.id === replaceId.value) ?? null)

async function save() {
	busy.value = true
	try {
		const replace = replaceId.value === '' ? null : replaceId.value
		if (props.source.kind === 'item') {
			await saveItemTemplate(props.source.programItemId, name.value, shared.value, replace)
		} else {
			await saveDayTemplate(props.source.campId, props.source.day, name.value, shared.value, replace)
		}
		showSuccess(t('lagerplaner', 'Template saved'))
		emit('close')
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

function chooseReplace(value) {
	replaceId.value = value
	if (replacing.value) {
		name.value = replacing.value.name
		shared.value = replacing.value.shared
	}
}
</script>

<template>
	<NcDialog
		:open="true"
		:name="source.kind === 'item' ? t('lagerplaner', 'Save programme item as template') : t('lagerplaner', 'Save day as template')"
		@update:open="(value) => { if (!value) { emit('close') } }">
		<form class="template-save" @submit.prevent="save">
			<p>{{ t('lagerplaner', 'Saved are times, titles, descriptions, bad weather alternatives and material. Responsible persons, tasks and dietary information are not saved. Please do not use names in descriptions.') }}</p>
			<div v-if="mine.length > 0" class="template-save__select">
				<label :for="`${id}-replace`">{{ t('lagerplaner', 'Save as') }}</label>
				<select :id="`${id}-replace`" :value="replaceId" @change="chooseReplace($event.target.value === '' ? '' : Number($event.target.value))">
					<option value="">
						{{ t('lagerplaner', 'New template') }}
					</option>
					<option v-for="template in mine" :key="template.id" :value="template.id">
						{{ t('lagerplaner', 'Update “{name}” (version {version})', { name: template.name, version: template.version + 1 }) }}
					</option>
				</select>
			</div>
			<NcTextField v-model="name" :label="t('lagerplaner', 'Name of the template')" />
			<NcCheckboxRadioSwitch v-model="shared" type="switch">
				{{ t('lagerplaner', 'Share with everyone in this Nextcloud') }}
			</NcCheckboxRadioSwitch>
			<div class="template-save__buttons">
				<NcButton type="submit" variant="primary" :disabled="busy || name.trim() === ''">
					{{ t('lagerplaner', 'Save template') }}
				</NcButton>
			</div>
		</form>
	</NcDialog>
</template>

<style scoped>
.template-save {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding-bottom: calc(var(--default-grid-baseline) * 3);
}

.template-save p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.template-save__select {
	display: flex;
	flex-direction: column;
	gap: 2px;
	color: var(--color-text-maxcontrast);
}

.template-save__select select {
	min-height: var(--default-clickable-area);
	margin: 0;
	color: var(--color-main-text);
}
</style>
