<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { computed, onMounted, ref, watch } from 'vue'

import InspectionSticker from './InspectionSticker.vue'
import ReminderSheet from './ReminderSheet.vue'
import { listReminders, reminderTemplates } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { dayWords, dueWords, INSPECTION, inspectionOf, light, openByUrgency, reminderTitle, stateWord } from '../utils/reminders.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

// The entry sheet is the screen's, as it is for the timeline, so Done asks the screen for it.
const emit = defineEmits(['done'])

const store = useVehiclesStore()

/** @type {import('vue').Ref<import('../services/api.js').Reminder[]>} */
const reminders = ref([])
const failure = ref('')

/**
 * The sheet: closed, open on a new reminder (`null`), or open on one of the list.
 *
 * @type {import('vue').Ref<import('../services/api.js').Reminder|null|undefined>}
 */
const opened = ref(undefined)

const open = computed(() => openByUrgency(reminders.value))

/**
 * Whether `reminders` is this vehicle's list. Until it is, the sticker question would be asked
 * of a vehicle that may well have its HU/AU already.
 */
const read = ref(false)

/** @type {import('vue').Ref<import('../services/api.js').ReminderTemplate|null>} */
const inspection = ref(null)
const asking = computed(() => read.value && inspection.value !== null && inspectionOf(reminders.value) === null)

onMounted(() => {
	reload()
	offer()
})
// Another vehicle is another list, and a counter that moved moves a reminder by km. The undo
// toast lives in the app shell and knows no banner; a reminder it brought back is one this list
// does not hold yet, and so is one the vehicle sheet added.
// An open sheet goes only with its vehicle: it holds values nobody has saved yet.
watch([() => props.vehicle.uuid, () => props.vehicle.odo_value, () => store.restored, () => store.reminded], ([uuid], [before]) => {
	if (uuid !== before) {
		opened.value = undefined
		read.value = false
	}
	reload()
})
// Whether an inspection is required turns on the country and the type (IInspectionScheme).
watch([() => props.vehicle.uuid, () => props.vehicle.jurisdiction, () => props.vehicle.vehicle_type], offer)

/**
 * The last read asked for. An answer to an older one is for a vehicle or a counter this banner
 * has moved on from, and is dropped (KpiHeader.vue does the same).
 */
let asked = 0

/** Reads the list again. Only the list states today's state and the estimate. */
async function reload() {
	const mine = ++asked
	try {
		const answer = await listReminders(props.vehicle.uuid)
		if (mine === asked) {
			reminders.value = answer
			read.value = true
			failure.value = ''
		}
	} catch (error) {
		if (mine === asked) {
			failure.value = error.message
		}
	}
}

/** The last template read asked for, dropped when older as `asked` is. */
let offered = 0

/** Reads whether this vehicle's jurisdiction requires an inspection, and its template. */
async function offer() {
	const mine = ++offered
	// The last answer was for another country, type or vehicle.
	inspection.value = null
	let found = null
	try {
		found = (await reminderTemplates(props.vehicle.uuid)).find((one) => one.key === INSPECTION) ?? null
	} catch {
		// The question is a convenience; + Reminder still offers the HU/AU where it can.
	}
	if (mine === offered) {
		inspection.value = found
	}
}

/** A write happened: the sheet is done with, and the list is a row behind. */
function written() {
	opened.value = undefined
	reload()
}

/**
 * Rule 5 (docs/architecture.md#reminder-engine): a date by counter only with enough data behind
 * it, and otherwise the banner says so rather than guessing. A date reminder needs none, and one
 * whose km the counter has reached has nothing left to estimate - the server answers null there
 * too, which is not a lack of data.
 *
 * @param {import('../services/api.js').Reminder} reminder - one open reminder
 * @return {string} the estimate, the lack of one, or nothing
 */
