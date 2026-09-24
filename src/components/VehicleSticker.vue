<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { computed, ref } from 'vue'

import { stickerUrl } from '../services/api.js'
import { nameOf } from '../utils/format.js'
import { qrOf } from '../utils/qr.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

const open = ref(false)
const url = computed(() => stickerUrl(props.vehicle.uuid))
const code = computed(() => qrOf(url.value))

/** The browser prints (ADR 0005); the print styles below keep only the sticker. */
function print() {
	window.print()
}
</script>

<template>
	<NcButton @click="open = true">
		{{ t('nextfleet', 'QR sticker') }}
	</NcButton>
	<NcDialog v-if="open"
		:name="t('nextfleet', 'QR sticker')"
		:open="true"
		size="small"
		@update:open="open = $event">
		<figure class="nextfleet-sticker">
			<!-- Black on white in both themes: a scanner reads dark modules on a light ground. -->
			<svg role="img"
				:aria-label="t('nextfleet', 'QR code for {name}', { name: { value: nameOf(vehicle), escape: false } })"
				:viewBox="`0 0 ${code.size} ${code.size}`"
				shape-rendering="crispEdges">
				<rect :width="code.size" :height="code.size" fill="#fff" />
				<path :d="code.path" fill="#000" />
			</svg>
			<figcaption>
				<strong>{{ nameOf(vehicle) }}</strong>
				<span>{{ t('nextfleet', 'Scan to add an entry') }}</span>
			</figcaption>
		</figure>
		<p class="nextfleet-sticker__hint">
			{{ t('nextfleet', 'The code opens this address, which still asks for a login:') }}
			<a :href="url">{{ url }}</a>
		</p>
		<template #actions>
			<NcButton variant="primary" @click="print">
				{{ t('nextfleet', 'Print') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.nextfleet-sticker {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.nextfleet-sticker svg {
	width: min(100%, 200px);
	height: auto;
}

.nextfleet-sticker figcaption {
	display: flex;
	flex-direction: column;
	align-items: center;
}

.nextfleet-sticker__hint {
	margin-top: calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}
</style>

<style>
/*
 * Printed while the dialog is open, the page is the sticker alone. Keyed on the sticker being in
 * the page so no other print is touched; a browser without :has() prints the whole page.
 */
@media print {
	body:has(.nextfleet-sticker) * {
		visibility: hidden;
	}

	body:has(.nextfleet-sticker) .nextfleet-sticker,
	body:has(.nextfleet-sticker) .nextfleet-sticker * {
		visibility: visible;
		color: #000;
	}

	body:has(.nextfleet-sticker) .nextfleet-sticker {
		position: fixed;
		inset: 0 auto auto 0;
		width: 50mm;
	}
}
</style>
