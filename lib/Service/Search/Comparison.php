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

	/**
	 * Nextcloud's own implementations define this and interpolate operators
	 * into strings while building the query. The interface does not mention it,
	 * so the requirement only shows up at runtime — as "could not be converted
	 * to string", from inside code that never names us.
	 */
	public function __toString(): string {
		$value = $this->value instanceof \DateTime
			? $this->value->format(\DateTime::ATOM)
			: (is_array($this->value) ? implode(',', $this->value) : (string)$this->value);
		return $this->field . ' ' . $this->type . ' ' . $value;
	}
}
