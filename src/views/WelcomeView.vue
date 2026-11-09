<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiAutoFix, mdiTent } from '@mdi/js'
import { t } from '../l10n.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { navigate } from '../router.js'

defineProps({
	loaded: { type: Boolean, default: false },
	hasCamps: { type: Boolean, default: false },
})
</script>

<template>
	<NcEmptyContent
		v-if="loaded"
		class="welcome"
		:name="hasCamps ? t('lagerplaner', 'Choose a camp or plan a new one') : t('lagerplaner', 'Plan your first camp')"
		:description="t('lagerplaner', 'The camp assistant asks six short questions and drafts a programme, meals and tasks. You can change everything afterwards.')">
		<template #icon>
			<NcIconSvgWrapper :path="mdiTent" />
		</template>
		<template #action>
			<NcButton variant="primary" @click="navigate('new')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAutoFix" />
				</template>
				{{ t('lagerplaner', 'Start the camp assistant') }}
			</NcButton>
		</template>
	</NcEmptyContent>
</template>

<style scoped>
.welcome {
	margin-top: 15vh;
}
</style>
