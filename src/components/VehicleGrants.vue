<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import { computed, onMounted, ref } from 'vue'

import { addGrant, changeGrant, listGrants, revokeGrant, searchGrantees } from '../services/api.js'
import { may } from '../utils/access.js'
import { ROLES, roleWord } from '../utils/format.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
	/** Whether the sheet around it is mid-save. */
	disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['revoked'])

/** Only the owner reads or writes access, so for anyone else the section is not there at all. */
const owns = computed(() => may(props.vehicle, 'own'))

/**
 * The list as the server last answered, or null while it is unread or could not be read.
 *
 * @type {import('vue').Ref<import('../services/api.js').Grant[]|null>}
 */
const grants = ref(null)
/** @type {import('vue').Ref<{ grantee: string, grantee_type: 'user'|'group', display_name: string }[]>} */
const found = ref([])
/** @type {import('vue').Ref<{ id: string }|null>} */
const picked = ref(null)
const writing = ref(false)
const failure = ref('')

const roles = computed(() => ROLES.map((id) => ({ id, label: roleWord(id) })))
// A car is lent to be driven; viewing alone is the rarer wish.
const role = ref(roles.value.find((one) => one.id === 'driver'))

/**
 * @param {{ grantee: string, grantee_type: string, display_name: string }} one - a user or a group
 * @return {object} it as the picker shows it. The id carries the type, because a group may share
 *   its name with an account; the grantee rides along as the subname because the picker filters
 *   on what it shows.
 */
function option(one) {
	return one.grantee_type === 'group'
		? { id: `group:${one.grantee}`, displayName: one.display_name, subname: t('nextfleet', 'Group {group}', { group: one.grantee }), isNoUser: true, grantee: one }
		: { id: `user:${one.grantee}`, displayName: one.display_name, subname: one.grantee, user: one.grantee, grantee: one }
}

const options = computed(() => found.value.map(option))

onMounted(async () => {
	if (!owns.value) {
		return
	}
	try {
		grants.value = await listGrants(props.vehicle.uuid)
	} catch {
		grants.value = null
	}
})

/** What was typed last; an answer to anything earlier is dropped, whenever it arrives. */
let typed = ''

/** @param {string} term - what was typed */
async function search(term) {
	typed = term
	let answer = []
	try {
		answer = term.trim() === '' ? [] : await searchGrantees(term)
	} catch {
		// No matches: the field stays usable for the next try.
	}
	if (term === typed) {
		found.value = answer
	}
}

/**
 * One write; the list shown is the one the server answered with, and a refusal leaves it as it was.
 *
 * @param {() => Promise<import('../services/api.js').Grant[]>} work - the write
 * @return {Promise<boolean>} whether it went through
 */
async function write(work) {
	writing.value = true
	failure.value = ''
	try {
		grants.value = await work()
		return true
	} catch (error) {
		failure.value = t('nextfleet', 'Access was not changed: {reason}', { reason: error.message })
		return false
	} finally {
		writing.value = false
	}
}

/** Grants the one picked the role chosen, and clears the pick for the next one. */
async function give() {
	if (await write(() => addGrant(props.vehicle.uuid, picked.value.grantee, role.value.id))) {
		picked.value = null
		found.value = []
	}
}

/**
 * @param {import('../services/api.js').Grant} grant - the row
 * @param {{ id: string }|null} chosen - the role picked for it
 */
function recast(grant, chosen) {
	if (chosen !== null && chosen.id !== grant.role) {
		write(() => changeGrant(props.vehicle.uuid, grant.uuid, chosen.id))
	}
}

/** @param {import('../services/api.js').Grant} grant - the row */
async function revoke(grant) {
	if (await write(() => revokeGrant(props.vehicle.uuid, grant.uuid))) {
		emit('revoked')
	}
}
</script>

<template>
	<section v-if="owns && grants !== null" class="grants">
		<h3>{{ t('nextfleet', 'Access') }}</h3>
		<NcNoteCard v-if="failure" type="error" :text="failure" />
		<ul v-if="grants.length > 0" class="grants__list">
			<li v-for="grant in grants" :key="grant.uuid" class="grants__row">
				<div class="grants__who">
					<span class="grants__name">{{ grant.display_name }}</span>
					<span class="grants__type">{{ grant.grantee_type === 'group' ? t('nextfleet', 'Group') : t('nextfleet', 'Account') }}</span>
				</div>
				<NcSelect class="grants__role"
					:model-value="roles.find((one) => one.id === grant.role) ?? null"
					:options="roles"
					:aria-label-combobox="t('nextfleet', 'Role of {name}', { name: grant.display_name })"
					:disabled="disabled || writing"
					:clearable="false"
					label="label"
					@update:model-value="recast(grant, $event)" />
				<NcButton variant="tertiary"
					:aria-label="t('nextfleet', 'Remove {name}', { name: grant.display_name })"
					:disabled="disabled || writing"
					@click="revoke(grant)">
					{{ t('nextfleet', 'Remove') }}
				</NcButton>
			</li>
		</ul>
		<p v-else class="grants__none">
			{{ t('nextfleet', 'Only you have access to this vehicle.') }}
		</p>
		<div class="grants__add">
			<NcSelectUsers v-model="picked"
				class="grants__whom"
				:options="options"
				:input-label="t('nextfleet', 'Give access to')"
				:disabled="disabled || writing"
				@search="search" />
			<NcSelect v-model="role"
				class="grants__role"
				:options="roles"
				:input-label="t('nextfleet', 'Role')"
				:disabled="disabled || writing"
				:clearable="false"
				label="label" />
			<NcButton :disabled="disabled || writing || picked === null" @click="give">
				{{ t('nextfleet', 'Give access') }}
			</NcButton>
		</div>
	</section>
</template>

<style scoped>
.grants {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
}

.grants__list {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
}

/* Name, role and Remove on one line where they fit; on a phone the name takes a line of its own
   and the role keeps Remove beside it. */
.grants__row,
.grants__add {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.grants__who {
	display: grid;
	flex: 1 1 14em;
	min-width: 0;
}

.grants__name {
	overflow-wrap: anywhere;
}

.grants__type,
.grants__none {
	color: var(--color-text-maxcontrast);
}

.grants__whom {
	flex: 1 1 14em;
}

.grants__role {
	flex: 1 1 8em;
}

/* NcSelect asks for 260px, which leaves no room for Remove beside it at 320px. Its rule has three
   classes, so this one needs as many to win. */
.grants .grants__row .grants__role {
	min-width: 0;
}
</style>
