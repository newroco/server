<?php
/**
 * @copyright Copyright (c) 2018 Robin Appelman <robin@icewind.nl>
 *
 * @author Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\Files_Trashbin\Trash;

use OC\Files\Filesystem;
use OC\Files\View;
use OCA\Files_Trashbin\Helper;
use OCA\Files_Trashbin\Storage;
use OCA\Files_Trashbin\Trashbin;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IUser;

class LegacyTrashBackend implements ITrashBackend {
	/** @var array */
	private $deletedFiles = [];

	/** @var IRootFolder */
	private $rootFolder;

	public function __construct(IRootFolder $rootFolder) {
		$this->rootFolder = $rootFolder;
	}

	/**
	 * @param array $items
	 * @param IUser $user
	 * @param ITrashItem $parent
	 * @return ITrashItem[]
	 */
	private function mapTrashItems(array $items, IUser $user, ITrashItem $parent = null): array {
		$parentTrashPath = ($parent instanceof ITrashItem) ? $parent->getTrashPath() : '';
		$isRoot = $parent === null;
		return array_map(function (FileInfo $file) use ($parent, $parentTrashPath, $isRoot, $user) {
			$originalLocation = $isRoot ? $file['extraData'] : $parent->getOriginalLocation() . '/' . $file->getName();
			if (!$originalLocation) {
				$originalLocation = $file->getName();
			}
			return new TrashItem(
				$this,
				$originalLocation,
				$file->getMTime(),
				$parentTrashPath . '/' . $file->getName() . ($isRoot ? '.d' . $file->getMtime() : ''),
				$file,
				$user
			);
		}, $items);
	}

	public function listTrashRoot(IUser $user): array {
		$entries = Helper::getTrashFilesById('/', $user->getUID());
		return $this->mapTrashItems($entries, $user);
	}

	public function listTrashFolder(ITrashItem $folder): array {
		$folderInTrash = $folder->getName() . '.d' . $folder->getDeletedTime();
		$user = $folder->getUser();
		$userName = $user->getUID();
		$view = new View("/$userName/files_trashbin/files/");
		if($view->is_dir($folderInTrash)) {
			$entries = Helper::getTrashFiles($folderInTrash, $userName);
		} else {
			$entries = Helper::getTrashFilesById($folder->getTrashPath(), $userName);
		}
		return $this->mapTrashItems($entries, $user, $folder);
	}

	public function restoreItem(ITrashItem $item) {
		if($item->isFakeDir()) {
			return $this->restoreAllItemsFromFakeFolder($item->getUser()->getUID(), $item);
		}
		Trashbin::restore($item->getTrashPath(), $item->getName(), $item->getDeletedTime());
	}

	/**
	 * Removes all items from a fake folder
	 * 
	 * @param string $user
	 * @param ITrashItem $folder
	 * 
	 * @return void
	 */
	private function removeAllItemsFromFakeFolder($user, ITrashItem $folder): void
	{
		$location = str_replace('.d' . $folder->getDeletedTime(), '', trim($folder->getTrashPath(), '/'));
		$folderItems = Helper::getItemsForFolder($location, $user);

		foreach($folderItems as $item) {
			$item = array_values($item);
			array_splice($item, 1, 0, $user);
			Trashbin::delete(...$item);
		}
	}

	/**
	 * Restores all items from a fake folder
	 * 
	 * @param string $user
	 * @param ITrashItem $folder
	 * 
	 * @return void
	 */
	private function restoreAllItemsFromFakeFolder($user, ITrashItem $folder): void
	{
		$location = str_replace('.d' . $folder->getDeletedTime(), '', trim($folder->getTrashPath(), '/'));
		$folderItems = Helper::getItemsForFolder($location, $user);

		foreach($folderItems as $item) {
			$file = $item['id'] . '.d' . $item['timestamp'];
			$filename = $item['id'];
			$timestamp = $item['timestamp'];

			Trashbin::restore($file, $filename, $timestamp);
		}
	}

	public function removeItem(ITrashItem $item) {
		$user = $item->getUser();
		if($item->isFakeDir()) {
			return $this->removeAllItemsFromFakeFolder($user->getUID(), $item);
		}

		if ($item->isRootItem()) {
			$args = [$item->getName(), $user->getUID(), $item->getDeletedTime()];
		} else {
			$args = [$item->getTrashPath(), $user->getUID(), null];
		}
		Trashbin::delete(...$args);
	}

	public function moveToTrash(IStorage $storage, string $internalPath): bool {
		if (!$storage instanceof Storage) {
			return false;
		}
		$normalized = Filesystem::normalizePath($storage->getMountPoint() . '/' . $internalPath, true, false, true);
		$view = Filesystem::getView();
		if (!isset($this->deletedFiles[$normalized]) && $view instanceof View) {
			$this->deletedFiles[$normalized] = $normalized;
			if ($filesPath = $view->getRelativePath($normalized)) {
				$filesPath = trim($filesPath, '/');
				$result = \OCA\Files_Trashbin\Trashbin::move2trash($filesPath);
			} else {
				$result = false;
			}
			unset($this->deletedFiles[$normalized]);
		} else {
			$result = false;
		}

		return $result;
	}

	public function getTrashNodeById(IUser $user, int $fileId) {
		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$trash = $userFolder->getParent()->get('files_trashbin/files');
			$trashFiles = $trash->getById($fileId);
			if (!$trashFiles) {
				return null;
			}
			return $trashFiles ? array_pop($trashFiles) : null;
		} catch (NotFoundException $e) {
			return null;
		}
	}
}
