<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { computed, onMounted, ref, watch } from 'vue'

import { ChangedError, ImportRefusedError, NotFoundError, previewImport, RefusedError } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { energyWord, EXPENSE_CATEGORIES, expenseWord, formatCount, MAINTENANCE_TYPES, maintenanceWord } from '../utils/format.js'
import { FORMATS, formatWord, placedWord, reasonWord, refusalWord, unitsAsked } from '../utils/imports.js'
import { t } from '../utils/l10n.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

const emit = defineEmits(['close', 'imported'])

const store = useVehiclesStore()

/**
 * The file picked in the person's own Files, or null until the picker answered.
 *
 * @type {import('vue').Ref<{fileid: number, basename: string}|null>}
 */
const file = ref(null)
/** The format step, then the preview of what the import would do. */
const step = ref(/** @type {'format'|'preview'} */ ('format'))

const formats = computed(() => FORMATS.map((one) => ({ id: `${one.importer}/${one.recordType}`, label: formatWord(one), format: one })))
/** @type {import('vue').Ref<{id: string, label: string, format: import('../utils/imports.js').Format}|null>} */
const format = ref(null)

// Both units always travel, asked or not: the server reads only what the file needs, and a
// Spritmonitor row naming its own fuel may need a volume the vehicle's energies did not suggest.
const distances = computed(() => [
	{ id: 'km', label: t('nextfleet', 'Kilometres') },
	{ id: 'mi', label: t('nextfleet', 'Miles') },
])
const volumes = computed(() => [
	{ id: 'l', label: t('nextfleet', 'Litres') },
	{ id: 'us_gal', label: t('nextfleet', 'US gallons') },
	{ id: 'uk_gal', label: t('nextfleet', 'UK gallons') },
])
// Preset metric: every vehicle counts in kilometres (docs/contributing.md), and no registered
// jurisdiction keeps miles or gallons. Only the file can say otherwise, so the user is asked.
const distance = ref(distances.value[0])
const volume = ref(volumes.value[0])
const asks = computed(() => (format.value === null ? [] : unitsAsked(format.value.format, props.vehicle)))

/** @type {import('vue').Ref<string|null>} */
const dateOrder = ref(null)
/** @type {import('vue').Ref<string|null>} */
const energy = ref(null)
/** @type {import('vue').Ref<Record<string, string>>} */
const categoryMap = ref({})
const includeDuplicates = ref(false)

/**
 * What the server answered for the current answers, or null while none is in.
 *
 * @type {import('vue').Ref<import('../services/api.js').ImportPreview|null>}
 */
const preview = ref(null)
const busy = ref(false)
const failure = ref('')
/** The import found the file changed since its preview, and the preview on screen is the new one. */
const changed = ref(false)

/** @param {string} name - a question @return {string[]|null} its choices while it is open */
function open(name) {
	return preview.value?.questions.find((one) => one.name === name)?.choices ?? null
}

const dateOrders = computed(() => [
	{ id: 'dmy', label: t('nextfleet', 'Day first, as 31/12/2024') },
	{ id: 'mdy', label: t('nextfleet', 'Month first, as 12/31/2024') },
])
const energies = computed(() => (open('energy') ?? props.vehicle.energy_types ?? [])
	.map((/** @type {string} */ id) => ({ id, label: energyWord(id) })))
const meanings = computed(() => [
	{ id: 'skip', label: t('nextfleet', 'Skip these rows') },
	...EXPENSE_CATEGORIES.map((one) => ({ id: `expense.${one}`, label: t('nextfleet', 'Expense: {category}', { category: expenseWord(one) }) })),
	...MAINTENANCE_TYPES.map((one) => ({ id: `maintenance.${one}`, label: t('nextfleet', 'Maintenance: {type}', { type: maintenanceWord(one) }) })),
])

/** What a category text becomes while the user leaves it: the format's meaning for its code. */
const defaults = computed(() => Object.fromEntries((preview.value?.category_defaults ?? []).map(({ text, meaning }) => [text, meaning])))

// A question stays a field once answered, so the answer can still be changed: it is no longer in
// the open list, but the choice is.
const asksDateOrder = computed(() => open('date_order') !== null || dateOrder.value !== null)
const asksEnergy = computed(() => open('energy') !== null || energy.value !== null)

const counts = computed(() => {
	const counted = preview.value?.counts
	if (!counted) {
		return ''
	}

	return t('nextfleet', 'New: {new}. Already there: {duplicate}. Not readable: {unreadable}.', {
		new: formatCount(counted.new),
		duplicate: formatCount(counted.duplicate),
		unreadable: formatCount(counted.unreadable),
	})
})

/** The sample's rows that will not become an entry, each with its reason. */
const leftOut = computed(() => (preview.value?.proposals ?? []).filter((one) => one.outcome !== null))

const importable = computed(() => preview.value !== null && preview.value.questions.length === 0 && preview.value.counts.creates > 0)

/**
 * The request a preview and the import are both asked with: the same body, so the import writes
 * what this preview counted.
 *
 * @return {import('../services/api.js').ImportAsked} the body
 */
