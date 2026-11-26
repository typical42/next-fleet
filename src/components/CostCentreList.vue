<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { getCurrentUser } from '@nextcloud/auth'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { onMounted, ref } from 'vue'

import { ConflictError, NotFoundError, RefusedError, createCostCentre, deleteCostCentre, listCostCentres, updateCostCentre } from '../services/api.js'
import { centreWord } from '../utils/costCentres.js'
import { t } from '../utils/l10n.js'

/**
 * The user's own list. The route also answers the lists of the owners whose vehicles they drive;
 * those are not theirs to change.
 *
 * @type {import('vue').Ref<import('../services/api.js').CostCentre[]>}
 */
const centres = ref([])
const loaded = ref(false)
const busy = ref(false)
const failure = ref('')

const code = ref('')
const name = ref('')

/** The record being renamed in its row, or null. */
const editing = ref(/** @type {string|null} */ (null))
const editCode = ref('')
const editName = ref('')

/** What each refusal of CostCentreService reads as. */
const REFUSALS = {
	code_taken: () => t('nextfleet', 'Another cost centre has this code.'),
	name_taken: () => t('nextfleet', 'Another cost centre has this name.'),
	in_use: () => t('nextfleet', 'Entries or a vehicle use this cost centre. Archive it instead.'),
}

/** @return {Promise<void>} when the list is on screen, or why not */
async function read() {
	const me = getCurrentUser()?.uid
	centres.value = (await listCostCentres()).filter((one) => one.user_id === me)
	loaded.value = true
}

onMounted(async () => {
	try {
		await read()
	} catch (error) {
		failure.value = error.message
	}
})

/**
 * One write. A conflict reads the list again, so the next click goes out under the current token;
 * so does a record deleted elsewhere, so its row goes.
 *
 * @param {() => Promise<void>} work - the write
 * @return {Promise<boolean>} whether it landed
 */
async function attempt(work) {
	busy.value = true
	failure.value = ''
	try {
		await work()
		return true
	} catch (error) {
		if (error instanceof ConflictError || error instanceof NotFoundError) {
			failure.value = error instanceof ConflictError
				? t('nextfleet', 'This cost centre was changed somewhere else. The list now shows it as it is.')
				: t('nextfleet', 'This cost centre no longer exists. The list now shows what is left.')
			editing.value = null
			await read().catch(() => {})
		} else {
			failure.value = (error instanceof RefusedError && REFUSALS[error.reason]?.()) || error.message
		}
		return false
	} finally {
		busy.value = false
	}
}

/**
 * @param {string} typedCode - the code field
 * @param {string} typedName - the name field
 * @return {{code: string|null, name: string}|null} what the server takes, or null without a name
 */
function named(typedCode, typedName) {
	if (typedName.trim() === '') {
		failure.value = t('nextfleet', 'A cost centre needs a name.')
		return null
	}

	return { code: typedCode.trim() === '' ? null : typedCode.trim(), name: typedName.trim() }
}

/**
 * Reads the list again after a write that moves a record in it: the server orders by its
 * collation, which a sort here could contradict. The write landed, so a failed read is no failure;
 * the record stays where it was put.
 *
 * @return {Promise<void>} when the list is in the server's order, or as it was
 */
async function reorder() {
	await read().catch(() => {})
}

/** @param {import('../services/api.js').CostCentre} written - the record as the server now holds it */
function replace(written) {
	centres.value = centres.value.map((one) => (one.uuid === written.uuid ? written : one))
}

/** Adds what the two fields hold; a refusal leaves them as typed. */
async function add() {
	const fields = named(code.value, name.value)
	if (fields === null) {
		return
	}
	await attempt(async () => {
		centres.value = [...centres.value, await createCostCentre(fields)]
		code.value = ''
		name.value = ''
		await reorder()
	})
}

/** @param {import('../services/api.js').CostCentre} centre - the record to rename */
function rename(centre) {
	editing.value = centre.uuid
	editCode.value = centre.code ?? ''
	editName.value = centre.name
	failure.value = ''
}

