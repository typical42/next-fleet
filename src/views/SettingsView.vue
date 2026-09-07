<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { computed, onMounted, ref } from 'vue'

import { getPreferences, savePreferences } from '../services/api.js'
import { jurisdictionWord } from '../utils/format.js'

/** The registered countries, as the server named them. @type {import('vue').Ref<{ key: string, name: string }[]>} */
const offered = ref([])
/** The stored key, which is not always one of the offered ones. */
const chosen = ref('')
const busy = ref(true)
const failure = ref('')

const options = computed(() => offered.value.map(({ key, name }) => ({
	id: key,
	label: jurisdictionWord(key, name),
})))

// A stored country the list no longer offers selects nothing, which is the honest answer: the
// dropdown cannot show what it is not offering, and the vehicle would still be written under it.
const selected = computed(() => options.value.find((option) => option.id === chosen.value) ?? null)

/** @param {import('../services/api.js').Settings} settings - the server's answer */
function hold(settings) {
	offered.value = settings.jurisdictions
	chosen.value = settings.preferences.jurisdiction
}

onMounted(async () => {
	try {
		hold(await getPreferences())
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
	</NcSettingsSection>
</template>
