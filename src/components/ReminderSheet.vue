<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'

import { ConflictError, createReminder, dismissReminder, listReminders, reminderTemplates, snoozeReminder } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { formatDay, parseDay, parseWhole } from '../utils/format.js'
import { addDays, addMonths, templateWord } from '../utils/reminders.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
	/**
	 * The reminder to edit, or null to add one.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Reminder|null>}
	 */
	reminder: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const store = useVehiclesStore()

const editing = computed(() => props.reminder !== null)

/** @type {import('vue').Ref<import('../services/api.js').ReminderTemplate[]>} */
const templates = ref([])
const templateOptions = computed(() => templates.value.map(({ key }) => ({ id: key, label: templateWord(key) })))
/** The template a new reminder starts from; null is the user's own title. */
const template = ref(null)

const modes = computed(() => [
	{ id: 'date', label: t('nextfleet', 'Date') },
	{ id: 'odo', label: t('nextfleet', 'Counter') },
	{ id: 'either', label: t('nextfleet', 'Date or counter, whichever comes first') },
])

// The numbers stay strings on the way through, as in the vehicle sheet: what is typed is what is
// shown, and it is read once, on save.
const title = ref(props.reminder?.title ?? '')
const mode = ref(modes.value.find((one) => one.id === (props.reminder?.mode ?? 'date')) ?? null)
const dueDate = ref(parseDay(props.reminder?.due_date))
const dueOdo = ref(text(props.reminder?.due_odo))
const leadOdo = ref(text(props.reminder?.lead_odo))
const recurMonths = ref(text(props.reminder?.recur_months))
const recurOdo = ref(text(props.reminder?.recur_odo))
// The column defaults: a month before and on the day (docs/architecture.md#reminder-engine).
const warnMonthBefore = ref(props.reminder?.warn_month_before ?? true)
const warnMonthStart = ref(props.reminder?.warn_month_start ?? false)
const warnDueDate = ref(props.reminder?.warn_due_date ?? true)
const snoozeUntil = ref(null)

const byDate = computed(() => mode.value?.id !== 'odo')
const byOdo = computed(() => mode.value?.id !== 'date')
// A template names itself in the reader's language; a stored title would not translate.
const ownTitle = computed(() => editing.value ? props.reminder.template_key === null : template.value === null)
const unit = computed(() => props.vehicle.odo_unit ?? 'km')

const saving = ref(false)
const failure = ref('')
/**
 * The write that was refused because the reminder moved on, or null; the next write reads it back
 * first. Which one decides only what the note says, as in VehicleSheet.vue.
 *
 * @type {import('vue').Ref<'save'|'other'|null>}
 */
const refused = ref(null)
/**
 * The reminder the next write is checked against: the prop, until a refused write reads a newer
 * one (VehicleSheet.vue says why the prop cannot be it).
 */
const held = ref(props.reminder)

const labels = computed(() => ({
	dueOdo: t('nextfleet', 'Due at ({unit})', { unit: unit.value }),
	leadOdo: t('nextfleet', 'Warn before ({unit})', { unit: unit.value }),
	recurMonths: t('nextfleet', 'Repeat every (months)'),
	recurOdo: t('nextfleet', 'Repeat every ({unit})', { unit: unit.value }),
}))

const action = computed(() => {
	if (refused.value !== null) {
		return t('nextfleet', 'Save anyway')
	}
	if (failure.value) {
		return t('nextfleet', 'Try again')
	}

	return editing.value ? t('nextfleet', 'Save') : t('nextfleet', 'Add reminder')
})

const note = computed(() => {
	if (failure.value) {
		return { type: 'error', text: failure.value }
	}
	if (refused.value === 'save') {
		return { type: 'warning', text: t('nextfleet', 'This reminder was changed somewhere else while you had it open. Saving again writes your values over that change.') }
	}
	if (refused.value === 'other') {
		return { type: 'warning', text: t('nextfleet', 'This reminder was changed somewhere else while you had it open. Doing the same again acts on it as it now stands.') }
	}

	return { type: 'error', text: '' }
})

onMounted(async () => {
	if (editing.value) {
		return
	}

	try {
		templates.value = await reminderTemplates(props.vehicle.uuid)
	} catch {
		// The templates are a convenience; an own title still makes a reminder.
	}
})

/**
 * A template fills in what it knows, and every field it fills stays editable (docs/ui.md).
 *
 * @param {{id: string, label: string}|null} option - the template picked, or none for an own title
 */
