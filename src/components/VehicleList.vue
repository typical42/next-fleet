<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'

import { nameOf } from '../utils/format.js'

defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle[]>} */
	vehicles: { type: Array, required: true },
	/** The uuid the content area is showing, or '' for the overview. */
	selected: { type: String, default: '' },
})

defineEmits(['select'])
</script>

<template>
	<!-- The entries and nothing around them: NcAppNavigation wraps its `list` slot in an
	     NcAppNavigationList already, and a second one nests a <ul> inside a <ul>. -->
	<NcAppNavigationItem v-for="vehicle in vehicles"
		:key="vehicle.uuid"
		:name="nameOf(vehicle)"
		:active="vehicle.uuid === selected"
		@click="$emit('select', vehicle.uuid)" />
</template>
