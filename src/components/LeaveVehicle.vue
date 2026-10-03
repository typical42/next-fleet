<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, ref, watch } from 'vue'

import { readHeld } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { may } from '../utils/access.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

const store = useVehiclesStore()

/**
 * What the session holds on the vehicle, or null while unread, refused, or for the owner, who
 * holds no grant: then nothing shows, since an offer the server would refuse is worse than none.
 *
 * @type {import('vue').Ref<import('../services/api.js').Held|null>}
 */
const held = ref(null)
/** Whether the question stands: leaving has no undo, only the owner grants again. */
const asking = ref(false)
const leaving = ref(false)
const failure = ref('')

// Joined rather than pluralised: one group is the common case, and the catalogue has no plurals.
const groups = computed(() => (held.value?.groups ?? []).map((one) => one.display_name).join(', '))

/**
 * The screen stays mounted when another vehicle is picked, so this runs per vehicle, and an answer
 * that arrives after the next pick is dropped rather than shown under the wrong name.
 */
async function read() {
	const uuid = props.vehicle.uuid
	held.value = null
	asking.value = false
	failure.value = ''
	if (may(props.vehicle, 'own')) {
		return
	}
	try {
		const answer = await readHeld(uuid)
		if (uuid === props.vehicle.uuid) {
			held.value = answer
		}
	} catch {
		// Nothing shows: see `held`.
	}
}

watch(() => props.vehicle.uuid, read, { immediate: true })

/**
 * Without a group still reaching it the vehicle leaves the store, and this screen with it
 * (src/App.vue).
 */
async function leave() {
	leaving.value = true
	failure.value = ''
	try {
		held.value = await store.leave(props.vehicle.uuid)
		asking.value = false
	} catch (error) {
		failure.value = t('nextfleet', 'You could not leave: {reason}', { reason: error.message })
	} finally {
		leaving.value = false
	}
}
</script>

<template>
	<div v-if="held" class="leave">
		<template v-if="held.role">
			<NcNoteCard v-if="failure" type="error" :text="failure" />
			<div v-if="asking" class="leave__question">
				<NcNoteCard type="warning"
					:text="t('nextfleet', 'Leaving gives back the access you were given. Only the owner can give it to you again.')" />
				<div class="leave__answers">
					<NcButton :disabled="leaving" @click="asking = false">
						{{ t('nextfleet', 'Stay') }}
					</NcButton>
					<NcButton variant="warning" :disabled="leaving" @click="leave">
						{{ t('nextfleet', 'Leave') }}
					</NcButton>
				</div>
			</div>
			<NcButton v-else variant="tertiary" @click="asking = true">
				{{ t('nextfleet', 'Leave vehicle') }}
			</NcButton>
		</template>
		<p v-else-if="groups" class="leave__group">
			{{ t('nextfleet', 'You have access through the group {groups}. Only the owner can change that.', { groups }) }}
		</p>
	</div>
</template>

<style scoped>
.leave__answers {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.leave__group {
	color: var(--color-text-maxcontrast);
}
</style>
