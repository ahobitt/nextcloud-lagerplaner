<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { mdiAccount, mdiAccountGroup, mdiClose } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { onMounted, ref, watch } from 'vue'
import { addMember, errorMessage, getMembers, removeMember, searchMembers, updateMember } from '../api.js'
import { t } from '../l10n.js'
import { ROLES, roleDescriptions, roleLabels } from '../roles.js'

const props = defineProps({
	campId: { type: Number, required: true },
	campName: { type: String, required: true },
	canManage: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])

const open = ref(true)
const loading = ref(true)
const owner = ref(null)
const members = ref([])
const term = ref('')
const results = ref([])
const searching = ref(false)
const selected = ref(null)
const newRoles = ref(['mitarbeit'])
const busy = ref(false)

onMounted(load)

async function load() {
	try {
		const team = await getMembers(props.campId)
		owner.value = team.owner
		members.value = team.members
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		loading.value = false
	}
}

let timer = null
watch(term, (value) => {
	clearTimeout(timer)
	if (value.trim().length < 1) {
		results.value = []
		return
	}
	timer = setTimeout(async () => {
		searching.value = true
		try {
			results.value = await searchMembers(props.campId, value.trim())
		} catch (error) {
			showError(errorMessage(error))
		} finally {
			searching.value = false
		}
	}, 300)
})

function choose(entry) {
	selected.value = entry
	newRoles.value = entry.type === 'group' ? ['mitarbeit'] : ['mitarbeit']
}

async function add() {
	busy.value = true
	try {
		const member = await addMember(props.campId, selected.value.type, selected.value.id, newRoles.value)
		members.value = [...members.value.filter((m) => m.id !== member.id), member]
		showSuccess(t('lagerplaner', '{name} now has access to the camp.', { name: member.displayName }))
		selected.value = null
		term.value = ''
		results.value = []
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		busy.value = false
	}
}

async function changeRoles(member, roles) {
	if (roles.length === 0) {
		showError(t('lagerplaner', 'Choose at least one role, or remove the entry.'))
		return
	}
	try {
		const updated = await updateMember(member.id, roles)
		members.value = members.value.map((m) => (m.id === updated.id ? updated : m))
	} catch (error) {
		showError(errorMessage(error))
	}
}

async function remove(member) {
	try {
		await removeMember(member.id)
		members.value = members.value.filter((m) => m.id !== member.id)
	} catch (error) {
		showError(errorMessage(error))
	}
}

function rolesText(roles) {
	const labels = roleLabels()
	return roles.map((role) => labels[role] ?? role).join(', ')
}

function close() {
	open.value = false
	emit('close')
}
</script>

