<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, onMounted, ref } from 'vue'

import EntrySheet from '../components/EntrySheet.vue'
import InboxSheet from '../components/InboxSheet.vue'
import { attachDocument, thumbnailUrl } from '../services/api.js'
import { useInboxStore } from '../store/inbox.js'
import { shortDate } from '../utils/format.js'
import { t } from '../utils/l10n.js'

const props = defineProps({
	/** The fleet the navigation lists; the sheet offers those the session may file papers on. */
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle[]>} */
	vehicles: { type: Array, required: true },
})

const inbox = useInboxStore()
const loaded = ref(false)
const failure = ref('')

/**
 * The file the sheet is open on, or null.
 *
 * @type {import('vue').Ref<import('../services/api.js').Waiting|null>}
 */
const open = ref(null)

/**
 * A new cost the file is logged as, while the entry sheet is open on it: the sheet asked for it.
 *
 * @type {import('vue').Ref<{file: import('../services/api.js').Waiting, vehicle: string, type: 'energy'|'maintenance'|'expense', kind: string}|null>}
 */
const logging = ref(null)
const loggingOn = computed(() => props.vehicles.find((one) => one.uuid === logging.value?.vehicle) ?? null)
/** An entry saved whose file could not be filed on it. */
const unfiled = ref('')

/** The app's block on the personal settings page (lib/Settings/Personal.php). */
const settingsUrl = generateUrl('/settings/user/additional')

/**
 * Read on every opening, not only by the shell: auto-upload adds files while the app stays open.
 *
 * @return {Promise<void>} when the files are in, or the failure is on screen
 */
async function load() {
	failure.value = ''
	try {
		await inbox.load()
		loaded.value = true
	} catch (error) {
		failure.value = error.message
	}
}

onMounted(load)

/**
 * A file's own moment has no offset; it is shown where the person is.
 *
 * @param {import('../services/api.js').Waiting} file - one waiting
 * @return {string} its day
 */
function day(file) {
	return shortDate(file.mtime, -new Date(file.mtime * 1000).getTimezoneOffset())
}

/**
 * @param {string} vehicle - where the file went
 * @return {Promise<void>} when the grid shows what still waits
 */
async function attached(vehicle) {
	const fileId = /** @type {{file_id: number}} */ (open.value).file_id
	open.value = null
	await gone(fileId, vehicle)
}

/**
 * @param {number} fileId - the file attached
 * @param {string} vehicle - where it went
 * @return {Promise<void>} when the grid shows what still waits
 */
async function gone(fileId, vehicle) {
	inbox.attached(fileId, vehicle)
	unfiled.value = ''
	// The server sends the newest hundred; the older ones come once those are gone.
	if (inbox.files.length === 0 && inbox.count > 0) {
		await load()
	}
}

/**
 * The sheet hands over to the entry sheet: the file is the receipt of a cost not entered yet.
 *
 * @param {{vehicle: string, type: 'energy'|'maintenance'|'expense', kind: string}} asked - what to log it as
 */
function log(asked) {
	logging.value = { file: /** @type {import('../services/api.js').Waiting} */ (open.value), ...asked }
	open.value = null
	unfiled.value = ''
}

/**
 * Files the paper on the entry just written. The entry is the record and stays when that fails;
 * the file waits on the grid, to be attached to it by hand.
 *
 * @param {{uuid: string}} entry - the cost the entry sheet wrote
 * @return {Promise<void>} when it is filed, or the failure is on screen
 */
async function logged(entry) {
	const { file, vehicle, type, kind } = /** @type {NonNullable<typeof logging.value>} */ (logging.value)
	try {
		await attachDocument(vehicle, { file_id: file.file_id, kind, linked_type: type, linked_uuid: entry.uuid })
	} catch (error) {
		unfiled.value = t('nextfleet', 'The entry is saved, but {file} could not be attached to it: {reason}. Tap the file to attach it again.', { file: file.name, reason: error.message })
		return
	}
	await gone(file.file_id, vehicle)
}
</script>

