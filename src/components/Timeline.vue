<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import { computed, markRaw, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'

import { closeGap, ConflictError, readGaps, readTimeline, RefusedError, resetReading } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { may } from '../utils/access.js'
import { formatCount, formatMonth, fullMoment, monthKey } from '../utils/format.js'
import TimelineRow from './TimelineRow.vue'
import { t } from '../utils/l10n.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
	/**
	 * The vehicle's documents, read by its documents section. Those linked to an entry show on its row.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Document[]>}
	 */
	papers: { type: Array, default: () => [] },
})

// A tapped row, for the screen to open the sheet on (src/views/VehicleView.vue).
defineEmits(['open', 'new', 'import'])

const store = useVehiclesStore()

/** The chip. The empty one is every kind, which is the absent parameter (src/services/api.js). */
const chip = ref('')

/**
 * How many pages are on screen at most. Years of entries scrolled through would otherwise put
 * thousands of rows in the page; the ones above go as the next come, and stay in hand to come
 * back. What is shown keeps its reading order, so a keyboard walks it as before.
 */
const WINDOW = 4

/**
 * Every page read so far, in order, each row marked raw: a row is replaced, never changed in
 * place, so Vue need not watch every field of thousands.
 *
 * @type {import('vue').ShallowRef<import('../services/api.js').Entry[][]>}
 */
const pages = shallowRef([])
/** The first page on screen. */
const first = ref(0)
const rows = computed(() => pages.value.slice(first.value, first.value + WINDOW).flat())
/** Pages already read below the ones on screen, which the way down shows before reading more. */
const below = computed(() => first.value + WINDOW < pages.value.length)
/** Where the next page starts, or null when the page in hand is the last one. */
const next = ref(null)
const loading = ref(false)
const failure = ref('')
/** True until the current list's first page is in, which is what tells an empty list from an unread one. */
const unread = ref(true)
/**
 * The vehicle's Gaps, whole rather than paged, or null until they are read. Read only under Logbook
 * Mode, the only place they are said (docs/features.md#logbook-mode).
 *
 * @type {import('vue').Ref<import('../services/api.js').Gap[]|null>}
 */
const gaps = ref(null)

const chips = computed(() => [
	{ value: '', label: t('nextfleet', 'All') },
	{ value: 'trip', label: t('nextfleet', 'Trips') },
	{ value: 'odometer', label: t('nextfleet', 'Odometer') },
	{ value: 'energy', label: t('nextfleet', 'Energy') },
	{ value: 'maintenance', label: t('nextfleet', 'Maintenance') },
	// Energy and maintenance cost money too, but have chips of their own; this one is the rest. Not
	// "Costs": that is the header's button, which opens the whole bill.
	{ value: 'expense', label: t('nextfleet', 'Expenses') },
])

/**
 * The rows under their months. Grouped here rather than by the server, because it is a question
 * about how the list reads and the answer depends on the reader's locale (src/utils/format.js).
 * The list is already ordered, so one pass over it is the grouping.
 */
const groups = computed(() => {
	// A Gap belongs to the month of the trip whose claim opened it, which is where that trip's row is.
	/** @type {Map<string, number>} */
	const unaccounted = new Map()
	for (const gap of gaps.value ?? []) {
		const key = monthKey(gap.to_at, gap.to_at_off)
		unaccounted.set(key, (unaccounted.get(key) ?? 0) + gap.distance)
	}

	/** @type {{key: string, month: string, gap: string, rows: import('../services/api.js').Entry[]}[]} */
	const months = []
	for (const entry of rows.value) {
		const key = monthKey(entry.occurred_at, entry.occurred_at_off)
		if (months.at(-1)?.key !== key) {
			const distance = unaccounted.get(key)
			months.push({
				key,
				month: formatMonth(entry.occurred_at, entry.occurred_at_off),
				gap: distance === undefined ? '' : `${formatCount(distance)} ${props.vehicle.odo_unit}`,
				rows: [],
			})
		}
		months.at(-1).rows.push(entry)
	}

	return months
})

/** Each Gap under the trip whose claim opened it, which is the row that offers to close it. */
const gapOf = computed(() => new Map((gaps.value ?? []).map((gap) => [gap.trip, gap])))

/**
 * @param {string|null} type - the kind of Entry
 * @param {string|null} uuid - the Entry's own identity
 * @return {string} one key for a row and for the documents linked to it
 */
function entryKey(type, uuid) {
	return `${type}-${uuid}`
}