function chooseTemplate(option) {
	template.value = option
	const chosen = templates.value.find((one) => one.key === option?.id)
	if (chosen === undefined) {
		return
	}

	mode.value = modes.value.find((one) => one.id === chosen.mode) ?? null
	recurMonths.value = text(chosen.recur_months)
	recurOdo.value = text(chosen.recur_odo)
	leadOdo.value = text(chosen.lead_odo)
}

/**
 * What the sheet writes, and only what its mode reads: the server refuses a field the engine
 * would never look at (docs/architecture.md#reminder-engine). An edit is a full replace, so an
 * emptied field travels as null.
 *
 * @return {Record<string, unknown>} the reminder's new state
 * @throws {Error} when a number field holds something that is not a whole number
 */
function fields() {
	/** @type {Record<string, unknown>} */
	const written = {}
	if (!editing.value && template.value !== null) {
		written.template_key = template.value.id
	}
	if (ownTitle.value) {
		written.title = title.value
	}
	written.mode = mode.value?.id ?? ''
	if (byDate.value) {
		written.due_date = formatDay(dueDate.value)
		written.recur_months = whole(recurMonths.value, labels.value.recurMonths)
	}
	if (byOdo.value) {
		written.due_odo = whole(dueOdo.value, labels.value.dueOdo)
		written.lead_odo = whole(leadOdo.value, labels.value.leadOdo)
		written.recur_odo = whole(recurOdo.value, labels.value.recurOdo)
	}
	if (byDate.value) {
		written.warn_month_before = warnMonthBefore.value
		written.warn_month_start = warnMonthStart.value
		written.warn_due_date = warnDueDate.value
	}

	return written
}

/**
 * One attempt at a write. A failure leaves the sheet open with every value intact (docs/ui.md);
 * a success closes it through the banner, which reads the list again.
 *
 * @param {(reminder: import('../services/api.js').Reminder|null) => Promise<unknown>} work - the
 *   write, handed the reminder at its current token
 * @param {'save'|'other'} kind - whether it writes the fields, for the note a refusal gets
 */
async function attempt(work, kind) {
	saving.value = true
	failure.value = ''
	try {
		await work(await current())
		emit('saved')
	} catch (error) {
		if (error instanceof ConflictError) {
			refused.value = kind
		} else {
			failure.value = error.message
		}
	} finally {
		saving.value = false
	}
}

/**
 * The reminder under the token that is current. After a refused write it is read back from the
 * list, since there is no read of one reminder alone.
 *
 * @return {Promise<import('../services/api.js').Reminder|null>} the reminder, or null for a new one
 */
async function current() {
	if (refused.value !== null && held.value !== null) {
		const found = (await listReminders(props.vehicle.uuid)).find((one) => one.uuid === held.value.uuid)
		if (found === undefined) {
			throw new Error(t('nextfleet', 'This reminder was deleted somewhere else.'))
		}
		held.value = found
		refused.value = null
	}

	return held.value
}

/** The write the sheet is for: the create it was opened without a reminder for, or the edit. */
function save() {
	return attempt(async (reminder) => {
		const written = fields()
		await (reminder === null
			? createReminder(props.vehicle.uuid, written)
			: store.revise(props.vehicle.uuid, 'reminder', reminder, written))
	}, 'save')
}

/** @param {string} until - the day the snooze ends */
function snooze(until) {
	return attempt((reminder) => snoozeReminder(props.vehicle.uuid, reminder, until), 'other')
}

/** Dismiss this occurrence; a recurring reminder moves on to its next one. */
function skip() {
	return attempt((reminder) => dismissReminder(props.vehicle.uuid, reminder), 'other')
}

/** Nothing asks "are you sure?": the store holds the way back and the toast offers it (docs/ui.md). */
function remove() {
	return attempt((reminder) => store.strike(props.vehicle.uuid, 'reminder', { uuid: reminder.uuid, updated_at: reminder.updated_at }), 'other')
}

/** @return {string} today where the person is, as a plain day */
function today() {
	return formatDay(new Date())
}

/** Esc in a date field belongs to the picker the browser opened (VehicleSheet.vue says why). */
function keepPicker() {}

/** A sheet that is mid-save has values nobody has an answer for yet, so it does not close. */
function requestClose() {
	if (!saving.value) {
		emit('close')
	}
}

/**
 * @param {string} typed - what the field holds
 * @param {string} label - the field, for the message
 * @return {number|null} the number, or null for an empty field
 * @throws {Error} when the field holds something else
 */