function asked() {
	const chosen = /** @type {{format: import('../utils/imports.js').Format}} */ (format.value).format

	return {
		file_id: /** @type {{fileid: number}} */ (file.value).fileid,
		importer: chosen.importer,
		record_type: chosen.recordType,
		units: { distance: distance.value.id, volume: volume.value.id },
		tz: Intl.DateTimeFormat().resolvedOptions().timeZone,
		...(dateOrder.value === null ? {} : { date_order: dateOrder.value }),
		...(energy.value === null ? {} : { energy: energy.value }),
		...(Object.keys(categoryMap.value).length === 0 ? {} : { category_map: categoryMap.value }),
		include_duplicates: includeDuplicates.value,
	}
}

/**
 * @param {Error} error - what a preview or the import was refused with
 * @return {string} it in words
 */
function refusal(error) {
	if (error instanceof ImportRefusedError) {
		return refusalWord(error.reason, error.row)
	}
	// The entry sheet's words for the same gap (lib/Import/Answers.php).
	if (error instanceof RefusedError && error.reason === 'no_energy') {
		return t('nextfleet', 'Choose the energy this vehicle takes under Edit vehicle first.')
	}

	// A file shared with the person is refused like a missing one (docs/architecture.md#import),
	// and the server's words for that name only the vehicle.
	return error instanceof NotFoundError
		? t('nextfleet', 'Only a file of your own can be imported, not one shared with you.')
		: error.message
}

/** Bumped by each preview, so an answer to an older set of answers is dropped. */
let previews = 0

/** @return {Promise<void>} when the preview for the current answers is in, or refused */
async function show() {
	const question = ++previews
	busy.value = true
	failure.value = ''
	try {
		const answer = await previewImport(props.vehicle.uuid, asked())
		if (question === previews) {
			preview.value = answer
		}
	} catch (error) {
		if (question === previews) {
			preview.value = null
			failure.value = refusal(error)
		}
	} finally {
		if (question === previews) {
			busy.value = false
		}
	}
}

/** From the format step on to its preview. */
function next() {
	step.value = 'preview'
	changed.value = false
	show()
}

/** Back to the format; the answers belonged to that file's reading and go with it. */
function back() {
	step.value = 'format'
	preview.value = null
	failure.value = ''
	dateOrder.value = null
	energy.value = null
	categoryMap.value = {}
	includeDuplicates.value = false
}

// Every answer changes what the import would do, so it is previewed again rather than guessed at.
watch([dateOrder, energy, categoryMap, includeDuplicates], () => {
	if (step.value === 'preview') {
		changed.value = false
		show()
	}
}, { deep: true })

/**
 * @param {string} text - a category text of the file
 * @param {{id: string}|null} meaning - what the user said it is
 */
function mean(text, meaning) {
	const map = { ...categoryMap.value }
	if (meaning === null) {
		delete map[text]
	} else {
		map[text] = meaning.id
	}
	categoryMap.value = map
}

/**
 * The import, checked against the file this preview read. Its result and its undo are the toast's
 * (src/components/UndoToast.vue), so the sheet goes once it is done.
 *
 * @return {Promise<void>} when it is done, or the refusal is on screen
 */
async function run() {
	const etag = /** @type {import('../services/api.js').ImportPreview} */ (preview.value).etag
	busy.value = true
	failure.value = ''
	try {
		await store.bring(props.vehicle.uuid, { ...asked(), etag })
		emit('imported')
	} catch (error) {
		busy.value = false
		if (error instanceof ChangedError) {
			await show()
			// Only over a preview that came back: a changed file may now be refused outright.
			changed.value = preview.value !== null
			return
		}
		failure.value = refusal(error)
	}
}

/**
 * Nextcloud's own picker, one CSV file. There is no upload here: the file is the person's own in
 * their Files (docs/architecture.md#import).
 */
onMounted(async () => {
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('nextfleet', 'Choose an export file'))
			.setMultiSelect(false)
			.allowDirectories(false)
			.setMimeTypeFilter(['text/csv'])
			.setButtonFactory((selected) => [{
				label: t('nextfleet', 'Choose'),
				variant: 'primary',
				disabled: selected.length === 0,
				callback: () => {},
			}])
			.build()
			.pickNodes()
	} catch (error) {
		// A picker that failed rather than closed keeps the sheet up, with nothing but why.
		if (error instanceof FilePickerClosed) {
			emit('close')
		} else {
			failure.value = error.message
		}
		return
	}
	const [node] = nodes
	if (node?.fileid === undefined) {
		emit('close')
		return
	}

	file.value = { fileid: node.fileid, basename: node.basename }
})

/** A sheet whose import is on the way does not close: nobody would hear how it ended. */
function requestClose() {
	if (!busy.value) {
		emit('close')
	}
}
</script>

