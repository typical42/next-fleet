<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { getCanonicalLocale } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'

import { ConflictError, deletePartner, mergePartner, NotFoundError, partnerCsvUrl, readPartnerTrips, RefusedError, updatePartner } from '../services/api.js'
import { formatDate, nameOf } from '../utils/format.js'
import { t } from '../utils/l10n.js'
import { placeDropdown } from '../utils/dropdown.js'
import { daysOf, RANGES, rangeWord } from '../utils/statistics.js'
import { distanceOf } from '../utils/trends.js'

const props = defineProps({
	/**
	 * The record, as the owner reads it: address and note included.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Partner>}
	 */
	partner: { type: Object, required: true },
	/**
	 * The whole list, which a merge picks from.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Partner[]>}
	 */
	partners: { type: Array, required: true },
	/**
	 * The whole fleet, to name the vehicle of each trip.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Vehicle[]>}
	 */
	vehicles: { type: Array, required: true },
})
// `saved` carries the record as the server now holds it, `merged` {gone, into}, `deleted` the uuid
// that went; `reload` asks for the list anew, which holds a record that moved on since it was read.
const emit = defineEmits(['back', 'saved', 'merged', 'deleted', 'reload'])

const name = ref('')
const address = ref('')
const note = ref('')
const failure = ref('')
const stale = ref(false)
const saving = ref(false)

// Another record after a merge, or a new token after a save or a new read: the record as the
// server holds it now. Two records written in one second share a token, so the uuid counts too.
watch([() => props.partner.uuid, () => props.partner.updated_at], () => {
	name.value = props.partner.name
	address.value = props.partner.address ?? ''
	note.value = props.partner.note ?? ''
	failure.value = ''
	stale.value = false
}, { immediate: true })

const named = computed(() => name.value.trim() !== '')

/**
 * @param {string} text - a field as typed
 * @return {string|null} the text, or none for a blank one
 */
function orNone(text) {
	return text.trim() === '' ? null : text
}

/**
 * @param {unknown} error - what a write threw
 * @return {string} what the screen says about it
 */
function refusal(error) {
	if (error instanceof RefusedError && error.reason === 'name_taken') {
		return t('nextfleet', 'Another partner in your list has this name. To make them one, merge them.')
	}
	if (error instanceof ConflictError) {
		stale.value = true
		return t('nextfleet', 'This partner was changed after you opened it.')
	}
	// Merged or deleted elsewhere: a new read of the list takes it off the screen.
	if (error instanceof NotFoundError) {
		stale.value = true
		return t('nextfleet', 'This partner no longer exists.')
	}
	// Not `stale`: the open record is as it was, so no new token would ever clear that.
	if (error instanceof RefusedError && error.reason === 'target_gone') {
		targetGone.value = true
		return t('nextfleet', 'The partner to merge into no longer exists.')
	}
	return error instanceof Error ? error.message : String(error)
}

/** Writes the whole record; an emptied field goes as none. */
async function save() {
	saving.value = true
	failure.value = ''
	try {
		emit('saved', await updatePartner(props.partner, { name: name.value.trim(), address: orNone(address.value), note: orNone(note.value) }))
	} catch (error) {
		failure.value = refusal(error)
	} finally {
		saving.value = false
	}
}

// Merging into itself means nothing, so the list offers only the others.
const targets = computed(() => props.partners
	.filter((one) => one.uuid !== props.partner.uuid)
	.map((one) => ({ id: one.uuid, label: one.name })))
/** @type {import('vue').Ref<{id: string, label: string}|null>} */
const target = ref(null)
/** The merge found its target merged or deleted elsewhere. */
const targetGone = ref(false)
// After a merge the screen shows the record it went into, which cannot be merged into itself.
watch(() => props.partner.uuid, () => {
	target.value = null
	targetGone.value = false
})

/** Which question is open: none, the merge's or the delete's. Each has no undo, so each asks. */
const asking = ref(/** @type {''|'merge'|'delete'} */ (''))
const answerFailure = ref('')
const answering = ref(false)

/** @param {''|'merge'|'delete'} question - the one to show, or none */
function ask(question) {
	answerFailure.value = ''
	asking.value = question
}

/** Merges this record into the one picked, which the screen then shows. */
async function merge() {
	await answer(async () => {
		const into = await mergePartner(props.partner, /** @type {{id: string}} */ (target.value).id)
		emit('merged', { gone: props.partner.uuid, into })
	})
}

/** Deletes this record. The trips keep their text. */
async function erase() {
	await answer(async () => {
		await deletePartner(props.partner)
		emit('deleted', props.partner.uuid)
	})
}

/**
 * Closes any question and asks for the list anew, which holds the record as it is now, and no
 * longer the target of a merge that found it gone.
 */
function reload() {
	asking.value = ''
	if (targetGone.value) {
		targetGone.value = false
		target.value = null
	}
	emit('reload')
}

/** @param {() => Promise<void>} write - the merge or the delete */
async function answer(write) {
	answering.value = true
	answerFailure.value = ''
	try {
		await write()
		asking.value = ''
	} catch (error) {
		answerFailure.value = refusal(error)
	} finally {
		answering.value = false
	}
}

