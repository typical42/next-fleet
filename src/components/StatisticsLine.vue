<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed } from 'vue'

import { lineOf } from '../utils/trends.js'

const props = defineProps({
	/**
	 * One figure per step, null where there is none.
	 *
	 * @type {import('vue').PropType<(number|null)[]>}
	 */
	values: { type: Array, required: true },
	/**
	 * What each step says on hover; the table beside the line is where the figures are read.
	 *
	 * @type {import('vue').PropType<string[]>}
	 */
	titles: { type: Array, required: true },
})

const width = computed(() => props.values.length * 10)
const runs = computed(() => lineOf(props.values))
const dots = computed(() => runs.value.flat().map((dot) => ({ ...dot, title: props.titles[dot.i] })))
</script>

<template>
	<!-- The table carries every figure, so the drawing stays out of the accessibility tree. -->
	<svg class="statistics__line"
		:viewBox="`0 0 ${width} 100`"
		preserveAspectRatio="none"
		aria-hidden="true">
		<polyline v-for="(run, i) in runs"
			:key="i"
			class="statistics__stroke"
			:points="run.map(({ x, y }) => `${x},${y}`).join(' ')" />
		<!-- A line of no length with round caps is a dot that stays round however the drawing is
		     stretched; a circle would not. -->
		<line v-for="dot in dots"
			:key="dot.i"
			class="statistics__dot"
			:x1="dot.x"
			:y1="dot.y"
			:x2="dot.x"
			:y2="dot.y">
			<title>{{ dot.title }}</title>
		</line>
		<line class="statistics__baseline"
			x1="0"
			y1="100"
			:x2="width"
			y2="100" />
	</svg>
</template>

<style scoped>
/* The dots reach past the box at the highest figure and on the baseline. */
.statistics__line {
	display: block;
	width: 100%;
	height: 12em;
	overflow: visible;
}

.statistics__stroke {
	fill: none;
	stroke: var(--color-primary-element);
	stroke-width: 2px;
	vector-effect: non-scaling-stroke;
}

.statistics__dot {
	stroke: var(--color-primary-element);
	stroke-width: 8px;
	stroke-linecap: round;
	vector-effect: non-scaling-stroke;
}

.statistics__baseline {
	stroke: var(--color-border-dark);
	stroke-width: 1px;
	vector-effect: non-scaling-stroke;
}
</style>
