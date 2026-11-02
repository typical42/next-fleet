<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
defineProps({
	/**
	 * Three at most, bottom up, each coloured by its place: six are unreadable at 320 px.
	 *
	 * @type {import('vue').PropType<{key: string, label: string}[]>}
	 */
	bands: { type: Array, required: true },
	/**
	 * One per bucket (stacksOf()).
	 *
	 * @type {import('vue').PropType<import('../utils/costs.js').Bar<string>[]>}
	 */
	bars: { type: Array, required: true },
	/**
	 * What each bar says on hover; the table beside the bars is where the figures are read.
	 *
	 * @type {import('vue').PropType<string[]>}
	 */
	titles: { type: Array, required: true },
})
</script>

<template>
	<div class="statistics__stack">
		<ul class="statistics__legend">
			<li v-for="(band, i) in bands" :key="band.key">
				<span :class="['statistics__swatch', `statistics__colour--${i}`]" />
				{{ band.label }}
			</li>
		</ul>
		<!-- The table carries every figure, so the drawing stays out of the accessibility tree. -->
		<svg class="statistics__bars"
			:viewBox="`0 0 ${bars.length * 10} 100`"
			preserveAspectRatio="none"
			aria-hidden="true">
			<g v-for="(bar, i) in bars" :key="i">
				<title>{{ titles[i] }}</title>
				<rect v-for="band in bar.bands"
					:key="band.band"
					:class="['statistics__band', `statistics__colour--${bands.findIndex(({ key }) => key === band.band)}`]"
					:data-band="band.band"
					:x="i * 10 + 2"
					:y="100 - band.to * 100"
					width="6"
					:height="(band.to - band.from) * 100" />
			</g>
			<line class="statistics__baseline"
				x1="0"
				y1="100"
				:x2="bars.length * 10"
				y2="100" />
		</svg>
	</div>
</template>

<style scoped>
.statistics__legend {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 4);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.statistics__swatch {
	display: inline-block;
	width: 0.75em;
	height: 0.75em;
	border-radius: 2px;
	vertical-align: baseline;
}

.statistics__bars {
	display: block;
	width: 100%;
	height: 12em;
}

/* A stroke in the page's colour is the gap between two bands; it keeps its width however the
   drawing is stretched. */
.statistics__band {
	stroke: var(--color-main-background);
	stroke-width: 2px;
	vector-effect: non-scaling-stroke;
}

.statistics__baseline {
	stroke: var(--color-border-dark);
	stroke-width: 1px;
	vector-effect: non-scaling-stroke;
}

.statistics__colour--0 {
	fill: var(--nextfleet-band-0);
	background: var(--nextfleet-band-0);
}

.statistics__colour--1 {
	fill: var(--nextfleet-band-1);
	background: var(--nextfleet-band-1);
}

.statistics__colour--2 {
	fill: var(--nextfleet-band-2);
	background: var(--nextfleet-band-2);
}

/* The three band colours, validated as a set for colour-blind separation in each theme. */
.statistics__stack {
	--nextfleet-band-0: #2a78d6;
	--nextfleet-band-1: #eb6834;
	--nextfleet-band-2: #1baf7a;
}

body[data-theme-dark] .statistics__stack,
body[data-theme-dark-highcontrast] .statistics__stack {
	--nextfleet-band-0: #3987e5;
	--nextfleet-band-1: #d95926;
	--nextfleet-band-2: #199e70;
}

@media (prefers-color-scheme: dark) {
	body[data-theme-default] .statistics__stack {
		--nextfleet-band-0: #3987e5;
		--nextfleet-band-1: #d95926;
		--nextfleet-band-2: #199e70;
	}
}
</style>