function whole(typed, label) {
	if (typed.trim() === '') {
		return null
	}
	const number = parseWhole(typed)
	if (number === null) {
		throw new Error(t('nextfleet', '{field} is not a whole number.', { field: label }))
	}

	return number
}

/**
 * @param {unknown} value - a column as the API stated it
 * @return {string} it as a field holds it
 */
function text(value) {
	return value === null || value === undefined ? '' : String(value)
}
</script>

<template>
	<NcDialog :name="editing ? t('nextfleet', 'Edit reminder') : t('nextfleet', 'New reminder')"
		:open="true"
		size="normal"
		@update:open="requestClose">
		<div class="sheet" @keydown.esc.stop="requestClose">
			<NcNoteCard v-if="note.text" :type="note.type" :text="note.text" />

			<NcSelect v-if="!editing"
				:model-value="template"
				:options="templateOptions"
				:input-label="t('nextfleet', 'Reminder')"
				:placeholder="t('nextfleet', 'Own title')"
				:disabled="saving"
				label="label"
				@update:model-value="chooseTemplate" />
			<NcTextField v-if="ownTitle"
				v-model="title"
				:label="t('nextfleet', 'Title')"
				:disabled="saving" />

			<NcSelect v-model="mode"
				:options="modes"
				:input-label="t('nextfleet', 'Due by')"
				:disabled="saving"
				:clearable="false"
				label="label" />
			<template v-if="byDate">
				<NcDateTimePickerNative v-model="dueDate"
					type="date"
					:label="t('nextfleet', 'Due date')"
					:disabled="saving"
					@keydown.esc.stop="keepPicker" />
				<NcTextField v-model="recurMonths"
					:label="labels.recurMonths"
					:disabled="saving"
					inputmode="numeric" />
			</template>
			<template v-if="byOdo">
				<NcTextField v-model="dueOdo"
					:label="labels.dueOdo"
					:disabled="saving"
					inputmode="numeric" />
				<NcTextField v-model="leadOdo"
					:label="labels.leadOdo"
					:disabled="saving"
					inputmode="numeric" />
				<NcTextField v-model="recurOdo"
					:label="labels.recurOdo"
					:disabled="saving"
					inputmode="numeric" />
			</template>
			<template v-if="byDate">
				<NcFormBoxSwitch v-model="warnMonthBefore"
					:label="t('nextfleet', 'Warn a month before')"
					:disabled="saving" />
				<NcFormBoxSwitch v-model="warnMonthStart"
					:label="t('nextfleet', 'Warn at the start of the month it is due')"
					:disabled="saving" />
				<NcFormBoxSwitch v-model="warnDueDate"
					:label="t('nextfleet', 'Warn on the due date')"
					:disabled="saving" />
			</template>

			<!-- Snooze leaves the due date alone; skipping moves a recurring reminder on to its next
			     occurrence (docs/architecture.md#reminder-engine). -->
			<div v-if="editing" class="sheet__later">
				<NcButton :disabled="saving" @click="snooze(addDays(today(), 7))">
					{{ t('nextfleet', 'Snooze 1 week') }}
				</NcButton>
				<NcButton :disabled="saving" @click="snooze(addMonths(today(), 1))">
					{{ t('nextfleet', 'Snooze 1 month') }}
				</NcButton>
				<NcDateTimePickerNative v-model="snoozeUntil"
					type="date"
					:label="t('nextfleet', 'Snooze until')"
					:disabled="saving"
					@keydown.esc.stop="keepPicker" />
				<NcButton :disabled="saving || snoozeUntil === null" @click="snooze(formatDay(snoozeUntil))">
					{{ t('nextfleet', 'Snooze until then') }}
				</NcButton>
				<NcButton :disabled="saving" @click="skip">
					{{ t('nextfleet', 'Skip this time') }}
				</NcButton>
			</div>
		</div>

		<template #actions>
			<NcButton v-if="editing"
				variant="error"
				:disabled="saving"
				@click="remove">
				{{ t('nextfleet', 'Delete reminder') }}
			</NcButton>
			<NcButton :disabled="saving" @click="requestClose">
				{{ t('nextfleet', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ action }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.sheet {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
}

.sheet__later {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: calc(var(--default-grid-baseline) * 2);
	padding-top: calc(var(--default-grid-baseline) * 2);
	border-top: 1px solid var(--color-border);
}
</style>
