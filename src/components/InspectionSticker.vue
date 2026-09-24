<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { getCanonicalLocale, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'

import { useVehiclesStore } from '../store/index.js'
import { formatDay, parseWhole } from '../utils/format.js'
import { INSPECTION, firstInspection, monthEnd } from '../utils/reminders.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
	/**
	 * The inspection template the vehicle's jurisdiction offers.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').ReminderTemplate>}
	 */
	template: { type: Object, required: true },
})

const emit = defineEmits(['saved'])

const store = useVehiclesStore()

// Named by the reader's locale: the sticker shows a number, the person thinks in month names.
const months = computed(() => Array.from({ length: 12 }, (_, index) => ({
	id: index + 1,
	label: new Intl.DateTimeFormat(getCanonicalLocale(), { month: 'long' }).format(new Date(2000, index, 1)),
})))

// A vehicle still waiting for its first inspection has no sticker yet worth reading; the rule
// knows the month (docs/ui.md, the due banner).
const first = props.template.first_due_months === null
	? null
	: firstInspection(props.vehicle.first_reg, props.template.first_due_months, formatDay(new Date()))
const month = ref(months.value.find((one) => one.id === first?.month) ?? null)
const year = ref(first === null ? '' : String(first.year))

const saving = ref(false)
const failure = ref('')

/** One write; the template fills the mode and the recurrence (docs/architecture.md#reminder-engine). */
async function save() {
	const number = parseWhole(year.value)
	if (month.value === null || number === null || number < 1000) {
		failure.value = t('nextfleet', 'Pick the month and year on the sticker.')
		return
	}

	saving.value = true
	failure.value = ''
	try {
		await store.remind(props.vehicle.uuid, { template_key: INSPECTION, due_date: monthEnd(number, month.value.id) })
		// Stays disabled: the host hides the question only once it has read the new reminder
		// back, and a second click before that would add a second HU/AU.
		emit('saved')
	} catch (error) {
		failure.value = error.message
		saving.value = false
	}
}
</script>

<template>
	<div class="sticker">
		<p class="sticker__question">
			{{ t('nextfleet', 'When is the next HU/AU?') }}
		</p>
		<NcNoteCard v-if="failure" type="error" :text="failure" />
		<div class="sticker__fields">
			<NcSelect v-model="month"
				:options="months"
				:input-label="t('nextfleet', 'Month')"
				:disabled="saving"
				:clearable="false"
				label="label" />
			<NcTextField v-model="year"
				class="sticker__year"
				:label="t('nextfleet', 'Year')"
				:disabled="saving"
				inputmode="numeric"
				maxlength="4" />
			<NcButton :disabled="saving" @click="save">
				{{ t('nextfleet', 'Add HU/AU reminder') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.sticker {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
}

.sticker__question {
	font-weight: bold;
}

.sticker__fields {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: calc(var(--default-grid-baseline) * 2);
}

/* NcTextField takes the whole row by default, which pushes four digits onto a line of their own. */
.sticker__year {
	flex: 0 0 8em;
}
</style>
