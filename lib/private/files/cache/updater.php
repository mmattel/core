<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Michael Gapczynski <GapczynskiM@gmail.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Files\Cache;

/**
 * Update the cache and propagate changes
 *
 */
class Updater {
	/**
	 * @var bool
	 */
	protected $enabled = true;

	/**
	 * @var \OC\Files\Storage\Storage
	 */
	protected $storage;

	/**
	 * @var \OC\Files\Cache\Propagator
	 */
	protected $propagator;

	/**
	 * @var Scanner
	 */
	protected $scanner;

	/**
	 * @var Cache
	 */
	protected $cache;

	/**
	 * @param \OC\Files\Storage\Storage $storage
	 */
	public function __construct(\OC\Files\Storage\Storage $storage) {
		$this->storage = $storage;
		$this->propagator = $storage->getPropagator();
		$this->scanner = $storage->getScanner();
		$this->cache = $storage->getCache();
	}

	/**
	 * Disable updating the cache trough this updater
	 */
	public function disable() {
		$this->enabled = false;
	}

	/**
	 * Re-enable the updating of the cache trough this updater
	 */
	public function enable() {
		$this->enabled = true;
	}

	/**
	 * Get the propagator for etags and mtime for the view the updater works on
	 *
	 * @return Propagator
	 */
	public function getPropagator() {
		return $this->propagator;
	}

	/**
	 * Propagate etag and mtime changes for the parent folders of $path up to the root of the filesystem
	 *
	 * @param string $path the path of the file to propagate the changes for
	 * @param int|null $time the timestamp to set as mtime for the parent folders, if left out the current time is used
	 */
	public function propagate($path, $time = null) {
		if (Scanner::isPartialFile($path)) {
			return;
		}
		$this->propagator->propagateChange($path, $time);
	}

	/**
	 * Update the cache for $path and update the size, etag and mtime of the parent folders
	 *
	 * @param string $path
	 * @param int $time
	 */
	public function update($path, $time = null) {
		if (!$this->enabled or Scanner::isPartialFile($path)) {
			return;
		}
		if (is_null($time)) {
			$time = time();
		}

		$data = $this->scanner->scan($path, Scanner::SCAN_SHALLOW, -1, false);
		$this->correctParentStorageMtime($path);
		$this->cache->correctFolderSize($path, $data);
		$this->propagator->propagateChange($path, $time);
	}

	/**
	 * Remove $path from the cache and update the size, etag and mtime of the parent folders
	 *
	 * @param string $path
	 */
	public function remove($path) {
		if (!$this->enabled or Scanner::isPartialFile($path)) {
			return;
		}

		$parent = dirname($path);
		if ($parent === '.') {
			$parent = '';
		}

		$this->cache->remove($path);
		$this->cache->correctFolderSize($parent);
		$this->correctParentStorageMtime($path);
		$this->propagator->propagateChange($path, time());
	}

	/**
	 * Rename a file or folder in the cache and update the size, etag and mtime of the parent folders
	 *
	 * @param \OC\Files\Storage\Storage $sourceStorage
	 * @param string $source
	 * @param string $target
	 */
	public function renameFromStorage(\OC\Files\Storage\Storage $sourceStorage, $source, $target) {
		if (!$this->enabled or Scanner::isPartialFile($source) or Scanner::isPartialFile($target)) {
			return;
		}

		$time = time();

		$sourceCache = $sourceStorage->getCache($source);
		$sourceUpdater = $sourceStorage->getUpdater();
		$sourcePropagator = $sourceStorage->getPropagator();

		if ($sourceCache->inCache($source)) {
			if ($this->cache->inCache($target)) {
				$this->cache->remove($target);
			}

			if ($sourceStorage === $this->storage) {
				$this->cache->move($source, $target);
			} else {
				$this->cache->moveFromCache($sourceCache, $source, $target);
			}
		}

		if (pathinfo($source, PATHINFO_EXTENSION) !== pathinfo($target, PATHINFO_EXTENSION)) {
			// handle mime type change
			$mimeType = $this->storage->getMimeType($target);
			$fileId = $this->cache->getId($target);
			$this->cache->update($fileId, ['mimetype' => $mimeType]);
		}

		$sourceCache->correctFolderSize($source);
		$this->cache->correctFolderSize($target);
		$sourceUpdater->correctParentStorageMtime($source);
		$this->correctParentStorageMtime($target);
		$this->updateStorageMTimeOnly($target);
		$sourcePropagator->propagateChange($source, $time);
		$this->propagator->propagateChange($target, $time);
	}

	private function updateStorageMTimeOnly($internalPath) {
		$fileId = $this->cache->getId($internalPath);
		if ($fileId !== -1) {
			$this->cache->update(
				$fileId, [
					'mtime' => null, // this magic tells it to not overwrite mtime
					'storage_mtime' => $this->storage->filemtime($internalPath)
				]
			);
		}
	}

	/**
	 * update the storage_mtime of the direct parent in the cache to the mtime from the storage
	 *
	 * @param string $internalPath
	 */
	public function correctParentStorageMtime($internalPath) {
		$parentId = $this->cache->getParentId($internalPath);
		$parent = dirname($internalPath);
		if ($parentId != -1) {
			$this->cache->update($parentId, array('storage_mtime' => $this->storage->filemtime($parent)));
		}
	}
}
