// SPDX-License-Identifier: AGPL-3.0-or-later
import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	main: 'src/main.js',
	admin: 'src/admin.js',
}, {
	// Styles landen in css/lagerplaner-main.css und css/lagerplaner-admin.css und werden vom
	// PageController beziehungsweise der Einstellungsseite geladen.
	inlineCSS: false,
	extractLicenseInformation: false,
})
