<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, onMounted, ref } from 'vue'

import PartnerDetail from '../components/PartnerDetail.vue'
import { listPartners } from '../services/api.js'
import { t } from '../utils/l10n.js'

defineProps({
	/**
	 * The whole fleet, sold vehicles included, to name the vehicle of each trip.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Vehicle[]>}
	 */
	vehicles: { type: Array, required: true },
})

/** The caller's own list, as the server ordered it; null until it is read. @type {import('vue').Ref<import('../services/api.js').Partner[]|null>} */
const partners = ref(null)
const failure = ref('')
/** The uuid of the partner shown instead of the list; empty for the list. */
const opened = ref('')
const partner = computed(() => partners.value?.find((one) => one.uuid === opened.value) ?? null)

onMounted(load)

/** Reads the list, and on a failure says why instead. */
async function load() {
	failure.value = ''
	try {
		partners.value = await listPartners()
	} catch (error) {
		failure.value = error.message
	}
}

/** @param {import('../services/api.js').Partner} saved - as the server answered the write */
function replace(saved) {
	partners.value = (partners.value ?? []).map((one) => one.uuid === saved.uuid ? saved : one)
}

/** @param {{gone: string, into: import('../services/api.js').Partner}} merge - what the merge left */
function merged({ gone, into }) {
	replace(into)
	drop(gone)
	opened.value = into.uuid
}

/** @param {string} gone - the uuid of a partner that is no longer in the list */
function drop(gone) {
	partners.value = (partners.value ?? []).filter((one) => one.uuid !== gone)
}
</script>

<template>
	<div class="partners">
		<h2 v-if="!partner">
			{{ t('nextfleet', 'Partners') }}
		</h2>
		<!-- Over the open partner too: its "Load again" reads the list here. -->
		<template v-if="failure">
			<NcNoteCard type="error" :text="failure" />
			<NcButton @click="load">
				{{ t('nextfleet', 'Try again') }}
			</NcButton>
		</template>
		<PartnerDetail v-if="partner"
			:partner="partner"
			:partners="partners ?? []"
			:vehicles="vehicles"
			@back="opened = ''"
			@saved="replace"
			@merged="merged"
			@deleted="drop"
			@reload="load" />
		<template v-else-if="!failure">
			<NcLoadingIcon v-if="partners === null" :name="t('nextfleet', 'Loading…')" />
			<NcEmptyContent v-else-if="partners.length === 0"
				:name="t('nextfleet', 'No partners yet')"
				:description="t('nextfleet', 'A business trip adds its partner to this list.')" />
			<template v-else>
				<p class="partners__hint">
					{{ t('nextfleet', 'The partners of all your vehicles. Drivers see the names, only you see the address and the note.') }}
				</p>
				<ul class="partners__list">
					<!-- Its link goes to "#" unless told otherwise, so the click is held back from
					     changing the address (VehicleList.vue). -->
					<NcListItem v-for="one in partners"
						:key="one.uuid"
						:name="one.name"
						:title="one.name"
						@click.prevent="opened = one.uuid">
						<template v-if="one.address" #subname>
							{{ one.address }}
						</template>
					</NcListItem>
				</ul>
			</template>
		</template>
	</div>
</template>

<style scoped>
.partners {
	padding: calc(var(--default-grid-baseline) * 4);
}

.partners__list {
	max-width: 600px;
}

.partners__hint {
	color: var(--color-text-maxcontrast);
	margin-block-end: calc(var(--default-grid-baseline) * 2);
}
</style>
