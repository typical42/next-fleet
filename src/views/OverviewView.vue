<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import { useHotKey } from '@nextcloud/vue/composables/useHotKey'
import { computed, onMounted, ref } from 'vue'

import CompleteHint from '../components/CompleteHint.vue'
import { listFleetReminders } from '../services/api.js'
import { formatOdometer, nameOf, subtitleOf } from '../utils/format.js'
import { holderWords } from '../utils/pool.js'
import { dueWords, fleetByUrgency, light, reminderTitle, stateWord } from '../utils/reminders.js'
import { t } from '../utils/l10n.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle[]>} */
	vehicles: { type: Array, required: true },
	/**
	 * The vehicles disposed of, which the fleet above leaves out. Listed apart so that a sale
	 * recorded by mistake can be opened and set back, and a sold car's trips still corrected.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Vehicle[]>}
	 */
	disposed: { type: Array, default: () => [] },
})

const emit = defineEmits(['new', 'select'])

/** @type {import('vue').Ref<Array<import('../services/api.js').Reminder & {vehicle: string}>>} */
const reminders = ref([])
const failure = ref('')

// Overdue is read off the clock when the list is worked out, so once per visit like the reminders.
const ordered = computed(() => fleetByUrgency(props.vehicles, reminders.value)
	.map((row) => ({ ...row, holder: holderWords(row.vehicle) })))

// Read once per visit: a reminder moves on the vehicle's own screen, and coming back here mounts
// the overview again.
onMounted(async () => {
	try {
		reminders.value = await listFleetReminders()
	} catch (error) {
		failure.value = error.message
	}
})

// `n` starts the screen's primary action (docs/ui.md#details-that-decide-whether-it-feels-easy).
// The shell mounts one screen at a time, so the key belongs here. useHotKey skips a keystroke
// typed into a field or an open sheet.
useHotKey('n', () => emit('new'))
</script>

<template>
	<!-- The empty state teaches: the button that makes the first vehicle (docs/ui.md). -->
	<NcEmptyContent v-if="vehicles.length === 0"
		:name="t('nextfleet', 'No vehicles yet')"
		:description="t('nextfleet', 'Everything else hangs off a vehicle, so that is where a logbook starts.')">
		<template #action>
			<NcButton variant="primary" @click="$emit('new')">
				{{ t('nextfleet', 'New vehicle') }}
			</NcButton>
		</template>
	</NcEmptyContent>
	<div v-else class="overview">
		<h2>{{ t('nextfleet', 'Vehicles') }}</h2>
		<!-- Creating a vehicle asks for four fields; the rest is asked for here, once there is a
		     fleet to ask it about (docs/ui.md). -->
		<CompleteHint :vehicles="vehicles" @select="$emit('select', $event)" />
		<p v-if="failure" class="overview__failure">
			{{ t('nextfleet', 'The reminders could not be read: {reason}', { reason: failure }) }}
		</p>
		<!-- Named apart from the hint's list: a row here is a fleet vehicle (tests/e2e/). -->
		<ul class="overview__list">
			<NcListItem v-for="{ vehicle, next, holder } in ordered"
				:key="vehicle.uuid"
				:name="nameOf(vehicle)"
				:details="formatOdometer(vehicle)"
				@click="$emit('select', vehicle.uuid)">
				<template #subname>
					<!-- The colour repeats the word, never replaces it (docs/ui.md). -->
					<span class="overview__light" :class="`overview__light--${light(next)}`">
						{{ next ? stateWord(next.state) : t('nextfleet', 'Nothing due') }}
					</span>
					<!-- First after the light: who has the car is what somebody about to take it
					     opens the app for. -->
					<span v-if="holder"
						class="overview__holder"
						:class="{ 'overview__holder--overdue': holder.overdue }">
						{{ holder.words }}
					</span>
					<!-- Before what comes due: whose car it is outranks the next reminder, which
					     the vehicle's own screen shows again. -->
					<span v-if="vehicle.owned_by" class="overview__owner">
						{{ t('nextfleet', 'Owned by {name}', { name: vehicle.owned_by }) }}
					</span>
					<span v-if="next" class="overview__next">
						{{ reminderTitle(next) }} · {{ dueWords(next, vehicle.odo_unit) }}
					</span>
					<span v-if="subtitleOf(vehicle)" class="overview__made">
						{{ subtitleOf(vehicle) }}
					</span>
				</template>
			</NcListItem>
		</ul>
	</div>
	<!-- Apart from the fleet and after it, also below the empty state: a fleet of sold vehicles is
	     still taught to add one, and the sold ones still open. -->
	<section v-if="disposed.length > 0" class="overview overview--disposed">
		<h3>{{ t('nextfleet', 'Disposed of') }}</h3>
		<ul class="overview__disposed">
			<NcListItem v-for="vehicle in disposed"
				:key="vehicle.uuid"
				:name="nameOf(vehicle)"
				:details="formatOdometer(vehicle)"
				@click="$emit('select', vehicle.uuid)">
				<template v-if="subtitleOf(vehicle)" #subname>
					{{ subtitleOf(vehicle) }}
				</template>
			</NcListItem>
		</ul>
	</section>
</template>

<style scoped>
.overview {
	padding: calc(var(--default-grid-baseline) * 4);
}

.overview--disposed {
	padding-block-start: 0;
}

.overview__failure {
	color: var(--color-error-text);
}

.overview__light {
	font-weight: bold;
}

/* A drawn dot rather than a ● in the text: the glyph's size is the font's, and a screen reader
   would read it out beside the word that already says the state. The element colours come first:
   on NC 34 `--color-warning` and its kin are pale backgrounds, and NC 31 has only those, vivid. */
.overview__light::before {
	content: '';
	display: inline-block;
	inline-size: 0.75em;
	block-size: 0.75em;
	border-radius: 50%;
	background-color: currentColor;
	margin-inline-end: var(--default-grid-baseline);
}

.overview__light--red::before {
	color: var(--color-element-error, var(--color-error));
}

.overview__light--amber::before {
	color: var(--color-element-warning, var(--color-warning));
}

.overview__light--green::before {
	color: var(--color-element-success, var(--color-success));
}

.overview__holder--overdue {
	/* Text, so the text token: the element one is a fill and fails contrast as a letter colour. */
	color: var(--color-warning-text);
	font-weight: bold;
}

.overview__holder,
.overview__owner,
.overview__next,
.overview__made {
	margin-inline-start: calc(var(--default-grid-baseline) * 2);
}

/* NcListItem cuts the subname to one line, which at 320 px hides what comes due. Here it wraps a
   part at a time, and the row grows to fit. */
.overview__list :deep(.list-item__anchor) {
	height: auto;
	min-height: var(--list-item-height);
}

.overview__list :deep(.list-item-content__subname) {
	white-space: normal;
}

.overview__list :deep(.list-item-content__subname) > span {
	display: inline-block;
	max-width: 100%;
}
</style>
