<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, nextTick, ref, watch } from 'vue'

import { readHeld } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { may } from '../utils/access.js'
import { roleWord } from '../utils/format.js'
import { t } from '../utils/l10n.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

// `kept`: a group still reaches the vehicle, under another role, so what the screen's rows offer moved.
const emit = defineEmits(['kept'])

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
/** @type {import('vue').Ref<HTMLElement|null>} */
const root = ref(null)

/**
 * Opens or closes the question, and moves focus to its first answer or back to the button that
 * asked it: the buttons are swapped out from under a keyboard or screen reader user otherwise.
 *
 * @param {boolean} open - whether the question stands
 */
async function ask(open) {
	asking.value = open
	await nextTick()
	root.value?.querySelector(open ? '.leave__answers button' : '.leave__trigger')?.focus()
}

// Joined rather than pluralised: one group is the common case, and the catalogue has no plurals.
const groups = computed(() => (held.value?.groups ?? []).map((one) => one.display_name).join(', '))

/**
 * @param {import('../services/api.js').Holder} one - who reads the vehicle
 * @return {string} their name, a group said as one, and their role
 */
function holderWords(one) {
	const name = one.grantee_type === 'group' ? t('nextfleet', 'Group {group}', { group: one.display_name }) : one.display_name
	return `${name} · ${roleWord(one.role)}`
}

/**
 * Runs per vehicle, since the screen stays mounted across picks; an answer that arrives after the
 * next pick is dropped.
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
 * The refusals of a leave whose grant was revoked meanwhile, by the server's English words: 403
 * without another way in, 404 with one or once the vehicle is gone. The rest show as sent.
 *
 * @type {Record<string, () => string>}
 */
const REFUSALS = {
	'Not yours': () => t('nextfleet', 'You have no access of your own to give back any more.'),
	'No such vehicle': () => t('nextfleet', 'You have no access of your own to give back any more.'),
}

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
		if (held.value.groups.length > 0) {
			emit('kept')
			// The buttons are gone; the words that replaced them take focus rather than the page.
			await nextTick()
			root.value?.querySelector('.leave__group')?.focus()
		}
	} catch (error) {
		failure.value = REFUSALS[error.message]?.() ?? t('nextfleet', 'You could not leave: {reason}', { reason: error.message })
	} finally {
		leaving.value = false
	}
}
</script>

<template>
	<div v-if="held" ref="root" class="leave">
		<template v-if="held.role">
			<NcNoteCard v-if="failure" type="error" :text="failure" />
			<div v-if="asking" class="leave__question">
				<NcNoteCard type="warning"
					:text="t('nextfleet', 'Leaving gives back the access you were given. Only the owner can give it to you again.')" />
				<div class="leave__answers">
					<NcButton :disabled="leaving" @click="ask(false)">
						{{ t('nextfleet', 'Stay') }}
					</NcButton>
					<NcButton variant="warning" :disabled="leaving" @click="leave">
						{{ t('nextfleet', 'Leave') }}
					</NcButton>
				</div>
			</div>
			<NcButton v-else
				class="leave__trigger"
				variant="tertiary"
				@click="ask(true)">
				{{ t('nextfleet', 'Leave vehicle') }}
			</NcButton>
		</template>
		<p v-else-if="groups" class="leave__group" tabindex="-1">
			{{ t('nextfleet', 'You have access through the group {groups}. Only the owner can change that.', { groups }) }}
		</p>
		<!-- Read-only: whoever a trip entered here reaches (docs/ui.md#the-access-section). -->
		<details v-if="held.holders?.length" class="leave__holders">
			<summary>{{ t('nextfleet', 'Who can see this vehicle') }}</summary>
			<ul>
				<li v-for="(one, index) in held.holders" :key="index">
					{{ holderWords(one) }}
				</li>
			</ul>
		</details>
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

.leave__holders ul {
	padding-inline-start: calc(var(--default-grid-baseline) * 4);
	list-style: disc;
}
</style>
