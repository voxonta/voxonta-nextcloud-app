<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCA\DoneTranscription\Service\Search\BinaryOperator;
use OCA\DoneTranscription\Service\Search\Comparison;
use OCA\DoneTranscription\Service\Search\Order;
use OCA\DoneTranscription\Service\Search\Query;
use OCP\AppFramework\Http;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOrder;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * The archive, read from the files the transcription service already writes.
 *
 * Access control is Nextcloud's, and this is the whole design: a call reaches a
 * person only if it was shared with them, and the shares are what this class
 * reads. There is no permission rule here to enforce or to forget — a call that
 * was never shared is simply not in what comes back.
 *
 * Files come from two sources at once. A file search sees everything mounted
 * into the user's tree; the share manager sees shares directly. Neither is
 * complete alone — the search misses Talk-conversation shares that never mount,
 * the share manager's getSharedWith does not return Talk's per-user room shares
 * — so both are read and merged by file id.
 *
 * Two markdown formats live in the archive and both are read: current files
 * open with a YAML block, spring ones with a "# Транскрипция:" header.
 * Converting the old ones would rewrite documents people already hold.
 */
class FileArchive {
	private const HEADER_LEGACY = '# Транскрипция:';
	private const MINUTES_MARKER = 'Протокол';

	/**
	 * The analyser's own filenames. It writes a folder per call holding a dozen
	 * files and the service shares exactly two of them; the rest (speaker
	 * analysis, meeting dynamics) stays unshared and never reaches this code.
	 *
	 * Prefixes, not exact names: a recipient mounts every one of them flat into
	 * Talk/, so the second call's summary arrives as
	 * "01_Executive_Summary (33).md". Which is also why the two are paired by
	 * their folder in the file index rather than by anything in the name.
	 */
	private const ANALYSIS_SUMMARY = '01_Executive_Summary';
	private const ANALYSIS_TRANSCRIPT = '10_Original_Transcript';

	/**
	 * Where a call sits in the analyser's tree: ".../2026-07-21/004_status-…".
	 * The date orders the archive and answers a date filter, and the number
	 * orders the calls within a day — neither is in the recipient's filename.
	 */
	private const ANALYSIS_PATH = '#/(\d{4}-\d{2}-\d{2})/(\d+)_#';

	/**
	 * A transcript's filename starts with a date: "2026-03-24 …". The `_`
	 * matches exactly one character, so this is the shape of a date and not
	 * merely "starts with 20" — the looser form also matched prompt files named
	 * 20_extract_knowledge.md.
	 */
	private const NAME_PATTERN = '20__-__-__ %';

	/**
	 * Where the service keeps the files, for the account it writes as and for
	 * anyone the whole archive is shared to. Listing these directly is the fast
	 * path; a search across every mount is the slow fallback for participants
	 * who only have scattered shares.
	 */
	private const OWN_FOLDERS = ['Talk/Транскрипции', 'Talk/Протоколы'];

	/** The folder names, for finding a shared-in copy wherever it was mounted. */
	private const OWN_FOLDER_NAMES = ['Транскрипции', 'Протоколы'];

	/**
	 * The analyser's tree: <date>/<NNN>_<topic>/{01_…,10_…}.
	 *
	 * Where a call is whole — the transcript and the summary in one folder —
	 * so wherever this is readable it is the archive, and the loose transcripts
	 * the service also writes are the same calls a second time. They are read
	 * only for the months before the analyser existed; see cutoff().
	 */
	private const ANALYSIS_FOLDER = 'Talk/Аналитика встреч';
	private const ANALYSIS_FOLDER_NAME = 'Аналитика встреч';

	/**
	 * Upper bound on transcripts a search returns. Passing 0 for "no limit"
	 * makes Nextcloud drop the name filter and return every markdown file, so a
	 * large finite number is used instead — well past any real archive.
	 */
	private const MAX_RESULTS = 100000;

	/**
	 * Share types a recipient can hold. Numeric rather than the IShare::TYPE_*
	 * constants because the newer ones (USERROOM, DECK_USER) are absent from
	 * some versions of the published stubs, while the values are stable and the
	 * core takes an int. Legend: 0 user, 1 group, 2 usergroup, 10 Talk room,
	 * 11 Talk user-in-room, 13 Deck. The two Talk types are exactly what a file
	 * search cannot see.
	 */
	private const SHARE_TYPES = [0, 1, 2, 10, 11, 13];

	/** Shares fetched per page, per type. */
	private const SHARE_PAGE = 200;

	/**
	 * Most pages any one share type may take before we stop. At SHARE_PAGE=200
	 * this is 200 000 shares — far past any real archive, and only a backstop
	 * against a provider that ignores the offset and never advances.
	 */
	private const PAGE_GUARD = 1000;

	/** @var array<string, array<int, array{id: int, name: string, node: ?File, share: ?IShare}>> */
	private array $cache = [];

	/** @var array<string, IShare[]> every share held, read once per request */
	private array $shares = [];

	public function __construct(
		private IRootFolder $rootFolder,
		private IManager $shareManager,
		private IUserManager $userManager,
		private IDBConnection $db,
		private LoggerInterface $logger,
		private ICacheFactory $cacheFactory,
	) {
	}

	/**
	 * Parsed headers, kept between requests.
	 *
	 * Reading one is a file open through the storage layer — some 30ms — and a
	 * page is fifty of them, which is the whole cost of the list. The files
	 * never change once written, so a parse holds for as long as the cache
	 * keeps it, and only the first visit pays.
	 */
	private function headerCache(): \OCP\ICache {
		return $this->cacheFactory->createDistributed('done_transcription_headers');
	}

