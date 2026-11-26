<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'

import { ConflictError, NotFoundError, RefusedError, createBudget, deleteBudget, listBudgets, updateBudget } from '../services/api.js'
import { may } from '../utils/access.js'
import { decimalComplaint, formatDecimal, formatMoney, parseDecimal, parseWhole } from '../utils/format.js'
import { t } from '../utils/l10n.js'

const props = defineProps({
	/**
	 * The vehicle as the sheet read it: its stored currency, and whether the reader owns it.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Vehicle>}
	 */
	vehicle: { type: Object, required: true },
	disabled: { type: Boolean, default: false },
})

/** @type {import('vue').Ref<import('../services/api.js').Budget[]>} */
const budgets = ref([])
const loaded = ref(false)
const busy = ref(false)
const failure = ref('')

/** The owner sets the budgets; whoever else sees the costs reads them. */
const owns = computed(() => may(props.vehicle, 'own'))
/** A budget is an amount in the vehicle's currency, so none can be set without one. */
const priced = computed(() => (props.vehicle.currency ?? '') !== '')

const year = ref(String(new Date().getFullYear()))
const amount = ref('')
const net = ref(false)

/** The budget being changed in its row, or null. */
const editing = ref(/** @type {string|null} */ (null))
const editAmount = ref('')
const editNet = ref(false)

/** What each refusal of BudgetService reads as. */
const REFUSALS = {
	no_currency: () => t('nextfleet', 'Set the currency first. A budget is an amount in the currency of the vehicle.'),
	year_taken: () => t('nextfleet', 'This year has a budget already. Change that one.'),
}

/** @return {Promise<void>} when the list is on screen, or why not */
async function read() {
	budgets.value = await listBudgets(props.vehicle.uuid)
	loaded.value = true
}

onMounted(async () => {
	try {
		await read()
	} catch (error) {
		failure.value = error.message
	}
})

/**
 * @param {import('../services/api.js').Budget} budget - one budget
 * @return {string} its amount, and whether it is net or gross
 */
function amountOf(budget) {
	const money = formatMoney(budget.amount, props.vehicle.currency)

	return budget.net ? t('nextfleet', '{amount} net', { amount: money }) : t('nextfleet', '{amount} gross', { amount: money })
}

/**
 * One write. A conflict reads the list again, so the next click goes out under the current token;
 * so does a budget deleted elsewhere, so its row goes.
 *
 * @param {() => Promise<void>} work - the write
 * @return {Promise<boolean>} whether it landed
 */
async function attempt(work) {
	busy.value = true
	failure.value = ''
	try {
		await work()
		return true
	} catch (error) {
		if (error instanceof ConflictError || error instanceof NotFoundError) {
			failure.value = error instanceof ConflictError
				? t('nextfleet', 'This budget was changed somewhere else. The list now shows it as it is.')
				: t('nextfleet', 'This budget no longer exists. The list now shows what is left.')
			editing.value = null
			await read().catch(() => {})
		} else {
			failure.value = (error instanceof RefusedError && REFUSALS[error.reason]?.()) || error.message
			// Set in another session: read again, so the budget to change is on screen.
			if (error instanceof RefusedError && error.reason === 'year_taken') {
				await read().catch(() => {})
			}
		}
		return false
	} finally {
		busy.value = false
	}
}

/**
 * @param {string} typed - the amount field
 * @return {number|null} cents above 0, or null and the reason on screen
 */
function cents(typed) {
	const value = parseDecimal(typed, 2)
	if (value === null || value === 0) {
		failure.value = decimalComplaint(typed, t('nextfleet', 'That is not an amount.'))
		return null
	}

	return value
}

/** Adds what the fields hold; a refusal leaves them as typed. */
async function add() {
	const calendarYear = parseWhole(year.value)
	if (calendarYear === null || calendarYear < 1000 || calendarYear > 9999) {
		failure.value = t('nextfleet', 'That is not a year.')
		return
	}
	const value = cents(amount.value)
	if (value === null) {
		return
	}
	await attempt(async () => {
		const made = await createBudget(props.vehicle.uuid, { year: calendarYear, amount: value, net: net.value })
		budgets.value = [...budgets.value, made].sort((a, b) => a.year - b.year)
		amount.value = ''
	})
}

