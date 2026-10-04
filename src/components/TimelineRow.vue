<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { mdiPaperclip } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { computed, ref } from 'vue'

import { documentUrl } from '../services/api.js'
import { may } from '../utils/access.js'
import { savePaper } from '../utils/papers.js'
import { categoryWord, entryName, fieldWords, formatConsumption, formatCount, formatEnergyAmount, formatMoney, isoInstant, maintenanceWord, shortDate } from '../utils/format.js'
import { t } from '../utils/l10n.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Entry>} */
	entry: { type: Object, required: true },
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
	/**
	 * The Gap this trip's claim opened, if any. The timeline hands it over only under Logbook Mode,
	 * the only place a Gap is said (docs/features.md#logbook-mode).
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Gap|null>}
	 */
	gap: { type: Object, default: null },
	/**
	 * The documents linked to this entry. They are added in the vehicle's documents section, never
	 * here; the row only opens them.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Document[]>}
	 */
	papers: { type: Array, default: () => [] },
})

// `open` is the row itself, tapped: the Entry is edited in the sheet it was entered in (docs/ui.md).
// `reset` answers a Reading in question with "the counter was replaced".
defineEmits(['closeGap', 'open', 'reset', 'void'])

/**
 * The sheet the row opens is where an Entry is edited, voided or deleted, so a row the reader may not
 * change opens nothing (TimelineService::withMay()).
 */
const opens = computed(() => may(props.entry, 'edit'))

/** Why the last paper tapped was not saved, said on the row it was tapped on. */
const unsaved = ref('')

/** @param {import('../services/api.js').Document} paper - the one tapped */
async function save(paper) {
	unsaved.value = ''
	unsaved.value = await savePaper(props.vehicle.uuid, paper)
}

const unaccounted = computed(() => (props.gap === null ? '' : `${formatCount(props.gap.distance)} ${props.vehicle.odo_unit}`))

const trip = computed(() => props.entry.trip)
const odometer = computed(() => props.entry.odometer)
const energy = computed(() => props.entry.energy)
const maintenance = computed(() => props.entry.maintenance)
const expense = computed(() => props.entry.expense)

/** What each of EnergyService::flags() and TimelineService::withOverlaps() reads as. */
const FLAG_WORDS = {
	foreign_energy: t('nextfleet', 'Not an energy this vehicle takes'),
	no_price: t('nextfleet', 'No price'),
	overfilled: t('nextfleet', 'More than the vehicle holds'),
	overlap: t('nextfleet', 'Overlaps another trip'),
}

/**
 * A Reconciliation Trip a later trip overlaps counts that trip's kilometres twice. Said with its fix,
 * so the overlap is not said again beside it.
 */
const overtaken = computed(() => (props.entry.flags ?? []).includes('overtaken'))

// A trip is placed where it set off and a Reading where it was read (lib/Service/TimelineService.php),
// and the day is the one the offset it was entered at puts it on - not the one the reader's own
// clock is on (docs/architecture.md#time).
const day = computed(() => shortDate(props.entry.occurred_at, props.entry.occurred_at_off))

const name = computed(() => entryName(props.entry))

/**
 * The one figure the row states, and it is one the person gave. A trip's is the kilometres or the
 * counter it ended on, never both, because the two are never computed into one another
 * (docs/architecture.md#odometer-rules); the Reading a distance was counted into is the server's
 * arithmetic. A fill-up's is the amount, the one field it always has; maintenance and an expense
 * state what they cost.
 */
const figure = computed(() => {
	if (energy.value !== undefined) {
		return formatEnergyAmount(energy.value.amount, energy.value.energy)
	}
	if (maintenance.value !== undefined) {
		return maintenance.value.cost === null ? '' : formatMoney(maintenance.value.cost, props.vehicle.currency)
	}
	if (expense.value !== undefined) {
		return formatMoney(expense.value.amount, props.vehicle.currency)
	}

	const value = trip.value === undefined
		? odometer.value?.value
		: trip.value.distance ?? trip.value.end_odo

	// An hour Reading is on a chain of its own, in that chain's unit (rule 4). A trip never is. Hours
	// are the only second unit there is, and switching them off keeps their Readings.
	const unit = odometer.value?.counter === 'second' ? 'h' : props.vehicle.odo_unit

	return value === null || value === undefined
		? ''
		: `${formatCount(value)} ${unit}`
})

