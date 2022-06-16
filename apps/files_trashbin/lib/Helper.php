<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Bjoern Schiessle <bjoern@schiessle.org>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 * @author Vincent Petry <vincent@nextcloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\Files_Trashbin;

use OC\Files\FileInfo;
use OCP\Constants;
use OCP\Files\Cache\ICacheEntry;

class Helper {
	/**
	 * Retrieves the contents of a trash bin directory.
	 *
	 * @param string $dir path to the directory inside the trashbin
	 * or empty to retrieve the root of the trashbin
	 * @param string $user
	 * @param string $sortAttribute attribute to sort on or empty to disable sorting
	 * @param bool $sortDescending true for descending sort, false otherwise
	 * @return \OCP\Files\FileInfo[]
	 */
	public static function getTrashFiles($dir, $user, $sortAttribute = '', $sortDescending = false) {
		$result = [];
		$timestamp = null;

		$view = new \OC\Files\View('/' . $user . '/files_trashbin/files');

		if (ltrim($dir, '/') !== '' && !$view->is_dir($dir)) {
			throw new \Exception('Directory does not exists');
		}

		$mount = $view->getMount($dir);
		$storage = $mount->getStorage();
		$absoluteDir = $view->getAbsolutePath($dir);
		$internalPath = $mount->getInternalPath($absoluteDir);

		$originalLocations = \OCA\Files_Trashbin\Trashbin::getLocations($user);
		$dirContent = $storage->getCache()->getFolderContents($mount->getInternalPath($view->getAbsolutePath($dir)));
		foreach ($dirContent as $entry) {
			$entryName = $entry->getName();
			$name = $entryName;
			if ($dir === '' || $dir === '/') {
				$pathparts = pathinfo($entryName);
				$timestamp = substr($pathparts['extension'], 1);
				$name = $pathparts['filename'];
			} elseif ($timestamp === null) {
				// for subfolders we need to calculate the timestamp only once
				$parts = explode('/', ltrim($dir, '/'));
				$timestamp = substr(pathinfo($parts[0], PATHINFO_EXTENSION), 1);
			}
			$originalPath = '';
			$originalName = substr($entryName, 0, -strlen($timestamp) - 2);
			if (isset($originalLocations[$originalName][$timestamp])) {
				$originalPath = $originalLocations[$originalName][$timestamp];
				if (substr($originalPath, -1) === '/') {
					$originalPath = substr($originalPath, 0, -1);
				}
			}
			$type = $entry->getMimeType() === ICacheEntry::DIRECTORY_MIMETYPE ? 'dir' : 'file';
			$i = [
				'name' => $name,
				'mtime' => $timestamp,
				'mimetype' => $type === 'dir' ? 'httpd/unix-directory' : \OC::$server->getMimeTypeDetector()->detectPath($name),
				'type' => $type,
				'directory' => ($dir === '/') ? '' : $dir,
				'size' => $entry->getSize(),
				'etag' => '',
				'permissions' => Constants::PERMISSION_ALL - Constants::PERMISSION_SHARE,
				'fileid' => $entry->getId(),
			];
			if ($originalPath) {
				if ($originalPath !== '.') {
					$i['extraData'] = $originalPath . '/' . $originalName;
				} else {
					$i['extraData'] = $originalName;
				}
			}
			$result[] = new FileInfo($absoluteDir . '/' . $i['name'], $storage, $internalPath . '/' . $i['name'], $i, $mount);
		}

		if ($sortAttribute !== '') {
			return \OCA\Files\Helper::sortFiles($result, $sortAttribute, $sortDescending);
		}
		return $result;
	}

