<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Provenance;

/** Wer ein Planungselement erzeugt hat (Konzept 17.6). */
enum Origin: string {
	case Assistant = 'assistant';
	case Template = 'template';
	case Manual = 'manual';
	case GameImport = 'game_import';
	case Ai = 'ai';
}
