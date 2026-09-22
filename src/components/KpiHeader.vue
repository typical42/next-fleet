<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { computed, onMounted, ref, watch } from 'vue'

import { readKpis } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { usePreferencesStore } from '../store/preferences.js'
import { tilesOf } from '../utils/kpis.js'
import { PERIODS, periodOf, periodWord } from '../utils/period.js'
import KpiTile from './KpiTile.vue'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

const store = useVehiclesStore()
const preferences = usePreferencesStore()

const periods = computed(() => PERIODS.map((id) => ({ id, label: periodWord(id) })))
const period = computed({
	get: () => periods.value.find(({ id }) => id === preferences.period) ?? periods.value[0],
	// A refused write keeps the choice for this session (src/store/preferences.js); the figures
	// are what the header is for, not a message about the next session.
	set: ({ id }) => preferences.choosePeriod(id).catch(() => {}),
})
/** The month the one-month period shows, as its first day. */
const month = ref(firstOfMonth(new Date()))

/** @type {import('vue').Ref<import('../services/api.js').Kpis|null>} */
const now = ref(null)
/** @type {import('vue').Ref<import('../services/api.js').Kpis|null>} */
const before = ref(null)
const failure = ref('')

const tiles = computed(() => tilesOf(props.vehicle, now.value, before.value))

/**
 * Bumped by every read, so an answer to a period or vehicle nobody is looking at any more is
 * dropped rather than shown under the new one.
 */
let asked = 0

onMounted(reload)
// An edit of the vehicle can change its currency or prices, and an undo from the toast in the shell
// (src/components/UndoToast.vue) brings back an Entry; both move the figures. A new Entry is the
// screen's to report, through reload().
// The preferences may land after the first read (src/App.vue reads them after the fleet).
watch([() => props.vehicle.uuid, () => props.vehicle.updated_at, () => period.value.id, month, () => store.restored, () => preferences.reclaimVat], reload)

/**
 * Reads the period and the one before it. The screen calls it after an entry was written.
 *
 * @return {Promise<void>} when both are in, or refused
 */
async function reload() {
	const mine = ++asked
	const { from, to, before: earlier } = periodOf(period.value.id, new Date(), monthKey(month.value))
	const net = preferences.reclaimVat
	now.value = null
	before.value = null
	failure.value = ''

	try {
		const answers = await Promise.all([
			readKpis(props.vehicle.uuid, { from, to, net }),
			readKpis(props.vehicle.uuid, { ...earlier, net }),
		])
		if (mine === asked) {
			[now.value, before.value] = answers
		}
	} catch {
		if (mine === asked) {
			failure.value = t('nextfleet', 'The figures could not be read.')
		}
	}
}

/**
 * A cleared field is no month to show, so the one shown stays.
 *
 * @param {Date|null} date - a day of the picked month
 */
function pickMonth(date) {
	if (date) {
		month.value = firstOfMonth(date)
	}
}

/**
 * @param {Date} date - any day of the month
 * @return {Date} its first day, local midnight
 */
function firstOfMonth(date) {
	return new Date(date.getFullYear(), date.getMonth(), 1)
}

/**
 * @param {Date} date - a day of the month
 * @return {string} the month as period.js takes it, `YYYY-MM`
 */
function monthKey(date) {
	return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

defineExpose({ reload })
</script>

<template>
	<section class="kpis">
		<div class="kpis__period">
			<NcSelect v-model="period"
				:options="periods"
				:input-label="t('nextfleet', 'Period')"
				:clearable="false"
				label="label" />
			<NcDateTimePickerNative v-if="period.id === 'month'"
				:model-value="month"
				type="month"
				:label="t('nextfleet', 'Month')"
				@update:model-value="pickMonth" />
		</div>
		<p v-if="failure" class="kpis__failure">
			{{ failure }}
		</p>
		<dl class="kpis__tiles">
			<KpiTile v-for="tile in tiles" :key="tile.label" :tile="tile" />
		</dl>
	</section>
</template>

<style scoped>
.kpis {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.kpis__period {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.kpis__failure {
	color: var(--color-text-maxcontrast);
}

.kpis__tiles {
	display: grid;
	/* Tiles wrap into as many columns as fit, down to one at 320 px. */
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 12em), 1fr));
	gap: calc(var(--default-grid-baseline) * 4);
	margin-top: calc(var(--default-grid-baseline) * 4);
}
</style>
