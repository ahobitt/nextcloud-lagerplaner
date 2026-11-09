// SPDX-License-Identifier: AGPL-3.0-or-later
import { getLanguage } from '@nextcloud/l10n'
import { n, t } from './l10n.js'

/** "2027-07-24" als lokales Datum, ohne Zeitzonenverschiebung. */
export function parseDay(day) {
	const [y, m, d] = day.split('-').map(Number)
	return new Date(y, m - 1, d)
}

export function toDay(date) {
	const pad = (v) => String(v).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

export function formatDay(day, options = { weekday: 'long', day: 'numeric', month: 'long' }) {
	return new Intl.DateTimeFormat(getLanguage(), options).format(parseDay(day))
}

export function formatRange(start, end) {
	const format = new Intl.DateTimeFormat(getLanguage(), { day: 'numeric', month: 'short', year: 'numeric' })
	return start === end ? format.format(parseDay(start)) : format.formatRange(parseDay(start), parseDay(end))
}

export function dayCount(start, end) {
	return Math.round((parseDay(end) - parseDay(start)) / 86_400_000) + 1
}

export function endTime(start, minutes) {
	const [h, m] = start.split(':').map(Number)
	const total = h * 60 + m + minutes
	return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}

export function duration(minutes) {
	if (minutes < 60) {
		return n('lagerplaner', '%n minute', '%n minutes', minutes)
	}
	const hours = Math.floor(minutes / 60)
	const rest = minutes % 60
	return rest === 0
		? n('lagerplaner', '%n hour', '%n hours', hours)
		: t('lagerplaner', '{hours} h {minutes} min', { hours, minutes: rest })
}

export const mealLabels = () => ({
	breakfast: t('lagerplaner', 'Breakfast'),
	lunch: t('lagerplaner', 'Lunch'),
	snack: t('lagerplaner', 'Afternoon snack'),
	dinner: t('lagerplaner', 'Dinner'),
})
