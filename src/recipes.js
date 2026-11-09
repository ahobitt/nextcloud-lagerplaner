// SPDX-License-Identifier: AGPL-3.0-or-later
import { getLanguage } from '@nextcloud/l10n'
import { t } from './l10n.js'

export const UNITS = ['g', 'kg', 'ml', 'l', 'Stück', 'EL', 'TL', 'Prise', 'Bund', 'Dose', 'Packung', 'Zehe', 'Glas', 'nach Geschmack']
export const ALLERGENS = ['gluten', 'lactose', 'milk_protein', 'egg', 'tree_nuts', 'peanuts', 'soy', 'other']
export const CATEGORIES = ['main', 'soup', 'breakfast', 'dessert', 'side', 'campfire']

export const dietLabels = () => ({
	vegan: t('lagerplaner', 'Vegan'),
	vegetarian: t('lagerplaner', 'Vegetarian'),
	none: t('lagerplaner', 'With meat or fish'),
})

export const allergenLabels = () => ({
	gluten: t('lagerplaner', 'Gluten'),
	lactose: t('lagerplaner', 'Lactose'),
	milk_protein: t('lagerplaner', 'Milk protein'),
	egg: t('lagerplaner', 'Egg'),
	tree_nuts: t('lagerplaner', 'Tree nuts'),
	peanuts: t('lagerplaner', 'Peanuts'),
	soy: t('lagerplaner', 'Soy'),
	other: t('lagerplaner', 'Other allergens'),
})

export const categoryLabels = () => ({
	main: t('lagerplaner', 'Main dish'),
	soup: t('lagerplaner', 'Soup or stew'),
	breakfast: t('lagerplaner', 'Breakfast'),
	dessert: t('lagerplaner', 'Dessert'),
	side: t('lagerplaner', 'Side dish'),
	campfire: t('lagerplaner', 'Campfire'),
})

export function containsText(contains) {
	if (contains.length === 0) {
		return t('lagerplaner', 'No declared allergens')
	}
	const labels = allergenLabels()
	return t('lagerplaner', 'Contains {list}', { list: contains.map((a) => labels[a] ?? a).join(', ') })
}

const number = () => new Intl.NumberFormat(getLanguage(), { maximumFractionDigits: 2 })

/** „4,94 kg“, „13 Stück (aufgerundet)“ oder „nach Geschmack“ */
export function quantityText(ingredient) {
	if (ingredient.quantity === null) {
		return ingredient.unit
	}
	const text = `${number().format(ingredient.quantity)} ${ingredient.unit}`
	return ingredient.rounded
		? t('lagerplaner', '{amount} (rounded from {exact})', { amount: text, exact: number().format(ingredient.exact) })
		: text
}