<template>
	<NcDialog
		:open="open"
		size="normal"
		:name="t('lagerplaner', 'Team for {camp}', { camp: campName })"
		@update:open="(value) => !value && close()">
		<NcLoadingIcon v-if="loading" :size="32" />
		<div v-else class="team">
			<ul class="team__list">
				<li v-if="owner" class="team__member">
					<NcIconSvgWrapper :path="mdiAccount" />
					<div class="team__who">
						<span class="team__name">{{ owner.displayName }}</span>
						<span class="team__roles">{{ t('lagerplaner', 'Camp leader, created the camp') }}</span>
					</div>
				</li>
				<li v-for="member in members" :key="member.id" class="team__member">
					<NcIconSvgWrapper :path="member.type === 'group' ? mdiAccountGroup : mdiAccount" />
					<div class="team__who">
						<span class="team__name">
							{{ member.displayName }}
							<span v-if="member.type === 'group'" class="team__kind">{{ t('lagerplaner', 'Group: everyone in it') }}</span>
						</span>
						<div v-if="canManage" class="team__role-choices">
							<NcCheckboxRadioSwitch
								v-for="role in ROLES"
								:key="role"
								:model-value="member.roles"
								type="checkbox"
								:value="role"
								@update:model-value="(roles) => changeRoles(member, roles)">
								{{ roleLabels()[role] }}
							</NcCheckboxRadioSwitch>
						</div>
						<span v-else class="team__roles">{{ rolesText(member.roles) }}</span>
						<span v-if="member.type === 'group' && member.roles.includes('leitung')" class="team__warning">
							{{ t('lagerplaner', 'All current and future members of this group have full rights, including deleting the camp.') }}
						</span>
					</div>
					<NcButton
						v-if="canManage"
						variant="tertiary"
						:aria-label="t('lagerplaner', 'Remove {name} from the team', { name: member.displayName })"
						@click="remove(member)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiClose" />
						</template>
					</NcButton>
				</li>
			</ul>
			<p v-if="members.length === 0" class="team__hint">
				{{ t('lagerplaner', 'Nobody else has access yet.') }}
			</p>

			<section v-if="canManage" class="team__add">
				<h3>{{ t('lagerplaner', 'Add people or a whole group') }}</h3>
				<p class="team__hint">
					{{ t('lagerplaner', 'Share with a group such as your leaders\' circle: everyone in the group gets access, also people who join later.') }}
				</p>
				<NcTextField v-model="term" :label="t('lagerplaner', 'Search for a person or group')" />
				<NcLoadingIcon v-if="searching" :size="20" />
				<ul v-if="results.length > 0 && !selected" class="team__results">
					<li v-for="entry in results" :key="`${entry.type}:${entry.id}`">
						<NcButton variant="tertiary" wide alignment="start" @click="choose(entry)">
							<template #icon>
								<NcIconSvgWrapper :path="entry.type === 'group' ? mdiAccountGroup : mdiAccount" />
							</template>
							{{ entry.displayName }}{{ entry.type === 'group' ? ` (${t('lagerplaner', 'group')})` : '' }}
						</NcButton>
					</li>
				</ul>
				<p v-else-if="term.trim() && !searching && results.length === 0 && !selected" class="team__hint">
					{{ t('lagerplaner', 'Nobody found. Only people and groups you are allowed to share with appear here.') }}
				</p>

				<div v-if="selected" class="team__selected">
					<p>
						<strong>{{ selected.displayName }}</strong>
						{{ selected.type === 'group' ? t('lagerplaner', '(group)') : '' }}
					</p>
					<NcNoteCard
						v-if="selected.type === 'group' && newRoles.includes('leitung')"
						type="warning"
						:text="t('lagerplaner', 'Everyone who is or becomes a member of this group gets full rights, including managing the team and deleting the camp.')" />
					<fieldset>
						<legend>{{ t('lagerplaner', 'Roles') }}</legend>
						<NcCheckboxRadioSwitch v-for="role in ROLES" :key="role" v-model="newRoles" type="checkbox" :value="role">
							{{ roleLabels()[role] }}
							<span class="team__description">{{ roleDescriptions()[role] }}</span>
						</NcCheckboxRadioSwitch>
					</fieldset>
					<div class="team__buttons">
						<NcButton variant="primary" :disabled="busy || newRoles.length === 0" @click="add">
							{{ t('lagerplaner', 'Give access') }}
						</NcButton>
						<NcButton variant="tertiary" @click="selected = null">
							{{ t('lagerplaner', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</section>
		</div>
	</NcDialog>
</template>

<style scoped>
.team {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.team__list,
.team__results {
	margin: 0;
	padding: 0;
	list-style: none;
}

.team__member {
	display: flex;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.team__who {
	display: flex;
	flex: 1;
	flex-direction: column;
	min-width: 0;
}

.team__name {
	font-weight: bold;
}

.team__kind,
.team__roles,
.team__hint,
.team__description {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.team__warning {
	color: var(--color-warning-text);
	font-size: 0.9em;
}

.team__kind {
	margin-inline-start: var(--default-grid-baseline);
	font-size: 0.9em;
}

.team__description {
	display: block;
	font-size: 0.9em;
}

.team__role-choices {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 3);
}

.team__add h3 {
	margin: 0 0 var(--default-grid-baseline);
}

.team__hint {
	margin: 0;
}

.team__selected {
	padding: calc(var(--default-grid-baseline) * 3);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.team__selected p {
	margin: 0 0 var(--default-grid-baseline);
}

.team__buttons {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 2);
}
</style>