/** @param {import('../services/api.js').CostCentre} centre - the record being renamed */
async function saveName(centre) {
	const fields = named(editCode.value, editName.value)
	if (fields === null) {
		return
	}
	if (await attempt(async () => {
		replace(await updateCostCentre(centre, { ...fields, archived: centre.archived }))
		await reorder()
	})) {
		editing.value = null
	}
}

/**
 * An archived record stays on its entries and in the figures; it is no longer offered.
 *
 * @param {import('../services/api.js').CostCentre} centre - the record
 * @param {boolean} archived - what it is to be
 */
async function archive(centre, archived) {
	await attempt(async () => replace(await updateCostCentre(centre, { code: centre.code, name: centre.name, archived })))
}

/**
 * Asks nothing: the server deletes only a record nothing names, so adding it again is the way back.
 *
 * @param {import('../services/api.js').CostCentre} centre - the record
 */
async function remove(centre) {
	await attempt(async () => {
		await deleteCostCentre(centre)
		centres.value = centres.value.filter((one) => one.uuid !== centre.uuid)
	})
}
</script>

<template>
	<div class="centres">
		<NcNoteCard v-if="failure" type="error" :text="failure" />

		<ul v-if="centres.length > 0" class="centres__list">
			<li v-for="centre in centres" :key="centre.uuid" class="centres__row">
				<div v-if="editing === centre.uuid" class="centres__edit">
					<NcTextField v-model="editCode"
						:label="t('nextfleet', 'Code')"
						:disabled="busy"
						maxlength="32" />
					<NcTextField v-model="editName"
						:label="t('nextfleet', 'Name')"
						:disabled="busy"
						maxlength="255" />
					<div class="centres__actions">
						<NcButton variant="primary" :disabled="busy" @click="saveName(centre)">
							{{ t('nextfleet', 'Save') }}
						</NcButton>
						<NcButton :disabled="busy" @click="editing = null">
							{{ t('nextfleet', 'Cancel') }}
						</NcButton>
					</div>
				</div>
				<template v-else>
					<span class="centres__name">{{ centreWord(centre) }}</span>
					<!-- Each label names the row and starts with what its button shows, so voice
					     control finds the button by that. -->
					<div class="centres__actions">
						<NcButton variant="tertiary"
							:aria-label="t('nextfleet', 'Rename {name}', { name: centreWord(centre) })"
							:disabled="busy"
							@click="rename(centre)">
							{{ t('nextfleet', 'Rename') }}
						</NcButton>
						<NcButton variant="tertiary"
							:aria-label="centre.archived
								? t('nextfleet', 'Offer again: {name}', { name: centreWord(centre) })
								: t('nextfleet', 'Archive {name}', { name: centreWord(centre) })"
							:disabled="busy"
							@click="archive(centre, !centre.archived)">
							{{ centre.archived ? t('nextfleet', 'Offer again') : t('nextfleet', 'Archive') }}
						</NcButton>
						<NcButton variant="tertiary"
							:aria-label="t('nextfleet', 'Delete {name}', { name: centreWord(centre) })"
							:disabled="busy"
							@click="remove(centre)">
							{{ t('nextfleet', 'Delete') }}
						</NcButton>
					</div>
				</template>
			</li>
		</ul>
		<p v-else-if="loaded" class="centres__hint">
			{{ t('nextfleet', 'No cost centres yet.') }}
		</p>

		<div class="centres__add">
			<NcTextField v-model="code"
				:label="t('nextfleet', 'Code')"
				:helper-text="t('nextfleet', 'Optional, such as 4711')"
				:disabled="busy || !loaded"
				maxlength="32" />
			<NcTextField v-model="name"
				:label="t('nextfleet', 'Name')"
				:disabled="busy || !loaded"
				maxlength="255" />
			<NcButton :disabled="busy || !loaded" @click="add">
				{{ t('nextfleet', 'Add cost centre') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.centres__list {
	margin: calc(var(--default-grid-baseline) * 2) 0;
}

.centres__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.centres__name {
	overflow-wrap: anywhere;
}

.centres__edit,
.centres__add {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
	flex: 1 1 100%;
}

.centres__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.centres__hint {
	color: var(--color-text-maxcontrast);
}
</style>
