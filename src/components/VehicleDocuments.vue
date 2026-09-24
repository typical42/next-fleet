<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { computed, ref, watch } from 'vue'

import { attachDocument, detachDocument, documentUrl, listDocuments, NotFoundError, readTimeline } from '../services/api.js'
import { DOCUMENT_KINDS, documentKindWord, entryName, shortDate } from '../utils/format.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

// The timeline shows the linked ones as paperclips, so the screen hands it this list
// (src/views/VehicleView.vue) rather than both reading it.
const emit = defineEmits(['listed'])

/**
 * The list as the server last answered, or null until it is read.
 *
 * @type {import('vue').Ref<import('../services/api.js').Document[]|null>}
 */
const documents = ref(null)
const failure = ref('')
const writing = ref(false)

/** The papers under their kinds, in a fixed order, kinds without any left out. */
const groups = computed(() => DOCUMENT_KINDS
	.map((kind) => ({ kind, papers: (documents.value ?? []).filter((one) => one.kind === kind) }))
	.filter((group) => group.papers.length > 0))

/** @param {import('../services/api.js').Document[]} list - as the server answered */
function show(list) {
	documents.value = list
	emit('listed', list)
}

/** Bumped by each read, so an answer for a vehicle the screen has left is dropped. */
let asked = 0

/** @return {Promise<void>} when the list is in, or the refusal is on screen */
async function load() {
	const question = ++asked
	documents.value = null
	failure.value = ''
	try {
		const list = await listDocuments(props.vehicle.uuid)
		if (question === asked) {
			show(list)
		}
	} catch (error) {
		if (question === asked) {
			failure.value = error.message
			// The paperclips on the timeline would otherwise be the last vehicle's.
			emit('listed', [])
		}
	}
}

// The shell keeps one vehicle screen and swaps the vehicle under it (src/App.vue).
watch(() => props.vehicle.uuid, load, { immediate: true })

/**
 * @param {import('../services/api.js').Document} paper - a linked one
 * @return {string} what it belongs to, in words
 */
function belongsWord(paper) {
	/** @type {Record<string, string>} */
	const words = {
		energy: t('nextfleet', 'Belongs to a fill-up'),
		maintenance: t('nextfleet', 'Belongs to a maintenance record'),
		expense: t('nextfleet', 'Belongs to an expense'),
	}

	return words[paper.linked_type ?? ''] ?? ''
}

/**
 * @param {import('../services/api.js').Document} paper - the one to take off
 * @return {Promise<void>} when it is off, or the refusal is on screen
 */
async function detach(paper) {
	writing.value = true
	failure.value = ''
	try {
		show(await detachDocument(props.vehicle.uuid, paper.uuid))
	} catch (error) {
		failure.value = t('nextfleet', 'The document was not removed: {reason}', { reason: error.message })
	} finally {
		writing.value = false
	}
}

/**
 * The file picked and waiting for its kind, or null when no question is open.
 *
 * @type {import('vue').Ref<{fileid: number, basename: string}|null>}
 */
const picked = ref(null)
/** @type {import('vue').Ref<string|null>} */
const kind = ref(null)
/** @type {import('vue').Ref<{id: string, label: string, type: string}|null>} */
const belongs = ref(null)
/**
 * What "Belongs to" offers: one option per entry, named as its timeline row is.
 *
 * @type {import('vue').Ref<{id: string, label: string, type: string}[]>}
 */
const owners = ref([])
const attachFailure = ref('')

const kinds = computed(() => DOCUMENT_KINDS.map((one) => ({ id: one, label: documentKindWord(one) })))

/**
 * The entries a paper may belong to: the newest page of each linkable kind, newest first. A receipt
 * is filed soon after the fill-up or the invoice it is for.
 *
 * @return {Promise<void>} when they are in; a refusal leaves none, since the link is optional
 */
async function readOwners() {
	owners.value = []
	try {
		const pages = await Promise.all(['energy', 'maintenance', 'expense']
			.map((type) => readTimeline(props.vehicle.uuid, { type, cursor: null })))
		owners.value = pages.flatMap((one) => one.rows)
			.sort((a, b) => b.occurred_at - a.occurred_at)
			.map((entry) => ({
				id: entry[entry.type].uuid,
				type: entry.type,
				label: `${shortDate(entry.occurred_at, entry.occurred_at_off)} · ${entryName(entry)}`,
			}))
	} catch {
		owners.value = []
	}
}

/**
 * Nextcloud's own picker, one file. There is no upload here: the Files app and the phone's
 * auto-upload already put the file there (docs/architecture.md#documents).
 *
 * @return {Promise<void>} when the question about the file is open, or the picker was closed
 */
async function add() {
	failure.value = ''
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('nextfleet', 'Choose a document'))
			.setMultiSelect(false)
			.allowDirectories(false)
			// The picker brings no button of its own; pickNodes() answers with what this one picked.
			.setButtonFactory((selected) => [{
				label: t('nextfleet', 'Choose'),
				variant: 'primary',
				disabled: selected.length === 0,
				callback: () => {},
			}])
			.build()
			.pickNodes()
	} catch (error) {
		if (!(error instanceof FilePickerClosed)) {
			failure.value = error.message
		}
		return
	}
	const [node] = nodes
	if (node?.fileid === undefined) {
		return
	}

	picked.value = { fileid: node.fileid, basename: node.basename }
	kind.value = null
	belongs.value = null
	attachFailure.value = ''
	await readOwners()
}

