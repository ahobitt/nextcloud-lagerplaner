<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'
import { applyTemplate, errorMessage, listTemplates } from '../api.js'
import { formatDay } from '../format.js'
import { n, t } from '../l10n.js'

const props = defineProps({
	campId: { type: Number, required: true },
	day: { type: String, required: true },
})
const emit = defineEmits(['close', 'applied'])

const templates = ref(null)
const query = ref('')
const selected = ref(null)
const startTime = ref('09:30')
const busy = ref(false)

onMounted(async () => {
	try {
		templates.value = await listTemplates()
	} catch (error) {
		showError(errorMessage(error))
		templates.value = []
	}
})

const visible = computed(() => (templates.value ?? []).filter((template) => template.name.toLowerCase().includes(query.value.trim().toLowerCase())))

async function apply() {
	busy.value = true
	try {
		const result = await applyTemplate(selected.value.id, props.campId, props.day, selected.value.kind === 'item' ? startTime.value : '')
		showSuccess(n('lagerplaner', '%n programme item added', '%n programme items added', result.created))
		if (result.materialsSkipped) {
			showError(t('lagerplaner', 'The material of the template was not added because you may not plan material in this camp.'))
		}
		emit('applied')
		emit('close')
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<NcDialog
		:open="true"
		:name="t('lagerplaner', 'Insert template on {day}', { day: formatDay(day) })"
		@update:open="(value) => { if (!value) { emit('close') } }">
		<div class="template-apply">
			<NcLoadingIcon v-if="templates === null" :size="24" />
			<template v-else>
				<p v-if="templates.length === 0">
					{{ t('lagerplaner', 'No templates yet. Save a programme item or a whole day as a template first.') }}
				</p>
				<template v-else>
					<NcTextField v-model="query" :label="t('lagerplaner', 'Search templates')" />
					<ul class="template-apply__list">
						<li v-for="template in visible" :key="template.id">
							<label>
								<input v-model="selected" type="radio" name="template" :value="template">
								<span>
									<strong>{{ template.name }}</strong>
									<span class="template-apply__meta">
										{{ template.kind === 'day' ? n('lagerplaner', 'Day with %n item', 'Day with %n items', template.itemCount) : t('lagerplaner', 'Programme item') }},
										{{ template.mine ? t('lagerplaner', 'yours') : t('lagerplaner', 'shared by {name}', { name: template.owner }) }},
										{{ t('lagerplaner', 'version {version}', { version: template.version }) }}
									</span>
								</span>
							</label>
						</li>
					</ul>
					<NcTextField v-if="selected?.kind === 'item'" v-model="startTime" :label="t('lagerplaner', 'Start (HH:MM)')" />
					<p v-if="selected?.kind === 'day'" class="template-apply__meta">
						{{ t('lagerplaner', 'The items are added with their times. Existing items of the day stay; check for overlaps afterwards.') }}
					</p>
					<NcButton variant="primary" :disabled="busy || !selected" @click="apply">
						{{ t('lagerplaner', 'Insert') }}
					</NcButton>
				</template>
			</template>
		</div>
	</NcDialog>
</template>

<style scoped>
.template-apply {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding-bottom: calc(var(--default-grid-baseline) * 3);
}

.template-apply__list {
	max-height: 50vh;
	margin: 0;
	padding: 0;
	overflow-y: auto;
	list-style: none;
}

.template-apply__list label {
	display: flex;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
}

.template-apply__list label > span {
	display: flex;
	flex-direction: column;
}

.template-apply__meta {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