function estimateWords(reminder) {
	const reached = (props.vehicle.odo_value ?? null) !== null && reminder.due_odo !== null && props.vehicle.odo_value >= reminder.due_odo
	if (reminder.mode === 'date' || reached) {
		return ''
	}

	return reminder.estimate
		? t('nextfleet', 'Expected around {date}', { date: dayWords(reminder.estimate) })
		: t('nextfleet', 'Not enough data yet')
}
</script>

<template>
	<section class="due" :aria-label="t('nextfleet', 'Reminders')">
		<p v-if="failure" class="due__failure">
			{{ t('nextfleet', 'The reminders could not be read: {reason}', { reason: failure }) }}
		</p>
		<!-- No reminder rows in the timeline: what is coming lives here, what happened lives there
		     (docs/ui.md). -->
		<ul v-if="open.length > 0" class="due__list">
			<li v-for="reminder in open" :key="reminder.uuid" class="due__row">
				<button type="button" class="due__open" @click="opened = reminder">
					<!-- The colour repeats the word, never replaces it (docs/ui.md). -->
					<span class="due__state" :class="`due__state--${light(reminder)}`">
						{{ stateWord(reminder.state) }}
					</span>
					<span class="due__title">{{ reminderTitle(reminder) }}</span>
					<span class="due__when">{{ dueWords(reminder, vehicle.odo_unit) }}</span>
					<span v-if="estimateWords(reminder)" class="due__estimate">{{ estimateWords(reminder) }}</span>
				</button>
				<!-- Done is a Maintenance Record, which closes the reminder and schedules the next
				     from the work itself (docs/architecture.md#reminder-engine, rule 4). -->
				<NcButton class="due__done"
					variant="tertiary"
					:aria-label="t('nextfleet', 'Done: {title}', { title: { value: reminderTitle(reminder), escape: false } })"
					@click="emit('done', reminder.uuid)">
					{{ t('nextfleet', 'Done') }}
				</NcButton>
			</li>
		</ul>
		<!-- The store tells the banner of the new reminder, so it reads itself again. Keyed on the
		     vehicle: another one has another first registration to prefill from. -->
		<InspectionSticker v-if="asking"
			:key="vehicle.uuid"
			class="due__sticker"
			:vehicle="vehicle"
			:template="inspection" />
		<div class="due__actions">
			<NcButton variant="tertiary" @click="opened = null">
				{{ t('nextfleet', '+ Reminder') }}
			</NcButton>
		</div>

		<ReminderSheet v-if="opened !== undefined"
			:vehicle="vehicle"
			:reminder="opened"
			@close="opened = undefined"
			@saved="written" />
	</section>
</template>

<style scoped>
.due {
	margin-block: calc(var(--default-grid-baseline) * 3);
}

.due__list {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.due__row {
	display: flex;
	align-items: center;
}

.due__row + .due__row {
	border-top: 1px solid var(--color-border);
}

.due__done {
	flex: 0 0 auto;
	margin-inline-end: calc(var(--default-grid-baseline) * 2);
}

/* The whole row is the button, so a phone has a target the width of the screen. */
.due__open {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: calc(var(--default-grid-baseline) * 1) calc(var(--default-grid-baseline) * 3);
	flex: 1 1 auto;
	min-width: 0;
	margin: 0;
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
	border: none;
	border-radius: 0;
	background: none;
	color: var(--color-main-text);
	font-weight: normal;
	text-align: start;
}

.due__open:hover,
.due__open:focus-visible {
	background-color: var(--color-background-hover);
}

.due__state {
	flex: 0 0 auto;
	font-weight: bold;
}

.due__state::before {
	content: '●';
	margin-inline-end: var(--default-grid-baseline);
}

.due__state--red::before {
	color: var(--color-error);
}

.due__state--amber::before {
	color: var(--color-warning);
}

.due__state--green::before {
	color: var(--color-success);
}

.due__title {
	font-weight: bold;
}

.due__when,
.due__estimate {
	color: var(--color-text-maxcontrast);
}

.due__sticker {
	margin-block: calc(var(--default-grid-baseline) * 2);
}

.due__failure {
	color: var(--color-error-text);
}

.due__actions {
	display: flex;
	justify-content: end;
}
</style>
