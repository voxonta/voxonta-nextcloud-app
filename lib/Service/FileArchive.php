<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * The archive, read from the user's own files.
 *
 * Meetings are folders holding summary.md and transcript.md, shared with the
 * people who were in the call. That means access control is Nextcloud's, not
 * ours: this class searches the *caller's* folder, so a meeting they were not
 * given cannot be found here — there is no check to forget, and no way to widen
 * it by accident.
 *
 * Files are located by system tag rather than by path. A share lands wherever
 * the recipient moved it, and people do move things; the tag survives that,
 * a hardcoded folder name does not.
 */
class FileArchive {
	public const TAG = 'Meeting transcript';

	private const SUMMARY = 'summary.md';
	private const TRANSCRIPT = 'transcript.md';

	public function __construct(
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Meetings this person can see, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list(string $userId, int $limit = 50, int $offset = 0): array {
		$meetings = [];
		foreach ($this->summaries($userId) as $summary) {
			$meta = $this->metadata($summary);
			if ($meta !== null) {
				$meetings[] = $meta;
			}
		}

		// Newest first: the call people look for is almost always a recent one.
		usort($meetings, static fn ($a, $b) => ($b['call_start_ts'] ?? 0) <=> ($a['call_start_ts'] ?? 0));
		return array_slice($meetings, $offset, $limit);
	}

	/**
	 * @throws BackendException
	 */
	public function summary(string $userId, string $sessionId): string {
		return $this->read($userId, $sessionId, self::SUMMARY);
	}

	/**
	 * @throws BackendException
	 */
	public function transcript(string $userId, string $sessionId): string {
		return $this->read($userId, $sessionId, self::TRANSCRIPT);
	}

	/**
	 * @throws BackendException
	 */
	private function read(string $userId, string $sessionId, string $name): string {
		foreach ($this->summaries($userId) as $summary) {
			$meta = $this->metadata($summary);
			if (($meta['session_id'] ?? null) !== $sessionId) {
				continue;
			}
			try {
				$folder = $summary->getParent();
				$file = $folder->get($name);
				return (string)$file->getContent();
			} catch (NotFoundException) {
				// transcript.md may legitimately be absent — a call where
				// nobody spoke produces a summary and nothing else.
				return '';
			} catch (\Throwable $e) {
				$this->logger->error('could not read {name} for {session}', [
					'name' => $name, 'session' => $sessionId, 'exception' => $e,
				]);
				throw new BackendException('could not read the meeting',
					Http::STATUS_SERVICE_UNAVAILABLE);
			}
		}

		// Not "no access" — from here the two are the same thing, and saying so
		// would confirm the meeting exists to someone who cannot open it.
		throw new BackendException('not found', Http::STATUS_NOT_FOUND);
	}

	/**
	 * @return File[]
	 */
	private function summaries(string $userId): array {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$found = $userFolder->searchBySystemTag(self::TAG, $userId);
		} catch (\Throwable $e) {
			$this->logger->error('could not search the archive for {user}', [
				'user' => $userId, 'exception' => $e,
			]);
			return [];
		}

		// Only summaries: the tag sits on every file in the meeting folder, and
		// counting transcript.md too would list each meeting twice.
		return array_values(array_filter(
			$found,
			static fn (Node $node) => $node instanceof File
				&& $node->getName() === self::SUMMARY,
		));
	}

	/**
	 * Read the YAML front matter a summary carries.
	 *
	 * Only the head of the file is parsed: the body is the summary itself and
	 * can be long, and a listing that read every body in full would load the
	 * whole archive to draw one screen.
	 *
	 * @return array<string, mixed>|null null when the file has no usable header
	 */
	private function metadata(File $node): ?array {
		try {
			$handle = $node->fopen('r');
			if ($handle === false) {
				return null;
			}
			$head = fread($handle, 4096);
			fclose($handle);
		} catch (\Throwable $e) {
			$this->logger->warning('could not read the header of {path}', [
				'path' => $node->getPath(), 'exception' => $e,
			]);
			return null;
		}

		if (!is_string($head) || !str_starts_with(ltrim($head), '---')) {
			return null;
		}
		$body = substr(ltrim($head), 3);
		$end = strpos($body, "\n---");
		if ($end === false) {
			return null;
		}

		$meta = [];
		foreach (explode("\n", substr($body, 0, $end)) as $line) {
			$parts = explode(':', trim($line), 2);
			if (count($parts) !== 2) {
				continue;
			}
			$meta[trim($parts[0])] = trim($parts[1]);
		}

		if (!isset($meta['session_id'])) {
			// Without an id the meeting cannot be addressed, so it is not one.
			return null;
		}

		return [
			'session_id' => (string)$meta['session_id'],
			'room_name' => (string)($meta['room'] ?? ''),
			'call_start_ts' => (int)($meta['start_ts'] ?? 0),
			'call_end_ts' => (int)($meta['end_ts'] ?? 0),
			'participants' => $this->listValue($meta['participants'] ?? ''),
			'has_transcript' => ($meta['has_transcript'] ?? 'true') !== 'false',
		];
	}

	/**
	 * @return string[]
	 */
	private function listValue(string $raw): array {
		$raw = trim($raw, " \t[]");
		if ($raw === '') {
			return [];
		}
		return array_values(array_filter(array_map(
			static fn ($v) => trim($v, " \"'"),
			explode(',', $raw),
		)));
	}
}