/** Each entry's linked documents. */
const papersOf = computed(() => {
	/** @type {Map<string, import('../services/api.js').Document[]>} */
	const linked = new Map()
	for (const paper of props.papers) {
		if (paper.linked_uuid !== null) {
			const key = entryKey(paper.linked_type, paper.linked_uuid)
			linked.set(key, [...(linked.get(key) ?? []), paper])
		}
	}

	return linked
})

/**
 * The Gap the driver is being asked about, or null when no question is open.
 *
 * @type {import('vue').Ref<import('../services/api.js').Gap|null>}
 */
const closing = ref(null)
const closingFailure = ref('')
/** The Gap moved since it was read, so confirming it again cannot help. */
const moved = ref(false)
const writing = ref(false)

/** The question names exactly what the server will write (docs/features.md#logbook-mode). */
const question = computed(() => {
	const gap = closing.value
	if (gap === null) {
		return ''
	}

	return t('nextfleet', 'Record {distance} driven between {from} and {to} as one private trip?', {
		distance: `${formatCount(gap.distance)} ${props.vehicle.odo_unit}`,
		from: fullMoment(gap.from_at, gap.from_at_off),
		to: fullMoment(gap.to_at, gap.to_at_off),
	})
})

/**
 * An element whose coming into view asks `onSeen`: the ends of the list. It comes and goes with
 * the pages, so the observer follows it rather than being set up once. A browser without one still
 * has the button inside it.
 *
 * @param {() => void} onSeen - what its coming into view asks for
 * @return {{element: import('vue').Ref<HTMLElement|null>, again: () => void}} the element's ref,
 *     and a way to ask the question again
 */
function sentinelOf(onSeen) {
	const element = ref(null)
	/** @type {IntersectionObserver|null} */
	let observer = null

	watch(element, (now) => {
		observer?.disconnect()
		observer = null
		if (now === null || typeof IntersectionObserver === 'undefined') {
			return
		}

		observer = new IntersectionObserver((entries) => {
			if (entries.some((entry) => entry.isIntersecting)) {
				onSeen()
			}
		})
		observer.observe(now)
	})
	onBeforeUnmount(() => observer?.disconnect())

	return {
		element,
		// An element that never leaves the viewport fires no second intersection, so a page that
		// did not fill the screen would leave the rest of the list unreachable. Asking the observer
		// the question again is what unsticks it.
		again() {
			if (element.value !== null && observer !== null) {
				observer.unobserve(element.value)
				observer.observe(element.value)
			}
		},
	}
}

// A refused page stops the scrolling from asking the server again: it would ask on every pixel and
// get the same answer. The button inside the sentinel is what still asks, which is why the retry
// does not go through here. Pages already read below are no question to the server.
const end = sentinelOf(() => (below.value || failure.value === '') && more())
const start = sentinelOf(() => newer())
// What the template's refs bind to.
const sentinel = end.element
const topSentinel = start.element

/** The section, whose rows keep the reader's place while the window moves (shift()). */
const root = ref(null)

onMounted(reload)

// A chip is a different question and is asked from the top: a cursor the previous chip handed out
// names a place in an order the narrower list does not have (docs/architecture.md#the-timeline).
// The shell keeps one vehicle screen and swaps the vehicle under it (src/App.vue), so the second is
// the same kind of event: the list this component holds belongs to the vehicle it was read for.
// The mode is the third: switched on, the list has Gaps to state that were never read. A question
// about a Gap belongs to the list it was asked from.
watch([chip, () => props.vehicle.uuid, () => props.vehicle.logbook_mode === true], () => {
	closing.value = null
	reload()
})

// An undo is made from the toast in the app shell (src/components/UndoToast.vue), which holds no
// list; the Entry it brought back is a row this one lacks.
watch(() => store.restored, reload)

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
	pages.value = []
	first.value = 0
	next.value = null
	unread.value = true
	gaps.value = null

	return page(asked)
}

/**
 * The next page, from where the last one stopped: one already read if the window is above it, else
 * the server's. Both ways on lead here: the bottom of the list coming into view, and the button
 * sitting in it.
 *
 * @return {Promise<void>} when it is in
 */
async function more() {
	if (below.value) {
		await shift(1)
	} else if (!loading.value) {
		// One page at a time. A second request while the first is in the air would ask from the
		// cursor the first has not answered with yet, and land the same rows twice.
		await page(asked)
	}
	end.again()
}

/**
 * The page above the window back on screen, from what was read: the server is not asked twice.
 *
 * @return {Promise<void>} when it is shown
 */
async function newer() {
	if (first.value > 0) {
		await shift(-1)
	}
	start.again()
}

/**
 * Moves the window by one page and keeps the reader's place: the first row of a page on screen
 * before and after stays where it was on screen. Browsers that anchor scrolling do this
 * themselves, and then nothing moves here; the rest would jump by a page of rows.
 *
 * @param {1|-1} by - down or up
 * @return {Promise<void>} when it has moved
 */