/** Closes the question, unless its answer is still on the way. */
function dismiss() {
	if (!writing.value) {
		picked.value = null
	}
}

/** @return {Promise<void>} when it is attached, or the refusal is on screen */
async function attach() {
	writing.value = true
	attachFailure.value = ''
	try {
		show(await attachDocument(props.vehicle.uuid, {
			file_id: /** @type {{fileid: number}} */ (picked.value).fileid,
			kind: /** @type {string} */ (kind.value),
			...(belongs.value === null ? {} : { linked_type: belongs.value.type, linked_uuid: belongs.value.id }),
		}))
		picked.value = null
	} catch (error) {
		// A file shared with the person is refused like a missing one (docs/architecture.md#documents),
		// and the server's words for that name only the vehicle.
		attachFailure.value = error instanceof NotFoundError
			? t('nextfleet', 'Only a file of your own can be attached, not one shared with you.')
			: error.message
	} finally {
		writing.value = false
	}
}
</script>

<template>
	<section class="documents">
		<div class="documents__head">
			<h3>{{ t('nextfleet', 'Documents') }}</h3>
			<!-- The only place a document is added: an entry's row opens its papers but adds none. -->
			<NcButton :disabled="writing" @click="add">
				{{ t('nextfleet', 'Add document') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="failure" type="error" :text="failure" />
		<NcLoadingIcon v-if="documents === null && failure === ''" />
		<p v-else-if="documents !== null && documents.length === 0" class="documents__empty">
			{{ t('nextfleet', 'No documents yet. Attach the registration, the insurance policy or a receipt from your Files.') }}
		</p>

		<div v-for="group in groups" :key="group.kind">
			<h4 class="documents__kind">
				{{ documentKindWord(group.kind) }}
			</h4>
			<ul class="documents__list">
				<li v-for="paper in group.papers" :key="paper.uuid" class="documents__paper">
					<span class="documents__name">
						<a v-if="paper.name !== null" :href="documentUrl(vehicle.uuid, paper.uuid)">{{ paper.name }}</a>
						<span v-else class="documents__gone">{{ t('nextfleet', 'The file is gone from Files') }}</span>
						<span v-if="paper.linked_type !== null" class="documents__belongs">{{ belongsWord(paper) }}</span>
					</span>
					<NcButton variant="tertiary"
						:aria-label="paper.name === null
							? t('nextfleet', 'Remove this document')
							: t('nextfleet', 'Remove {name}', { name: { value: paper.name, escape: false } })"
						:disabled="writing"
						@click="detach(paper)">
						{{ t('nextfleet', 'Remove') }}
					</NcButton>
				</li>
			</ul>
		</div>

		<!-- The file's name is in the body, not the title: a long one would wrap under the close button. -->
		<NcDialog v-if="picked"
			:name="t('nextfleet', 'Attach document')"
			:open="true"
			size="small"
			@update:open="dismiss">
			<NcNoteCard v-if="attachFailure" type="error" :text="attachFailure" />
			<p class="documents__picked">
				{{ picked.basename }}
			</p>
			<!-- A select, not a row of five buttons, which would not fit the dialog at 320 px. -->
			<NcSelect :model-value="kinds.find((one) => one.id === kind) ?? null"
				:options="kinds"
				:input-label="t('nextfleet', 'What it is')"
				:clearable="false"
				label="label"
				@update:model-value="kind = $event?.id ?? null" />
			<NcSelect v-if="owners.length > 0"
				v-model="belongs"
				:options="owners"
				:input-label="t('nextfleet', 'Belongs to')"
				:placeholder="t('nextfleet', 'The vehicle itself')"
				label="label" />

			<template #actions>
				<NcButton :disabled="writing" @click="dismiss">
					{{ t('nextfleet', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="writing || kind === null" @click="attach">
					{{ t('nextfleet', 'Attach') }}
				</NcButton>
			</template>
		</NcDialog>
	</section>
</template>

<style scoped>
.documents {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.documents__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.documents__empty,
.documents__kind,
.documents__belongs {
	color: var(--color-text-maxcontrast);
}

.documents__kind {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
	font-size: 0.9em;
	text-transform: uppercase;
}

.documents__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.documents__paper {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}

.documents__paper > :last-child {
	/* The name gives way at 320 px, the button does not. */
	flex-shrink: 0;
}

.documents__picked {
	overflow-wrap: anywhere;
}

.documents__name {
	display: flex;
	flex-wrap: wrap;
	min-width: 0;
	gap: 0 calc(var(--default-grid-baseline) * 2);
	/* A file name is one word as long as it likes; at 320 px it breaks rather than pushing the page. */
	overflow-wrap: anywhere;
}

.documents__name a {
	text-decoration: underline;
}

.documents__gone {
	color: var(--color-warning-text);
}

.documents__belongs {
	font-size: 0.9em;
}
</style>
