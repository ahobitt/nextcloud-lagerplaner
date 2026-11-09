<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiDelete, mdiPencil } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { onMounted, ref } from 'vue'
import { deleteTemplate, errorMessage, getTemplate, listTemplates, updateTemplate } from '../api.js'
import { n, t } from '../l10n.js'

const templates = ref(null)
const open = ref(null)
const detail = ref(null)
const rename = ref(null)

async function load() {
	try {
		templates.value = await listTemplates()
	} catch (error) {
		showError(errorMessage(error))
		templates.value = []
	}
}
onMounted(load)

async function act(action) {
	try {
		await action()
		await load()
		return true
	} catch (error) {
		showError(errorMessage(error))
		return false
	}
}

async function toggle(template) {
	if (open.value === template.id) {
		open.value = null
		return
	}
	open.value = template.id
	detail.value = null
	try {
		detail.value = await getTemplate(template.id)
	} catch (error) {
		showError(errorMessage(error))
	}
}

async function saveName() {
	if (await act(() => updateTemplate(rename.value.id, { name: rename.value.name }))) {
		rename.value = null
	}
}

const items = (data) => (data.items ?? (data.item ? [data.item] : []))
</script>

<template>
	<div class="templates-view">
		<h2>{{ t('lagerplaner', 'Templates') }}</h2>
		<p class="templates-view__intro">
			{{ t('lagerplaner', 'Save programme items or whole days from a camp as templates and insert them into other camps. Day templates can also be used by the assistant for normal camp days.') }}
		</p>
		<NcLoadingIcon v-if="templates === null" :size="32" />
		<p v-else-if="templates.length === 0" class="templates-view__intro">
			{{ t('lagerplaner', 'No templates yet. In a camp, use the actions of a programme item or a day to save one.') }}
		</p>
		<ul v-else class="templates">
			<li v-for="template in templates" :key="template.id" class="template">
				<div class="template__row">
					<button type="button" class="template__name" :aria-expanded="open === template.id" @click="toggle(template)">
						{{ template.name }}
					</button>
					<span class="template__meta">
						{{ template.kind === 'day' ? n('lagerplaner', 'Day with %n item', 'Day with %n items', template.itemCount) : t('lagerplaner', 'Programme item') }},
						{{ t('lagerplaner', 'version {version}', { version: template.version }) }}<template v-if="!template.mine">, {{ t('lagerplaner', 'shared by {name}', { name: template.owner }) }}</template>
					</span>
					<NcCheckboxRadioSwitch
						v-if="template.mine"
						:model-value="template.shared"
						type="switch"
						@update:model-value="act(() => updateTemplate(template.id, { shared: $event }))">
						{{ t('lagerplaner', 'Shared') }}
					</NcCheckboxRadioSwitch>
					<NcActions v-if="template.mine" :aria-label="t('lagerplaner', 'Actions for {title}', { title: template.name })">
						<NcActionButton close-after-click @click="rename = { id: template.id, name: template.name }">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPencil" />
							</template>
							{{ t('lagerplaner', 'Rename') }}
						</NcActionButton>
						<NcActionButton close-after-click @click="act(() => deleteTemplate(template.id))">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDelete" />
							</template>
							{{ t('lagerplaner', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</div>
				<form v-if="rename?.id === template.id" class="template__rename" @submit.prevent="saveName">
					<NcTextField v-model="rename.name" :label="t('lagerplaner', 'Name of the template')" />
					<NcButton type="submit" variant="primary">
						{{ t('lagerplaner', 'Save') }}
					</NcButton>
					<NcButton variant="tertiary" @click="rename = null">
						{{ t('lagerplaner', 'Cancel') }}
					</NcButton>
				</form>
				<div v-if="open === template.id" class="template__detail">
					<NcLoadingIcon v-if="!detail" :size="20" />
					<ol v-else>
						<li v-for="(item, index) in items(detail.data)" :key="index">
							<span v-if="item.startTime" class="template__time">{{ item.startTime }}</span>
							<strong>{{ item.title }}</strong> <span class="template__meta">{{ n('lagerplaner', '%n minute', '%n minutes', item.durationMinutes) }}</span>
							<span v-if="item.materials?.length" class="template__meta">, {{ t('lagerplaner', 'Material: {material}', { material: item.materials.map((m) => m.name).join(', ') }) }}</span>
						</li>
					</ol>
				</div>
			</li>
		</ul>
	</div>
</template>

<style scoped>
.templates-view {
	max-width: 900px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline) * 4);
	padding-top: calc(var(--default-clickable-area) + var(--default-grid-baseline) * 2);
}

.templates-view h2 {
	margin: 0;
}

.templates-view__intro,
.template__meta {
	color: var(--color-text-maxcontrast);
}

.templates {
	margin: 0;
	padding: 0;
	list-style: none;
}

.template {
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.template__row,
.template__rename {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0 calc(var(--default-grid-baseline) * 3);
}

.template__name {
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font-weight: bold;
	cursor: pointer;
}

.template__name:hover,
.template__name:focus-visible {
	text-decoration: underline;
}

.template__meta {
	font-size: 0.9em;
}

.template__row > .template__meta {
	flex: 1;
}

.template__detail ol {
	margin: var(--default-grid-baseline) 0 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}

.template__time {
	display: inline-block;
	width: 3.5em;
	font-variant-numeric: tabular-nums;
}
</style>
