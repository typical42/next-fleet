<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { computed, onMounted, ref } from 'vue'

import UndoToast from './components/UndoToast.vue'
import VehicleList from './components/VehicleList.vue'
import VehicleSheet from './components/VehicleSheet.vue'
import { useVehiclesStore } from './store/index.js'
import OverviewView from './views/OverviewView.vue'
import VehicleView from './views/VehicleView.vue'

const store = useVehiclesStore()

/** The vehicle the content area shows; empty means the overview (docs/ui.md). */
const selected = ref('')
const creating = ref(false)
const failure = ref('')

// Looked up in the fleet the navigation lists rather than in the whole store: a vehicle disposed
// of in the edit sheet leaves that list (docs/ui.md), and its screen would otherwise stay open
// with no entry to leave it by.
const vehicle = computed(() => store.visible.find((one) => one.uuid === selected.value))

onMounted(load)

/** Reading the fleet is the one request the shell makes on its own, so it reports its own failure. */
async function load() {
	failure.value = ''
	try {
		await store.load()
	} catch (error) {
		failure.value = error.message
	}
}

/**
 * @param {import('./services/api.js').Vehicle} created - the vehicle the sheet just added
 */
function open(created) {
	creating.value = false
	selected.value = created.uuid
}
</script>

<template>
	<NcContent app-name="nextfleet">
		<NcAppNavigation :aria-label="t('nextfleet', 'Vehicles')">
			<template #default>
				<NcAppNavigationNew :text="t('nextfleet', 'New vehicle')" @click="creating = true" />
			</template>
			<template #list>
				<VehicleList :vehicles="store.visible"
					:selected="selected"
					@select="selected = $event" />
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
			<VehicleView v-else-if="vehicle" :vehicle="vehicle" />
			<OverviewView v-else
				:vehicles="store.visible"
				@new="creating = true"
				@select="selected = $event" />
		</NcAppContent>
		<VehicleSheet v-if="creating" @close="creating = false" @created="open" />
		<!-- Outside the screens on purpose: a delete takes the screen that asked for it with the
		     vehicle, and the way back has to outlive both. It shows itself when there is one. -->
		<UndoToast />
	</NcContent>
</template>
