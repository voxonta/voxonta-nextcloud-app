<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service\Search;

use OCP\Files\Search\ISearchComparison;

/** One field against one value: `name LIKE '2026%'`. */
class Comparison extends Operator implements ISearchComparison {
	public function __construct(
		private string $type,
		private string $field,
		private string|int|bool|\DateTime|array $value,
		private string $extra = '',
	) {
	}

	public function getType(): string {
		return $this->type;
	}

	public function getField(): string {
		return $this->field;
	}

	public function getValue(): string|int|bool|\DateTime|array {
		return $this->value;
	}

	public function getExtra(): string {
		return $this->extra;
	}
}