/**
 * What the row says beside its figure: what a journey was driven for, which is the question a
 * Fahrtenbuch asks first; where a fill-up was bought; what kind of work was done and by whom.
 */
const tail = computed(() => {
	if (trip.value !== undefined) {
		return [categoryWord(trip.value.category)]
	}
	if (energy.value !== undefined) {
		return [
			props.entry.consumption ? formatConsumption(props.entry.consumption, energy.value.energy) : '',
			energy.value.station,
			// Neither is a flag: both are true things the driver said. They are what makes a
			// fill-up close no consumption segment, so the row says them.
			energy.value.full_tank === false ? t('nextfleet', 'Partial') : '',
			energy.value.missed_previous === true ? t('nextfleet', 'Previous fill-up not recorded') : '',
		].filter(Boolean)
	}
	if (maintenance.value !== undefined) {
		return [maintenance.value.type ? maintenanceWord(maintenance.value.type) : '', maintenance.value.vendor].filter(Boolean)
	}

	return []
})

/** What a fill-up or a trip is flagged for, computed on read by the server, each as words. */
const flagWords = computed(() => (props.entry.flags ?? [])
	.filter((flag) => flag !== 'overtaken' && !(flag === 'overlap' && overtaken.value))
	.map((flag) => FLAG_WORDS[flag] ?? flag))

/**
 * A Reading that contradicts the chain before it is flagged rather than corrected
 * (docs/architecture.md#odometer-rules), and the timeline is where that question gets put
 * (docs/ui.md). An Entry's question is the Readings it left on the counter, because the Entry and
 * those Readings are one row.
 */
const ownReadings = computed(() => {
	const own = odometer.value ?? props.entry.reading

	return own ? [own] : props.entry.readings ?? []
})

const inQuestion = computed(() => ownReadings.value.some((reading) => reading.flagged === true))

/** The Reading rule 3's question is put about, for whoever may answer it (OdometerService::reset()). */
const questioned = computed(() => (may(props.entry, 'edit')
	? ownReadings.value.find((reading) => reading.flagged === true && reading.origin === 'observed') ?? null
	: null))

/** Said only under Logbook Mode (docs/features.md#logbook-mode). */
const missingWords = computed(() => (props.vehicle.logbook_mode === true ? fieldWords(props.entry.missing ?? [], props.vehicle.odo_unit) : ''))
</script>

