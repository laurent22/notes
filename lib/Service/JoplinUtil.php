<?php

namespace OCA\Notes\Service;

use OCP\IL10N;
use OCP\ILogger;
use OCP\Encryption\Exceptions\GenericEncryptionException;
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\Files\File;
use OCP\Files\Folder;

use OCA\Notes\Db\Note;

class JoplinUtil {

	private $l10n;
	private $root;
	private $logger;
	private $appName;

	/**
	 * @param IRootFolder $root
	 * @param IL10N $l10n
	 * @param ILogger $logger
	 * @param String $appName
	 */
	public function __construct(
		IRootFolder $root,
		IL10N $l10n,
		ILogger $logger,
		$appName
	) {
		$this->root = $root;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->appName = $appName;
	}

	public function getFoldersForUser($userId) {
		$path = '/' . $userId . '/files/Joplin'; // TODO: move to settings
		if (!$this->root->nodeExists($path)) throw new \Exception('No such path: ' . $path);
		$folder = $this->root->get($path);
		return [$folder];
	}

	public function getNotes($userId, $onlyMeta) {
		$items = $this->getItems($userId);
		$notes = [];
		foreach ($items as $item) {
			if ($item['type_'] !== 1) continue;
			$notes[] = $this->itemToNote($item, $onlyMeta);
		}
		return $notes;
	}

	private function getItems($userId) {
		$folders = $this->getFoldersForUser($userId);
		$files = $this->getItemFiles($folders);
		$items = [];
		try {
			foreach ($files as $file) {
				$item = $this->unserializeItem($file->getContent());
				$item['fileId_'] = $file->getId();
				$items[] = $item;
			}
		} catch (\Exception $e) {
			var_dump($e->getMessage());
			var_dump($e->getTraceAsString());
		}

		return $items;
	}

	public function getNote($userId, $id) {
		$notes = $this->getNotes($userId, false);
		foreach ($notes as $note) {
			if ($note->getId() === $id) return $note;
		}
		return null;
	}

	private function itemToNote($item, $onlyMeta = false) {
		$output = [];

		$note = new Note();

		$note->setId($item['fileId_']);
		if (!$onlyMeta) $note->setContent($item['body']);
		$note->setModified(1573592772); // TODO: Parse timestamp
		$note->setTitle($item['title']);
		$note->setCategory('');

		return $note;
	}

	private function unserializeItem($content) {
		$lines = explode("\n", $content);
		$output = [];
		$state = 'readingProps';
		$body = [];

		for ($i = count($lines) - 1; $i >= 0; $i--) {
			$line = $lines[$i];

			if ($state === 'readingProps') {
				$line = trim($line);

				if ($line === '') {
					$state = 'readingBody';
					continue;
				}

				$p = strpos($line, ':');
				if ($p === false) throw new \Exception("Invalid property format: $line: $content");
				$key = trim(substr($line, 0, $p));
				$value = trim(substr($line, $p + 1));
				$output[$key] = $value;
			} else if ($state === 'readingBody') {
				array_unshift($body, $line);
			}
		}

		if (!isset($output['type_'])) throw new \Exception("Missing required property: type_: $content");
		$output['type_'] = (int)$output['type_'];

		if (count($body)) {
			$title = array_shift($body);
			array_shift($body);
			$output['title'] = $title;
		}
		
		if ($output['type_'] === 1) $output['body'] = implode("\n", $body);
		
		// TODO:
		// const ItemClass = this.itemClass(output.type_);
		// output = ItemClass.removeUnknownFields(output);

		// for (let n in output) {
		// 	if (!output.hasOwnProperty(n)) continue;
		// 	output[n] = await this.unserialize_format(output.type_, n, output[n]);
		// }

		return $output;
	}

	private function getItemFiles($folders) {
		$notes = [];
		foreach ($folders as $folder) {
			$nodes = $folder->getDirectoryListing();
			foreach ($nodes as $node) {
				if ($node->getType() === FileInfo::TYPE_FOLDER) {
					continue;
				}
				if ($this->isJoplinItem($node)) {
					$notes[] = $node;
				}
			}
		}
		return $notes;
	}

	private function isJoplinItem($file) {
		$allowedExtensions = ['md'];

		if ($file->getType() !== 'file') {
			return false;
		}

		$ext = pathinfo($file->getName(), PATHINFO_EXTENSION);
		$iext = strtolower($ext);
		if (!in_array($iext, $allowedExtensions)) {
			return false;
		}
		return true;
	}

	public function isItemFile($userId, $id) {
		$items = $this->getItems($userId);
		foreach ($items as $item) {
			if ($item['fileId_'] === $id) return true;
		}
		return false;
	}

}
