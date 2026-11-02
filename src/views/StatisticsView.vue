<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { computed, onMounted, ref, watch } from 'vue'

import StatisticsBars from '../components/StatisticsBars.vue'
import StatisticsBuckets from '../components/StatisticsBuckets.vue'
import StatisticsLine from '../components/StatisticsLine.vue'
import StatisticsTable from '../components/StatisticsTable.vue'
import { csvUrl, readStatistics } from '../services/api.js'
import { usePreferencesStore } from '../store/preferences.js'
import { BANDS, bandWord, barsOf, co2Of, labelsOf, tableOf } from '../utils/costs.js'
import { categoryWord, energyWord, formatConsumption, formatMoney, nameOf } from '../utils/format.js'
import { t } from '../utils/l10n.js'
import { blank, daysOf, RANGES, rangeWord } from '../utils/statistics.js'
import { averagesTableOf, bentOf, rateTableOf, segmentsTableOf, vehiclesTableOf } from '../utils/trends.js'
import { driversTableOf, TRIP_BANDS, tripBarsOf, tripTableOf, tripTitlesOf } from '../utils/trips.js'

const props = defineProps({
	/**
	 * The whole fleet, sold vehicles included: the server counts them for the time they were held.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Vehicle[]>}
	 */
	vehicles: { type: Array, required: true },
	/** The vehicle the screen opens on; empty for every vehicle. */
	vehicle: { type: String, default: '' },
})

const preferences = usePreferencesStore()
const zone = Intl.DateTimeFormat().resolvedOptions().timeZone

const ALL = { id: '', label: t('nextfleet', 'All vehicles') }
const choices = computed(() => [ALL, ...props.vehicles.map((one) => ({ id: one.uuid, label: nameOf(one) }))])
const chosen = ref(props.vehicle)
watch(() => props.vehicle, (uuid) => {
	chosen.value = uuid
})
const filter = computed({
	get: () => choices.value.find(({ id }) => id === chosen.value) ?? ALL,
	set: (choice) => {
		chosen.value = choice?.id ?? ''
	},
})
const shown = computed(() => props.vehicles.find((one) => one.uuid === chosen.value) ?? null)

const ranges = RANGES.map((id) => ({ id, label: rangeWord(id) }))
const range = ref(ranges[0])
// The reader's own year, as the server cuts it at the reader's midnight.
const thisYear = new Date().getFullYear()
const year = ref(thisYear)
/** @type {import('vue').Ref<Date|null>} */
const first = ref(null)
/** @type {import('vue').Ref<Date|null>} */
const last = ref(null)
const days = computed(() => daysOf(range.value.id, new Date(), { year: year.value, from: first.value, to: last.value }))
// Two days that are not a range are not read (daysOf()); the screen says why it stays empty.
const unpicked = computed(() => {
	if (days.value !== null) {
		return ''
	}
	return first.value === null || last.value === null
		? t('nextfleet', 'Pick the first and the last day.')
		: t('nextfleet', 'The last day is before the first.')
})

/**
 * The export menu's files, in the order the vehicle screen's sheets are.
 *
 * @type {{ table: 'trips'|'energy'|'maintenance'|'expenses', label: string }[]}
 */
const files = [
	{ table: 'trips', label: t('nextfleet', 'Trips') },
	{ table: 'energy', label: t('nextfleet', 'Fill-ups') },
	{ table: 'maintenance', label: t('nextfleet', 'Maintenance') },
	{ table: 'expenses', label: t('nextfleet', 'Expenses') },
]
// The files are a year's of one vehicle (docs/architecture.md#csv-export).
const exporting = computed(() => shown.value !== null && range.value.id === 'year')

/**
 * Saves one file of the year on screen. Buttons rather than links: a menu of NcActionLinks alone
 * is typed "navigation" and renders `role="false"` on every item, which axe refuses.
 *
 * @param {'trips'|'energy'|'maintenance'|'expenses'} table - which file
 */