async function shift(by) {
	const kept = by > 0 ? pages.value[first.value].length : 0
	/** @type {HTMLElement|undefined} */
	const anchor = root.value?.querySelectorAll('.timeline__rows > li')[kept]
	const before = anchor?.getBoundingClientRect().top ?? 0
	first.value += by
	await nextTick()
	const moved = anchor?.isConnected ? anchor.getBoundingClientRect().top - before : 0
	if (moved !== 0) {
		scrollerOf(anchor).scrollBy(0, moved)
	}
}

/**
 * @param {HTMLElement} element - an element in the list
 * @return {Element} what scrolls it: the app content in Nextcloud, the document elsewhere
 */
function scrollerOf(element) {
	for (let node = element.parentElement; node !== null; node = node.parentElement) {
		const overflow = getComputedStyle(node).overflowY
		if ((overflow === 'auto' || overflow === 'scroll') && node.scrollHeight > node.clientHeight) {
			return node
		}
	}

	return document.scrollingElement ?? document.documentElement
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
		// The Gaps travel with the page until they are in. A header that said nothing because they
		// never arrived would read as a month with none, so their refusal is the page's.
		const owed = gaps.value === null && props.vehicle.logbook_mode === true
		const [answered, found] = await Promise.all([
			readTimeline(props.vehicle.uuid, { type: chip.value, cursor: next.value }),
			owed ? readGaps(props.vehicle.uuid) : gaps.value,
		])
		if (question !== asked) {
			return
		}

		// A reader who went back up while the page was on its way stays there; it waits below.
		const atEnd = !below.value
		pages.value = [...pages.value, answered.rows.map((row) => markRaw(row))]
		next.value = answered.next
		gaps.value = found
		if (atEnd && below.value) {
			await shift(1)
		}
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

/**
 * Opens the question about one Gap.
 *
 * @param {import('../services/api.js').Gap} gap - the Gap a row offered to close
 */
function ask(gap) {
	closing.value = gap
	closingFailure.value = ''
	moved.value = false
}

/** Closes the question, unless its answer is still on the way: the write would land unseen. */
function dismiss() {
	if (!writing.value) {
		closing.value = null
	}
}

/**
 * Closes the Gap the driver confirmed, and reads the list again: the trip it wrote is a new row, and
 * the month's header no longer has that Gap to state.
 *
 * @return {Promise<void>} when it is closed, or the refusal is on screen
 */
async function confirm() {
	writing.value = true
	closingFailure.value = ''
	try {
		await closeGap(props.vehicle.uuid, closing.value)
		closing.value = null
		await reload()
	} catch (error) {
		if (error instanceof ConflictError) {
			moved.value = true
			closingFailure.value = t('nextfleet', 'This gap has changed since it was read. The timeline shows it as it is now.')
			await reload()
		} else {
			closingFailure.value = error.message
		}
	} finally {
		writing.value = false
	}
}

/** Why the last answer offered on a row - a void, a counter replaced - did not go through. */
const rowFailure = ref('')

/**
 * Voids a Reconciliation Trip a later trip overtook, from the row that says so, and holds the way
 * back as the sheet's void does (store.strike()). Read again either way: the row it voided goes,
 * or the one that moved is shown as it is now.
 *
 * @param {import('../services/api.js').Entry} entry - the row
 * @return {Promise<void>} when it is voided and the list read again, or the refusal is on screen
 */
async function strike(entry) {
	rowFailure.value = ''
	try {
		await store.strike(props.vehicle.uuid, 'trip', entry.trip)
	} catch (error) {
		rowFailure.value = error instanceof ConflictError
			? t('nextfleet', 'This trip has changed since it was read. The timeline shows it as it is now.')
			: error.message
	}
	await reload()
}

/**
 * Answers a Reading in question with "the counter was replaced" (docs/architecture.md#odometer-rules,
 * rule 3). Read again either way: the answer moves the flags, the Gaps and the distances around it.
 *
 * @param {import('../services/api.js').Reading} reading - the Reading the row asked about
 * @return {Promise<void>} when it is answered and the list read again, or the refusal is on screen
 */
async function answerReplaced(reading) {
	rowFailure.value = ''
	try {
		await resetReading(props.vehicle.uuid, reading)
	} catch (error) {
		if (error instanceof ConflictError) {
			rowFailure.value = t('nextfleet', 'This reading has changed since it was read. The timeline shows it as it is now.')
		} else if (error instanceof RefusedError && error.reason === 'not_in_question') {
			rowFailure.value = t('nextfleet', 'This reading is no longer in question. The timeline shows it as it is now.')
		} else {
			rowFailure.value = error.message
		}
	}
	await reload()
}

defineExpose({ reload })
</script>

<template>
	<section ref="root" class="timeline">
		<div class="timeline__head">
			<h3>{{ t('nextfleet', 'Timeline') }}</h3>
			<!-- The chips are one choice out of six, which is what a radio group is - and it is the
			     chooser the rest of the app already uses (src/components/EntrySheet.vue). -->
			<NcRadioGroup v-model="chip" class="timeline__chips" :label="t('nextfleet', 'Show')">
				<NcRadioGroupButton v-for="one in chips"
					:key="one.value"
					:value="one.value"
					:label="one.label" />
			</NcRadioGroup>
		</div>

		<NcNoteCard v-if="rowFailure" type="error" :text="rowFailure" />

		<NcEmptyContent v-if="groups.length === 0 && !unread && failure === ''"
			:name="t('nextfleet', 'Nothing recorded yet')"
			:description="t('nextfleet', 'What happens to this vehicle is listed here, newest first.')">
			<!-- Empty states do the teaching (docs/ui.md): the two ways to a first row. The import is
			     the edit sheet's, so it asks what that sheet asks. -->
			<template #action>
				<NcButton v-if="may(vehicle, 'log')" variant="primary" @click="$emit('new')">
					{{ t('nextfleet', 'New entry') }}
				</NcButton>
				<NcButton v-if="may(vehicle, 'edit') && vehicle.lifecycle !== 'disposed'" @click="$emit('import')">
					{{ t('nextfleet', 'Import from a file…') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<div v-if="first > 0" ref="topSentinel" class="timeline__more">
			<NcButton @click="newer">
				{{ t('nextfleet', 'Show newer entries') }}
			</NcButton>
		</div>

		<div v-for="group in groups" :key="group.key" class="timeline__group">
			<!-- Sticky, so the month a row belongs to is on screen however far down the list somebody
			     has scrolled (docs/ui.md). -->
			<h4 class="timeline__month">
				{{ group.month }}
				<span v-if="group.gap" class="timeline__gap">
					{{ t('nextfleet', '{distance} unaccounted for', { distance: group.gap }) }}
				</span>
			</h4>
			<ul class="timeline__rows">
				<TimelineRow v-for="entry in group.rows"
					:key="entryKey(entry.type, entry[entry.type].uuid)"
					:entry="entry"
					:vehicle="vehicle"
					:gap="entry.trip === undefined ? null : gapOf.get(entry.trip.uuid) ?? null"
					:papers="papersOf.get(entryKey(entry.type, entry[entry.type].uuid)) ?? []"
					@close-gap="ask"
					@open="$emit('open', $event)"
					@reset="answerReplaced"
					@void="strike" />
			</ul>
		</div>

		<div v-if="next !== null || below || failure !== ''" ref="sentinel" class="timeline__more">
			<!-- The refusal is the next page's, so it shows where that page would come. -->
			<NcNoteCard v-if="failure && !below" type="error" :text="failure" />
			<NcButton :disabled="loading && !below" @click="more">
				{{ failure && !below ? t('nextfleet', 'Try again') : t('nextfleet', 'Load more') }}
			</NcButton>
		</div>
		<NcLoadingIcon v-else-if="loading" class="timeline__waiting" />

		<!-- One Gap, one question, never a batch (CONTEXT.md). -->
		<NcDialog v-if="closing"
			:name="t('nextfleet', 'Close gap')"
			:open="true"
			size="small"
			@update:open="dismiss">
			<NcNoteCard v-if="closingFailure" type="error" :text="closingFailure" />
			<p>{{ question }}</p>
			<p>{{ t('nextfleet', 'The logbook marks it as reconciled: worked out from the counter, not a journey somebody recorded.') }}</p>

			<template #actions>
				<NcButton :disabled="writing" @click="dismiss">
					{{ t('nextfleet', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="writing || moved" @click="confirm">
					{{ closingFailure && !moved ? t('nextfleet', 'Try again') : t('nextfleet', 'Record private trip') }}
				</NcButton>
			</template>
		</NcDialog>
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

/* NcRadioGroup lays its buttons out in one row that never wraps, so six chips run off a phone's
   edge. The row has no class of its own, only a CSS module's. */
.timeline__chips :deep([class*='ncFormBox_row']) {
	flex-wrap: wrap;
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

.timeline__gap {
	/* A figure with its unit, which capitals would turn into a different unit. */
	text-transform: none;
	margin-inline-start: calc(var(--default-grid-baseline) * 2);
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
