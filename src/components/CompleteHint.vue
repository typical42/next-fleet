<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, ref } from 'vue'

import { useVehiclesStore } from '../store/index.js'
import { usePreferencesStore } from '../store/preferences.js'
import { may } from '../utils/access.js'
import { missingFrom } from '../utils/complete.js'
import { fieldWords, nameOf } from '../utils/format.js'
import { t } from '../utils/l10n.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle[]>} */
	vehicles: { type: Array, required: true },
})

defineEmits(['select'])

const preferences = usePreferencesStore()
const fleet = useVehiclesStore()

/** Why a dismissal did not stick, if it did not. */
const failure = ref('')

/**
 * The vehicles still worth asking about, each with what it is missing. Empty until the preferences
 * arrive: an unread list of dismissals is not an empty one.
 */
const incomplete = computed(() => {
	if (!preferences.loaded) {
		return []
	}

	// The answer is in the edit sheet, which takes `edit`.
	return props.vehicles
		.filter((vehicle) => may(vehicle, 'edit') && !preferences.isDismissed(vehicle.uuid))
		.map((vehicle) => ({ vehicle, missing: missingFrom(vehicle) }))
		.filter((one) => one.missing.length > 0)
})

/**
 * The vehicles whose Logbook Mode question is open: only where the country has a logbook ruleset
 * (docs/ui.md#details-that-decide-whether-it-feels-easy).
 */
const unasked = computed(() => preferences.loaded
	? props.vehicles.filter((vehicle) => preferences.hasLogbookRules(vehicle.jurisdiction ?? '')
		&& vehicle.logbook_mode !== true && may(vehicle, 'edit') && !preferences.isLogbookDismissed(vehicle.uuid))
	: [])

/** Whether an answer to the logbook question is on its way: a second tap would lose the race to it. */
const answering = ref(false)

/**
 * Switch the mode on and count that as the answer, so a vehicle switched off later is not asked
 * again. Only the switch and the token travel: an edit sheet open elsewhere keeps its fields.
 *
 * @param {import('../services/api.js').Vehicle} vehicle - the vehicle to keep a logbook with
 */
function switchOn(vehicle) {
	return answer(async () => {
		await fleet.save(/** @type {any} */ ({ uuid: vehicle.uuid, updated_at: vehicle.updated_at, logbook_mode: true }))
		await preferences.dismissLogbook(vehicle.uuid)
	})
}

/**
 * @param {() => Promise<void>} write - the answer
 * @return {Promise<void>} when it is stored, or refused and said so
 */
async function answer(write) {
	failure.value = ''
	answering.value = true
	try {
		await write()
	} catch (error) {
		failure.value = error.message
	} finally {
		answering.value = false
	}
}

/**
 * @param {string} uuid - the vehicle whose question is answered
 */
async function dismiss(uuid) {
	failure.value = ''
	try {
		await preferences.dismiss(uuid)
	} catch (error) {
		// The hint stays: a dismissal the server never took would be back on the next load.
		failure.value = error.message
	}
}
</script>

<template>
	<div v-if="incomplete.length > 0 || unasked.length > 0" class="hint">
		<NcNoteCard v-if="failure" type="error" :text="failure" />
		<NcNoteCard v-if="unasked.length > 0"
			type="info"
			:heading="t('nextfleet', 'Keep a logbook for the tax office with this vehicle?')">
			<ul class="hint__list">
				<li v-for="vehicle in unasked" :key="vehicle.uuid" class="hint__ask">
					<NcButton class="hint__name" variant="tertiary" @click="$emit('select', vehicle.uuid)">
						{{ nameOf(vehicle) }}
					</NcButton>
					<NcButton variant="secondary" :disabled="answering" @click="switchOn(vehicle)">
						{{ t('nextfleet', 'Switch Logbook mode on') }}
					</NcButton>
					<NcButton variant="tertiary"
						:disabled="answering"
						:aria-label="t('nextfleet', 'Dismiss the logbook question for {name}', { name: nameOf(vehicle) })"
						@click="answer(() => preferences.dismissLogbook(vehicle.uuid))">
						{{ t('nextfleet', 'Dismiss') }}
					</NcButton>
				</li>
			</ul>
		</NcNoteCard>
		<!-- One card for the fleet, not one per vehicle: stacked cards would push the list off
		     the screen. -->
		<NcNoteCard v-if="incomplete.length > 0" type="info" :heading="t('nextfleet', 'Some details are still missing')">
			<ul class="hint__list">
				<li v-for="one in incomplete" :key="one.vehicle.uuid" class="hint__item">
					<!-- The answer is in the vehicle's own edit sheet, so the name is the way there. -->
					<NcButton class="hint__name" variant="tertiary" @click="$emit('select', one.vehicle.uuid)">
						{{ nameOf(one.vehicle) }}
					</NcButton>
					<span class="hint__missing">
						{{ t('nextfleet', 'Still missing: {fields}', { fields: fieldWords(one.missing) }) }}
					</span>
					<!-- Every row says "Dismiss": the label names the vehicle for a screen
					     reader. -->
					<NcButton variant="tertiary"
						:aria-label="t('nextfleet', 'Dismiss the hint for {name}', { name: nameOf(one.vehicle) })"
						@click="dismiss(one.vehicle.uuid)">
						{{ t('nextfleet', 'Dismiss') }}
					</NcButton>
				</li>
			</ul>
		</NcNoteCard>
	</div>
</template>

<style scoped>
/* Wider than the gap inside a row: at 320 px a row wraps into three lines, and one vehicle has to
   stay visibly one vehicle. */
.hint__list {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 4);
}

/* Wraps rather than scrolls, so at 320 px nothing is cut off. Two names, since a vehicle can be in
   both cards. */
.hint__item,
.hint__ask {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

/* A button keeps its text on one line, so a long plate or make and model would make the card as
   wide as the name. The name is how the person picks the vehicle, so it breaks rather than being
   cut off. */
.hint__name :deep(.button-vue__text) {
	white-space: normal;
	overflow-wrap: anywhere;
	text-align: start;
}

.hint__missing {
	flex: 1 1 12em;
	color: var(--color-text-maxcontrast);
}
</style>
