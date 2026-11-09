<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Recipes;

enum Diet: string {
	case Vegan = 'vegan';
	case Vegetarian = 'vegetarian';
	case Omnivore = 'none';
}
