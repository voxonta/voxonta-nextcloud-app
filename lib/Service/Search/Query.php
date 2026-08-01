<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service\Search;

use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchOrder;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;

/**
 * A search the file index can answer.
 *
 * Nextcloud publishes the interfaces for this but keeps the implementations in
 * its private namespace, so apps that build a query either reach into private
 * code or supply their own. These few classes are the second option: they
 * implement the published interfaces and nothing else, which is what keeps the
 * app safe across upgrades.
 */
class Query implements ISearchQuery {
	/**
	 * @param ISearchOrder[] $order
	 */
	public function __construct(
		private ISearchOperator $operator,
		private int $limit,
		private int $offset,
		private array $order,
		private ?IUser $user,
	) {
	}

	public function getSearchOperation(): ISearchOperator {
		return $this->operator;
	}

	public function getLimit(): int {
		return $this->limit;
	}

	public function getOffset(): int {
		return $this->offset;
	}

	public function getOrder(): array {
		return $this->order;
	}

	public function getUser(): ?IUser {
		return $this->user;
	}

	public function limitToHome(): bool {
		// Shared calls live outside the home directory — limiting to it would
		// hide every meeting a participant did not organise.
		return false;
	}

	public function getSelectFields(): array {
		return [];
	}
}
