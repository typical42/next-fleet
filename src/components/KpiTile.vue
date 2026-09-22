<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
defineProps({
	/** @type {import('vue').PropType<import('../utils/kpis.js').Tile>} */
	tile: { type: Object, required: true },
})
</script>

<template>
	<!-- A div is the one wrapper a description list allows around a term and its values. -->
	<div class="tile">
		<dt>{{ tile.label }}</dt>
		<dd class="tile__figure">
			{{ tile.figure }}
		</dd>
		<dd v-if="tile.change" class="tile__small">
			{{ tile.change }}
		</dd>
		<dd v-for="note in tile.notes" :key="note" class="tile__small">
			{{ note }}
		</dd>
	</div>
</template>

<style scoped>
.tile dt,
.tile__small {
	color: var(--color-text-maxcontrast);
}

.tile dd {
	/* A description list indents its values by default, which puts the figure out of line with
	   its label. */
	margin-inline-start: 0;
}

.tile__figure {
	font-size: 1.5em;
}
</style>