	/**
	 * Calls this person can see, newest first.
	 *
	 * Candidate names are cheap — a share carries its target path without the
	 * file being loaded — so the full list is filtered and sorted on names
	 * alone, and only the page about to be shown has its header read. Paging is
	 * over transcripts, and the offset the caller gets back is where to resume.
	 *
	 * @return array{meetings: array<int, array<string, mixed>>,
	 *               next_offset: int, has_more: bool}
	 */
	public function list(string $userId, int $limit = 50, int $offset = 0,
		string $query = '', int $from = 0, int $to = 0, string $room = ''): array {
		$candidates = $this->transcriptCandidates($userId);

		// Filtering is on the filename, which carries the participants and the
		// meeting name as well as the date — so a search for a person or a
		// subject, and a date range, both work without opening a single file.
		if ($query !== '' || $from !== 0 || $to !== 0 || $room !== '') {
			$candidates = array_values(array_filter(
				$candidates,
				fn (array $e) => $this->matchesFilter($userId, $e, $query, $from, $to, $room),
			));
		}
		$tHeaders = microtime(true);

		$meetings = [];
		$scanned = 0;
		$day = '';
		foreach (array_slice($candidates, $offset) as $entry) {
			// A page runs to the end of a day even if that overshoots the limit.
			// Which call came first is only known from its header, and a day cut
			// between two pages could not be put back in order — nor could the
			// list say "Today" once for it.
			if (count($meetings) >= $limit && $this->day($entry) !== $day) {
				break;
			}
			$scanned++;
			$meta = $this->metadataFor($userId, $entry);
			if ($meta !== null) {
				$meetings[] = $meta;
				$day = $this->day($entry);
			}
		}

		// Now that the headers have been read, the calls can be put in the order
		// they were held. The analyser numbers its folders as it processes them,
		// which is close to chronological but not it: a long call ends after a
		// short one that started later.
		usort($meetings, static fn (array $a, array $b) =>
			$b['call_start_ts'] <=> $a['call_start_ts']);

		$this->logger->debug('archive listing for {user}: {total} candidates, '
			. '{scanned} scanned from {offset}, {kept} shown, headers {ms}ms', [
				'user' => $userId,
				'total' => count($candidates),
				'scanned' => $scanned,
				'offset' => $offset,
				'kept' => count($meetings),
				'ms' => round((microtime(true) - $tHeaders) * 1000),
			]);

		return [
			'meetings' => $meetings,
			'next_offset' => $offset + $scanned,
			'has_more' => ($offset + $scanned) < count($candidates),
		];
	}

	/**
	 * The minutes, when the analyser has produced them.
	 *
	 * @throws BackendException
	 */
	public function summary(string $userId, string $sessionId): string {
		$entry = $this->candidates($userId)[(int)$sessionId] ?? null;
		if ($entry === null) {
			throw new BackendException('not found', Http::STATUS_NOT_FOUND);
		}
		$file = $this->summaryFile($userId, $entry);

		// A call transcribed but not analysed is a normal state, not an error.
		return $file === null ? '' : $this->contents($file);
	}

