<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ref } from 'vue'

import { useVehiclesStore } from '../store/index.js'
import { parseWhole } from '../utils/format.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

const emit = defineEmits(['close'])

const store = useVehiclesStore()

// Prefilled with the counter as it stands and visibly editable, because a driver reads the last
// three digits off the dashboard and not the whole number (docs/ui.md).
const value = ref(props.vehicle.odo_value === null || props.vehicle.odo_value === undefined
	? ''
	: String(props.vehicle.odo_value))
const saving = ref(false)
const failure = ref('')

/**
 * Records the reading. A refusal leaves the sheet open with every value intact and offers the
 * retry - nothing is written anywhere else, because the open sheet is the queue (docs/ui.md).
 */
async function save() {
	const number = parseWhole(value.value)
	if (number === null) {
		failure.value = t('nextfleet', 'That is not a counter reading.')

		return
	}

	saving.value = true
	failure.value = ''
	try {
		await store.record(props.vehicle.uuid, {
			value: number,
			// The clock is the reader's: when they read it, and the offset they read it at
			// (docs/architecture.md#time). The server's own clock is when it heard about it.
			read_at: Math.floor(Date.now() / 1000),
			read_at_off: -new Date().getTimezoneOffset(),
		})
		emit('close')
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
	<NcDialog :name="t('nextfleet', 'Odometer')"
		:open="true"
		size="small"
		@update:open="requestClose">
		<NcNoteCard v-if="failure" type="error" :text="failure" />
		<!-- NcDialog closes itself on Escape, but through a useHotKey, which passes over every
		     keystroke aimed at a text field - and this sheet is one field with the caret already
		     in it. So the key is caught where the dialog cannot see it and stopped there, which
		     keeps the mid-save guard on the one way out. -->
		<NcTextField v-model="value"
			:label="t('nextfleet', 'Counter reading')"
			:disabled="saving"
			inputmode="decimal"
			autofocus
			@keydown.esc.stop="requestClose" />
		<template #actions>
			<NcButton :disabled="saving" @click="requestClose">
				{{ t('nextfleet', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ failure ? t('nextfleet', 'Try again') : t('nextfleet', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>
