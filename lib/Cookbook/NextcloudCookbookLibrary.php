<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Cookbook;

use OCA\Lagerplaner\Service\NotFoundException;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FileNotFound;
use OCP\IUserManager;

/**
 * Liest und schreibt Cookbook-Rezepte über die öffentlichen Datei-Schnittstellen von
 * Nextcloud. Es gelten die Rechte der Person: nur ihr eigener Rezeptordner, einschließlich
 * Ordnern, die mit ihr geteilt sind. Interne Klassen von Cookbook werden nicht benutzt.
 */
class NextcloudCookbookLibrary implements CookbookLibrary {
	public const APP = 'cookbook';
	public const MAX_RECIPES = 1000;
	public const MAX_BYTES = 1048576;

	public function __construct(
		private IAppManager $apps,
		private IUserManager $users,
		private IUserConfig $config,
		private IRootFolder $root,
	) {
	}

	public function status(string $uid): string {
		$user = $this->users->get($uid);
		if ($user === null || !$this->apps->isEnabledForUser(self::APP, $user)) {
			return self::NOT_INSTALLED;
		}
		return $this->folder($uid) === null ? self::NO_FOLDER : self::READY;
	}

	public function list(string $uid): array {
		$folder = $this->folder($uid);
		if ($folder === null) {
			return [];
		}
		$recipes = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof Folder || count($recipes) >= self::MAX_RECIPES) {
				continue;
			}
			$file = $this->recipeFile($node);
			$json = $file === null ? null : $this->decode($file);
			if ($json === null) {
				continue;
			}
			$yield = $json['recipeYield'] ?? null;
			$recipes[] = [
				'id' => (int)$node->getId(),
				'name' => is_string($json['name'] ?? null) && $json['name'] !== '' ? $json['name'] : $node->getName(),
				'category' => is_string($json['recipeCategory'] ?? null) ? $json['recipeCategory'] : '',
				'keywords' => is_string($json['keywords'] ?? null) ? $json['keywords'] : '',
				'servings' => is_int($yield) ? $yield : (is_string($yield) && ctype_digit(trim($yield)) ? (int)$yield : null),
				'modified' => (int)$file->getMTime(),
			];
		}
		usort($recipes, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
		return $recipes;
	}

	public function read(string $uid, int $id): array {
		$node = $this->root->getUserFolder($uid)->getFirstNodeById($id);
		$file = $node instanceof Folder ? $this->recipeFile($node) : null;
		$json = $file === null ? null : $this->decode($file);
		if ($json === null) {
			throw new NotFoundException('Dieses Rezept gibt es in Cookbook nicht mehr.');
		}
		return ['data' => $json, 'modified' => (int)$file->getMTime()];
	}

	public function create(string $uid, string $name, array $data): array {
		$folder = $this->folder($uid) ?? throw new NotFoundException('Cookbook hat noch keinen Rezeptordner. Bitte Cookbook einmal öffnen.');
		// Ordnernamen wie in Cookbook: der Rezeptname, ohne Zeichen, die Dateisysteme nicht erlauben.
		$base = trim(preg_replace('/[\/\\\\:*?"<>|]+/u', '-', $name) ?? '', " .-") ?: 'Rezept';
		$candidate = $base;
		for ($i = 2; $folder->nodeExists($candidate); $i++) {
			$candidate = $base . ' (' . $i . ')';
		}
		$recipeFolder = $folder->newFolder($candidate);
		$file = $recipeFolder->newFile('recipe.json', json_encode($data + ['name' => $candidate], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		return ['id' => (int)$recipeFolder->getId(), 'modified' => (int)$file->getMTime()];
	}

	/** Der Rezeptordner, wie Cookbook ihn in den Einstellungen der Person speichert. */
	private function folder(string $uid): ?Folder {
		$path = $this->config->getValueString($uid, self::APP, 'folder', '');
		if ($path === '') {
			return null;
		}
		try {
			$node = $this->root->getUserFolder($uid)->get($path);
		} catch (FileNotFound) {
			return null;
		}
		return $node instanceof Folder ? $node : null;
	}

	private function recipeFile(Folder $folder): ?File {
		try {
			$file = $folder->get('recipe.json');
		} catch (FileNotFound) {
			return null;
		}
		return $file instanceof File ? $file : null;
	}

	/** @return array<string, mixed>|null */
	private function decode(File $file): ?array {
		if ($file->getSize() > self::MAX_BYTES) {
			return null;
		}
		$json = json_decode((string)$file->getContent(), true);
		return is_array($json) ? $json : null;
	}
}
