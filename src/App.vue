<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { computed, onMounted, ref } from 'vue'

import UndoToast from './components/UndoToast.vue'
import VehicleList from './components/VehicleList.vue'
import VehicleSheet from './components/VehicleSheet.vue'
import { useVehiclesStore } from './store/index.js'
import { usePreferencesStore } from './store/preferences.js'
import CostsView from './views/CostsView.vue'
import OverviewView from './views/OverviewView.vue'
import ReportsView from './views/ReportsView.vue'
import VehicleView from './views/VehicleView.vue'

const store = useVehiclesStore()
const preferences = usePreferencesStore()

/**
 * The vehicle the content area shows; empty means the overview (docs/ui.md). A reminder
 * notification opens the app on its vehicle with `?vehicle=`.
 */
const landing = new URLSearchParams(window.location.search)
const selected = ref(landing.get('vehicle') ?? '')
/**
 * Whether the vehicle's screen opens with the entry sheet up, which is what the QR sticker's link
 * asks (docs/ui.md). Taken off the address at once, so a reload later does not open a second sheet.
 */
const arrivedToEnter = ref(landing.get('entry') === 'new')
if (arrivedToEnter.value) {
	landing.delete('entry')
	const rest = landing.toString()
	window.history.replaceState(window.history.state, '', `${window.location.pathname}${rest === '' ? '' : `?${rest}`}`)
}
/** Whether the content area shows the reports instead, which belong to no one vehicle. */
const reporting = ref(false)
/** Whether the selected vehicle's costs show instead of its screen; picking any vehicle ends it. */
const costing = ref(false)
const creating = ref(false)
const failure = ref('')

// Looked up in the fleet the navigation lists rather than in the whole store: a vehicle disposed
// of in the edit sheet leaves that list (docs/ui.md), and its screen would otherwise stay open
// with no entry to leave it by.
const vehicle = computed(() => store.visible.find((one) => one.uuid === selected.value))
// Read off what the content area shows, not off `selected`: a vehicle that left the fleet falls
// back to the overview with its uuid still selected.
const overview = computed(() => !reporting.value && !vehicle.value)

onMounted(load)

/** Reading the fleet is the one request the shell makes on its own, so it reports its own failure. */
async function load() {
	failure.value = ''
	try {
		await store.load()
	} catch (error) {
		failure.value = error.message
	}

	// Read here rather than by the screen that asks about them, which would ask again on every
	// navigation. Its own attempt, and a silent one: preferences that did not arrive cost a hint
	// (src/components/CompleteHint.vue), and reporting that where the fleet reports its failures
	// would put a red card over a screen that is working.
	try {
		await preferences.load()
	} catch {
		// The hint asks nothing until it knows what was already answered.
	}
}

/**
 * @param {import('./services/api.js').Vehicle} created - the vehicle the sheet just added
 */
function open(created) {
	creating.value = false
	show(created.uuid)
}

/**
 * @param {string} uuid - the vehicle whose screen to show
 */
function show(uuid) {
	reporting.value = false
	costing.value = false
	arrivedToEnter.value = false
	selected.value = uuid
}

/** No vehicle stays selected: a report picks its own, sold ones included. */
function report() {
	selected.value = ''
	reporting.value = true
}
</script>

<template>
	<NcContent app-name="nextfleet">
		<NcAppNavigation :aria-label="t('nextfleet', 'Vehicles')">
			<template #default>
				<NcAppNavigationNew :text="t('nextfleet', 'New vehicle')" @click="creating = true" />
			</template>
			<template #list>
				<NcAppNavigationItem :name="t('nextfleet', 'Overview')"
					:active="overview"
					@click="show('')" />
				<VehicleList :vehicles="store.visible"
					:selected="selected"
					@select="show" />
			</template>
			<template #footer>
				<NcAppNavigationList>
					<NcAppNavigationItem :name="t('nextfleet', 'Reports')"
						:active="reporting"
						@click="report" />
				</NcAppNavigationList>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<!-- A fleet that did not arrive is not an empty fleet: offering to create the first
			     vehicle there teaches the wrong thing and hides the retry. -->
			<NcEmptyContent v-if="failure"
				:name="t('nextfleet', 'The fleet could not be loaded')"
				:description="failure">
				<template #action>
					<NcButton variant="primary" @click="load">
						{{ t('nextfleet', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<!-- The whole fleet rather than the visible one: a sold vehicle's logbook is still kept
			     (docs/features.md#logbook-mode). -->
			<ReportsView v-else-if="reporting" :vehicles="store.list" />
			<CostsView v-else-if="vehicle && costing" :vehicle="vehicle" @back="costing = false" />
			<VehicleView v-else-if="vehicle"
				:vehicle="vehicle"
				:enter="arrivedToEnter"
				@costs="arrivedToEnter = false; costing = true" />
			<OverviewView v-else
				:vehicles="store.visible"
				@new="creating = true"
				@select="show" />
		</NcAppContent>
		<VehicleSheet v-if="creating" @close="creating = false" @created="open" />
		<!-- Outside the screens on purpose: a delete takes the screen that asked for it with the
		     vehicle, and the way back has to outlive both. It shows itself when there is one. -->
		<UndoToast />
	</NcContent>
</template>