function save(table) {
	const link = document.createElement('a')
	link.href = csvUrl(chosen.value, String(year.value), table)
	link.download = ''
	link.click()
}

/** @type {import('vue').Ref<import('../services/api.js').Statistics|null>} */
const answer = ref(null)
const failure = ref('')

const labels = computed(() => answer.value ? labelsOf(answer.value, undefined, zone) : [])

/**
 * @param {string} title - a chart's heading
 * @return {string} the name of its table, which runs by the answer's buckets
 */
function byBucket(title) {
	return answer.value?.bucket === 'year'
		// TRANSLATORS: {chart} is a chart's heading, such as "Cost per 100 km in EUR".
		? t('nextfleet', '{chart}, by year', { chart: title })
		// TRANSLATORS: {chart} is a chart's heading, such as "Cost per 100 km in EUR".
		: t('nextfleet', '{chart}, by month', { chart: title })
}
/**
 * The range's flags cover every bucket in it. The answer was read with the preference on screen:
 * a change rereads it.
 *
 * @param {import('../services/api.js').CostSum|undefined} range - a currency's range of costs
 * @return {string[]} what its sums leave out or how they count
 */
function notesOf(range) {
	return [
		...(preferences.reclaimVat ? [t('nextfleet', 'Net of VAT')] : []),
		...(range?.incomplete ? [t('nextfleet', 'Incomplete: a fill-up in the period has no price')] : []),
		...(range?.unstated ? [t('nextfleet', 'Entries without a VAT rate count gross')] : []),
	]
}
// Depreciation is not in the bars: it is an estimate spread over the holding period, not a
// month's spending (docs/ui.md).
const charts = computed(() => (answer.value?.costs ?? []).map((series) => {
	const table = tableOf(series, labels.value)

	return {
		series,
		bars: barsOf(series),
		table: { ...table, rows: table.rows.map((row) => ({ ...row, total: row.key === 'total' })) },
		titles: series.buckets.map((cost, i) => `${labels.value[i]}: ${cost.total === null ? t('nextfleet', 'No entries') : formatMoney(cost.total, series.currency)}`),
		notes: notesOf(series.range),
	}
}))
const names = computed(() => Object.fromEntries(props.vehicles.map((one) => [one.uuid, nameOf(one)])))
const rates = computed(() => (answer.value?.per_distance ?? []).map((series) => {
	const title = series.per === 'h'
		? t('nextfleet', 'Cost per hour in {currency}', { currency: series.currency })
		: t('nextfleet', 'Cost per 100 km in {currency}', { currency: series.currency })

	return {
		key: `${series.currency}-${series.per}`,
		title,
		values: series.buckets.map(({ value }) => value),
		titles: series.buckets.map(({ value }, i) => `${labels.value[i]}: ${value === null ? '–' : formatMoney(Math.round(value), series.currency)}`),
		table: rateTableOf(series, labels.value),
		tableLabel: byBucket(title),
		vehicles: vehiclesTableOf(series, names.value),
		// TRANSLATORS: {chart} is a chart's heading, such as "Cost per 100 km in EUR".
		vehiclesLabel: t('nextfleet', '{chart}, by vehicle', { chart: title }),
		bent: bentOf(series),
		// The rate counts the costs of its currency, so their flags hold for it; a currency's
		// flags may come from a vehicle of the other unit, which errs on telling.
		notes: notesOf(answer.value?.costs.find(({ currency }) => currency === series.currency)?.range),
	}
}))
const consumption = computed(() => (answer.value?.consumption ?? []).map((line) => {
	const segments = line.segments ?? []
	// A row per segment, headed by the day of the fill-up that closes it.
	const table = segmentsTableOf(line, undefined, zone)

	return {
		key: `${line.uuid}:${line.energy}`,
		title: t('nextfleet', '{energy} consumption', { energy: energyWord(line.energy) }),
		values: segments.map(({ value }) => value),
		titles: segments.map((segment, i) => `${table.rows[i].label}: ${formatConsumption(segment, line.energy)}`),
		table,
	}
}))
const averages = computed(() => averagesTableOf(answer.value?.consumption ?? [], names.value))
const costBands = BANDS.map((band) => ({ key: band, label: bandWord(band) }))
const tripBands = TRIP_BANDS.map((band) => ({ key: band, label: categoryWord(band) }))
const trips = computed(() => (answer.value?.distance ?? []).map((series) => {
	const title = series.per === 'h' ? t('nextfleet', 'Hours by trip category') : t('nextfleet', 'Distance by trip category')

	return {
		series,
		title,
		bars: tripBarsOf(series),
		titles: tripTitlesOf(series, labels.value),
		table: tripTableOf(series, labels.value),
		tableLabel: byBucket(title),
		// The rate's distance is the counter's (docs/architecture.md#numbers-consumption-cost-emissions).
		note: series.per === 'h'
			? t('nextfleet', 'Only the trips in the logbook count, so this can differ from the hours on the counter.')
			: t('nextfleet', 'Only the trips in the logbook count, so this can differ from the distance on the counter.'),
	}
}))
const drivers = computed(() => (answer.value?.by_driver ?? []).map((series) => ({
	per: series.per,
	title: series.per === 'h' ? t('nextfleet', 'Hours by driver') : t('nextfleet', 'Distance by driver'),
	table: driversTableOf(series),
})))
const onlyOwn = computed(() => (answer.value?.vehicles ?? []).some((one) => !one.drivers))
const kept = computed(() => (answer.value?.vehicles ?? []).some((one) => !one.costs))
const co2 = computed(() => answer.value && shown.value ? co2Of(answer.value.co2) : null)
const currencyless = computed(() => shown.value !== null && !kept.value && charts.value.length === 0)
// A vehicle whose costs are kept, or that has no currency, adds no cost series, so a blank answer
// says nothing of its records: it has no figures for the reader, not none at all.
const empty = computed(() => answer.value !== null && !currencyless.value && blank(answer.value))

