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
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'

import { useVehiclesStore } from '../store/index.js'
import { parseWhole } from '../utils/format.js'

const emit = defineEmits(['close', 'created'])

const store = useVehiclesStore()

const plate = ref('')
const manufacturer = ref('')
const model = ref('')
/** @type {import('vue').Ref<{ id: string, label: string }|null>} */
const engine = ref(null)
const counter = ref('')

const saving = ref(false)
const failure = ref('')
/**
 * The vehicle, once it exists. A create sheet asks for two writes - the vehicle and its first
 * Reading - and only the second one may fail on its own, so the first is never repeated.
 *
 * @type {import('vue').Ref<import('../services/api.js').Vehicle|null>}
 */
const created = ref(null)

// An Engine is a code in the database and a word on screen (docs/ui.md#languages), and the words
// are looked up on render because the catalogue is registered by the page, not by this module.
const engines = computed(() => [
	{ id: 'petrol', label: t('nextfleet', 'Petrol') },
	{ id: 'diesel', label: t('nextfleet', 'Diesel') },
	{ id: 'lpg', label: t('nextfleet', 'LPG') },
	{ id: 'cng', label: t('nextfleet', 'CNG') },
	{ id: 'electric', label: t('nextfleet', 'Electric') },
	{ id: 'hybrid', label: t('nextfleet', 'Hybrid') },
])

// Which of the two writes failed decides what the sheet has to say, because only one of them can
// be repeated: the vehicle is already there.
const note = computed(() => (created.value
	? t('nextfleet', 'The vehicle was added, but its counter reading was not: {reason}', { reason: failure.value })
	: failure.value))

/**
 * Four fields, then the counter as a first Reading. A failure leaves the sheet open with every
 * value intact (docs/ui.md); if the vehicle is already there, the retry writes only the Reading.
 */
async function save() {
	// The counter is optional and it is judged before anything is written: a field nobody can
	// read is a question for the driver, not a vehicle created with the answer thrown away.
	const number = counter.value.trim() === '' ? null : parseWhole(counter.value)
	if (counter.value.trim() !== '' && number === null) {
		failure.value = t('nextfleet', 'That is not a counter reading.')

		return
	}

	saving.value = true
	failure.value = ''
	try {
		if (created.value === null) {
			created.value = await store.create({
				plate: plate.value,
				manufacturer: manufacturer.value,
				model: model.value,
				engine: engine.value?.id ?? '',
			})
		}

		if (number !== null) {
			await store.record(created.value.uuid, {
				value: number,
				read_at: Math.floor(Date.now() / 1000),
				read_at_off: -new Date().getTimezoneOffset(),
			})
		}

		emit('created', created.value)
	} catch (error) {
		failure.value = error.message
	} finally {
		saving.value = false
	}
}

/** A sheet that is mid-save has values nobody has an answer for yet, so it does not close. */
function requestClose() {
	if (!saving.value) {
		emit('close')
	}
}
</script>

<template>
	<NcDialog :name="t('nextfleet', 'New vehicle')"
		:open="true"
		size="small"
		@update:open="requestClose">
		<NcNoteCard v-if="failure"
			:type="created ? 'warning' : 'error'"
			:text="note" />

		<!-- Four fields, not twelve: the rest arrives through the vehicle's own screen
		     (docs/ui.md). -->
		<NcTextField v-model="plate"
			:label="t('nextfleet', 'Registration plate')"
			:disabled="saving || !!created"
			autofocus />
		<NcTextField v-model="manufacturer"
			:label="t('nextfleet', 'Manufacturer')"
			:disabled="saving || !!created" />
		<NcTextField v-model="model"
			:label="t('nextfleet', 'Model')"
			:disabled="saving || !!created" />
		<NcSelect v-model="engine"
			:options="engines"
			:input-label="t('nextfleet', 'Engine')"
			:disabled="saving || !!created"
			label="label" />
		<NcTextField v-model="counter"
			:label="t('nextfleet', 'Counter reading')"
			:disabled="saving"
			inputmode="decimal" />

		<template #actions>
			<NcButton :disabled="saving" @click="requestClose">
				{{ t('nextfleet', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ failure ? t('nextfleet', 'Try again') : t('nextfleet', 'Add vehicle') }}
			</NcButton>
		</template>
	</NcDialog>
</template>