<template>
	<NcDialog v-if="file || failure"
		:name="t('nextfleet', 'Import from a file')"
		:open="true"
		size="normal"
		@update:open="requestClose">
		<div class="import" @keydown.esc.stop="requestClose">
			<!-- The name in the body, not the title: a long one would wrap under the close button. -->
			<p v-if="file" class="import__file">
				{{ file.basename }}
			</p>
			<NcNoteCard v-if="changed"
				type="warning"
				:text="t('nextfleet', 'The file changed since the preview. This is what it holds now; check it and import again.')" />
			<NcNoteCard v-if="failure" type="error" :text="failure" />

			<template v-if="file && step === 'format'">
				<NcSelect v-model="format"
					:options="formats"
					:input-label="t('nextfleet', 'Format')"
					:clearable="false"
					label="label" />
				<NcSelect v-if="asks.includes('distance')"
					v-model="distance"
					:options="distances"
					:input-label="t('nextfleet', 'Distance in the file')"
					:clearable="false"
					label="label" />
				<NcSelect v-if="asks.includes('volume')"
					v-model="volume"
					:options="volumes"
					:input-label="t('nextfleet', 'Volume in the file')"
					:clearable="false"
					label="label" />
			</template>

			<template v-else-if="file">
				<p class="import__format">
					{{ format?.label }}
				</p>
				<NcLoadingIcon v-if="busy && preview === null" />

				<!-- At 320 px the sheet scrolls, often with no field in the preview; this stop is
				     where a keyboard scrolls it from. -->
				<div v-if="preview !== null"
					class="import__preview"
					tabindex="0"
					role="region"
					:aria-label="t('nextfleet', 'Preview')">
					<p class="import__counts">
						{{ counts }}
					</p>

					<!-- Only what the file does not say is asked (docs/ui.md, "Importing"); this says
					     why Import is grey. -->
					<p v-if="preview.questions.length > 0" class="import__ask">
						{{ t('nextfleet', 'The file does not say this. Answer it to import.') }}
					</p>
					<NcSelect v-if="asksDateOrder"
						:model-value="dateOrders.find((one) => one.id === dateOrder) ?? null"
						:options="dateOrders"
						:input-label="t('nextfleet', 'Order of the dates')"
						:clearable="false"
						:disabled="busy"
						label="label"
						@update:model-value="dateOrder = $event?.id ?? null" />
					<NcSelect v-if="asksEnergy"
						:model-value="energies.find((one) => one.id === energy) ?? null"
						:options="energies"
						:input-label="t('nextfleet', 'Energy of the fill-ups')"
						:clearable="false"
						:disabled="busy"
						label="label"
						@update:model-value="energy = $event?.id ?? null" />
					<NcSelect v-for="text in preview.categories"
						:key="text"
						:model-value="meanings.find((one) => one.id === (categoryMap[text] ?? defaults[text])) ?? null"
						:options="meanings"
						:input-label="text"
						:placeholder="t('nextfleet', 'What these rows are')"
						:clearable="defaults[text] === undefined"
						:disabled="busy"
						label="label"
						@update:model-value="mean(text, $event)" />
					<NcFormBoxSwitch v-if="preview.counts.duplicate > 0 || includeDuplicates"
						v-model="includeDuplicates"
						:label="t('nextfleet', 'Import the rows already there as well')"
						:disabled="busy" />

					<h3 class="import__heading">
						{{ t('nextfleet', 'Columns') }}
					</h3>
					<ul class="import__list">
						<li v-for="column in preview.columns.placed" :key="column.header">
							{{ t('nextfleet', '{header} → {field}', { header: column.header, field: placedWord(column.field, format?.format.recordType ?? '') }) }}
						</li>
					</ul>
					<p v-if="preview.columns.ignored.length > 0" class="import__ignored">
						{{ t('nextfleet', 'Not read: {columns}', { columns: preview.columns.ignored.join(', ') }) }}
					</p>

					<template v-if="leftOut.length > 0">
						<h3 class="import__heading">
							{{ t('nextfleet', 'Rows left out, among the first fifty') }}
						</h3>
						<ul class="import__list">
							<li v-for="row in leftOut" :key="row.row">
								{{ t('nextfleet', 'Row {row}: {reason}', { row: row.row, reason: reasonWord(row.reason ?? row.outcome ?? '', row.column) }) }}
							</li>
						</ul>
					</template>
				</div>
			</template>
		</div>

		<template #actions>
			<NcButton v-if="!file" @click="requestClose">
				{{ t('nextfleet', 'Close') }}
			</NcButton>
			<template v-else-if="step === 'format'">
				<NcButton @click="requestClose">
					{{ t('nextfleet', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="format === null" @click="next">
					{{ t('nextfleet', 'Preview') }}
				</NcButton>
			</template>
			<template v-else>
				<NcButton :disabled="busy" @click="back">
					{{ t('nextfleet', 'Back') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy || !importable" @click="run">
					{{ t('nextfleet', 'Import') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<style scoped>
.import,
.import__preview {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
}

.import__file,
.import__list li {
	/* A file name or a header is one word as long as it likes; at 320 px it breaks. */
	overflow-wrap: anywhere;
}

.import__format,
.import__ask,
.import__ignored {
	color: var(--color-text-maxcontrast);
}

/* A bare h3 takes Nextcloud's big heading. */
.import__heading {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
	font-size: 1em;
	font-weight: bold;
}

.import__list {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
	list-style: disc;
}
</style>
