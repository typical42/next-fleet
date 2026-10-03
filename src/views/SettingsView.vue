<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'

import { getPreferences, readInbox, savePreferences } from '../services/api.js'
import { jurisdictionWord, parseWhole } from '../utils/format.js'

/** The registered countries, as the server named them. @type {import('vue').Ref<{ key: string, name: string }[]>} */
const offered = ref([])
/** The stored key, which is not always one of the offered ones. */
const chosen = ref('')
const reclaimVat = ref(false)
const busy = ref(true)
const failure = ref('')

const options = computed(() => offered.value.map(({ key, name }) => ({
	id: key,
	label: jurisdictionWord(key, name),
})))

// A stored country the list no longer offers selects nothing, which is the honest answer: the
// dropdown cannot show what it is not offering, and the vehicle would still be written under it.
const selected = computed(() => options.value.find((option) => option.id === chosen.value) ?? null)

const grid = ref('')
/**
 * The figure the server holds, which the field goes back to on a refusal. Not reactive: nothing
 * is drawn from it.
 */
const stored = { gridFactor: /** @type {number|null} */ (null) }
const gridUnreadable = ref(false)

// An empty field is read at each vehicle's own country, not at the default chosen above, so
// every country's average is named.
const gridHelp = computed(() => {
	if (gridUnreadable.value) {
		return t('nextfleet', 'Whole grams per kWh, or empty')
	}
	const averages = offered.value
		.filter(({ grid_factor: average }) => average !== null)
		.map(({ key, name, grid_factor: average }) => t('nextfleet', '{country} {grams} g/kWh in {year}', {
			country: { value: jurisdictionWord(key, name), escape: false },
			grams: average.grams,
			year: average.year,
		}))
		.join('; ')

	return averages === ''
		? t('nextfleet', 'Empty: no country states an average')
		: t('nextfleet', 'Empty for the average of the country each vehicle is kept under: {averages}', { averages: { value: averages, escape: false } })
})

/** Shows what the server holds. */
function showGrid() {
	grid.value = stored.gridFactor === null ? '' : String(stored.gridFactor)
}

/** The inbox folder's file id, as the preference holds it. @type {import('vue').Ref<number|null>} */
const inboxFolder = ref(null)
/** Its path in Files, or null where it is gone. The preference holds only the id; the inbox names it. @type {import('vue').Ref<string|null>} */
const inboxPath = ref(null)

const inboxWords = computed(() => {
	if (inboxFolder.value === null) {
		return t('nextfleet', 'None chosen')
	}

	return inboxPath.value ?? t('nextfleet', 'The folder is gone, or no longer yours alone')
})

/** @param {import('../services/api.js').Settings} settings - the server's answer */
function hold(settings) {
	offered.value = settings.jurisdictions
	chosen.value = settings.preferences.jurisdiction
	reclaimVat.value = settings.preferences.reclaim_vat
	stored.gridFactor = settings.preferences.grid_factor
	showGrid()
	gridUnreadable.value = false
	inboxFolder.value = settings.preferences.inbox_folder
}

/** @return {Promise<void>} when the inbox folder is named, or known to be gone */
async function nameInbox() {
	inboxPath.value = inboxFolder.value === null ? null : (await readInbox()).folder?.path ?? null
}

onMounted(async () => {
	try {
		hold(await getPreferences())
		await nameInbox()
	} catch (error) {
		failure.value = error.message
	} finally {
		busy.value = false
	}
})

/**
 * A personal setting saves as it is changed - a settings page has no Save button. A refusal puts
 * the stored value back: a dropdown showing a country the server never accepted would claim the
 * next vehicle is written under it.
 *
 * @param {{ id: string }|null} option - what the dropdown now holds
 */
async function choose(option) {
	if (option === null || option.id === chosen.value) {
		return
	}

	const stored = chosen.value
	chosen.value = option.id
	busy.value = true
	failure.value = ''
	try {
		hold(await savePreferences({ jurisdiction: option.id }))
	} catch (error) {
		chosen.value = stored
		failure.value = error.message
	} finally {
		busy.value = false
	}
}

/**
 * Saves as it is changed, and a refusal puts the stored answer back, as for the country: a switch
 * showing net would claim figures the header does not show.
 *
 * @param {boolean} reclaims - what the switch now holds
 */
