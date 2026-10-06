/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ChangedError, getVehicle, ImportRefusedError, NotFoundError, previewImport, RefusedError, runImport } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import ImportSheet from './ImportSheet.vue'

// The store is left real: the import's way back is what it holds, and the toast reads it there.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	getVehicle: vi.fn(),
	previewImport: vi.fn(),
	runImport: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({
	FilePickerClosed: class extends Error {},
	getFilePickerBuilder: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active', energy_types: ['diesel'], may: ['view', 'log', 'edit'] }
const ZONE = Intl.DateTimeFormat().resolvedOptions().timeZone

/**
 * A preview as the server answers one, with only what a test cares about spelled out.
 *
 * @param {object} [more] - what this one differs in
 * @return {import('../services/api.js').ImportPreview} the preview
 */
function preview(more = {}) {
	return {
		columns: { placed: [{ header: 'Anna\'s date', field: 'filled_at' }, { header: 'FuelConsumed', field: 'amount' }], ignored: ['FuelEconomy', 'R&D tags'] },
		questions: [],
		categories: [],
		category_defaults: [],
		counts: { new: 2, duplicate: 1, unreadable: 1, creates: 2 },
		// A duplicate carries no reason: its outcome says it (lib/Import/Proposal.php).
		reasons: [{ reason: 'number', count: 1 }],
		proposals: [
			{ row: 2, kind: 'energy', fields: {}, outcome: null, reason: null, column: null },
			{ row: 3, kind: 'energy', fields: {}, outcome: 'duplicate', reason: null, column: null },
			{ row: 4, kind: 'energy', fields: {}, outcome: 'unreadable', reason: 'number', column: 'Cost' },
		],
		etag: 'etag-1',
		...more,
	}
}

/** What the picker hands back next, or the close it rejects with. */
let picked = /** @type {Promise<any>} */ (Promise.resolve([]))

/**
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the sheet, once the picker answered
 */
async function sheet() {
	const wrapper = shallowMount(ImportSheet, {
		props: { vehicle: VEHICLE },
		global: {
			renderStubDefaultSlot: true,
			stubs: { NcDialog: { template: '<div class="dialog"><slot /><slot name="actions" /></div>' } },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} text - the button's words
 * @return {any} the button
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} label - the field's label
 * @return {any} the select
 */
function select(wrapper, label) {
	return wrapper.findAllComponents(NcSelect).find((/** @type {any} */ one) => one.props('inputLabel') === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} label - the field's label
 * @param {string} id - the option's id
 */
async function choose(wrapper, label, id) {
	const field = select(wrapper, label)
	await field.vm.$emit('update:modelValue', field.props('options').find((/** @type {any} */ one) => one.id === id))
	await flushPromises()
}

/**
 * Picks the format and asks for the preview.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} format - `importer/record type`
 */
async function previewed(wrapper, format = 'lubelogger/fuel') {
	await choose(wrapper, 'Format', format)
	await button(wrapper, 'Preview').vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	picked = Promise.resolve([{ fileid: 42, basename: 'fuel.csv' }])
	vi.mocked(getFilePickerBuilder).mockReturnValue(/** @type {any} */ ({
		setMultiSelect() { return this },
		allowDirectories() { return this },
		setMimeTypeFilter() { return this },
		setButtonFactory() { return this },
		build() { return { pickNodes: () => picked } },
	}))
	vi.mocked(previewImport).mockResolvedValue(preview())
	vi.mocked(getVehicle).mockResolvedValue(VEHICLE)
})

describe('the import sheet', () => {
	it('closes without a word when the picker is closed', async () => {
		picked = Promise.reject(new FilePickerClosed())

		const wrapper = await sheet()

		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(previewImport).not.toHaveBeenCalled()
	})

	/** Units the file does not say are preset and asked; the zone is the reader's. */
	it('previews the picked file in the format and units chosen', async () => {
		const wrapper = await sheet()
		expect(wrapper.text()).toContain('fuel.csv')
		await choose(wrapper, 'Format', 'lubelogger/fuel')
		expect(select(wrapper, 'Distance in the file').props('modelValue').id).toBe('km')

		await choose(wrapper, 'Volume in the file', 'us_gal')
		await button(wrapper, 'Preview').vm.$emit('click')
		await flushPromises()

		expect(previewImport).toHaveBeenCalledWith('v-1', {
			file_id: 42,
			importer: 'lubelogger',
			record_type: 'fuel',
			units: { distance: 'km', volume: 'us_gal' },
			tz: ZONE,
			include_duplicates: false,
		})
	})

	it('shows the columns, the counts, and each sample row left out with its reason', async () => {
		const wrapper = await sheet()

		await previewed(wrapper)

		const text = wrapper.text()
		expect(text).toContain('Anna\'s date → Date')
		expect(text).toContain('FuelConsumed → Quantity')
		expect(text).toContain('Not read: FuelEconomy, R&D tags')
		expect(text).toContain('New: 2. Already there: 1. Not readable: 1.')
		expect(text).toContain('Row 3: Already there.')
		expect(text).toContain('Row 4: Cost is not a number.')
		expect(text).not.toContain('Row 2:')
	})

	it('lets a keyboard reach the preview to scroll it', async () => {
		const wrapper = await sheet()
		expect(wrapper.find('[role="region"]').exists()).toBe(false)

		await previewed(wrapper)

		const region = wrapper.find('[role="region"][tabindex="0"]')
		expect(region.attributes('aria-label')).toBe('Preview')
		expect(region.text()).toContain('Not read: FuelEconomy, R&D tags')
	})

	/** Only what the file does not say is asked, and the import waits for the answer. */
	it('asks an open question as a field and previews again with the answer', async () => {
		vi.mocked(previewImport).mockResolvedValueOnce(preview({ questions: [{ name: 'date_order', choices: ['dmy', 'mdy'] }] }))
		const wrapper = await sheet()
		await previewed(wrapper)
		expect(button(wrapper, 'Import').props('disabled')).toBe(true)
		expect(wrapper.text()).toContain('The file does not say this. Answer it to import.')

		await choose(wrapper, 'Order of the dates', 'mdy')
		expect(wrapper.text()).not.toContain('The file does not say this.')

		expect(previewImport).toHaveBeenLastCalledWith('v-1', expect.objectContaining({ date_order: 'mdy' }))
		expect(button(wrapper, 'Import').props('disabled')).toBe(false)
		expect(select(wrapper, 'Order of the dates')).toBeDefined()
	})

	it('asks what each category text of a costs file means', async () => {
		vi.mocked(previewImport).mockResolvedValue(preview({
			categories: ['Versicherung', 'Inspektion'],
			questions: [{ name: 'category_map', choices: ['Versicherung', 'Inspektion'] }],
		}))
		const wrapper = await sheet()
		await previewed(wrapper, 'spritmonitor/costs')

		await choose(wrapper, 'Versicherung', 'expense.insurance')
		await choose(wrapper, 'Inspektion', 'maintenance.inspection')

		expect(previewImport).toHaveBeenLastCalledWith('v-1', expect.objectContaining({
			record_type: 'costs',
			category_map: { Versicherung: 'expense.insurance', Inspektion: 'maintenance.inspection' },
		}))
	})

	it('shows what a category code becomes by default and lets it be changed', async () => {
		vi.mocked(previewImport).mockResolvedValue(preview({
			categories: ['6', '9'],
			category_defaults: [{ text: '6', meaning: 'expense.tax' }, { text: '9', meaning: 'expense.other' }],
		}))
		const wrapper = await sheet()
		await previewed(wrapper, 'spritmonitor/costs')

		expect(select(wrapper, '6').props('modelValue')).toEqual(expect.objectContaining({ id: 'expense.tax' }))
		// Cleared, it would only show its default again.
		expect(select(wrapper, '6').props('clearable')).toBe(false)
		await choose(wrapper, '9', 'skip')

		expect(previewImport).toHaveBeenLastCalledWith('v-1', expect.objectContaining({ category_map: { 9: 'skip' } }))
		expect(select(wrapper, '9').props('modelValue')).toEqual(expect.objectContaining({ id: 'skip' }))
	})

	/** Rows already there are skipped unless the user says otherwise. */
	it('includes the duplicates when asked to', async () => {
		const wrapper = await sheet()
		await previewed(wrapper)

		await wrapper.findComponent({ name: 'NcFormBoxSwitch' }).vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(previewImport).toHaveBeenLastCalledWith('v-1', expect.objectContaining({ include_duplicates: true }))
	})

	it('imports against the preview\'s etag and hands the way back to the store', async () => {
		const created = [{ type: 'energy', uuid: 'e-1' }, { type: 'energy', uuid: 'e-2' }]
		vi.mocked(runImport).mockResolvedValue({ counts: preview().counts, created })
		const wrapper = await sheet()
		await previewed(wrapper)

		await button(wrapper, 'Import').vm.$emit('click')
		await flushPromises()

		expect(runImport).toHaveBeenCalledWith('v-1', expect.objectContaining({ file_id: 42, etag: 'etag-1' }))
		expect(useVehiclesStore().imported?.created).toEqual(created)
		expect(wrapper.emitted('imported')).toHaveLength(1)
	})

	it('previews again when the file changed since the preview', async () => {
		vi.mocked(runImport).mockRejectedValueOnce(new ChangedError('The file changed since the preview'))
		const wrapper = await sheet()
		await previewed(wrapper)
		vi.mocked(previewImport).mockResolvedValueOnce(preview({ etag: 'etag-2' }))

		await button(wrapper, 'Import').vm.$emit('click')
		await flushPromises()

		expect(previewImport).toHaveBeenCalledTimes(2)
		expect(wrapper.findAllComponents(NcNoteCard).map((one) => one.props('text')))
			.toContain('The file changed since the preview. This is what it holds now; check it and import again.')
		expect(wrapper.emitted('imported')).toBeUndefined()

		vi.mocked(runImport).mockResolvedValueOnce({ counts: preview().counts, created: [] })
		await button(wrapper, 'Import').vm.$emit('click')
		expect(runImport).toHaveBeenLastCalledWith('v-1', expect.objectContaining({ etag: 'etag-2' }))
	})

	it('says only why a changed file is no longer read', async () => {
		vi.mocked(runImport).mockRejectedValueOnce(new ChangedError('The file changed since the preview'))
		const wrapper = await sheet()
		await previewed(wrapper)
		vi.mocked(previewImport).mockRejectedValueOnce(new ImportRefusedError('Not a file an import reads: binary', 'binary', 3))

		await button(wrapper, 'Import').vm.$emit('click')
		await flushPromises()

		expect(wrapper.findAllComponents(NcNoteCard).map((one) => one.props('text'))).toEqual(['The file is not text.'])
	})

	it('says why the picker failed', async () => {
		picked = Promise.reject(new Error('Files is not reachable'))

		const wrapper = await sheet()

		expect(wrapper.findAllComponents(NcNoteCard).map((one) => one.props('text'))).toEqual(['Files is not reachable'])
		expect(wrapper.emitted('close')).toBeUndefined()
		await button(wrapper, 'Close').vm.$emit('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('says why a file is not read at all', async () => {
		vi.mocked(previewImport).mockRejectedValueOnce(new ImportRefusedError('Not a file an import reads: binary', 'binary', 3))
		const wrapper = await sheet()

		await previewed(wrapper)

		expect(wrapper.findAllComponents(NcNoteCard).map((one) => one.props('text'))).toContain('The file is not text.')
	})

	it('says where to choose the energy a fuel file needs', async () => {
		vi.mocked(previewImport).mockRejectedValueOnce(new RefusedError('the server, in English', 'no_energy'))
		const wrapper = await sheet()

		await previewed(wrapper)

		expect(wrapper.findAllComponents(NcNoteCard).map((one) => one.props('text')))
			.toContain('Choose the energy this vehicle takes under Edit vehicle first.')
	})

	it('says a file shared with the person is not theirs to import', async () => {
		vi.mocked(previewImport).mockRejectedValueOnce(new NotFoundError('No such vehicle'))
		const wrapper = await sheet()

		await previewed(wrapper)

		expect(wrapper.findAllComponents(NcNoteCard).map((one) => one.props('text')))
			.toContain('Only a file of your own can be imported, not one shared with you.')
	})
})
