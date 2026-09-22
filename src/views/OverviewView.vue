<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import { useHotKey } from '@nextcloud/vue/composables/useHotKey'
import { computed, onMounted, ref } from 'vue'

import CompleteHint from '../components/CompleteHint.vue'
import { listFleetReminders } from '../services/api.js'
import { formatOdometer, nameOf, subtitleOf } from '../utils/format.js'
import { dueWords, fleetByUrgency, light, reminderTitle, stateWord } from '../utils/reminders.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle[]>} */
	vehicles: { type: Array, required: true },
})

const emit = defineEmits(['new', 'select'])

/** @type {import('vue').Ref<Array<import('../services/api.js').Reminder & {vehicle: string}>>} */
const reminders = ref([])
const failure = ref('')

const ordered = computed(() => fleetByUrgency(props.vehicles, reminders.value))

// Read once per visit: a reminder moves on the vehicle's own screen, and coming back here mounts
// the overview again.
onMounted(async () => {
	try {
		reminders.value = await listFleetReminders()
	} catch (error) {
		failure.value = error.message
	}
})

// `n` is the primary action of the screen in view (docs/ui.md), and the shell mounts one screen
// at a time - so the key belongs to the screen rather than to an arbiter above it. useHotKey
// already passes over a keystroke typed into a field or aimed at an open sheet, and drops the
// listener when the screen goes.
useHotKey('n', () => emit('new'))
</script>

<template>
	<!-- The empty state does the teaching: not "no vehicles" but the button that makes the
	     first one (docs/ui.md). -->
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
		<!-- Named, because it is not the only list on this screen any more: the hint above lists
		     vehicles too, and a row here means "a vehicle in the fleet" (tests/e2e/). -->
		<p v-if="failure" class="overview__failure">
			{{ t('nextfleet', 'The reminders could not be read: {reason}', { reason: failure }) }}
		</p>
		<ul class="overview__list">
			<NcListItem v-for="{ vehicle, next } in ordered"
				:key="vehicle.uuid"
				:name="nameOf(vehicle)"
				:details="formatOdometer(vehicle)"
				@click="$emit('select', vehicle.uuid)">
				<template #subname>
					<!-- The colour repeats the word, never replaces it (docs/ui.md). -->
					<span class="overview__light" :class="`overview__light--${light(next)}`">
						{{ next ? stateWord(next.state) : t('nextfleet', 'Nothing due') }}
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
</template>

<style scoped>
.overview {
	padding: calc(var(--default-grid-baseline) * 4);
}

.overview__failure {
	color: var(--color-error-text);
}

.overview__light {
	font-weight: bold;
}

.overview__light::before {
	content: '●';
	margin-inline-end: var(--default-grid-baseline);
}

.overview__light--red::before {
	color: var(--color-error);
}

.overview__light--amber::before {
	color: var(--color-warning);
}

.overview__light--green::before {
	color: var(--color-success);
}

.overview__next,
.overview__made {
	margin-inline-start: calc(var(--default-grid-baseline) * 2);
}
</style>
