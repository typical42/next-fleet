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
 * The vehicles still worth asking about, each with what it is missing. Nothing is asked before the
 * preferences have arrived: an unread list is not an empty one, and a hint somebody answered last
 * week coming back is exactly what dismissing it was for.
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
 * The vehicles whose Logbook Mode question is open. Asked once, because the mode stays off by
 * default (docs/ui.md); only where the country has a logbook ruleset, since that is a tax office
 * to keep the logbook for.
 */
const unasked = computed(() => preferences.loaded
	? props.vehicles.filter((vehicle) => preferences.hasLogbookRules(vehicle.jurisdiction ?? '')
		&& vehicle.logbook_mode !== true && may(vehicle, 'edit') && !preferences.isLogbookDismissed(vehicle.uuid))
	: [])

/** Whether an answer to the logbook question is on its way: a second tap would lose the race to it. */
const answering = ref(false)

/**
 * Switch the mode on, then count that as the answer, so a vehicle switched off again later is not
 * asked a second time. Only the switch and the token travel, so the other fields stay as the
 * server has them.
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
		// The hint stays: it is stored for every browser this user opens (docs/ui.md), so a
		// dismissal the server never took would be back on the next load anyway.
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
					<NcButton variant="tertiary" @click="$emit('select', vehicle.uuid)">
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
		<!-- One card for the fleet rather than one per vehicle: three of them stacked would push
		     the list nobody has finished writing off the screen (docs/ui.md). -->
		<NcNoteCard v-if="incomplete.length > 0" type="info" :heading="t('nextfleet', 'Some details are still missing')">
			<ul class="hint__list">
				<li v-for="one in incomplete" :key="one.vehicle.uuid" class="hint__item">
					<!-- The answer is in the vehicle's own edit sheet, so the name is the way there. -->
					<NcButton variant="tertiary" @click="$emit('select', one.vehicle.uuid)">
						{{ nameOf(one.vehicle) }}
					</NcButton>
					<span class="hint__missing">
						{{ t('nextfleet', 'Still missing: {fields}', { fields: fieldWords(one.missing) }) }}
					</span>
					<!-- Every row says "Dismiss", so the button says which vehicle it is dismissing
					     to anybody who hears the buttons rather than seeing the row. -->
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

/* The line wraps rather than scrolls: at 320 px the name, what is missing and the way out each
   take their own row, and none of them is cut off. The logbook question's rows are laid out alike
   but named apart, since a vehicle can be in both cards. */
.hint__item,
.hint__ask {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.hint__missing {
	flex: 1 1 12em;
	color: var(--color-text-maxcontrast);
}
</style>