const ranges = RANGES.map((id) => ({ id, label: rangeWord(id) }))
const range = ref(ranges[0])
const thisYear = new Date().getFullYear()
const year = ref(thisYear)
/** @type {import('vue').Ref<Date|null>} */
const first = ref(null)
/** @type {import('vue').Ref<Date|null>} */
const last = ref(null)
const days = computed(() => daysOf(range.value.id, new Date(), { year: year.value, from: first.value, to: last.value }))

/** @type {import('vue').Ref<import('../services/api.js').PartnerTrips|null>} */
const trips = ref(null)
const tripsFailure = ref('')

// The period and the partner both decide which trips are meant; a late answer for another one is dropped.
watch([days, () => props.partner.uuid], async ([asked, uuid]) => {
	trips.value = null
	tripsFailure.value = ''
	if (asked === null) {
		return
	}
	const current = () => days.value === asked && props.partner.uuid === uuid
	try {
		const answer = await readPartnerTrips(uuid, asked)
		if (current()) {
			trips.value = answer
		}
	} catch (error) {
		if (current()) {
			tripsFailure.value = error instanceof Error ? error.message : String(error)
		}
	}
}, { immediate: true })

const csv = computed(() => days.value === null ? undefined : partnerCsvUrl(props.partner.uuid, days.value))

const vehicleOf = computed(() => new Map(props.vehicles.map((one) => [one.uuid, one])))
const locale = getCanonicalLocale()

const rows = computed(() => (trips.value?.trips ?? []).map(({ vehicle_uuid: uuid, distance: moved, trip }) => {
	const vehicle = vehicleOf.value.get(uuid)
	return {
		uuid: trip.uuid,
		day: formatDate(trip.started_at, trip.started_at_off, locale),
		vehicle: vehicle ? nameOf(vehicle) : '',
		route: [trip.from_label, trip.to_label].filter(Boolean).join(' → '),
		partner: trip.partner ?? '',
		distance: moved === null ? '' : distanceOf(moved, vehicle?.odo_unit === 'h' ? 'h' : 'km', locale),
	}
}))

const totals = computed(() => (trips.value?.distance ?? []).map((sum) => ({
	per: sum.per,
	text: t('nextfleet', 'Trips: {count}, distance: {distance}', { count: sum.trips, distance: distanceOf(sum.distance, sum.per, locale) }),
})))
</script>

