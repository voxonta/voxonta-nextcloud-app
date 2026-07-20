<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service\Search;

use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchOperator;

/** AND, OR, NOT over other operators. */
class BinaryOperator extends Operator implements ISearchBinaryOperator {
	/** @var ISearchOperator[] */
	private array $arguments;

	public function __construct(private string $type, ISearchOperator ...$arguments) {
		$this->arguments = $arguments;
	}

	public function getType(): string {
		return $this->type;
	}

	public function getArguments(): array {
		return $this->arguments;
	}
}
