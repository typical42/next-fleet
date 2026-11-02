<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
defineProps({
	/**
	 * One per bucket (labelsOf()), under the bars or the line above.
	 *
	 * @type {import('vue').PropType<string[]>}
	 */
	labels: { type: Array, required: true },
})
</script>

<template>
	<!-- The table heads say the same, so the drawing's axis stays out of the accessibility tree. -->
	<ol class="statistics__buckets"
		:style="{ gridTemplateColumns: `repeat(${labels.length}, 1fr)` }"
		aria-hidden="true">
		<li v-for="(label, i) in labels" :key="i">
			{{ label }}
		</li>
	</ol>
</template>

<style scoped>
.statistics__buckets {
	display: grid;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-align: center;
}

.statistics__buckets li {
	overflow: hidden;
	/* Twelve short month names do not fit 320 px; the first letters do, and the table spells them. */
	text-overflow: clip;
	white-space: nowrap;
}
</style>
