<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Games;

/**
 * Reduziert fremdes HTML auf einfache Textauszeichnung.
 *
 * Erlaubt sind nur Absätze, Zeilenumbrüche, Listen und Hervorhebungen, jeweils ohne
 * Attribute. Skripte, Bilder, Formulare und eingebettete Widgets (etwa die
 * Sternebewertung von Pfadfinder-Spiele.de) werden samt Inhalt entfernt, alle
 * übrigen Elemente durch ihren Text ersetzt.
 */
final class HtmlSanitizer {
	private const ALLOWED = ['p', 'br', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i'];
	private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'img', 'video', 'audio', 'noscript', 'template'];
	private const WIDGET_CLASSES = ['kk-star-ratings', 'kksr-'];

	public function clean(string $html): string {
		if (trim($html) === '') {
			return '';
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$document->loadHTML(
			'<?xml encoding="utf-8"?><div>' . $html . '</div>',
			LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$root = $document->getElementsByTagName('div')->item(0);
		if ($root === null) {
			return '';
		}
		$result = $this->render($root);
		$result = preg_replace('#<p>\s*</p>#u', '', $result) ?? $result;
		return trim(preg_replace('/\s+/u', ' ', $result) ?? $result);
	}

	private function render(\DOMNode $node): string {
		$html = '';
		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMText) {
				$html .= htmlspecialchars($child->nodeValue ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
				continue;
			}
			if (!$child instanceof \DOMElement) {
				continue;
			}
			$tag = strtolower($child->tagName);
			if (in_array($tag, self::DROP, true) || $this->isWidget($child)) {
				continue;
			}
			if ($tag === 'br') {
				$html .= '<br>';
				continue;
			}
			$inner = $this->render($child);
			$html .= in_array($tag, self::ALLOWED, true) ? "<$tag>$inner</$tag>" : $inner;
		}
		return $html;
	}

	private function isWidget(\DOMElement $element): bool {
		$class = $element->getAttribute('class');
		foreach (self::WIDGET_CLASSES as $marker) {
			if (str_contains($class, $marker)) {
				return true;
			}
		}
		return false;
	}
}
