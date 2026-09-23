<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, onMounted, ref, watch } from 'vue'

import KpiTile from '../components/KpiTile.vue'
import { readYear } from '../services/api.js'
import { usePreferencesStore } from '../store/preferences.js'
import { BANDS, bandWord, barsOf, tableOf } from '../utils/costs.js'
import { formatMoney, nameOf } from '../utils/format.js'
import { periodTilesOf } from '../utils/kpis.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

defineEmits(['back'])

const preferences = usePreferencesStore()

// The reader's own year, as the server cuts it at the reader's midnight.
const thisYear = new Date().getFullYear()
const year = ref(thisYear)

/** @type {import('vue').Ref<import('../services/api.js').CostYear|null>} */
const answer = ref(null)
const failure = ref('')

// Depreciation is not in the bars: it is an estimate spread over the holding period, not a month's
// spending. It is in the TCO tile, and only there.
const tiles = computed(() => answer.value ? periodTilesOf(props.vehicle, answer.value.year, null) : [])
const bars = computed(() => answer.value ? barsOf(answer.value) : [])
const table = computed(() => answer.value ? tableOf(answer.value) : null)
const priced = computed(() => answer.value?.year.cost.currency !== null)

/** What each bar says on hover; the table is where the figures are read. */
const titles = computed(() => answer.value && table.value
	? answer.value.months.map(({ cost }, i) => `${table.value.columns[i]}: ${cost.total === null ? t('nextfleet', 'No entries') : formatMoney(cost.total, cost.currency)}`)
	: [])

/** Bumped by every read, so an answer to a year nobody is looking at any more is dropped. */
let asked = 0

onMounted(reload)
// An edit of the vehicle can change its currency or prices; the preference may land after the
// first read (src/App.vue reads it after the fleet).
watch([() => props.vehicle.uuid, () => props.vehicle.updated_at, year, () => preferences.reclaimVat], reload)

/** Reads the year shown. */
async function reload() {
	const mine = ++asked
	answer.value = null
	failure.value = ''
	try {
		const read = await readYear(props.vehicle.uuid, {
			year: String(year.value),
			tz: Intl.DateTimeFormat().resolvedOptions().timeZone,
			net: preferences.reclaimVat,
		})
		if (mine === asked) {
			answer.value = read
		}
	} catch {
		if (mine === asked) {
			failure.value = t('nextfleet', 'The costs could not be read.')
		}
	}
}
</script>

<template>
	<div class="costs">
		<div class="costs__header">
			<h2>{{ t('nextfleet', 'Costs of {vehicle}', { vehicle: { value: nameOf(vehicle), escape: false } }) }}</h2>
			<NcButton @click="$emit('back')">
				{{ t('nextfleet', 'Back to the vehicle') }}
			</NcButton>
		</div>

		<div class="costs__year">
			<NcButton variant="tertiary" :aria-label="t('nextfleet', 'Previous year')" @click="year--">
				←
			</NcButton>
			<span class="costs__year-number" aria-live="polite">{{ year }}</span>
			<!-- A year to come has nothing in it yet. -->
			<NcButton variant="tertiary"
				:aria-label="t('nextfleet', 'Next year')"
				:disabled="year >= thisYear"
				@click="year++">
				→
			</NcButton>
		</div>

		<NcNoteCard v-if="failure" type="error" :text="failure" />
		<!-- Nothing is summed across currencies, and a vehicle without one has nothing to sum in. -->
		<NcEmptyContent v-else-if="answer && !priced"
			:name="t('nextfleet', 'No currency')"
			:description="t('nextfleet', 'Costs add up only in a currency, and this vehicle has none. It is chosen in the edit sheet of the vehicle.')" />
		<template v-else-if="answer && table">
			<dl class="costs__tiles">
				<KpiTile v-for="tile in tiles" :key="tile.label" :tile="tile" />
			</dl>

			<figure class="costs__chart">
				<ul class="costs__legend">
					<li v-for="band in BANDS" :key="band">
						<span :class="['costs__swatch', `costs__swatch--${band}`]" />
						{{ bandWord(band) }}
					</li>
				</ul>
				<!-- The table below carries every figure, so the drawing is left out of the
				     accessibility tree rather than read out as twelve unlabelled shapes. -->
				<svg class="costs__bars"
					viewBox="0 0 120 100"
					preserveAspectRatio="none"
					aria-hidden="true">
					<g v-for="(bar, i) in bars" :key="bar.month">
						<title>{{ titles[i] }}</title>
						<rect v-for="band in bar.bands"
							:key="band.band"
							:class="['costs__band', `costs__band--${band.band}`]"
							:data-band="band.band"
							:x="i * 10 + 2"
							:y="100 - band.to * 100"
							width="6"
							:height="(band.to - band.from) * 100" />
					</g>
					<line class="costs__baseline"
						x1="0"
						y1="100"
						x2="120"
						y2="100" />
				</svg>
				<ol class="costs__months" aria-hidden="true">
					<li v-for="(column, i) in table.columns.slice(0, 12)" :key="i">
						{{ column }}
					</li>
				</ol>
			</figure>

			<!-- Scrolls on its own at 320 px, so the page does not; focusable so a keyboard can. -->
			<div class="costs__table"
				tabindex="0"
				role="region"
				:aria-label="t('nextfleet', 'Costs by month')">
				<table>
					<thead>
						<tr>
							<td />
							<th v-for="column in table.columns" :key="column" scope="col">
								{{ column }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in table.rows"
							:key="row.key"
							:class="{ 'costs__item': row.item, 'costs__total': row.key === 'total' }">
							<th scope="row">
								{{ row.label }}
							</th>
							<td v-for="(cell, i) in row.cells" :key="i">
								{{ cell }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>
	</div>
</template>

<style scoped>
.costs {
	padding: calc(var(--default-grid-baseline) * 4);
}

.costs__header {
	display: flex;
	flex-wrap: wrap;
	align-items: start;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.costs__year {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-block: calc(var(--default-grid-baseline) * 2);
}

.costs__year-number {
	font-size: 1.25em;
	font-variant-numeric: tabular-nums;
}

.costs__tiles {
	display: grid;
	/* As in the vehicle header: as many columns as fit, down to one at 320 px. */
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 12em), 1fr));
	gap: calc(var(--default-grid-baseline) * 4);
}

