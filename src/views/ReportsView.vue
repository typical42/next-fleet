<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'

import { getPreferences, logbookUrl } from '../services/api.js'
import { nameOf } from '../utils/format.js'

const props = defineProps({
	/**
	 * The whole fleet, sold vehicles included: a logbook is kept for years after its vehicle is
	 * gone (docs/features.md#logbook-mode).
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Vehicle[]>}
	 */
	vehicles: { type: Array, required: true },
})

/** The keys of the countries that print a logbook. @type {import('vue').Ref<string[]>} */
const printing = ref([])
const loaded = ref(false)
const failure = ref('')

// A vehicle whose country has no renderer would open a 404, so it is not offered.
const options = computed(() => props.vehicles
	.filter((vehicle) => printing.value.includes(vehicle.jurisdiction ?? ''))
	.map((vehicle) => ({ id: vehicle.uuid, label: nameOf(vehicle) })))

/** The uuid chosen; until somebody chooses, the first vehicle on offer is. */
const chosen = ref('')
const selected = computed(() => options.value.find((one) => one.id === chosen.value) ?? options.value[0] ?? null)

// The reader's own year: the export sorts trips into a year by the date they were driven on, which
// is the date the driver saw (docs/architecture.md#the-fahrtenbuch-export).
const year = ref(String(new Date().getFullYear()))
// The route answers anything but four digits with a 400.
const valid = computed(() => /^\d{4}$/.test(year.value.trim()))

const href = computed(() => selected.value && valid.value
	? logbookUrl(selected.value.id, year.value.trim())
	: undefined)

onMounted(async () => {
	try {
		const settings = await getPreferences()
		printing.value = settings.jurisdictions.filter((one) => one.logbook_export).map((one) => one.key)
		loaded.value = true
	} catch (error) {
		failure.value = error.message
	}
})
</script>

<template>
	<div class="reports">
		<h2>{{ t('nextfleet', 'Reports') }}</h2>
		<NcNoteCard v-if="failure" type="error" :text="failure" />
		<template v-else-if="loaded">
			<NcEmptyContent v-if="options.length === 0"
				:name="t('nextfleet', 'No logbook to print')"
				:description="t('nextfleet', 'A logbook prints for vehicles kept under a country that sets out how one reads. The country is chosen in the edit sheet of each vehicle.')" />
			<section v-else class="reports__logbook">
				<h3>{{ t('nextfleet', 'Logbook') }}</h3>
				<p>{{ t('nextfleet', 'The trips of one vehicle in one year, as its country asks for them, on a page your browser prints.') }}</p>
				<NcSelect :model-value="selected"
					:options="options"
					:input-label="t('nextfleet', 'Vehicle')"
					:clearable="false"
					label="label"
					@update:model-value="chosen = $event?.id ?? chosen" />
				<NcTextField v-model="year"
					:label="t('nextfleet', 'Year')"
					inputmode="numeric"
					:error="!valid"
					:helper-text="valid ? '' : t('nextfleet', 'A year is four digits')" />
				<!-- A link to the page in a tab of its own, not a request: the export is a page the
				     browser prints, and the app stays where it was. -->
				<NcButton :href="href"
					:disabled="href === undefined"
					target="_blank"
					variant="primary">
					{{ t('nextfleet', 'Open logbook') }}
				</NcButton>
			</section>
		</template>
	</div>
</template>

<style scoped>
.reports {
	padding: calc(var(--default-grid-baseline) * 4);
}

.reports__logbook {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 3);
	max-width: 400px;
}

.reports__logbook > :deep(.select),
.reports__logbook > :deep(.input-field) {
	width: 100%;
}
</style>
