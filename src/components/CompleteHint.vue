<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, ref } from 'vue'

import { usePreferencesStore } from '../store/preferences.js'
import { missingFrom } from '../utils/complete.js'
import { fieldWord, nameOf } from '../utils/format.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle[]>} */
	vehicles: { type: Array, required: true },
})

defineEmits(['select'])

const preferences = usePreferencesStore()

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

	return props.vehicles
		.filter((vehicle) => !preferences.isDismissed(vehicle.uuid))
		.map((vehicle) => ({ vehicle, missing: missingFrom(vehicle) }))
		.filter((one) => one.missing.length > 0)
})

/**
 * @param {string[]} missing - the columns still unanswered
 * @return {string} them as the edit sheet asks for them, in the order it asks
 */
function fields(missing) {
	return missing.map(fieldWord).join(', ')
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
	<div v-if="incomplete.length > 0" class="hint">
		<NcNoteCard v-if="failure" type="error" :text="failure" />
		<!-- One card for the fleet rather than one per vehicle: three of them stacked would push
		     the list nobody has finished writing off the screen (docs/ui.md). -->
		<NcNoteCard type="info" :heading="t('nextfleet', 'Some details are still missing')">
			<ul class="hint__list">
				<li v-for="one in incomplete" :key="one.vehicle.uuid" class="hint__item">
					<!-- The answer is in the vehicle's own edit sheet, so the name is the way there. -->
					<NcButton variant="tertiary" @click="$emit('select', one.vehicle.uuid)">
						{{ nameOf(one.vehicle) }}
					</NcButton>
					<span class="hint__missing">
						{{ t('nextfleet', 'Still missing: {fields}', { fields: fields(one.missing) }) }}
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
   take their own row, and none of them is cut off. */
.hint__item {
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
