<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { computed, ref, watch } from 'vue'

import { attachDocument, NotFoundError } from '../services/api.js'
import { may } from '../utils/access.js'
import { DOCUMENT_KINDS, documentKindWord, nameOf } from '../utils/format.js'
import { readOwners } from '../utils/owners.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Waiting>} */
	file: { type: Object, required: true },
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle[]>} */
	vehicles: { type: Array, required: true },
	/** The vehicle a file was attached to last, picked again: a batch of receipts is one car's. */
	preferred: { type: String, default: null },
})

// `attached` names the vehicle, so the screen can pick it again for the next file. `log` asks the
// screen for a new entry to file it on: {vehicle, type, kind}.
const emit = defineEmits(['attached', 'close', 'log'])

/** Attaching takes `log` at least; which row it may hang on is each row's own rule. */
const offered = computed(() => props.vehicles
	.filter((vehicle) => may(vehicle, 'log'))
	.map((vehicle) => ({ id: vehicle.uuid, label: nameOf(vehicle), vehicle })))

/** @type {import('vue').Ref<{id: string, label: string, vehicle: import('../services/api.js').Vehicle}|null>} */
const vehicle = ref(offered.value.find((one) => one.id === props.preferred) ?? offered.value[0] ?? null)

const kinds = computed(() => DOCUMENT_KINDS.map((one) => ({ id: one, label: documentKindWord(one) })))
/** A file in the inbox is most often a receipt photographed at the pump or the garage. */
const kind = ref('receipt')

/** @type {import('vue').Ref<import('../utils/owners.js').Owner[]>} */
const owners = ref([])
/** @type {import('vue').Ref<import('../utils/owners.js').Owner|null>} */
const belongs = ref(null)
const reading = ref(false)
/** Why the rows could not be read; not the same as there being none of the session's own. */
const readFailure = ref('')
const writing = ref(false)
const failure = ref('')

/** Without `edit`, a paper must hang on a row of the session's own: the vehicle's take a manager. */
const keepsVehicle = computed(() => vehicle.value !== null && may(vehicle.value.vehicle, 'edit'))

/** Bumped by each read, so the rows of a vehicle picked before are dropped. */
let asked = 0

/**
 * The rows the paper may belong to. Someone who must pick one gets their newest entry picked: a
 * receipt is a fill-up's or an invoice's, and that is how the common case stays two taps.
 *
 * @return {Promise<void>} when they are in; a refusal leaves none
 */
async function listOwners() {
	const question = ++asked
	owners.value = []
	belongs.value = null
	readFailure.value = ''
	if (vehicle.value === null) {
		return
	}

	reading.value = true
	try {
		const read = await readOwners(vehicle.value.id)
		if (question !== asked) {
			return
		}
		owners.value = read
		if (!keepsVehicle.value) {
			belongs.value = read.find((one) => one.type !== 'booking') ?? read[0] ?? null
		}
	} catch (error) {
		// A manager can still attach it to the vehicle; anyone else waits for the retry.
		if (question === asked) {
			readFailure.value = t('nextfleet', 'The entries and bookings could not be read: {reason}', { reason: error.message })
		}
	} finally {
		if (question === asked) {
			reading.value = false
		}
	}
}

watch(vehicle, listOwners, { immediate: true })

const blocked = computed(() => writing.value
	|| reading.value
	|| vehicle.value === null
	|| (!keepsVehicle.value && belongs.value === null))

/**
 * The costs a receipt can be logged as. A fill-up only where the entry sheet offers one: on a
 * vehicle that names an energy.
 */
const costs = computed(() => [
	...((vehicle.value?.vehicle.energy_types ?? []).length > 0 ? [{ type: 'energy', label: t('nextfleet', 'New fill-up') }] : []),
	{ type: 'maintenance', label: t('nextfleet', 'New maintenance') },
	{ type: 'expense', label: t('nextfleet', 'New expense') },
])

/** @param {string} type - the kind of Entry to log the file as */
function log(type) {
	emit('log', { vehicle: /** @type {{id: string}} */ (vehicle.value).id, type, kind: kind.value })
}

/** @return {Promise<void>} when it is attached, or the refusal is on screen */
async function attach() {
	const chosen = /** @type {{id: string}} */ (vehicle.value)
	writing.value = true
	failure.value = ''
	try {
		await attachDocument(chosen.id, {
			file_id: props.file.file_id,
			kind: kind.value,
			...(belongs.value === null ? {} : { linked_type: belongs.value.type, linked_uuid: belongs.value.id }),
		})
		emit('attached', chosen.id)
	} catch (error) {
		// As in the documents section: a file shared with the person is refused like a missing one.
		failure.value = error instanceof NotFoundError
			? t('nextfleet', 'Only a file of your own can be attached, not one shared with you.')
			: error.message
	} finally {
		writing.value = false
	}
}

/** Closes the sheet, unless its answer is still on the way. */
function dismiss() {
	if (!writing.value) {
		emit('close')
	}
}
</script>

<template>
	<!-- The file's name is in the body, not the title: a long one would wrap under the close button. -->
	<NcDialog :name="t('nextfleet', 'Attach document')"
		:open="true"
		size="small"
		@update:open="dismiss">
		<NcNoteCard v-if="failure" type="error" :text="failure" />
		<p class="inbox-sheet__file">
			{{ file.name }}
		</p>
		<NcNoteCard v-if="offered.length === 0"
			type="info"
			:text="t('nextfleet', 'None of your vehicles takes papers from you.')" />
		<template v-else>
			<NcSelect v-model="vehicle"
				:options="offered"
				:input-label="t('nextfleet', 'Vehicle')"
				:clearable="false"
				label="label" />
			<NcSelect :model-value="kinds.find((one) => one.id === kind) ?? null"
				:options="kinds"
				:input-label="t('nextfleet', 'What it is')"
				:clearable="false"
				label="label"
				@update:model-value="kind = $event?.id ?? kind" />
			<NcSelect v-if="owners.length > 0"
				v-model="belongs"
				:options="owners"
				:input-label="t('nextfleet', 'Belongs to')"
				:placeholder="keepsVehicle ? t('nextfleet', 'The vehicle itself') : t('nextfleet', 'Choose an entry or a booking')"
				:clearable="keepsVehicle"
				label="label" />
			<template v-else-if="readFailure">
				<NcNoteCard type="error" :text="readFailure" />
				<NcButton @click="listOwners">
					{{ t('nextfleet', 'Try again') }}
				</NcButton>
			</template>
			<NcNoteCard v-else-if="!keepsVehicle && !reading"
				type="info"
				:text="t('nextfleet', 'You can attach a paper only to an entry or a booking of your own, and there is none yet.')" />
			<p class="inbox-sheet__lead">
				{{ t('nextfleet', 'Not entered yet? Log it from this file:') }}
			</p>
			<div class="inbox-sheet__costs">
				<NcButton v-for="cost in costs"
					:key="cost.type"
					:disabled="writing"
					@click="log(cost.type)">
					{{ cost.label }}
				</NcButton>
			</div>
		</template>

		<template #actions>
			<NcButton :disabled="writing" @click="dismiss">
				{{ t('nextfleet', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="blocked" @click="attach">
				{{ t('nextfleet', 'Attach') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.inbox-sheet__file {
	overflow-wrap: anywhere;
}

.inbox-sheet__lead {
	margin-top: calc(var(--default-grid-baseline) * 3);
	color: var(--color-text-maxcontrast);
}

/* Three across on a desk, wrapping at 320 px. */
.inbox-sheet__costs {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