/** Bumped by every read, so an answer for a range no longer on screen is dropped. */
let asked = 0

onMounted(reload)
// An edit of a vehicle can change its currency or prices; the preference may land after the
// first read (src/App.vue reads it after the fleet).
watch([chosen, days, () => shown.value?.updated_at, () => preferences.reclaimVat], reload)

/** Reads the range shown. */
async function reload() {
	const mine = ++asked
	answer.value = null
	failure.value = ''
	if (days.value === null) {
		return
	}
	try {
		const read = await readStatistics({ vehicle: chosen.value, ...days.value, tz: zone, net: preferences.reclaimVat })
		if (mine === asked) {
			answer.value = read
		}
	} catch {
		if (mine === asked) {
			failure.value = t('nextfleet', 'The statistics could not be read.')
		}
	}
}
</script>

<template>
	<div class="statistics">
		<div class="statistics__header">
			<h2>{{ t('nextfleet', 'Statistics') }}</h2>
			<!-- The year on screen, whatever is in it: an empty file is still the answer. -->
			<NcActions v-if="exporting" :menu-name="t('nextfleet', 'Export')" :force-name="true">
				<NcActionButton v-for="file in files" :key="file.table" @click="save(file.table)">
					{{ file.label }}
				</NcActionButton>
			</NcActions>
		</div>

		<div class="statistics__filters">
			<NcSelect v-model="filter"
				:options="choices"
				:input-label="t('nextfleet', 'Vehicle')"
				:clearable="false"
				label="label" />
			<NcSelect v-model="range"
				:options="ranges"
				:input-label="t('nextfleet', 'Range')"
				:clearable="false"
				label="label" />
			<div v-if="range.id === 'year'" class="statistics__year">
				<NcButton variant="tertiary" :aria-label="t('nextfleet', 'Previous year')" @click="year--">
					←
				</NcButton>
				<span class="statistics__year-number" aria-live="polite">{{ year }}</span>
				<!-- A year to come has nothing in it yet. -->
				<NcButton variant="tertiary"
					:aria-label="t('nextfleet', 'Next year')"
					:disabled="year >= thisYear"
					@click="year++">
					→
				</NcButton>
			</div>
			<template v-if="range.id === 'between'">
				<NcDateTimePickerNative v-model="first" type="date" :label="t('nextfleet', 'First day')" />
				<NcDateTimePickerNative v-model="last" type="date" :label="t('nextfleet', 'Last day')" />
			</template>
		</div>

		<NcNoteCard v-if="failure" type="error" :text="failure" />
		<NcNoteCard v-else-if="unpicked" type="info" :text="unpicked" />
		<NcLoadingIcon v-else-if="answer === null" :name="t('nextfleet', 'Loading…')" />
		<template v-else>
			<NcNoteCard v-if="shown && kept" type="info" :text="t('nextfleet', 'The owner keeps the costs of this vehicle from you.')" />
			<NcNoteCard v-else-if="kept" type="info" :text="t('nextfleet', 'Leaves out the costs of vehicles whose owner keeps them from you.')" />
			<!-- Nothing is summed across currencies, and a vehicle without one has nothing to sum in. -->
			<NcEmptyContent v-if="currencyless"
				:name="t('nextfleet', 'No currency')"
				:description="t('nextfleet', 'Costs add up only in a currency, and this vehicle has none. It is chosen in the edit sheet of the vehicle.')" />
			<NcEmptyContent v-else-if="empty"
				:name="t('nextfleet', 'Nothing to show in this range')"
				:description="t('nextfleet', 'There are no figures for you in this range. Choose another range.')" />
			<template v-if="!empty">
				<section v-for="chart in charts"
					:key="chart.series.currency"
					class="statistics__costs"
					:aria-labelledby="`statistics-costs-${chart.series.currency}`">
					<h3 :id="`statistics-costs-${chart.series.currency}`">
						{{ t('nextfleet', 'Costs in {currency}', { currency: chart.series.currency }) }}
					</h3>
					<figure class="statistics__chart">
						<StatisticsBars :bands="costBands" :bars="chart.bars" :titles="chart.titles" />
						<StatisticsBuckets :labels="labels" />
					</figure>

					<StatisticsTable :label="answer.bucket === 'year'
							? t('nextfleet', 'Costs in {currency}, by year', { currency: chart.series.currency })
							: t('nextfleet', 'Costs in {currency}, by month', { currency: chart.series.currency })"
						:table="chart.table" />
					<p v-for="note in chart.notes" :key="note" class="statistics__note">
						{{ note }}
					</p>
				</section>

				<section v-for="rate in rates"
					:key="rate.key"
					class="statistics__rate"
					:aria-labelledby="`statistics-rate-${rate.key}`">
					<h3 :id="`statistics-rate-${rate.key}`">
						{{ rate.title }}
					</h3>
					<figure class="statistics__chart">
						<StatisticsLine :values="rate.values" :titles="rate.titles" />
						<StatisticsBuckets :labels="labels" />
					</figure>
					<StatisticsTable :label="rate.tableLabel" :table="rate.table" />
					<!-- One vehicle's line is its own; every vehicle's is the fleet's, so the vehicles
					     are compared beneath it. -->
					<StatisticsTable v-if="!shown" :label="rate.vehiclesLabel" :table="rate.vehicles" />
					<p v-for="note in rate.notes" :key="note" class="statistics__note">
						{{ note }}
					</p>
					<p v-if="rate.bent" class="statistics__note">
						{{ t('nextfleet', 'Costs in a period without a distance are not in the cost per distance.') }}
					</p>
				</section>

				<!-- Outside the costs: every role reads the whole logbook. -->
				<section v-for="chart in trips"
					:key="chart.series.per"
					class="statistics__trips"
					:aria-labelledby="`statistics-trips-${chart.series.per}`">
					<h3 :id="`statistics-trips-${chart.series.per}`">
						{{ chart.title }}
					</h3>
					<figure class="statistics__chart">
						<StatisticsBars :bands="tripBands" :bars="chart.bars" :titles="chart.titles" />
						<StatisticsBuckets :labels="labels" />
					</figure>
					<StatisticsTable :label="chart.tableLabel" :table="chart.table" />
					<p class="statistics__note">
						{{ chart.note }}
					</p>
				</section>

				<section v-for="chart in drivers"
					:key="chart.per"
					class="statistics__drivers"
					:aria-labelledby="`statistics-drivers-${chart.per}`">
					<h3 :id="`statistics-drivers-${chart.per}`">
						{{ chart.title }}
					</h3>
					<StatisticsTable :label="chart.title" :table="chart.table" />
					<p v-if="onlyOwn" class="statistics__note">
						{{ t('nextfleet', 'Shows only your own trips where you neither own nor manage the vehicle.') }}
					</p>
				</section>

				<!-- Outside the costs: litres need no cost right. -->
				<section v-if="consumption.length > 0" class="statistics__consumption" aria-labelledby="statistics-consumption">
					<h3 id="statistics-consumption">
						{{ t('nextfleet', 'Consumption') }}
					</h3>
					<template v-if="shown">
						<div v-for="line in consumption" :key="line.key">
							<h4>{{ line.title }}</h4>
							<figure class="statistics__chart">
								<StatisticsLine :values="line.values" :titles="line.titles" />
							</figure>
							<StatisticsTable :label="line.title" :table="line.table" />
						</div>
					</template>
					<StatisticsTable v-else :label="t('nextfleet', 'Consumption')" :table="averages" />
				</section>

				<!-- Outside the costs: kilograms need no currency, and no cost right. -->
				<section v-if="co2" class="statistics__co2" aria-labelledby="statistics-co2">
					<h3 id="statistics-co2">
						{{ t('nextfleet', 'CO₂ (estimate)') }}
					</h3>
					<p class="statistics__co2-figure">
						{{ co2.figure }}
					</p>
					<p v-for="note in co2.notes" :key="note" class="statistics__co2-note">
						{{ note }}
					</p>
					<ul v-if="co2.sources.length > 0" class="statistics__co2-sources">
						<li v-for="source in co2.sources" :key="source.href">
							<a :href="source.href" target="_blank" rel="noreferrer noopener">{{ source.label }} ↗</a>
						</li>
					</ul>
				</section>
			</template>
		</template>
	</div>
