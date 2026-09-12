<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { readTimeline } from '../services/api.js'
import { formatMonth, monthKey } from '../utils/format.js'
import TimelineRow from './TimelineRow.vue'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

/** The chip. The empty one is every kind, which is the absent parameter (src/services/api.js). */
const chip = ref('')

/** @type {import('vue').Ref<import('../services/api.js').Entry[]>} */
const rows = ref([])
/** Where the next page starts, or null when the page in hand is the last one. */
const next = ref(null)
const loading = ref(false)
const failure = ref('')
/** True until the current list's first page is in, which is what tells an empty list from an unread one. */
const unread = ref(true)

const chips = computed(() => [
	{ value: '', label: t('nextfleet', 'All') },
	{ value: 'trip', label: t('nextfleet', 'Trips') },
	{ value: 'odometer', label: t('nextfleet', 'Odometer') },
])

/**
 * The rows under their months. Grouped here rather than by the server, because it is a question
 * about how the list reads and the answer depends on the reader's locale (src/utils/format.js).
 * The list is already ordered, so one pass over it is the grouping.
 */
const groups = computed(() => {
	/** @type {{key: string, month: string, rows: import('../services/api.js').Entry[]}[]} */
	const months = []
	for (const entry of rows.value) {
		const key = monthKey(entry.occurred_at, entry.occurred_at_off)
		if (months.at(-1)?.key !== key) {
			months.push({ key, month: formatMonth(entry.occurred_at, entry.occurred_at_off), rows: [] })
		}
		months.at(-1).rows.push(entry)
	}

	return months
})

/** The bottom of the list, and what "more on scroll" watches for. */
const sentinel = ref(null)
/** @type {IntersectionObserver|null} */
let observer = null

onMounted(reload)
onBeforeUnmount(() => observer?.disconnect())

// A chip is a different question and is asked from the top: a cursor the previous chip handed out
// names a place in an order the narrower list does not have (docs/architecture.md#the-timeline).
// The shell keeps one vehicle screen and swaps the vehicle under it (src/App.vue), so the second is
// the same kind of event: the list this component holds belongs to the vehicle it was read for.
watch([chip, () => props.vehicle.uuid], reload)

// The sentinel comes and goes with the next page, so the observer follows it rather than being set
// up once. A browser without one still has the button inside it.
watch(sentinel, (element) => {
	observer?.disconnect()
	observer = null
	if (element === null || typeof IntersectionObserver === 'undefined') {
		return
	}

	observer = new IntersectionObserver((entries) => {
		// A refused page stops the scrolling from asking again: it would ask on every pixel and get
		// the same answer. The button inside the sentinel is what still asks, which is why the
		// retry does not go through here.
		if (entries.some((entry) => entry.isIntersecting) && failure.value === '') {
			more()
		}
	})
	observer.observe(element)
})

/**
 * Which list is being read, bumped by every question that replaces it - another chip, another
 * vehicle, a write to read back. A page in the air when one of those lands is an answer to a
 * question nobody is asking any more, so it is dropped rather than rendered under the new one.
 */
let asked = 0

/**
 * Reads the whole list again, from the top. The screen calls it after an entry was written: the row
 * just entered is the one the driver is looking for.
 *
 * @return {Promise<void>} when the first page is in
 */
async function reload() {
	asked += 1
	rows.value = []
	next.value = null
	unread.value = true

	return page(asked)
}

/**
 * The next page, from where the last one stopped. Both ways on lead here: the bottom of the list
 * coming into view, and the button sitting in it.
 *
 * @return {Promise<void>} when it is in
 */
async function more() {
	// One page at a time. A second request while the first is in the air would ask from the cursor
	// the first has not answered with yet, and land the same rows twice.
	if (!loading.value) {
		await page(asked)
	}

	// An element that never leaves the viewport fires no second intersection, so a page that did
	// not fill the screen would leave the rest of the list unreachable. Asking the observer the
	// question again is what unsticks it.
	if (sentinel.value !== null && observer !== null) {
		observer.unobserve(sentinel.value)
		observer.observe(sentinel.value)
	}
}

/**
 * One page. A refusal keeps every row already on screen: the list is behind, not lost, and the
 * retry is the way on (docs/ui.md).
 *
 * @param {number} question - the list this page was asked for
 * @return {Promise<void>} when the page is in, or the refusal is on screen
 */
async function page(question) {
	loading.value = true
	failure.value = ''
	try {
		const answered = await readTimeline(props.vehicle.uuid, { type: chip.value, cursor: next.value })
		if (question !== asked) {
			return
		}

		rows.value = [...rows.value, ...answered.rows]
		next.value = answered.next
	} catch (error) {
		if (question === asked) {
			failure.value = error.message
		}
	} finally {
		// Not the superseded page's to settle: the one that replaced it is still reading, and its
		// own call clears these when it is done.
		if (question === asked) {
			loading.value = false
			unread.value = false
		}
	}
}

defineExpose({ reload })
</script>

<template>
	<section class="timeline">
		<div class="timeline__head">
			<h3>{{ t('nextfleet', 'Timeline') }}</h3>
			<!-- The chips are one choice out of three, which is what a radio group is - and it is the
			     chooser the rest of the app already uses (src/components/EntrySheet.vue). -->
			<NcRadioGroup v-model="chip" :label="t('nextfleet', 'Show')">
				<NcRadioGroupButton v-for="one in chips"
					:key="one.value"
					:value="one.value"
					:label="one.label" />
			</NcRadioGroup>
		</div>

		<NcEmptyContent v-if="groups.length === 0 && !unread && failure === ''"
			:name="t('nextfleet', 'Nothing recorded yet')"
			:description="t('nextfleet', 'What happens to this vehicle is listed here, newest first.')" />

		<div v-for="group in groups" :key="group.key" class="timeline__group">
			<!-- Sticky, so the month a row belongs to is on screen however far down the list somebody
			     has scrolled (docs/ui.md). -->
			<h4 class="timeline__month">
				{{ group.month }}
			</h4>
			<ul class="timeline__rows">
				<TimelineRow v-for="entry in group.rows"
					:key="`${entry.type}-${(entry.trip ?? entry.odometer).uuid}`"
					:entry="entry"
					:vehicle="vehicle" />
			</ul>
		</div>

		<div v-if="next !== null || failure !== ''" ref="sentinel" class="timeline__more">
			<NcNoteCard v-if="failure" type="error" :text="failure" />
			<NcButton :disabled="loading" @click="more">
				{{ failure ? t('nextfleet', 'Try again') : t('nextfleet', 'Load more') }}
			</NcButton>
		</div>
		<NcLoadingIcon v-else-if="loading" class="timeline__waiting" />
	</section>
</template>

<style scoped>
.timeline {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.timeline__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timeline__month {
	position: sticky;
	/* The app content is the scroller, so the header settles against its top edge. */
	top: 0;
	z-index: 1;
	margin: 0;
	padding: calc(var(--default-grid-baseline) * 2) 0;
	background-color: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	text-transform: uppercase;
}

.timeline__rows {
	margin: 0;
	padding: 0;
	list-style: none;
}

.timeline__more,
.timeline__waiting {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 4) 0;
}
</style>
