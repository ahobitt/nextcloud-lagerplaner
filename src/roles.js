// SPDX-License-Identifier: AGPL-3.0-or-later
import { t } from './l10n.js'

/** Rollen in der Reihenfolge, in der sie angezeigt werden (Konzept 18.1). */
export const ROLES = ['leitung', 'programm', 'kueche', 'material', 'mitarbeit', 'lesen']

export const roleLabels = () => ({
	leitung: t('lagerplaner', 'Camp leader'),
	programm: t('lagerplaner', 'Programme team'),
	kueche: t('lagerplaner', 'Kitchen team'),
	material: t('lagerplaner', 'Material team'),
	mitarbeit: t('lagerplaner', 'Helper'),
	lesen: t('lagerplaner', 'Read only'),
})

export const roleDescriptions = () => ({
	leitung: t('lagerplaner', 'Everything, including team, assistant and deleting the camp'),
	programm: t('lagerplaner', 'Edit the programme and plan games'),
	kueche: t('lagerplaner', 'Dietary needs and menu variants'),
	material: t('lagerplaner', 'View the plan and tick off tasks; material planning follows'),
	mitarbeit: t('lagerplaner', 'View the plan and tick off tasks'),
	lesen: t('lagerplaner', 'View the plan only, without dietary needs'),
})
