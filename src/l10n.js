// SPDX-License-Identifier: AGPL-3.0-or-later
import { n as translatePlural, t as translate } from '@nextcloud/l10n'

/**
 * Übersetzung für Vue-Vorlagen.
 *
 * @nextcloud/l10n maskiert eingesetzte Werte standardmäßig für HTML. Vue maskiert
 * Textinhalte und Attribute selbst; doppelt maskiert würde aus „Papier & Stifte“
 * sichtbar „Papier &amp; Stifte“. Deshalb hier ohne eigene Maskierung. Die Ergebnisse
 * dürfen nie mit v-html ausgegeben werden.
 */
const RAW = { escape: false, sanitize: false }

export const t = (app, text, vars, count) => translate(app, text, vars, count, RAW)
export const n = (app, singular, plural, count, vars) => translatePlural(app, singular, plural, count, vars, RAW)