async function reclaim(reclaims) {
	const stored = reclaimVat.value
	reclaimVat.value = reclaims
	busy.value = true
	failure.value = ''
	try {
		hold(await savePreferences({ reclaim_vat: reclaims }))
	} catch (error) {
		reclaimVat.value = stored
		failure.value = error.message
	} finally {
		busy.value = false
	}
}

/**
 * Saves when the field is left rather than on every keystroke. An empty field clears the figure;
 * one it cannot read saves nothing, since a typo is not a cleared setting. A refusal puts the
 * stored figure back, as for the country.
 */
async function saveGrid() {
	const typed = grid.value.trim()
	const grams = typed === '' ? null : parseWhole(typed)
	gridUnreadable.value = typed !== '' && grams === null
	if (gridUnreadable.value || grams === stored.gridFactor) {
		return
	}

	busy.value = true
	failure.value = ''
	try {
		hold(await savePreferences({ grid_factor: grams }))
	} catch (error) {
		showGrid()
		failure.value = error.message
	} finally {
		busy.value = false
	}
}

/**
 * Nextcloud's own picker, one folder. The server takes only a folder of the person's own
 * (docs/architecture.md#the-inbox); a refusal leaves the one they had.
 *
 * @return {Promise<void>} when the pick is saved, refused, or the picker was closed
 */
async function chooseInbox() {
	failure.value = ''
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('nextfleet', 'Choose the inbox folder'))
			.setMultiSelect(false)
			.allowDirectories(true)
			.setMimeTypeFilter(['httpd/unix-directory'])
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

	await saveInbox(node.fileid)
}

/**
 * @param {number|null} folder - the folder's file id, or null to stop using one
 * @return {Promise<void>} when it is saved and named, or the refusal is on screen
 */
async function saveInbox(folder) {
	busy.value = true
	failure.value = ''
	try {
		hold(await savePreferences({ inbox_folder: folder }))
		await nameInbox()
	} catch (error) {
		failure.value = error.message
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<!-- The product name is not translated; everything around it is. -->
	<NcSettingsSection name="NextFleet"
		:description="t('nextfleet', 'Vehicles you add from now on are kept under this country: its units, its currency and its rules. The ones you already have keep theirs.')">
		<NcNoteCard v-if="failure" type="error" :text="failure" />

		<NcSelect :model-value="selected"
			:options="options"
			:input-label="t('nextfleet', 'Jurisdiction')"
			:disabled="busy"
			:clearable="false"
			label="label"
			@update:model-value="choose" />

		<NcCheckboxRadioSwitch :model-value="reclaimVat"
			type="switch"
			:disabled="busy"
			@update:model-value="reclaim">
			{{ t('nextfleet', 'I reclaim VAT') }}
		</NcCheckboxRadioSwitch>
		<p class="hint">
			{{ t('nextfleet', 'Cost figures are shown net of VAT.') }}
		</p>

		<NcTextField v-model="grid"
			:label="t('nextfleet', 'Grid factor for charging, g CO₂/kWh')"
			:helper-text="gridHelp"
			:error="gridUnreadable"
			:disabled="busy"
			inputmode="numeric"
			@change="saveGrid" />

		<div class="inbox">
			<p>
				{{ t('nextfleet', 'Inbox folder') }}:
				<strong class="inbox__folder">{{ inboxWords }}</strong>
			</p>
			<div class="inbox__actions">
				<NcButton :disabled="busy" @click="chooseInbox">
					{{ t('nextfleet', 'Choose folder') }}
				</NcButton>
				<NcButton v-if="inboxFolder !== null"
					variant="tertiary"
					:disabled="busy"
					@click="saveInbox(null)">
					{{ t('nextfleet', 'Stop using it') }}
				</NcButton>
			</div>
			<p class="hint">
				{{ t('nextfleet', 'Point the auto-upload of the Nextcloud mobile app at this folder. Its photos and PDFs that belong to no vehicle yet wait under Inbox in NextFleet. The app never moves or deletes them.') }}
			</p>
		</div>
	</NcSettingsSection>
</template>

<style scoped>
.hint {
	color: var(--color-text-maxcontrast);
}

.inbox {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.inbox__folder {
	overflow-wrap: anywhere;
}

.inbox__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
