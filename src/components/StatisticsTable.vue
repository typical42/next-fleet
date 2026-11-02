<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
defineProps({
	/** What the table holds, its region's accessible name. */
	label: { type: String, required: true },
	/**
	 * Column heads, then rows each headed by their label. A row may be an `item`, broken out of the
	 * one above it, or a `total` beneath the rest.
	 *
	 * @type {import('vue').PropType<{columns: string[], rows: {key: string, label: string, cells: string[], item?: boolean, total?: boolean}[]}>}
	 */
	table: { type: Object, required: true },
})
</script>

<template>
	<!-- Scrolls on its own at 320 px, so the page does not; focusable so a keyboard can. -->
	<div class="statistics__table"
		tabindex="0"
		role="region"
		:aria-label="label">
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
					:class="{ 'statistics__item': row.item, 'statistics__total': row.total }">
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

<style scoped>
.statistics__table {
	overflow-x: auto;
}

.statistics__table table {
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;
}

.statistics__table th,
.statistics__table td {
	padding: calc(var(--default-grid-baseline) * 1) calc(var(--default-grid-baseline) * 2);
	text-align: end;
	white-space: nowrap;
}

/* The row heads stay in view while the buckets scroll past them. */
.statistics__table th[scope='row'] {
	position: sticky;
	inset-inline-start: 0;
	background: var(--color-main-background);
	text-align: start;
	font-weight: normal;
}

.statistics__item th[scope='row'] {
	padding-inline-start: calc(var(--default-grid-baseline) * 6);
	color: var(--color-text-maxcontrast);
}

.statistics__total {
	border-top: 1px solid var(--color-border-dark);
	font-weight: bold;
}

.statistics__total th[scope='row'] {
	font-weight: bold;
}
</style>
