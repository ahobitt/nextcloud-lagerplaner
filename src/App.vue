<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiAccountMultiple, mdiContentSaveOutline, mdiMapMarkerRadius, mdiPlus, mdiTent } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { t } from './l10n.js'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { onMounted, ref } from 'vue'
import { errorMessage, listCamps } from './api.js'
import { formatRange } from './format.js'
import { navigate, route } from './router.js'
import CampView from './views/CampView.vue'
import SitesView from './views/SitesView.vue'
import TemplatesView from './views/TemplatesView.vue'
import WelcomeView from './views/WelcomeView.vue'
import WizardView from './views/WizardView.vue'

const camps = ref([])
const loaded = ref(false)

async function reload() {
	try {
		camps.value = await listCamps()
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		loaded.value = true
	}
}

async function onCreated(camp) {
	await reload()
	navigate(`camp/${camp.id}`)
}

async function onDeleted() {
	await reload()
	navigate('')
}

onMounted(reload)
</script>

<template>
	<NcContent app-name="lagerplaner">
		<NcAppNavigation :aria-label="t('lagerplaner', 'Camps')">
			<template #list>
				<NcAppNavigationNew :text="t('lagerplaner', 'New camp')" @click="navigate('new')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
				</NcAppNavigationNew>
				<NcAppNavigationItem
					v-for="camp in camps"
					:key="camp.id"
					:name="camp.name"
					:title="formatRange(camp.startDate, camp.endDate)"
					:active="route.params.id === camp.id"
					:href="`#/camp/${camp.id}`">
					<template #icon>
						<NcIconSvgWrapper :path="camp.isOwner === false ? mdiAccountMultiple : mdiTent" />
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<ul class="app-navigation-entry__settings">
					<NcAppNavigationItem :name="t('lagerplaner', 'Templates')" :active="route.name === 'templates'" href="#/templates">
						<template #icon>
							<NcIconSvgWrapper :path="mdiContentSaveOutline" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('lagerplaner', 'Campsites and houses')" :active="route.name === 'sites'" href="#/sites">
						<template #icon>
							<NcIconSvgWrapper :path="mdiMapMarkerRadius" />
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<WizardView v-if="route.name === 'new'" key="new" @created="onCreated" />
			<WizardView
				v-else-if="route.name === 'rerun'"
				:key="`rerun-${route.params.id}`"
				:camp-id="route.params.id"
				@applied="reload(); navigate(`camp/${route.params.id}`)" />
			<CampView
				v-else-if="route.name === 'camp'"
				:key="`camp-${route.params.id}`"
				:camp-id="route.params.id"
				@deleted="onDeleted" />
			<SitesView v-else-if="route.name === 'sites'" />
			<TemplatesView v-else-if="route.name === 'templates'" />
			<WelcomeView v-else :loaded="loaded" :has-camps="camps.length > 0" />
		</NcAppContent>
	</NcContent>
</template>
