<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'

import { categoryWord, formatCount, isoInstant, shortDate } from '../utils/format.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Entry>} */
	entry: { type: Object, required: true },
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

const trip = computed(() => props.entry.trip)
const odometer = computed(() => props.entry.odometer)

// A trip is placed where it set off and a Reading where it was read (lib/Service/TimelineService.php),
// and the day is the one the offset it was entered at puts it on - not the one the reader's own
// clock is on (docs/architecture.md#time).
const day = computed(() => shortDate(props.entry.occurred_at, props.entry.occurred_at_off))

/**
 * What the row is called. A journey is the route it took, which is what a driver recognises it by;
 * a journey nobody labelled is what it was driven for, and one that is neither is still a journey.
 */
const name = computed(() => {
	if (trip.value === undefined) {
		return t('nextfleet', 'Counter reading')
	}

	const route = [trip.value.from_label, trip.value.to_label].filter(Boolean)

	return route.join(' → ') || trip.value.purpose || t('nextfleet', 'Trip')
})

/**
 * The one figure the row states. A trip's is what the driver gave — the kilometres, or the counter
 * it ended on — and never both, because the two are never computed into one another
 * (docs/architecture.md#odometer-rules). The Reading a distance was counted into is the server's
 * arithmetic and not a second number the driver would recognise.
 */
const figure = computed(() => {
	const value = trip.value === undefined
		? odometer.value?.value
		: trip.value.distance ?? trip.value.end_odo

	return value === null || value === undefined
		? ''
		: `${formatCount(value)} ${props.vehicle.odo_unit}`
})

/** What the journey was driven for, which is the question a Fahrtenbuch asks first. */
const tail = computed(() => (trip.value === undefined ? '' : categoryWord(trip.value.category)))

/**
 * A Reading that contradicts the chain before it is flagged rather than corrected
 * (docs/architecture.md#odometer-rules), and the timeline is where that question gets put
 * (docs/ui.md). A trip's question is the Reading it left on the counter, because the journey and
 * that Reading are one row.
 */
const inQuestion = computed(() => (trip.value === undefined ? odometer.value : props.entry.reading)?.flagged === true)
</script>

<template>
	<li class="row">
		<!-- The whole moment, so what a machine reads off the markup is the moment the text beside
		     it states - offset and all (src/utils/format.js). -->
		<time class="row__day" :datetime="isoInstant(entry.occurred_at, entry.occurred_at_off)">{{ day }}</time>
		<span class="row__name">{{ name }}</span>
		<span class="row__figure">{{ figure }}</span>
		<span class="row__tail">
			{{ tail }}
			<!-- A word, not a colour: status is never colour alone (docs/ui.md). -->
			<span v-if="inQuestion" class="row__flag">{{ t('nextfleet', 'In question') }}</span>
		</span>
	</li>
</template>

<style scoped>
.row {
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

.row__day,
.row__tail {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.row__name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
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
</style>
