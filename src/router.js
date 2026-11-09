// SPDX-License-Identifier: AGPL-3.0-or-later
import { reactive } from 'vue'

/**
 * Sehr kleines Hash-Routing, damit keine Serverrouten für Unterseiten nötig sind.
 * #/            Übersicht
 * #/new         Lagerassistent für ein neues Lager
 * #/sites       Lagerplatzverzeichnis
 * #/camp/12     Lager
 * #/camp/12/assistant  Assistent erneut ausführen
 * #/camp/12/tasks      Lager mit geöffnetem Reiter (tasks, material, meals, shopping)
 */
export const route = reactive({ name: 'home', params: {} })

function parse() {
	const parts = window.location.hash.replace(/^#\/?/, '').split('/').filter(Boolean)
	if (parts[0] === 'templates') {
		return { name: 'templates', params: {} }
	}
	if (parts[0] === 'sites') {
		return { name: 'sites', params: {} }
	}
	if (parts[0] === 'new') {
		return { name: 'new', params: {} }
	}
	if (parts[0] === 'camp' && /^\d+$/.test(parts[1] ?? '')) {
		if (parts[2] === 'assistant') {
			return { name: 'rerun', params: { id: Number(parts[1]) } }
		}
		return { name: 'camp', params: { id: Number(parts[1]), tab: parts[2] ?? null } }
	}
	return { name: 'home', params: {} }
}

export function navigate(path) {
	window.location.hash = '#/' + path
}

export function startRouter() {
	const update = () => Object.assign(route, parse())
	window.addEventListener('hashchange', update)
	update()
}
