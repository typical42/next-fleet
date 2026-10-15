<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcListItem from '@nextcloud/vue/components/NcListItem'
import { computed, onMounted, onUpdated } from 'vue'

import { nameOf, secondLabelOf } from '../utils/format.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle[]>} */
	vehicles: { type: Array, required: true },
	/** The uuid the content area is showing, or '' for the overview. */
	selected: { type: String, default: '' },
})

defineEmits(['select'])

const entries = computed(() => props.vehicles.map((vehicle) => ({
	uuid: vehicle.uuid,
	name: nameOf(vehicle),
	second: secondLabelOf(vehicle),
})))

/**
 * Each entry's name, by uuid. It sits inside the entry's link, which NcListItem gives no ref to,
 * and its root is no element either.
 *
 * @type {Map<string, Element>}
 */
const names = new Map()

/**
 * @param {string} uuid - the entry's vehicle
 * @param {Element|null} name - its rendered name, or null once gone
 */
function hold(uuid, name) {
	if (name === null) {
		names.delete(uuid)
	} else {
		names.set(uuid, name)
	}
}

/**
 * A screen reader announces aria-current when the link takes focus, as it does for the
 * NcAppNavigationItems beside these. NcListItem hands attributes to its <li> only, so the link
 * gets it here, after each render. Vue leaves an attribute it did not set alone.
 */
function markCurrent() {
	for (const [uuid, name] of names) {
		const link = name.closest('a')
		if (uuid === props.selected) {
			link?.setAttribute('aria-current', 'page')
		} else {
			link?.removeAttribute('aria-current')
		}
	}
}

onMounted(markCurrent)
onUpdated(markCurrent)
</script>

<template>
	<!-- The entries and nothing around them: NcAppNavigation wraps its `list` slot in an
	     NcAppNavigationList already, and a second one nests a <ul> inside a <ul>. NcListItem is
	     an <li> itself. Its link goes to "#" unless told otherwise, so the click is held back
	     from changing the address. Both lines are cut to the navigation's width, so each carries
	     its whole text as a tooltip. -->
	<NcListItem v-for="entry in entries"
		:key="entry.uuid"
		:title="entry.name"
		:active="entry.uuid === selected"
		@click.prevent="$emit('select', entry.uuid)">
		<template #name>
			<strong :ref="(name) => hold(entry.uuid, name)">{{ entry.name }}</strong>
		</template>
		<template v-if="entry.second" #subname>
			<span class="vehicle-list__second" :title="entry.second">
				{{ entry.second }}
			</span>
		</template>
	</NcListItem>
</template>
