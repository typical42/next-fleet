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

/* Nextcloud's core sets `dt` and `dd` inline-block with padding, so a label sits beside its figure
   when both fit and floats above it when they do not. A tile is a label over a figure. */
.tile dt,
.tile dd {
	display: block;
	margin-inline-start: 0;
	padding: 0;
	text-align: start;
}

.tile__figure {
	font-size: 1.5em;
}
</style>
