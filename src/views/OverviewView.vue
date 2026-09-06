<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcListItem from '@nextcloud/vue/components/NcListItem'

import { formatOdometer, nameOf, subtitleOf } from '../utils/format.js'

defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle[]>} */
	vehicles: { type: Array, required: true },
})

defineEmits(['new', 'select'])
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
		<ul>
			<NcListItem v-for="vehicle in vehicles"
				:key="vehicle.uuid"
				:name="nameOf(vehicle)"
				:details="formatOdometer(vehicle)"
				compact
				@click="$emit('select', vehicle.uuid)">
				<template #subname>
					{{ subtitleOf(vehicle) }}
				</template>
			</NcListItem>
		</ul>
	</div>
</template>

<style scoped>
.overview {
	padding: calc(var(--default-grid-baseline) * 4);
}
</style>
