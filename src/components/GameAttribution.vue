<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { t } from '../l10n.js'

/** Namensnennung nach CC BY: Titel, Urheberin, Quelle und Lizenz, jeweils verlinkt. */
defineProps({
	title: { type: String, required: true },
	link: { type: String, required: true },
	license: { type: Object, required: true },
})
</script>

<template>
	<span class="attribution">
		<a :href="link" target="_blank" rel="noopener noreferrer">„{{ title }}“</a>
		{{ t('lagerplaner', 'by {author}', { author: license.attribution }) }},
		<a :href="license.url" target="_blank" rel="noopener noreferrer license">{{ license.name }}</a>
	</span>
</template>

<style scoped>
.attribution {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.attribution a {
	color: inherit;
	text-decoration: underline;
}
</style>
