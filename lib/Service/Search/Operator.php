<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service\Search;

use OCP\Files\Search\ISearchOperator;

/**
 * Query hints are how the storage backends pass notes to each other while
 * resolving a search. Nothing here sets any; the storage may.
 */
abstract class Operator implements ISearchOperator {
	private array $hints = [];

	public function getQueryHint(string $name, $default) {
		return $this->hints[$name] ?? $default;
	}

	public function setQueryHint(string $name, $value): void {
		$this->hints[$name] = $value;
	}
}