	public static function getTrashFilesById($dir, $user)
	{
		if($dir === '/') {
			$dir = $dirname = '.';
		} else {
			$dirname = preg_replace('/\.d(.*?)\//', '/', $dir . '/');
			$dirname = trim($dirname, '/');
		}
		$prefix = 'files_trashbin/files';
		$view = new \OC\Files\View('/' . $user . '/' . $prefix);
		$mount = $view->getMount($dir);
		$storage = $mount->getStorage();
		$absoluteDir = $view->getAbsolutePath($dir);
		$result = [];
		$query = \OC::$server->getDatabaseConnection()->getQueryBuilder();
		$query->select('id', 'timestamp', 'location')
			->from('files_trash')
			->where($query->expr()->eq('user', $query->createNamedParameter($user)));
		if($dirname === '.') {
			$query->andWhere($query->expr()->notlike('location', $query->createNamedParameter('%/%/%')));
		} else {
			$depthLimit = substr_count($dirname, '/') + 2;
			$query->andWhere($query->expr()->like('location', $query->createNamedParameter($dirname . '%')));
			$query->andWhere($query->expr()->notlike('location', $query->createNamedParameter(str_repeat('%/', $depthLimit) . '%')));
		}
		$result = $query->executeQuery();
		$array = [];
		while ($row = $result->fetch()) {
			$location = $row['location'];
			if(strpos($location, '/') && $dirname === '.') {
				$location = substr($location, 0, strpos($location, '/'));
			}
			if($location !== $dirname && array_key_exists($location, $array)) {
				continue;
			}

			if($location === $dirname) {
				["id" => $id, "timestamp" => $timestamp,] = $row;
				$array['root'][] = ["name" => $id, "deleted_at" => $timestamp];
			} else {
				$locationArr = explode('/', $location);
				$array[end($locationArr)] = ['type' => 'fakedir', 'deleted_at' => $row['timestamp']];
			}
		}
		$result->closeCursor();

		$files = $array['root'];
		unset($array['root']);
		$entries = [];

		foreach($files as $key => $file) { // lists files deleted from root
			[$name, $timestamp] = [$file['name'], $file['deleted_at']];
			$filename = $file['name'] . '.d' . $file['deleted_at'];
			$file = $storage->getCache()->get($prefix . '/' . $filename);
			if(!$file) {
				continue;
			}
			$type = $file->getMimeType();

			$i = [
				'name' => $name,
				'mtime' => $timestamp,
				'mimetype' => $type === 'httpd/unix-directory' ? $type : \OC::$server->getMimeTypeDetector()->detectPath($name),
				'type' => $type,
				'directory' => '/',
				'size' => $file->getSize(),
				'etag' => '',
				'permissions' => Constants::PERMISSION_ALL - Constants::PERMISSION_SHARE,
				'fileid' => $file->getId(),
				'extraData' => $name
			];

			$entries[] = new FileInfo($absoluteDir . '/' . $i['name'], $storage, $prefix . '/' . $i['name'], $i, $mount);
		}

		foreach($array as $key => $dir) { // create fake directories from which files were deleted
			if($dir['type'] === 'fakedir') {
				$i = [
					'name' => $key,
					'mtime' => $dir['deleted_at'],
					'mimetype' => 'httpd/unix-directory',
					'type' => 'dir',
					'directory' => '/',
					'size' => 0,
					'etag' => '',
					'permissions' => Constants::PERMISSION_ALL - Constants::PERMISSION_SHARE,
					'fileid' => 0,
					'fakeDir' => true
				];
				$entries[] = new FileInfo($absoluteDir . '/' . $i['name'], $storage, $prefix . '/' . $i['name'], $i, $mount);
			}
		}

		return $entries;
	}

	/**
	 * Format file infos for JSON
	 *
	 * @param \OCP\Files\FileInfo[] $fileInfos file infos
	 */
	public static function formatFileInfos($fileInfos) {
		$files = [];
		foreach ($fileInfos as $i) {
			$entry = \OCA\Files\Helper::formatFileInfo($i);
			$entry['id'] = $i->getId();
			$entry['etag'] = $entry['mtime']; // add fake etag, it is only needed to identify the preview image
			$entry['permissions'] = \OCP\Constants::PERMISSION_READ;
			$files[] = $entry;
		}
		return $files;
	}
}
