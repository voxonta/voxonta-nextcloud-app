<?php

declare(strict_types=1);

namespace OCA\Voxonta\Service\Search;

use OCP\Files\FileInfo;
use OCP\Files\Search\ISearchOrder;

/** Sorting, applied by the database rather than by us. */
class Order implements ISearchOrder {
	public function __construct(
		private string $direction,
		private string $field,
		private string $extra = '',
	) {
	}

	public function getDirection(): string {
		return $this->direction;
	}

	public function getField(): string {
		return $this->field;
	}

	public function getExtra(): string {
		return $this->extra;
	}

	/**
	 * Used when results come from storages that cannot sort themselves, so it
	 * has to agree with what the database does for the same query.
	 */
	public function sortFileInfo(FileInfo $a, FileInfo $b): int {
		$result = match ($this->field) {
			'name' => strcmp($a->getName(), $b->getName()),
			'mtime' => $a->getMTime() <=> $b->getMTime(),
			'size' => $a->getSize() <=> $b->getSize(),
			default => 0,
		};
		return $this->direction === ISearchOrder::DIRECTION_DESCENDING
			? -$result : $result;
	}
}