/** @param {import('../services/api.js').Budget} budget - the budget to change */
function change(budget) {
	editing.value = budget.uuid
	editAmount.value = formatDecimal(budget.amount, 2)
	editNet.value = budget.net
	failure.value = ''
}

/** @param {import('../services/api.js').Budget} budget - the budget being changed */
async function save(budget) {
	const value = cents(editAmount.value)
	if (value === null) {
		return
	}
	if (await attempt(async () => {
		const written = await updateBudget(props.vehicle.uuid, budget, { amount: value, net: editNet.value })
		budgets.value = budgets.value.map((one) => (one.uuid === written.uuid ? written : one))
	})) {
		editing.value = null
	}
}

/**
 * Asks nothing: a budget is one amount, and setting the year again is the way back.
 *
 * @param {import('../services/api.js').Budget} budget - the budget
 */
async function remove(budget) {
	await attempt(async () => {
		await deleteBudget(props.vehicle.uuid, budget)
		budgets.value = budgets.value.filter((one) => one.uuid !== budget.uuid)
	})
}
</script>

<template>
	<div class="budgets">
		<NcNoteCard v-if="failure" type="error" :text="failure" />

		<ul v-if="budgets.length > 0" class="budgets__list">
			<li v-for="budget in budgets" :key="budget.uuid" class="budgets__row">
				<span class="budgets__year">{{ budget.year }}</span>
				<div v-if="editing === budget.uuid" class="budgets__edit">
					<NcTextField v-model="editAmount"
						:label="t('nextfleet', 'Amount')"
						:disabled="busy || disabled"
						inputmode="decimal" />
					<NcCheckboxRadioSwitch v-model="editNet" :disabled="busy || disabled">
						{{ t('nextfleet', 'The amount is net, without VAT') }}
					</NcCheckboxRadioSwitch>
					<div class="budgets__actions">
						<NcButton variant="primary" :disabled="busy || disabled" @click="save(budget)">
							{{ t('nextfleet', 'Save') }}
						</NcButton>
						<NcButton :disabled="busy || disabled" @click="editing = null">
							{{ t('nextfleet', 'Cancel') }}
						</NcButton>
					</div>
				</div>
				<template v-else>
					<span class="budgets__amount">{{ amountOf(budget) }}</span>
					<!-- Labels as in CostCentreList. -->
					<div v-if="owns" class="budgets__actions">
						<NcButton variant="tertiary"
							:aria-label="t('nextfleet', 'Change the budget for {year}', { year: String(budget.year) })"
							:disabled="busy || disabled"
							@click="change(budget)">
							{{ t('nextfleet', 'Change') }}
						</NcButton>
						<NcButton variant="tertiary"
							:aria-label="t('nextfleet', 'Delete the budget for {year}', { year: String(budget.year) })"
							:disabled="busy || disabled"
							@click="remove(budget)">
							{{ t('nextfleet', 'Delete') }}
						</NcButton>
					</div>
				</template>
			</li>
		</ul>
		<p v-else-if="loaded" class="budgets__hint">
			{{ t('nextfleet', 'No budgets yet.') }}
		</p>

		<template v-if="owns">
			<p v-if="!priced" class="budgets__hint">
				{{ t('nextfleet', 'Set the currency first. A budget is an amount in the currency of the vehicle.') }}
			</p>
			<div v-else class="budgets__add">
				<NcTextField v-model="year"
					:label="t('nextfleet', 'Year')"
					:disabled="busy || disabled || !loaded"
					inputmode="numeric"
					maxlength="4" />
				<NcTextField v-model="amount"
					:label="t('nextfleet', 'Amount')"
					:disabled="busy || disabled || !loaded"
					inputmode="decimal" />
				<NcCheckboxRadioSwitch v-model="net" :disabled="busy || disabled || !loaded">
					{{ t('nextfleet', 'The amount is net, without VAT') }}
				</NcCheckboxRadioSwitch>
				<NcButton :disabled="busy || disabled || !loaded" @click="add">
					{{ t('nextfleet', 'Add budget') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<style scoped>
.budgets__list {
	margin: calc(var(--default-grid-baseline) * 2) 0;
}

.budgets__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.budgets__year {
	font-weight: bold;
}

.budgets__amount {
	flex: 1 1 auto;
	overflow-wrap: anywhere;
}

.budgets__edit,
.budgets__add {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
	flex: 1 1 100%;
}

.budgets__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.budgets__hint {
	color: var(--color-text-maxcontrast);
}
</style>