<template>
	<li class="row" :class="{ 'row--opens': opens }">
		<!-- The whole moment, so what a machine reads off the markup is the moment the text beside
		     it states - offset and all (src/utils/format.js). -->
		<time class="row__day" :datetime="isoInstant(entry.occurred_at, entry.occurred_at_off)">{{ day }}</time>
		<!-- The name is the button, so it is what a keyboard and a screen reader reach the row by;
		     its hit area is stretched over the whole row. -->
		<button v-if="opens"
			type="button"
			class="row__open"
			@click="$emit('open', entry)">
			{{ name }}
		</button>
		<span v-else class="row__open">{{ name }}</span>
		<span class="row__figure">{{ figure }}</span>
		<span class="row__tail">
			<span v-for="(said, index) in tail" :key="index">{{ said }}</span>
			<!-- A word, not a colour: status is never colour alone (docs/ui.md). -->
			<span v-for="flag in flagWords" :key="flag" class="row__flag">{{ flag }}</span>
			<!-- Closed a Gap: the counter's arithmetic, not a journey somebody recorded (CONTEXT.md). -->
			<span v-if="trip?.reconciled === true">{{ t('nextfleet', 'Reconciled') }}</span>
			<!-- A word, not a colour: status is never colour alone (docs/ui.md). -->
			<span v-if="inQuestion" class="row__flag">{{ t('nextfleet', 'In question') }}</span>
			<template v-if="missingWords">
				<span class="row__flag">{{ t('nextfleet', 'Incomplete') }}</span>
				<span>{{ t('nextfleet', 'Still missing: {fields}', { fields: missingWords }) }}</span>
			</template>
			<!-- Only on a vehicle others use (TimelineService::withEnteredBy()). -->
			<span v-if="entry.entered_by">{{ t('nextfleet', 'Entered by {name}', { name: entry.entered_by }) }}</span>
		</span>
		<span v-if="papers.length > 0" class="row__papers">
			<template v-for="paper in papers" :key="paper.uuid">
				<a v-if="paper.name !== null"
					:href="documentUrl(vehicle.uuid, paper.uuid)"
					:aria-label="t('nextfleet', 'Open {name}', { name: paper.name })"
					:title="paper.name"
					@click.prevent="save(paper)">
					<NcIconSvgWrapper :path="mdiPaperclip" :size="20" />
				</a>
				<span v-else class="row__flag">{{ t('nextfleet', 'The file is no longer in the Files of whoever attached it') }}</span>
			</template>
			<span v-if="unsaved" class="row__flag">{{ unsaved }}</span>
		</span>
		<!-- Offered on the trip that opened the Gap, because a Gap is closed one at a time (CONTEXT.md). -->
		<span v-if="unaccounted" class="row__gap">
			<span class="row__flag">
				{{ t('nextfleet', '{distance} unaccounted for before this trip', { distance: unaccounted }) }}
			</span>
			<NcButton v-if="may(vehicle, 'log')"
				variant="tertiary"
				size="small"
				@click="$emit('closeGap', gap)">
				{{ t('nextfleet', 'Close gap') }}
			</NcButton>
		</span>
		<!-- Rule 3's two answers. A typo is fixed where the Entry was entered, so it opens the sheet. -->
		<span v-if="questioned" class="row__gap row__question">
			<span class="row__flag">{{ t('nextfleet', 'Was the counter replaced, or is this a typo?') }}</span>
			<NcButton variant="tertiary" size="small" @click="$emit('reset', questioned)">
				{{ t('nextfleet', 'Counter replaced') }}
			</NcButton>
			<NcButton variant="tertiary" size="small" @click="$emit('open', entry)">
				{{ t('nextfleet', 'Typo') }}
			</NcButton>
		</span>
		<span v-if="overtaken" class="row__gap">
			<span class="row__flag">{{ t('nextfleet', 'Reconciliation overtaken') }}</span>
			<NcButton v-if="may(entry, 'delete')"
				variant="tertiary"
				size="small"
				@click="$emit('void', entry)">
				{{ t('nextfleet', 'Void trip') }}
			</NcButton>
		</span>
	</li>
</template>

<style scoped>
.row {
	position: relative;
	/* The Gap's button sits above the row's own target; this keeps that stacking inside the row,
	   under the sticky month header. */
	isolation: isolate;
	display: grid;
	/* The day is as wide as a date and no wider, the name takes what is left, and the figure keeps
	   its own column so a column of numbers lines up. At 320 px the tail wraps under the rest
	   rather than squeezing the name to nothing (docs/ui.md). */
	grid-template-columns: max-content 1fr max-content;
	gap: 0 calc(var(--default-grid-baseline) * 2);
	align-items: baseline;
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.row--opens:hover {
	background-color: var(--color-background-hover);
}

.row__day,
.row__tail {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.row__open {
	overflow: hidden;
	min-width: 0;
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: inherit;
	font: inherit;
	text-align: start;
	text-overflow: ellipsis;
	white-space: nowrap;
}

button.row__open {
	cursor: pointer;
}

/* The whole row is the target, which is what "tap a row" means on a phone. */
button.row__open::after {
	content: '';
	position: absolute;
	inset: 0;
}

.row__open:focus-visible {
	outline: none;
}

.row__open:focus-visible::after {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.row__figure {
	font-variant-numeric: tabular-nums;
	text-align: end;
}

.row__tail {
	grid-column: 2 / -1;
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.row__flag {
	color: var(--color-warning-text);
}

.row__papers {
	/* Above the stretched row target, as the Gap's button is. */
	position: relative;
	z-index: 1;
	grid-column: 2 / -1;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	font-size: 0.9em;
}

.row__papers a {
	display: inline-flex;
	/* A finger's worth of target around a small icon (docs/ui.md). */
	min-width: var(--default-clickable-area);
	min-height: var(--default-clickable-area);
	align-items: center;
	justify-content: center;
	border-radius: var(--border-radius-element, var(--border-radius-large));
}

.row__papers a:hover,
.row__papers a:focus-visible {
	background-color: var(--color-background-hover);
}

.row__gap {
	/* Above the stretched row target, so the Gap's own button is still the Gap's. */
	position: relative;
	z-index: 1;
	grid-column: 2 / -1;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	font-size: 0.9em;
}
</style>