<template>
	<div class="inbox">
		<h2>{{ t('nextfleet', 'Inbox') }}</h2>
		<NcEmptyContent v-if="failure"
			:name="t('nextfleet', 'The inbox could not be read')"
			:description="failure">
			<template #action>
				<NcButton variant="primary" @click="load">
					{{ t('nextfleet', 'Try again') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<NcLoadingIcon v-else-if="!loaded" />
		<NcEmptyContent v-else-if="inbox.folder === null"
			:name="t('nextfleet', 'No inbox folder')"
			:description="t('nextfleet', 'Choose a folder of your own under NextFleet in your personal settings, and point the auto-upload of the Nextcloud mobile app at it. A folder that was deleted or shared with you cannot be the inbox.')">
			<template #action>
				<NcButton :href="settingsUrl">
					{{ t('nextfleet', 'Open personal settings') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="inbox.files.length === 0"
			:name="t('nextfleet', 'Nothing waiting')"
			:description="t('nextfleet', 'Photos and PDFs saved in {folder} show here until they are attached to a vehicle.', { folder: inbox.folder.path })" />
		<template v-else>
			<NcNoteCard v-if="unfiled" type="error" :text="unfiled" />
			<p class="inbox__lead">
				{{ inbox.count > inbox.files.length
					? t('nextfleet', 'The newest {shown} of {count} files in {folder} that belong to no vehicle yet.', { shown: inbox.files.length, count: inbox.count, folder: inbox.folder.path })
					: t('nextfleet', 'Files in {folder} that belong to no vehicle yet. Tap one to attach it.', { folder: inbox.folder.path }) }}
			</p>
			<ul class="inbox__grid">
				<li v-for="file in inbox.files" :key="file.file_id">
					<button type="button" class="inbox__file" @click="open = file">
						<!-- Empty alt: the name below says what it is, and a screen reader would read it twice. -->
						<img :src="thumbnailUrl(file)" alt="" loading="lazy">
						<span class="inbox__name">{{ file.name }}</span>
						<span class="inbox__date">{{ day(file) }}</span>
					</button>
				</li>
			</ul>
		</template>

		<InboxSheet v-if="open"
			:file="open"
			:vehicles="vehicles"
			:preferred="inbox.lastVehicle"
			@attached="attached"
			@log="log"
			@close="open = null" />
		<EntrySheet v-if="logging && loggingOn"
			:vehicle="loggingOn"
			:receipt="{ kind: logging.type, at: logging.file.mtime }"
			@saved="logged"
			@close="logging = null" />
	</div>
</template>

<style scoped>
.inbox {
	padding: calc(var(--default-grid-baseline) * 4);
}

.inbox__lead,
.inbox__date {
	color: var(--color-text-maxcontrast);
}

.inbox__grid {
	display: grid;
	/* Two across at 320 px, as many as fit on a desk. */
	grid-template-columns: repeat(auto-fill, minmax(128px, 1fr));
	gap: calc(var(--default-grid-baseline) * 3);
	margin: calc(var(--default-grid-baseline) * 3) 0 0;
	padding: 0;
	list-style: none;
}

.inbox__file {
	display: flex;
	flex-direction: column;
	align-items: stretch;
	width: 100%;
	height: 100%;
	margin: 0;
	padding: calc(var(--default-grid-baseline) * 2);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: normal;
	text-align: start;
	cursor: pointer;
}

.inbox__file:hover,
.inbox__file:focus-visible {
	background: var(--color-background-hover);
}

.inbox__file img {
	width: 100%;
	aspect-ratio: 1;
	object-fit: cover;
	border-radius: var(--border-radius);
}

.inbox__name {
	margin-top: var(--default-grid-baseline);
	/* A camera's name is one long word; it breaks rather than widening the tile. */
	overflow-wrap: anywhere;
}

.inbox__date {
	font-size: 0.9em;
}
</style>