	/**
	 * The file holding a meeting's summary, whichever shape the archive takes.
	 *
	 * @param array<string, mixed> $entry
	 */
	private function summaryFile(string $userId, array $entry): ?File {
		$candidates = $this->candidates($userId);

		// A call whose transcript was never shared: the summary is the call.
		if (str_starts_with($entry['name'], self::ANALYSIS_SUMMARY)) {
			return $this->resolve($userId, $entry);
		}

		// Analyser output: the summary is the file that sits in the same meeting
		// folder. The folder is known from the file index, not from the mounted
		// name — a recipient's copies are all flat in Talk/ with the meeting
		// nowhere in the name, so pairing on names would attach a stranger's
		// summary to this call.
		if (str_starts_with($entry['name'], self::ANALYSIS_TRANSCRIPT)) {
			$folder = $entry['folder'] ?? 0;
			foreach ($folder === 0 ? [] : $candidates as $other) {
				if (($other['folder'] ?? 0) === $folder
					&& str_starts_with($other['name'], self::ANALYSIS_SUMMARY)) {
					return $this->resolve($userId, $other);
				}
			}
			// A transcript with no summary shared beside it. Not an error.
			return null;
		}

		// Older calls: minutes are a separate file whose name starts with the
		// same timestamp as the transcript.
		$prefix = $this->timestampPrefix((string)$entry['name']);
		if ($prefix === '') {
			return null;
		}
		foreach ($candidates as $id => $other) {
			if ($id === (int)$entry['id']
				|| !str_contains($other['name'], self::MINUTES_MARKER)
				|| $this->timestampPrefix($other['name']) !== $prefix) {
				continue;
			}
			$file = $this->resolve($userId, $other);
			if ($file !== null) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * @throws BackendException
	 */
	public function transcript(string $userId, string $sessionId): string {
		$file = $this->fileFor($userId, $sessionId);

		// A summary standing in for a call has no transcript behind it — and
		// returning the summary again here would read as one.
		if (str_starts_with($file->getName(), self::ANALYSIS_SUMMARY)) {
			return '';
		}
		return $this->contents($file);
	}

	/**
	 * The file behind a call id, if this person may see it.
	 *
	 * The id is looked up among the caller's own shares, so a file never shared
	 * with them is not there — the lookup is the permission check.
	 *
	 * @throws BackendException
	 */
	private function fileFor(string $userId, string $sessionId): File {
		$entry = $this->candidates($userId)[(int)$sessionId] ?? null;
		$file = $entry === null ? null : $this->resolve($userId, $entry);
		if ($file !== null) {
			return $file;
		}

		// Not "no access" — from here the two are indistinguishable, and saying
		// which would confirm the call exists to someone who cannot open it.
		throw new BackendException('not found', Http::STATUS_NOT_FOUND);
	}

	/**
	 * Every markdown file the user can reach, by file id, cheaply: names come
	 * from share targets and directory listings, without loading the files.
	 *
	 * @return array<int, array{name: string, node: ?File, share: ?IShare}>
	 */
	private function candidates(string $userId): array {
		if (isset($this->cache[$userId])) {
			return $this->cache[$userId];
		}

		$entries = [];
		$t0 = microtime(true);

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			$this->logger->error('could not open the files of {user}', [
				'user' => $userId, 'exception' => $e,
			]);
			return $this->cache[$userId] = [];
		}

		// The archive folders, read from the file index by their parent id. Only
		// names and ids come back — no File objects — because building a File
		// for each of a few thousand entries is what made this slow, whether
		// through a search or a directory listing (five seconds for an admin).
		// The heavy objects are built later, for the fifty rows actually shown.
		//
		// The folders are found by name among the user's shares, not by a fixed
		// path, because a recipient mounts a shared folder wherever they like —
		// on this instance the archive lands under /Shares, not Talk/. The
		// service account owns them outright, so its own paths are tried too.
		// The analyser's tree first, because it is the archive wherever it is
		// readable: each call there is whole, and its date decides which of the
		// loose transcripts below are the same calls said twice.
		$analysisId = $this->analysisFolderId($userId, $userFolder);
		if ($analysisId !== null) {
			$this->addAnalysedCalls($analysisId, $entries);
		}

		$folderIds = $this->archiveFolderIds($userId, $userFolder);
		foreach ($folderIds as $folderId) {
			try {
				$rows = $this->indexRows($folderId);
			} catch (\Throwable $e) {
				$this->logger->warning('could not read the index for folder {id}', [
					'id' => $folderId, 'exception' => $e,
				]);
				continue;
			}
			foreach ($rows as $row) {
				$id = (int)$row['fileid'];
				$entries[$id] ??= [
					'id' => $id,
					'name' => (string)$row['name'],
					'node' => null,
					'share' => null,
				];
			}
		}

		// The fallback: an ordinary participant has no such folder, only shares
		// scattered across conversations. A name-filtered search finds those,
		// and their account has few mounts to span, so it stays quick. Skipped
		// entirely when the folders already answered.
		if ($entries === []) {
			$entries = $this->searchByName($userId, $userFolder);
		}

		$fromFolders = count($entries);

		// File shares — the calls shared to a participant individually or into a
		// conversation, which an archive folder does not cover.
		$this->addFileShares($userId, $entries);

		// The analyser's files carry nothing in their mounted name, so what
		// orders them and pairs them comes from the index.
		$this->annotateAnalysis($entries);

		$this->logger->debug('candidate files for {user}: {folders} from '
			. '{n} folders, {total} total after shares ({ms}ms)', [
				'user' => $userId,
				'folders' => $fromFolders,
				'n' => count($folderIds),
				'total' => count($entries),
				'ms' => round((microtime(true) - $t0) * 1000),
			]);

		return $this->cache[$userId] = $entries;
	}

	/**
	 * Every share this person holds, read once.
	 *
	 * Three things are wanted from the same list — the analyser's folder, the
	 * older archive folders, and the individual files — and asking the share
	 * manager for each of them separately meant walking every Talk
	 * conversation three times.
	 *
	 * @return IShare[]
	 */
	private function sharesOf(string $userId): array {
		if (isset($this->shares[$userId])) {
			return $this->shares[$userId];
		}

		$shares = [];
		foreach (self::SHARE_TYPES as $type) {
			$offset = 0;
			// Advance by however many actually came back, and stop only when a
			// page is empty. The count cannot be compared to the requested
			// limit: Talk's share provider returns fewer than asked without that
			// meaning the end. GUARD caps the loop if a provider ignored the
			// offset and kept returning the same page.
			for ($guard = 0; $guard < self::PAGE_GUARD; $guard++) {
				try {
					$page = $this->shareManager->getSharedWith(
						$userId, $type, null, self::SHARE_PAGE, $offset);
				} catch (\Throwable $e) {
					$this->logger->warning('could not read {type} shares for {user}', [
						'type' => $type, 'user' => $userId, 'exception' => $e,
					]);
					break;
				}
				if ($page === []) {
					break;
				}
				foreach ($page as $share) {
					$shares[] = $share;
				}
				$offset += count($page);
			}
		}
		return $this->shares[$userId] = $shares;
	}

	/**
	 * A document without its YAML header.
	 *
	 * The header is how the app reads a file; to a person it is noise printed
	 * above their meeting — "meeting_file_stem: 2026-07-21_ukhod-…".
	 */
	private function body(string $text): string {
		$text = ltrim($text);
		if (!str_starts_with($text, '---')) {
			return $text;
		}
		$end = strpos($text, "\n---", 3);
		if ($end === false) {
			return $text;
		}
		// Past the closing marker and its newline.
		$rest = substr($text, $end + 4);
		$nl = strpos($rest, "\n");
		return ltrim($nl === false ? '' : substr($rest, $nl + 1));
	}

	/**
	 * Parse the headers of analysed calls that have not been read yet.
	 *
	 * Called from a background job so a search never has to open a couple of
	 * thousand files while someone waits. Nothing here decides who sees what —
	 * it only fills the parse cache, which is keyed by file id and holds what
	 * the header says, not who may read it. A person still only ever sees the
	 * calls their own shares bring them.
	 *
	 * @return int how many were read this round
	 */
	public function warmUp(int $limit): int {
		try {
			$qb = $this->db->getQueryBuilder();
			// Both shapes a call can take: the analyser's pair, and the dated
			// transcripts of the months before it. A search spans the whole
			// archive, so warming only half of it still left the first one
			// reading a thousand files.
			$qb->select('f.fileid', 'f.name', 's.id')
				->from('filecache', 'f')
				->innerJoin('f', 'storages', 's', 'f.storage = s.numeric_id')
				->where($qb->expr()->orX(
					$qb->expr()->in('f.name',
						$qb->createNamedParameter(
							[self::ANALYSIS_SUMMARY . '.md', self::ANALYSIS_TRANSCRIPT . '.md'],
							IQueryBuilder::PARAM_STR_ARRAY)),
					$qb->expr()->like('f.name',
						$qb->createNamedParameter(self::NAME_PATTERN)),
				));
			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
		} catch (\Throwable $e) {
			$this->logger->warning('could not list the analysed calls to warm',
				['exception' => $e]);
			return 0;
		}

		$cache = $this->headerCache();
		$folders = [];
		$warmed = 0;

		foreach ($rows as $row) {
			if ($warmed >= $limit) {
				break;
			}
			$id = (int)$row['fileid'];
			$name = (string)$row['name'];
			// The minutes are not a call of their own; they are read only when
			// the call they belong to is opened.
			if (str_contains($name, self::MINUTES_MARKER)) {
				continue;
			}
			$summaryOnly = str_starts_with($name, self::ANALYSIS_SUMMARY);
			$key = ($summaryOnly ? 's' : 't') . $id;
			if ($cache->get($key) !== null) {
				continue;
			}

			// The owner's own tree, from the storage id — "home::transcriber".
			$owner = substr((string)$row['id'], 6);
			if (!str_starts_with((string)$row['id'], 'home::') || $owner === '') {
				continue;
			}
			try {
				$folders[$owner] ??= $this->rootFolder->getUserFolder($owner);
				$nodes = $folders[$owner]->getById($id);
				$file = $nodes[0] ?? null;
				if (!$file instanceof File) {
					continue;
				}
				$cache->set($key, $this->readMetadata($file, $summaryOnly) ?? [],
					30 * 24 * 3600);
				$warmed++;
			} catch (\Throwable $e) {
				$this->logger->warning('could not warm call {id}',
					['id' => $id, 'exception' => $e]);
			}
		}
		return $warmed;
	}

	/**
	 * The group conversations this person has calls from, most-used first.
	 *
	 * One-to-ones are left out: they are the bulk of the names and none of the
	 * use — nobody picks their own 1:1 from a list of hundreds, they search for
	 * the person. A conversation counts as a group if any of its calls had more
	 * than two people in it, which is what the headers say; the names alone do
	 * not tell them apart ("Дарья Костусенко, Дмитрий Чиненов" is a 1:1).
	 *
	 * @return array<int, array{name: string, count: int}>
	 */
	public function rooms(string $userId): array {
		$rooms = [];
		foreach ($this->transcriptCandidates($userId) as $entry) {
			$meta = $this->metadataFor($userId, $entry);
			$name = (string)($meta['room_name'] ?? '');
			if ($name === '') {
				continue;
			}
			$people = count($meta['participants'] ?? []);
			$rooms[$name] ??= ['name' => $name, 'count' => 0, 'people' => 0];
			$rooms[$name]['count']++;
			$rooms[$name]['people'] = max($rooms[$name]['people'], $people);
		}

		$groups = array_values(array_filter($rooms,
			static fn (array $r) => $r['people'] > 2));
		usort($groups, static fn (array $a, array $b) => $b['count'] <=> $a['count']);

		return array_map(
			static fn (array $r) => ['name' => $r['name'], 'count' => $r['count']],
			$groups);
	}

	/**
	 * Where a meeting's two files sit in this person's own tree, for Nextcloud's
	 * sharing panel to open on.
	 *
	 * Paths, not ids: the panel takes the path as the user sees it, which for a
	 * recipient is wherever they mounted the share.
	 *
	 * @return array{transcript: string, summary: string}
	 * @throws BackendException
	 */
	public function paths(string $userId, string $sessionId): array {
		$entry = $this->candidates($userId)[(int)$sessionId] ?? null;
		if ($entry === null) {
			throw new BackendException('not found', Http::STATUS_NOT_FOUND);
		}

		$transcript = isset($entry['summary_only']) ? null : $this->resolve($userId, $entry);
		$summary = $this->summaryFile($userId, $entry);

		// The ids come along for the fallback: opening the file in Files needs
		// an id, not a path.
		return [
			'transcript' => $transcript === null ? '' : $this->userPath($userId, $transcript),
			'transcript_id' => $transcript === null ? 0 : $transcript->getId(),
			'summary' => $summary === null ? '' : $this->userPath($userId, $summary),
			'summary_id' => $summary === null ? 0 : $summary->getId(),
		];
	}

	/** A node's path as the user sees it: "/Talk/…", not "/alice/files/Talk/…". */
	private function userPath(string $userId, File $file): string {
		$prefix = '/' . $userId . '/files';
		$path = $file->getPath();
		return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
	}

	/**
	 * The day a candidate belongs to, "YYYY-MM-DD": from the analyser's path,
	 * or from the filename of a loose transcript.
	 *
	 * @param array<string, mixed> $entry
	 */
	private function day(array $entry): string {
		return substr((string)($entry['sort'] ?? $entry['name']), 0, 10);
	}

	/**
	 * The analyser's folder, if this person can read it whole.
	 *
	 * Held outright by the service account and shared as one folder to whoever
	 * keeps the archive; an ordinary participant has no such folder and reaches
	 * their calls as individual shares instead.
	 */
	private function analysisFolderId(string $userId, Folder $userFolder): ?int {
		try {
			$folder = $userFolder->get(self::ANALYSIS_FOLDER);
			if ($folder instanceof Folder) {
				return $folder->getId();
			}
		} catch (\Throwable) {
			// Not the owner — look for it among the shares instead.
		}

		foreach ($this->sharesOf($userId) as $share) {
			if ($share->getNodeType() === 'folder'
				&& basename($share->getTarget()) === self::ANALYSIS_FOLDER_NAME) {
				return $share->getNodeId();
			}
		}
		return null;
	}

	/**
	 * Every analysed call under that folder, in one indexed read.
	 *
	 * The tree is three levels deep — <date>/<NNN>_<topic>/<file> — so this
	 * matches on the path prefix rather than walking parent by parent, which
	 * would be a query per day and per call. Only the two files that stand for
	 * a call are taken; the speaker analyses in the same folder are nobody's
	 * business but their subject's, and this never reads them.
	 *
	 * @param array<int, array<string, mixed>> $entries
	 */
	private function addAnalysedCalls(int $folderId, array &$entries): void {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('storage', 'path')
				->from('filecache')
				->where($qb->expr()->eq('fileid',
					$qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			$folder = $result->fetch();
			$result->closeCursor();
			if (!$folder) {
				return;
			}

			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid', 'name', 'parent', 'path')
				->from('filecache')
				->where($qb->expr()->eq('storage',
					$qb->createNamedParameter((int)$folder['storage'], IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->like('path',
					$qb->createNamedParameter(
						$this->db->escapeLikeParameter((string)$folder['path']) . '/%')))
				->andWhere($qb->expr()->in('name',
					$qb->createNamedParameter(
						[self::ANALYSIS_SUMMARY . '.md', self::ANALYSIS_TRANSCRIPT . '.md'],
						IQueryBuilder::PARAM_STR_ARRAY)));
			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
		} catch (\Throwable $e) {
			$this->logger->warning('could not read the analysed calls', [
				'exception' => $e,
			]);
			return;
		}

		foreach ($rows as $row) {
			$id = (int)$row['fileid'];
			$entry = [
				'id' => $id,
				'name' => (string)$row['name'],
				'node' => null,
				'share' => null,
				'folder' => (int)$row['parent'],
			];
			if (preg_match(self::ANALYSIS_PATH, (string)$row['path'], $m) === 1) {
				$entry['sort'] = $m[1] . ' ' . $m[2];
				$entry['date'] = strtotime($m[1]) ?: 0;
			}
			$entries[$id] = $entry;
		}
	}

	/**
	 * The day the analyser's archive begins, as "YYYY-MM-DD", or '' if this
	 * person has no analysed calls at all.
	 *
	 * From there on, a call is read from the analyser's folder — transcript and
	 * summary together — and the loose transcript of the same call is the same
	 * meeting listed twice. Before it there is no analysis, so the loose
	 * transcripts and their "Протокол" minutes are the whole archive. The date
	 * is taken from the data rather than written down, so it stays true if the
	 * analyser is ever re-run over older calls.
	 *
	 * @param array<int, array<string, mixed>> $entries
	 */
	private function cutoff(array $entries): string {
		$earliest = '';
		foreach ($entries as $entry) {
			$sort = (string)($entry['sort'] ?? '');
			if ($sort === '' || !isset($entry['folder'])) {
				continue;
			}
			$day = substr($sort, 0, 10);
			if ($earliest === '' || $day < $earliest) {
				$earliest = $day;
			}
		}
		return $earliest;
	}

	/**
	 * File ids of the archive folders the user can reach.
	 *
	 * By name, not by path: the service account holds "Talk/Транскрипции" as its
	 * own, while a recipient mounts the same shared folder wherever they moved
	 * it. getNodeType tells a folder share from a file share without loading the
	 * node, so this stays cheap even for an account with many shares.
	 *
	 * @return int[]
	 */
	private function archiveFolderIds(string $userId, Folder $userFolder): array {
		$ids = [];

		// The owner's own copies (the service account), by their known path.
		foreach (self::OWN_FOLDERS as $path) {
			try {
				$folder = $userFolder->get($path);
				if ($folder instanceof Folder) {
					$ids[$folder->getId()] = true;
				}
			} catch (\Throwable) {
				// Absent for a recipient — found through their shares below.
			}
		}

		// Shared-in copies, found by folder name among the shares.
		foreach ($this->sharesOf($userId) as $share) {
			if ($share->getNodeType() === 'folder'
				&& in_array(basename($share->getTarget()),
					self::OWN_FOLDER_NAMES, true)) {
				$ids[$share->getNodeId()] = true;
			}
		}

		return array_keys($ids);
	}

	/**
	 * Add the individual and conversation file shares to the candidate set.
	 *
	 * @param array<int, array{id: int, name: string, node: ?File, share: ?IShare}> $entries
	 */
	private function addFileShares(string $userId, array &$entries): void {
		foreach ($this->sharesOf($userId) as $share) {
			if ($share->getNodeType() !== 'file') {
				continue;
			}
			$id = $share->getNodeId();
			if (!isset($entries[$id])) {
				$entries[$id] = [
					'id' => $id,
					'name' => basename($share->getTarget()),
					'node' => null,
					'share' => $share,
				];
			}
		}
	}

	/**
	 * Fill in what the analyser's filenames do not say: the meeting folder, the
	 * date, and the order within a day.
	 *
	 * One indexed read for all of them at once. It asks the index about files
	 * the caller already holds a share for, so it widens nothing — a call not
	 * shared with them was never in $entries to begin with.
	 *
	 * @param array<int, array<string, mixed>> $entries
	 */
	private function annotateAnalysis(array &$entries): void {
		$wanted = [];
		foreach ($entries as $id => $entry) {
			if (str_starts_with($entry['name'], self::ANALYSIS_TRANSCRIPT)
				|| str_starts_with($entry['name'], self::ANALYSIS_SUMMARY)) {
				$wanted[$id] = true;
			}
		}
		if ($wanted === []) {
			return;
		}
		$ids = array_keys($wanted);

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid', 'parent', 'path')
				->from('filecache')
				->where($qb->expr()->in('fileid',
					$qb->createNamedParameter($ids,
						IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
		} catch (\Throwable $e) {
			$this->logger->warning('could not read the index for analysed calls', [
				'exception' => $e,
			]);
			return;
		}

		foreach ($rows as $row) {
			$id = (int)$row['fileid'];
			// Only what was asked for: an older transcript that came back too
			// must not be marked as analysed, or its name would stop being what
			// orders it and what a search reads.
			if (!isset($wanted[$id])) {
				continue;
			}
			$entries[$id]['folder'] = (int)$row['parent'];
			if (preg_match(self::ANALYSIS_PATH, (string)$row['path'], $m) === 1) {
				// Sorting is on this string for every format at once, so it is
				// shaped like the older filenames: date first, then what
				// separates two calls on the same day.
				$entries[$id]['sort'] = $m[1] . ' ' . $m[2];
				$entries[$id]['date'] = strtotime($m[1]) ?: 0;
			}
		}
	}

	/**
	 * The dated markdown directly under a folder, from the file index.
	 *
	 * Read from oc_filecache by parent id: an indexed lookup that returns names
	 * and ids without instantiating a File per row. The name filter is the SQL
	 * shape of a date — `20__-__-__ %`, where `_` is any one character — so it
	 * runs in the database, not in PHP.
	 *
	 * @return array<int, array{fileid: int, name: string}>
	 */
	private function indexRows(int $parentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid', 'name')
			->from('filecache')
			->where($qb->expr()->eq('parent',
				$qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('name',
				$qb->createNamedParameter(self::NAME_PATTERN)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	/**
	 * The name-filtered index search, used only when the archive folders are
	 * not mounted for this user.
	 *
	 * @return array<int, array{name: string, node: ?File, share: ?IShare}>
	 */
	private function searchByName(string $userId, Folder $userFolder): array {
		$entries = [];
		try {
			$found = $userFolder->search(new Query(
				new BinaryOperator(
					ISearchBinaryOperator::OPERATOR_AND,
					new Comparison(ISearchComparison::COMPARE_EQUAL,
						'mimetype', 'text/markdown'),
					new Comparison(ISearchComparison::COMPARE_LIKE,
						'name', self::NAME_PATTERN),
				),
				// A large finite limit, not 0: passing 0 makes Nextcloud's search
				// drop the name filter and return every markdown file.
				self::MAX_RESULTS,
				0,
				[new Order(ISearchOrder::DIRECTION_DESCENDING, 'name')],
				$this->userManager->get($userId),
			));
			foreach ($found as $node) {
				if ($node instanceof File) {
					$entries[$node->getId()] ??= [
						'id' => $node->getId(),
						'name' => $node->getName(),
						'node' => $node,
						'share' => null,
					];
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('could not search files for {user}', [
				'user' => $userId, 'exception' => $e,
			]);
		}
		return $entries;
	}

	/**
	 * Candidates that look like a transcript by name — newest first, and no
	 * files opened yet.
	 *
	 * @return array<int, array{name: string, node: ?File, share: ?IShare}>
	 */
	private function transcriptCandidates(string $userId): array {
		$candidates = $this->candidates($userId);
		$cutoff = $this->cutoff($candidates);

		// Which meeting folders have their transcript shared. For the months
		// before the service began sharing it, none do — and those calls are in
		// the archive as their summary alone rather than not at all.
		$transcribed = [];
		foreach ($candidates as $entry) {
			if (str_starts_with($entry['name'], self::ANALYSIS_TRANSCRIPT)
				&& isset($entry['folder'])) {
				$transcribed[$entry['folder']] = true;
			}
		}

		$transcripts = [];
		foreach ($candidates as $id => $entry) {
			if ($this->looksLikeTranscript($entry['name'])) {
				// A loose transcript from a day the analyser covers is that same
				// call a second time, without its summary. The analysed copy is
				// the one that gets listed.
				if (!isset($entry['folder']) && $cutoff !== ''
					&& substr($entry['name'], 0, 10) >= $cutoff) {
					continue;
				}
				$transcripts[$id] = $entry;
			} elseif (str_starts_with($entry['name'], self::ANALYSIS_SUMMARY)
				&& isset($entry['folder'])
				&& !isset($transcribed[$entry['folder']])) {
				$entry['summary_only'] = true;
				$transcripts[$id] = $entry;
			}
		}

		// The sort key begins with the date — the filename for the older files,
		// the meeting folder for the analysed ones — so sorting it in reverse is
		// chronological, newest first, without reading a single file.
		uasort($transcripts, static fn ($a, $b) => strcmp(
			(string)($b['sort'] ?? $b['name']), (string)($a['sort'] ?? $a['name'])));
		return array_values($transcripts);
	}

	/**
	 * A transcript's filename starts with a date and is not the minutes. Both
	 * checks are on the name alone; the header confirms it later.
	 */
	private function looksLikeTranscript(string $name): bool {
		// Two shapes: the analyser's fixed filename, and the older
		// "<timestamp> - <who>.md" the service writes into Транскрипции/.
		return str_starts_with($name, self::ANALYSIS_TRANSCRIPT)
			|| (preg_match('/^20\d\d-\d\d-\d\d /u', $name) === 1
				&& !str_contains($name, self::MINUTES_MARKER));
	}

	/**
	 * Turn a candidate into a File, building the heavy object only now — for the
	 * page being shown, not for every candidate.
	 *
	 * @param array{id: int, name: string, node: ?File, share: ?IShare} $entry
	 */
	private function resolve(string $userId, array $entry): ?File {
		if ($entry['node'] instanceof File) {
			return $entry['node'];
		}
		try {
			if ($entry['share'] !== null) {
				$node = $entry['share']->getNode();
				return $node instanceof File ? $node : null;
			}
			// From the index: only the id is known, so ask the user's tree for
			// it. Resolved against their own mounts, so it doubles as the access
			// check — a file they cannot reach comes back as nothing.
			$userFolder = $this->rootFolder->getUserFolder($userId);
			foreach ($userFolder->getById($entry['id']) as $node) {
				if ($node instanceof File) {
					return $node;
				}
			}
			return null;
		} catch (\Throwable $e) {
			// A file deleted since it was indexed — skip it rather than fail the
			// whole listing.
			$this->logger->warning('could not resolve file {id}', [
				'id' => $entry['id'], 'exception' => $e,
			]);
			return null;
		}
	}

	private function sessionId(File $file): string {
		return (string)$file->getId();
	}

	/**
	 * What the transcript's own header states about the call.
	 *
	 * @return array<string, mixed>|null null when this is not a transcript
	 */
	/**
	 * What a candidate says about its call — from the cache when possible.
	 *
	 * The file id is enough to answer from the cache, so the File is built only
	 * on a miss. Building one means resolving a mount per row, which for a page
	 * of fifty was most of the time spent even when nothing had to be read.
	 *
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>|null
	 */
	private function metadataFor(string $userId, array $entry): ?array {
		$summaryOnly = isset($entry['summary_only']);
		$cache = $this->headerCache();
		$key = ($summaryOnly ? 's' : 't') . (int)$entry['id'];

		$cached = $cache->get($key);
		if (is_array($cached)) {
			return $cached === [] ? null : $cached;
		}

		$file = $this->resolve($userId, $entry);
		$meta = $file === null ? null : $this->readMetadata($file, $summaryOnly);
		// The negative answer is worth keeping too: without it every listing
		// re-opens the same files that turned out not to be calls. A file that
		// could not be resolved is not cached — that may be a passing failure.
		if ($file !== null) {
			$cache->set($key, $meta ?? [], 30 * 24 * 3600);
		}
		return $meta;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function readMetadata(File $file, bool $summaryOnly): ?array {
		$head = $this->head($file);
		if ($head === null) {
			return null;
		}
		$head = ltrim($head);

		$meta = $summaryOnly
			? $this->fromSummary($head)
			: (str_starts_with($head, '---')
				? $this->fromYaml($head)
				: (str_starts_with($head, self::HEADER_LEGACY)
					? $this->fromLegacyHeader($head)
					: null));

		if ($meta === null) {
			return null;
		}

		$name = $meta['meeting_name'] !== ''
			? $meta['meeting_name']
			: $this->title($file->getName());
		unset($meta['meeting_name']);

		return $meta + [
			'session_id' => $this->sessionId($file),
			'room_name' => $name,
			'has_transcript' => !$summaryOnly,
			'has_time' => true,
		];
	}

	/**
	 * A call known only by its summary — the months before the service began
	 * sharing the transcript beside it.
	 *
	 * The summary's header states the day and nothing else, so the clock time
	 * and the participants are simply not known here; `has_time` says so, and
	 * the list shows the day without inventing an hour. The meeting's name is
	 * in the heading the analyser writes: "# Executive Summary: <name> (Вт, 21
	 * июля 2026)".
	 *
	 * @return array<string, mixed>|null
	 */
	private function fromSummary(string $head): ?array {
		if (preg_match('/^meeting_date:\s*\'?(\d{4}-\d{2}-\d{2})/mu', $head, $m) !== 1) {
			return null;
		}

		$name = '';
		if (preg_match('/^#\s*Executive Summary:\s*(.+)$/mu', $head, $h) === 1) {
			// The heading ends with the date in words, which the list already
			// shows above the call.
			$name = trim(preg_replace('/\s*\([^()]*\)\s*$/u', '', $h[1]));
		}

		return [
			'call_start_ts' => strtotime($m[1]) ?: 0,
			'call_end_ts' => 0,
			'has_time' => false,
			'participants' => [],
			'meeting_name' => $name,
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function fromYaml(string $head): ?array {
		$end = strpos($head, "\n---", 3);
		$block = $end === false ? $head : substr($head, 3, $end - 3);

		$scalars = [];
		$participants = [];
		$listKey = '';
		foreach (explode("\n", $block) as $line) {
			// A list item belongs to whichever key introduced it. The indent is
			// optional: the service indents its list items, the analyser does
			// not, and both headers land here.
			if (preg_match('/^\s*-\s+(.+)$/u', $line, $item)) {
				if ($listKey === 'participants') {
					$participants[] = trim($item[1], " \"\'");
				}
				continue;
			}
			if (preg_match('/^(\w+):\s*(.*)$/u', $line, $pair)) {
				$listKey = $pair[1];
				if ($pair[2] !== '') {
					$scalars[$pair[1]] = trim($pair[2], " \"\'");
				}
			}
		}

		// The minutes carry the same shape of header, so a meeting name is not
		// enough to call this a transcript; the marker is that it states a time.
		if (!isset($scalars['started_at']) && !isset($scalars['date'])) {
			return null;
		}

		$end = strtotime($scalars['finished_at'] ?? '') ?: 0;

		if (isset($scalars['started_at'])) {
			$start = strtotime($scalars['started_at']) ?: 0;
		} elseif ($end > 0 && isset($scalars['duration'])) {
			// The analyser states when the call ended and how long it ran, but
			// not when it began. Taking `date` as the start would put it at
			// midnight and make every call look hours long.
			$minutes = (int)preg_replace('/\D+/', '', $scalars['duration']);
			$start = $minutes > 0 ? $end - $minutes * 60 : 0;
		} else {
			$start = strtotime($scalars['date'] ?? '') ?: 0;
		}

		return [
			'call_start_ts' => $start,
			'call_end_ts' => $end > $start ? $end : 0,
			'participants' => $participants,
			'meeting_name' => $scalars['meeting_name'] ?? '',
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function fromLegacyHeader(string $head): ?array {
		[$start, $end] = $this->period($head);
		return [
			'call_start_ts' => $start,
			'call_end_ts' => $end,
			'participants' => $this->participants($head),
			'meeting_name' => '',
		];
	}

	private function head(File $file): ?string {
		try {
			$handle = $file->fopen('r');
			if ($handle === false) {
				return null;
			}
			$head = fread($handle, 2048);
			fclose($handle);
			return is_string($head) ? $head : null;
		} catch (\Throwable $e) {
			$this->logger->warning('could not read a transcript header', [
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * @throws BackendException
	 */
	private function contents(File $file): string {
		try {
			return $this->body((string)$file->getContent());
		} catch (\Throwable $e) {
			$this->logger->error('could not read a call', ['exception' => $e]);
			throw new BackendException('could not read the call',
				Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	/**
	 * "Дата: 2026-03-05 14:49 — 14:51" as a pair of timestamps.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function period(string $head): array {
		if (!preg_match('/^Дата:\s*(\S+)\s+(\d{2}:\d{2})(?:\s*[—-]\s*(\d{2}:\d{2}))?/mu',
			$head, $m)) {
			return [0, 0];
		}

		$start = strtotime($m[1] . ' ' . $m[2]);
		$end = isset($m[3]) ? strtotime($m[1] . ' ' . $m[3]) : false;

		// A call that crosses midnight ends "before" it starts by this reading;
		// a negative duration would show nonsense, so drop it.
		if ($end !== false && $start !== false && $end < $start) {
			$end = false;
		}
		return [$start ?: 0, $end ?: 0];
	}

	/**
	 * @return string[]
	 */
	private function participants(string $head): array {
		if (!preg_match('/^Участники:\s*(.+)$/mu', $head, $m)) {
			return [];
		}
		return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
	}

	/**
	 * The part of the filename people recognise, without the timestamp.
	 */
	private function title(string $name): string {
		$name = preg_replace('/\.md$/u', '', $name);
		$parts = explode(' - ', $name, 2);
		return trim($parts[1] ?? $parts[0]);
	}

	/**
	 * Whether a candidate passes the text and date filters.
	 *
	 * The older filename is "2026-07-20 16-00-00 - Вводная встреча по
	 * Superset.md" — date, then the participants or the meeting name — so both
	 * filters read straight from it. The analyser's files say neither: the date
	 * came from the index, and the meeting name only from the header, which is
	 * why a text search opens them and a date filter does not.
	 *
	 * `from`/`to` are day boundaries as unix seconds; either may be 0 to leave
	 * that end open.
	 *
	 * @param array<string, mixed> $entry
	 */
	private function matchesFilter(string $userId, array $entry, string $query,
		int $from, int $to, string $room = ''): bool {
		$name = (string)$entry['name'];

		// Dates first: cheap for every format, and it narrows what the text
		// filter below may have to open.
		if ($from !== 0 || $to !== 0) {
			$day = (int)($entry['date'] ?? (strtotime(substr($name, 0, 10)) ?: 0));
			if ($from !== 0 && $day < $from) {
				return false;
			}
			if ($to !== 0 && $day > $to) {
				return false;
			}
		}

		if ($room !== '') {
			$meta = $this->metadataFor($userId, $entry);
			if (($meta['room_name'] ?? '') !== $room) {
				return false;
			}
		}

		if ($query === '') {
			return true;
		}
		return mb_stripos($this->searchText($userId, $entry), $query) !== false;
	}

	/**
	 * What a search matches against for an analysed call: the meeting name and
	 * the participants, from the header.
	 *
	 * @param array<string, mixed> $entry
	 */
	private function searchText(string $userId, array $entry): string {
		// Through the same cache the listing fills, so a search costs nothing
		// for calls already shown. Going straight to the files instead meant
		// re-opening every one of them on each keystroke — twelve seconds, long
		// enough that the search read as broken.
		$meta = $this->metadataFor($userId, $entry);
		if ($meta === null) {
			// Not a call, or unreadable: the filename is all there is, and for
			// the older files it holds the date and the people anyway.
			return (string)$entry['name'];
		}
		return (string)($meta['room_name'] ?? '')
			. ' ' . implode(' ', $meta['participants'] ?? []);
	}

	/**
	 * The timestamp a filename starts with — what pairs a transcript with its
	 * minutes.
	 */
	private function timestampPrefix(string $name): string {
		return preg_match('/^([\d-]{10}(?:\s[\d-]{8})?)/u', $name, $m) ? $m[1] : '';
	}
}