</template>

<style scoped>
.statistics {
	padding: calc(var(--default-grid-baseline) * 4);
}

.statistics__header {
	display: flex;
	flex-wrap: wrap;
	align-items: start;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.statistics__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
	margin-block: calc(var(--default-grid-baseline) * 2);
}

.statistics__year {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.statistics__year-number {
	font-size: 1.25em;
	font-variant-numeric: tabular-nums;
}

.statistics__costs,
.statistics__rate,
.statistics__trips,
.statistics__drivers,
.statistics__consumption,
.statistics__co2 {
	margin-top: calc(var(--default-grid-baseline) * 6);
}

.statistics__costs h3,
.statistics__rate h3,
.statistics__trips h3,
.statistics__drivers h3,
.statistics__consumption h3,
.statistics__co2 h3 {
	margin-top: 0;
}

.statistics__co2-figure {
	font-size: 1.5em;
	font-variant-numeric: tabular-nums;
}

.statistics__note,
.statistics__co2-note {
	color: var(--color-text-maxcontrast);
}

.statistics__co2-sources {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 4);
	margin-top: calc(var(--default-grid-baseline) * 2);
}

.statistics__co2-sources a {
	text-decoration: underline;
}

.statistics__chart {
	margin: calc(var(--default-grid-baseline) * 4) 0;
	max-width: 720px;
}
</style>
