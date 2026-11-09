<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Access;

enum Permission: string {
	case View = 'view';
	case EditProgram = 'program';
	case EditTasks = 'tasks';
	case ToggleTasks = 'toggleTasks';
	/** Ernährungsangaben sind Gesundheitsdaten, auch aggregiert (Konzept 18.3). */
	case ViewCatering = 'cateringView';
	case EditCatering = 'catering';
	case RunWizard = 'wizard';
	case ManageMembers = 'members';
	/** Material anlegen, ändern und Programmpunkten zuordnen */
	case EditMaterial = 'material';
	/** Materialstatus setzen: vorhanden, gepackt, transportiert … */
	case PackMaterial = 'pack';
	/** Merkliste der Lagerplätze pflegen und den Lagerort festlegen */
	case ManageSites = 'sites';
	case DeleteCamp = 'delete';
}
