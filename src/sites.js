// SPDX-License-Identifier: AGPL-3.0-or-later
import { getLanguage } from '@nextcloud/l10n'
import { formatDay } from './format.js'
import { n, t } from './l10n.js'

export const FEATURES = ['water', 'sanitary', 'kitchen', 'electricity', 'fireplace', 'accessible']

export const featureLabels = () => ({
	water: t('lagerplaner', 'Drinking water'),
	sanitary: t('lagerplaner', 'Toilets and showers'),
	kitchen: t('lagerplaner', 'Kitchen'),
	electricity: t('lagerplaner', 'Electricity'),
	fireplace: t('lagerplaner', 'Fireplace'),
	accessible: t('lagerplaner', 'Accessible'),
	capacity: t('lagerplaner', 'Capacity'),
	beds: t('lagerplaner', 'Beds'),
	distance: t('lagerplaner', 'Location'),
})

export const typeLabels = () => ({
	tent: t('lagerplaner', 'Tent site'),
	house: t('lagerplaner', 'Group house'),
	both: t('lagerplaner', 'Campsite and house'),
})

/** Drei Zustände: vorhanden, nicht vorhanden, unbekannt. Unbekannt ist nie „nein“. */
export const featureState = (value) => (value === true ? 'yes' : value === false ? 'no' : value === 'partial' ? 'partial' : 'unknown')
export const featureSymbol = { yes: '✓', partial: '◐', no: '✗', unknown: '?' }
export const featureStateLabels = () => ({ yes: t('lagerplaner', 'available'), partial: t('lagerplaner', 'partially available'), no: t('lagerplaner', 'not available'), unknown: t('lagerplaner', 'unknown') })

const number = () => new Intl.NumberFormat(getLanguage(), { maximumFractionDigits: 1 })
export const distanceText = (km) => (km === null ? '' : t('lagerplaner', '{km} km', { km: number().format(km) }))

export function sizeText(site) {
	const parts = []
	parts.push(site.capacity ? n('lagerplaner', 'up to %n person', 'up to %n people', site.capacity) : t('lagerplaner', 'capacity unknown'))
	if (site.beds) {
		parts.push(n('lagerplaner', '%n bed', '%n beds', site.beds))
	}
	return parts.join(', ')
}

/** Aktualität: Stand laut Quelle und Alter des Datenstands (Konzept 5.6, 5.7). */
export function ageText(site) {
	if (site.source === 'manual') {
		return t('lagerplaner', 'Own entry')
	}
	const parts = []
	if (site.sourceDate) {
		parts.push(t('lagerplaner', 'as of {date} according to the source', { date: formatDay(site.sourceDate, { day: 'numeric', month: 'short', year: 'numeric' }) }))
	}
	if (site.importedDaysAgo !== null) {
		parts.push(site.importedDaysAgo < 31
			? t('lagerplaner', 'imported recently')
			: n('lagerplaner', 'imported %n month ago', 'imported %n months ago', Math.floor(site.importedDaysAgo / 30)))
	}
	return parts.join(', ')
}

/** Arten von Rückmeldungen („Feedback / Angaben ergänzen“) */
export const feedbackKindLabels = () => ({
	outdated: t('lagerplaner', 'Information is no longer correct'),
	website: t('lagerplaner', 'Website has changed'),
	equipment: t('lagerplaner', 'Equipment is missing or wrong'),
	capacity: t('lagerplaner', 'Capacity has changed'),
	closed: t('lagerplaner', 'Place is closed'),
	name: t('lagerplaner', 'Name is wrong'),
	location: t('lagerplaner', 'Location on the map is wrong'),
	address: t('lagerplaner', 'Postcode or town is wrong'),
	operator: t('lagerplaner', 'Operator has changed'),
	new_site: t('lagerplaner', 'A campsite is missing'),
})
