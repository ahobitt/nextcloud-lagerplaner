// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Druckt ein vollständiges HTML-Dokument über einen unsichtbaren Rahmen. Im Druckdialog des
 * Browsers lässt es sich auch als PDF speichern (Konzept 19.1).
 */
export function printHtml(html) {
	const frame = document.createElement('iframe')
	frame.setAttribute('aria-hidden', 'true')
	frame.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden'
	document.body.appendChild(frame)
	frame.contentDocument.open()
	frame.contentDocument.write(html)
	frame.contentDocument.close()
	frame.contentWindow.focus()
	frame.contentWindow.print()
	setTimeout(() => frame.remove(), 1000)
}
