<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { computed, ref, watch } from 'vue'

import { useVehiclesStore } from '../store/index.js'
import { nameOf } from '../utils/format.js'

const store = useVehiclesStore()

/**
 * Why the way back did not work, if it did not. Held here rather than in the store, which reports
 * a refusal to whoever asked for the write and keeps no view of it.
 */
const failure = ref('')
/** An undo in flight. The second click would be checked against a row that is no longer deleted. */
const undoing = ref(false)

// A refusal is about the row it was refused for. The next delete is a different row and a
// different token, so it gets an offer that has not already failed.
watch(() => store.deleted, () => {
	failure.value = ''
})

const message = computed(() => {
	const name = nameOf(store.deleted ?? {})

	return failure.value
		? t('nextfleet', '{name} could not be brought back: {reason}', { name, reason: failure.value })
		: t('nextfleet', '{name} was deleted.', { name })
})

/** The way back. What comes back is still the selected vehicle, so its screen returns with it. */
async function undo() {
	if (undoing.value) {
		return
	}

	undoing.value = true
	failure.value = ''
	try {
		await store.restore()
	} catch (error) {
		// The row moved on since the delete, so the token matches nothing and the vehicle is still
		// deleted. Closing here would claim an undo that did not happen.
		failure.value = error.message
	} finally {
		undoing.value = false
	}
}

/** The answer that takes nothing back. */
function dismiss() {
	failure.value = ''
	store.forget()
}
</script>

<template>
	<!-- The region stays in the page and the toast comes and goes inside it: a live region that is
	     inserted already full is not announced. Polite rather than assertive, because the deletion
	     is what the user just asked for - it follows what they are reading, it does not cut in. -->
	<div class="toast-region" role="status" aria-live="polite">
		<div v-if="store.deleted" class="toast">
			<p class="toast__message">
				{{ message }}
			</p>
			<!-- A refused undo is refused for the row's sake and not for the click's, so the same
			     click would be refused the same way: only the way out is left. -->
			<NcButton v-if="!failure"
				variant="tertiary"
				:disabled="undoing"
				@click="undo">
				{{ t('nextfleet', 'Undo') }}
			</NcButton>
			<NcButton variant="tertiary" @click="dismiss">
				{{ t('nextfleet', 'Dismiss') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
/*
 * The region is a place for the card to appear in and nothing else: taken out of the flow so that
 * an app shell laying out its children never has to account for it, and left in the page so a
 * screen reader has something to announce the change in.
 */
.toast-region {
	position: absolute;
}

/*
 * No timer: the token the undo carries is untimed (docs/architecture.md#concurrency), and a way
 * back that disappears on a clock is a time limit on the only way back there is.
 */
.toast {
	position: fixed;
	z-index: 10000;
	inset-block-end: calc(var(--default-grid-baseline) * 4);
	inset-inline: calc(var(--default-grid-baseline) * 2);
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: center;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 40em;
	margin-inline: auto;
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element, var(--border-radius-large));
	background-color: var(--color-main-background);
	box-shadow: 0 0 10px var(--color-box-shadow);
}

.toast__message {
	/* The message takes the row on a phone and shares it once there is room, so both buttons stay
	   reachable at 320 px without the text being cut. */
	flex: 1 1 12em;
}
</style>
