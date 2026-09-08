<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { useHotKey } from '@nextcloud/vue/composables/useHotKey'
import { ref } from 'vue'

import EntrySheet from '../components/EntrySheet.vue'
import VehicleSheet from '../components/VehicleSheet.vue'
import { formatOdometer, nameOf, subtitleOf } from '../utils/format.js'

defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

const entering = ref(false)
const editing = ref(false)

// `n` is the primary action of the screen in view (docs/ui.md), and the shell mounts one screen
// at a time - so the key belongs to the screen rather than to an arbiter above it. useHotKey
// already passes over a keystroke typed into a field or aimed at an open sheet, and drops the
// listener when the screen goes.
useHotKey('n', () => {
	entering.value = true
})
</script>

<template>
	<div class="vehicle">
		<!-- One primary button per screen, and on this screen it is the entry (docs/ui.md). -->
		<div class="vehicle__header">
			<div>
				<h2>{{ nameOf(vehicle) }}</h2>
				<p class="vehicle__subtitle">
					{{ subtitleOf(vehicle) }}
				</p>
			</div>
			<div class="vehicle__actions">
				<NcButton variant="primary" @click="entering = true">
					{{ t('nextfleet', 'New entry') }}
				</NcButton>
				<NcButton @click="editing = true">
					{{ t('nextfleet', 'Edit vehicle') }}
				</NcButton>
			</div>
		</div>

		<dl class="vehicle__kpis">
			<dt>{{ t('nextfleet', 'Odometer') }}</dt>
			<dd>{{ formatOdometer(vehicle) || t('nextfleet', 'Never read') }}</dd>
		</dl>

		<EntrySheet v-if="entering" :vehicle="vehicle" @close="entering = false" />
		<!-- A save is done with, so the sheet goes: what it wrote is in the store this screen
		     reads, and a sheet still open would be a second copy of the same vehicle. -->
		<VehicleSheet v-if="editing"
			:vehicle="vehicle"
			@close="editing = false"
			@saved="editing = false" />
	</div>
</template>

<style scoped>
.vehicle {
	padding: calc(var(--default-grid-baseline) * 4);
}

.vehicle__header {
	display: flex;
	align-items: start;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.vehicle__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.vehicle__subtitle {
	color: var(--color-text-maxcontrast);
}

.vehicle__kpis {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.vehicle__kpis dt {
	color: var(--color-text-maxcontrast);
}

.vehicle__kpis dd {
	/* A description list indents its values by default, which puts the figure out of line with
	   everything above it. */
	margin-inline-start: 0;
	font-size: 1.5em;
}
</style>