.costs__chart {
	margin: calc(var(--default-grid-baseline) * 4) 0;
	max-width: 720px;
}

.costs__legend {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 4);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.costs__swatch {
	display: inline-block;
	width: 0.75em;
	height: 0.75em;
	border-radius: 2px;
	vertical-align: baseline;
}

.costs__bars {
	display: block;
	width: 100%;
	height: 12em;
}

/* A stroke in the page's colour is the gap between two bands; it keeps its width however the
   drawing is stretched. */
.costs__band {
	stroke: var(--color-main-background);
	stroke-width: 2px;
	vector-effect: non-scaling-stroke;
}

.costs__baseline {
	stroke: var(--color-border-dark);
	stroke-width: 1px;
	vector-effect: non-scaling-stroke;
}

.costs__band--energy,
.costs__swatch--energy {
	fill: var(--nextfleet-costs-energy);
	background: var(--nextfleet-costs-energy);
}

.costs__band--maintenance,
.costs__swatch--maintenance {
	fill: var(--nextfleet-costs-maintenance);
	background: var(--nextfleet-costs-maintenance);
}

.costs__band--expenses,
.costs__swatch--expenses {
	fill: var(--nextfleet-costs-expenses);
	background: var(--nextfleet-costs-expenses);
}

.costs__months {
	display: grid;
	grid-template-columns: repeat(12, 1fr);
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-align: center;
}

.costs__months li {
	overflow: hidden;
	/* Twelve short month names do not fit 320 px; the first letters do, and the table spells them. */
	text-overflow: clip;
	white-space: nowrap;
}

.costs__table {
	overflow-x: auto;
}

.costs__table table {
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;
}

.costs__table th,
.costs__table td {
	padding: calc(var(--default-grid-baseline) * 1) calc(var(--default-grid-baseline) * 2);
	text-align: end;
	white-space: nowrap;
}

/* The row heads stay in view while the months scroll past them. */
.costs__table th[scope='row'] {
	position: sticky;
	inset-inline-start: 0;
	background: var(--color-main-background);
	text-align: start;
	font-weight: normal;
}

.costs__item th[scope='row'] {
	padding-inline-start: calc(var(--default-grid-baseline) * 6);
	color: var(--color-text-maxcontrast);
}

.costs__total {
	border-top: 1px solid var(--color-border-dark);
	font-weight: bold;
}

.costs__total th[scope='row'] {
	font-weight: bold;
}
</style>

<style>
/* The three band colours, validated as a set for colour-blind separation in each theme. Unscoped:
   the theme is an attribute on the body, which a scoped selector cannot reach. */
.costs {
	--nextfleet-costs-energy: #2a78d6;
	--nextfleet-costs-maintenance: #eb6834;
	--nextfleet-costs-expenses: #1baf7a;
}

body[data-theme-dark] .costs,
body[data-theme-dark-highcontrast] .costs {
	--nextfleet-costs-energy: #3987e5;
	--nextfleet-costs-maintenance: #d95926;
	--nextfleet-costs-expenses: #199e70;
}

@media (prefers-color-scheme: dark) {
	body[data-theme-default] .costs {
		--nextfleet-costs-energy: #3987e5;
		--nextfleet-costs-maintenance: #d95926;
		--nextfleet-costs-expenses: #199e70;
	}
}
</style>