<template>
	<section class="partner">
		<!-- The heading first, as on every screen: above it sits the navigation's toggle. -->
		<h2>{{ partner.name }}</h2>
		<NcButton variant="tertiary" @click="emit('back')">
			{{ t('nextfleet', 'Back to the partners') }}
		</NcButton>

		<div class="partner__form">
			<NcTextField v-model="name"
				:label="t('nextfleet', 'Name')"
				:error="!named"
				:helper-text="named ? '' : t('nextfleet', 'A partner needs a name.')" />
			<NcTextArea v-model="address"
				:label="t('nextfleet', 'Address')"
				resize="vertical" />
			<NcTextArea v-model="note"
				:label="t('nextfleet', 'Note')"
				resize="vertical" />
			<p class="partner__hint">
				{{ t('nextfleet', 'Only you see the address and the note. A new name does not change what the trips say.') }}
			</p>
			<NcNoteCard v-if="failure" type="error" :text="failure" />
			<div class="partner__actions">
				<NcButton v-if="stale" @click="reload">
					{{ t('nextfleet', 'Load again') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="!named || saving || stale"
					@click="save">
					{{ t('nextfleet', 'Save') }}
				</NcButton>
			</div>
		</div>

		<section class="partner__trips">
			<h3>{{ t('nextfleet', 'Trips') }}</h3>
			<div class="partner__period">
				<NcSelect v-model="range"
					:options="ranges"
					:input-label="t('nextfleet', 'Period')"
					:clearable="false"
					:searchable="false"
					:calculate-position="placeDropdown"
					label="label" />
				<div v-if="range.id === 'year'" class="partner__year">
					<NcButton variant="tertiary" :aria-label="t('nextfleet', 'Previous year')" @click="year--">
						←
					</NcButton>
					<span aria-live="polite">{{ year }}</span>
					<NcButton variant="tertiary"
						:aria-label="t('nextfleet', 'Next year')"
						:disabled="year >= thisYear"
						@click="year++">
						→
					</NcButton>
				</div>
				<template v-if="range.id === 'between'">
					<NcDateTimePickerNative v-model="first" type="date" :label="t('nextfleet', 'First day')" />
					<NcDateTimePickerNative v-model="last" type="date" :label="t('nextfleet', 'Last day')" />
				</template>
			</div>
			<NcNoteCard v-if="tripsFailure" type="error" :text="tripsFailure" />
			<NcNoteCard v-else-if="days === null" type="info" :text="t('nextfleet', 'Pick the first and the last day.')" />
			<NcLoadingIcon v-else-if="trips === null" :name="t('nextfleet', 'Loading…')" />
			<p v-else-if="rows.length === 0">
				{{ t('nextfleet', 'No trips with this partner in this period.') }}
			</p>
			<template v-else>
				<!-- Scrolls on its own at 320 px, so the page does not; focusable so a keyboard can. -->
				<div class="partner__table"
					tabindex="0"
					role="region"
					:aria-label="t('nextfleet', 'Trips')">
					<table>
						<thead>
							<tr>
								<th>{{ t('nextfleet', 'Date') }}</th>
								<th>{{ t('nextfleet', 'Vehicle') }}</th>
								<th>{{ t('nextfleet', 'Route') }}</th>
								<!-- TRANSLATORS: the partner as the trip names it, which may be another spelling of the record's name. -->
								<th>{{ t('nextfleet', 'As entered') }}</th>
								<th>{{ t('nextfleet', 'Distance') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in rows" :key="row.uuid">
								<td>{{ row.day }}</td>
								<td>{{ row.vehicle }}</td>
								<td>{{ row.route }}</td>
								<td>{{ row.partner }}</td>
								<td class="partner__number">
									{{ row.distance }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-for="total in totals" :key="total.per" class="partner__total">
					{{ total.text }}
				</p>
			</template>
			<NcButton v-if="csv" :href="csv" download>
				{{ t('nextfleet', 'Save as CSV') }}
			</NcButton>
		</section>

		<section class="partner__merge">
			<h3>{{ t('nextfleet', 'Merge') }}</h3>
			<p class="partner__hint">
				{{ t('nextfleet', 'Is this partner in the list twice, with another spelling? Merge it into the other one.') }}
			</p>
			<NcSelect v-model="target"
				:options="targets"
				:input-label="t('nextfleet', 'Merge into')"
				searchable
				:calculate-position="placeDropdown"
				label="label" />
			<NcButton :disabled="target === null" @click="ask('merge')">
				{{ t('nextfleet', 'Merge…') }}
			</NcButton>
		</section>

		<section class="partner__delete">
			<h3>{{ t('nextfleet', 'Delete') }}</h3>
			<p class="partner__hint">
				{{ t('nextfleet', 'Delete a partner who asks you to erase their data. The trips keep what they say.') }}
			</p>
			<NcButton variant="error" @click="ask('delete')">
				{{ t('nextfleet', 'Delete…') }}
			</NcButton>
		</section>

		<NcDialog v-if="asking === 'merge' && target"
			:name="t('nextfleet', 'Merge partners')"
			:open="true"
			size="small"
			@update:open="ask('')">
			<NcNoteCard v-if="answerFailure" type="error" :text="answerFailure" />
			<p>{{ t('nextfleet', 'The trips of "{gone}" will then belong to "{into}". "{gone}" is removed from the list.', { gone: partner.name, into: target.label }) }}</p>
			<p>{{ t('nextfleet', 'What the trips say stays as it was typed. You cannot undo this.') }}</p>
			<template #actions>
				<NcButton :disabled="answering" @click="ask('')">
					{{ t('nextfleet', 'Cancel') }}
				</NcButton>
				<NcButton v-if="stale || targetGone" @click="reload">
					{{ t('nextfleet', 'Load again') }}
				</NcButton>
				<NcButton v-else
					variant="primary"
					:disabled="answering"
					@click="merge">
					{{ t('nextfleet', 'Merge') }}
				</NcButton>
			</template>
		</NcDialog>
		<NcDialog v-if="asking === 'delete'"
			:name="t('nextfleet', 'Delete partner')"
			:open="true"
			size="small"
			@update:open="ask('')">
			<NcNoteCard v-if="answerFailure" type="error" :text="answerFailure" />
			<p>{{ t('nextfleet', 'The name, the address and the note of "{name}" are deleted.', { name: partner.name }) }}</p>
			<p>{{ t('nextfleet', 'What the trips say stays, because the logbook must be kept. You cannot undo this.') }}</p>
			<template #actions>
				<NcButton :disabled="answering" @click="ask('')">
					{{ t('nextfleet', 'Cancel') }}
				</NcButton>
				<NcButton v-if="stale" @click="reload">
					{{ t('nextfleet', 'Load again') }}
				</NcButton>
				<NcButton v-else
					variant="error"
					:disabled="answering"
					@click="erase">
					{{ t('nextfleet', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</section>
</template>

<style scoped>
.partner {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 3);
}

.partner > section,
.partner__form {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 2);
	width: 100%;
	max-width: 600px;
}

/* The table has five columns and is wider than a form. */
.partner > .partner__trips {
	max-width: 100%;
}

.partner__form > :deep(.input-field),
.partner__form > :deep(.textarea),
.partner > section > :deep(.select) {
	width: 100%;
}

.partner__hint {
	color: var(--color-text-maxcontrast);
}

.partner__actions,
.partner__period,
.partner__year {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

/* Five columns do not fit 320 px; the table scrolls, the page does not. */
.partner__table {
	max-width: 100%;
	overflow-x: auto;
}

.partner__table th,
.partner__table td {
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	text-align: start;
	white-space: nowrap;
}

.partner__table .partner__number {
	text-align: end;
}
</style>
