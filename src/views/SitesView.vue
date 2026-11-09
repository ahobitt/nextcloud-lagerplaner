<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiPlus } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { ref } from 'vue'
import { createSite, deleteSite, errorMessage, updateSite } from '../api.js'
import SiteForm from '../components/SiteForm.vue'
import SiteSearch from '../components/SiteSearch.vue'
import { t } from '../l10n.js'

const form = ref(null)
/** Neue Plätze legt nur die Administration an; alle anderen melden fehlende Plätze. */
const canCreate = ref(false)
const busy = ref(false)
const searchRef = ref(null)

async function save(site) {
	busy.value = true
	try {
		if (form.value.site) {
			await updateSite(form.value.site.id, site)
		} else {
			await createSite(site)
		}
		showSuccess(t('lagerplaner', 'Place saved'))
		form.value = null
		searchRef.value?.search()
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

async function remove(site) {
	try {
		await deleteSite(site.id)
		searchRef.value?.search()
	} catch (error) {
		showError(errorMessage(error))
	}
}
</script>

<template>
	<div class="sites-view">
		<header class="sites-view__header">
			<div>
				<h2>{{ t('lagerplaner', 'Campsites and group houses') }}</h2>
				<p>{{ t('lagerplaner', 'Unknown information is shown as unknown, never as “not available”. A directory entry says nothing about availability; always ask the operator.') }}</p>
			</div>
			<NcButton v-if="!form && canCreate" @click="form = { site: null }">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ t('lagerplaner', 'Add a place you know') }}
			</NcButton>
		</header>
		<SiteForm v-if="form" :key="form.site?.id ?? 'new'" :site="form.site" :busy="busy" @save="save" @cancel="form = null" />
		<SiteSearch ref="searchRef" @edit="(site) => form = { site }" @delete="remove" @can-create="(value) => canCreate = value" />
	</div>
</template>

<style scoped>
.sites-view {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	max-width: 1100px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline) * 4);
	padding-top: calc(var(--default-clickable-area) + var(--default-grid-baseline) * 2);
}

.sites-view__header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 3);
}

.sites-view__header h2 {
	margin: 0;
}

.sites-view__header p {
	max-width: 700px;
	margin: var(--default-grid-baseline) 0 0;
	color: var(--color-text-maxcontrast);
}
</style>
