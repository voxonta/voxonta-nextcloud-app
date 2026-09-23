<?php

declare(strict_types=1);

namespace OCA\Voxonta\Service;

use OCA\Voxonta\AppInfo\Application;
use OCA\Voxonta\Settings\AdminSettings;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Putting a finished meeting's files into Nextcloud.
 *
 * This is the app's one new power, and it is deliberately the smallest one that
 * works: it creates files under the bot's own folders and shares them with the
 * people who were in the call. It never overwrites and never deletes. The rule
 * this app was built on — the files are the only copy of what was said — still
 * holds; what changes is that new ones may now appear.
 *
 * A file already present is left exactly as it is. Collection re-runs (analysis
 * arrives long after the transcript), and the second pass must be a no-op
 * rather than a rewrite.
 */
class ArtifactWriter {
	/**
	 * The files a participant should find in their chat. The rest of an
	 * analysis set is written but not pushed at anyone: those are about the
	 * people in the call, not for the room.
	 */
	private const SHARED = ['summary'];
	/**
	 * The enriched transcript rather than the original one. Recognition returns
	 * an unbroken lower-case stream — "ну посмотрим как телемост работает
	 * интересно даже" — and the analysis already restores the sentences,
	 * capitals and punctuation from it. Handing a person the raw version when a
	 * readable one exists beside it is a choice, and it was the wrong one.
	 *
	 * The original is still written and still worth having: it is what the
	 * analysis reads, it is all that survives when a meeting is too short to
	 * analyse, and it is the only way to tell a speaker's own words from a
	 * rewrite — the enriched pass silently turned "с рыб кости" into
	 * "с рыбкостью". None of that is a reason to push it at the room.
	 */
	private const SHARED_BY_NAME = ['09_Enriched_Transcript.md'];

	public function __construct(
		private IRootFolder $rootFolder,
		private IManager $shareManager,
		private BotAccount $botAccount,
		private IAppConfig $appConfig,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Write one artifact. Returns true when it is now on disk — including when
	 * it already was, since the caller's question is "may I stop asking for
	 * this", not "did I create a file".
	 *
	 * @param array<string, mixed> $meta name/kind from the gateway's listing
	 */
	public function write(array $meta, string $content, array $participants): bool {
		$credentials = $this->botAccount->credentials();
		if ($credentials === null) {
			$this->logger->error('no bot account — nowhere to write meeting files');
			return false;
		}
		$uid = $credentials['user'];

		$name = $this->safeName((string)($meta['name'] ?? ''));
		if ($name === '') {
			return false;
		}
		$kind = (string)($meta['kind'] ?? '');

		// Per-call numbers are not part of a meeting's files. They belong to a
		// daily total that is appended to, not created once, and they feed our
		// own reporting rather than anything a participant opens. Writing them
		// as a file would put a stray dated JSON among the analysis, and only
		// the day's first call would land — every later one would see the name
		// taken and skip. Whoever owns that reporting keeps writing them.
		if ($kind === 'stats') {
			return true;  // "stop asking for this", not "written"
		}

		$relative = $this->folderFor($kind) . '/' . $name;

		// Signed in for the whole of it, creation included — see signInAs().
		// Wrapping only the share was the 2026-09-05 version of this fix, and it
		// missed by one step: the file is created first, that creation is itself
		// an activity, and whoever asks first fixes the name for the rest of the
		// process. Both ended up attributed to nobody.
		$restore = $this->signInAs($uid);
		try {
			try {
				$userFolder = $this->rootFolder->getUserFolder($uid);
				if ($userFolder->nodeExists($relative)) {
					// Already there. Not an error and not something to redo: a
					// file named for a meeting is that meeting's.
					return true;
				}
				$folder = $this->folderAt($userFolder, dirname($relative));
				$folder->newFile(basename($relative), $content);
			} catch (\Throwable $e) {
				$this->logger->error('could not write {path}: {message}',
					['path' => $relative, 'message' => $e->getMessage()]);
				return false;
			}

			$this->logger->info('wrote {path}', ['path' => $relative]);
			if ($this->shouldShare($kind, $name)) {
				$this->share($uid, $relative, $participants,
					$this->recipientName($name, $content));
			}
			return true;
		} finally {
			$restore();
		}
	}

	private function shouldShare(string $kind, string $name): bool {
		// An analysis set arrives named by its place in the set --
		// "2026-08-02/001_the-meeting/10_Original_Transcript.md" -- so the
		// by-name list has to be matched against the file, not the path.
		return in_array($kind, self::SHARED, true)
			|| in_array(basename($name), self::SHARED_BY_NAME, true);
	}

	/**
	 * An absolute link to an artifact that is already written, or null when it
	 * is not there. What a file is stored as and what a person should be told it
	 * is are different things; this lets the caller point at one by a name of
	 * its choosing, without renaming anything in the archive.
	 *
	 * @param array<string, mixed> $meta name/kind from the gateway's listing
	 */
	public function linkTo(array $meta): ?string {
		$credentials = $this->botAccount->credentials();
		$name = $this->safeName((string)($meta['name'] ?? ''));
		if ($credentials === null || $name === '') {
			return null;
		}
		$relative = $this->folderFor((string)($meta['kind'] ?? '')) . '/' . $name;
		try {
			$node = $this->rootFolder->getUserFolder($credentials['user'])
				->get($relative);
		} catch (NotFoundException) {
			return null;
		}
		return $this->urlGenerator->getAbsoluteURL('/f/' . $node->getId());
	}

	/** Whether results are meant to reach the room at all. */
	public function publishesToChat(): bool {
		return $this->appConfig->getValueBool(
			Application::APP_ID, AdminSettings::KEY_PUBLISH_TO_CHAT, true);
	}

	/**
	 * Share with each participant individually.
	 *
	 * Failing to share is logged and left: the file is written, which is the
	 * part that matters, and a missing share can be fixed by hand.
	 *
	 * @param array<int, string> $participants user ids
	 * @param ?string $recipientName what the recipient's copy is called, or null
	 *                               to leave it under the file's own name
	 */
	private function share(string $owner, string $path, array $participants,
		?string $recipientName = null): void {
		if (!$this->appConfig->getValueBool(
				Application::APP_ID, AdminSettings::KEY_PUBLISH_TO_CHAT, true)) {
			return;
		}
		try {
			$node = $this->rootFolder->getUserFolder($owner)->get($path);
		} catch (NotFoundException) {
			return;
		}
		if (!$node instanceof File) {
			return;
		}

		// No sign-in here: write() already holds it for the whole sequence.
		foreach ($participants as $uid) {
			if ($uid === '' || $uid === $owner) {
				continue;
			}
			try {
				$share = $this->shareManager->newShare();
				$share->setNode($node)
					->setShareType(IShare::TYPE_USER)
					->setSharedWith($uid)
					->setSharedBy($owner)
					->setPermissions(\OCP\Constants::PERMISSION_READ);
				$created = $this->shareManager->createShare($share);
			} catch (\Throwable $e) {
				// Already shared is the common case here, and it is fine.
				$this->logger->debug('could not share {path} with {uid}: {message}',
					['path' => $path, 'uid' => $uid, 'message' => $e->getMessage()]);
				continue;
			}
			if ($recipientName !== null) {
				$this->rename($created, $uid, $recipientName);
			}
		}
	}

	/**
	 * Give the recipient's copy a name that says which meeting it is.
	 *
	 * Every meeting's files are called the same — "01_Executive_Summary.md" —
	 * and they all land in one folder of the recipient's, so Nextcloud numbers
	 * them: one person held "01_Executive_Summary (202).md" by September, and
	 * finding a meeting in Files meant opening them one by one.
	 *
	 * Only the recipient's view changes. The file under the bot's account keeps
	 * its name, because the archive and the analyser's folder layout both rely
	 * on it. Nextcloud always names a new share after its file, so this is a
	 * move made right after creation; it notifies nobody.
	 *
	 * A failed rename leaves the share under the file's own name — worse to
	 * look at, still a working share.
	 */
	private function rename(IShare $share, string $uid, string $name): void {
		try {
			$folder = dirname($share->getTarget());
			$folder = $folder === '.' ? '' : rtrim($folder, '/');
			$share->setTarget($folder . '/' . $name);
			$this->shareManager->moveShare($share, $uid);
		} catch (\Throwable $e) {
			$this->logger->warning('could not rename the share of {name} for {uid}: {message}',
				['name' => $name, 'uid' => $uid, 'message' => $e->getMessage()]);
		}
	}

	/**
	 * "2026-09-23 Статусы задач и внедрение ИИ — итоги.md", or null to keep the
	 * file's own name.
	 *
	 * The topic comes from the file's front matter: the analyser writes it as
	 * `title` since 2026-09-23. A file without one — anything older — keeps its
	 * name rather than getting a half-made one.
	 */
	private function recipientName(string $name, string $content): ?string {
		$label = match (true) {
			str_starts_with(basename($name), '01_Executive_Summary') => 'итоги',
			str_starts_with(basename($name), '09_Enriched_Transcript') => 'расшифровка',
			default => null,
		};
		if ($label === null) {
			return null;
		}
		$meta = self::frontMatter($content);
		$title = self::fileSafe($meta['title'] ?? '');
		if ($title === '') {
			return null;
		}
		$date = substr($meta['meeting_date'] ?? '', 0, 10);
		$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date . ' ' : '';
		return $date . $title . ' — ' . $label . '.md';
	}

	/**
	 * The scalar fields of a markdown file's YAML block.
	 *
	 * Enough YAML for what the analyser writes, not a parser: `key: value`,
	 * quoted or not, and a long value folded onto indented lines — PyYAML wraps
	 * at eighty columns, and a topic can be longer than that.
	 *
	 * @return array<string, string>
	 */
	public static function frontMatter(string $content): array {
		if (!str_starts_with($content, "---\n")) {
			return [];
		}
		$end = strpos($content, "\n---", 4);
		if ($end === false) {
			return [];
		}
		$fields = [];
		$key = null;
		foreach (explode("\n", substr($content, 4, $end - 4)) as $line) {
			if (preg_match('/^([A-Za-z_]\w*):\s*(.*)$/u', $line, $m) === 1) {
				$key = $m[1];
				$fields[$key] = $m[2];
			} elseif ($key !== null && preg_match('/^\s+(\S.*)$/u', $line, $m) === 1
				&& !str_starts_with($m[1], '- ')) {
				$fields[$key] .= ($fields[$key] === '' ? '' : ' ') . $m[1];
			} else {
				$key = null;
			}
		}
		return array_map(static function (string $v): string {
			$v = trim($v);
			if (strlen($v) >= 2 && $v[0] === "'" && str_ends_with($v, "'")) {
				return str_replace("''", "'", substr($v, 1, -1));
			}
			if (strlen($v) >= 2 && $v[0] === '"' && str_ends_with($v, '"')) {
				return stripcslashes(substr($v, 1, -1));
			}
			return $v;
		}, $fields);
	}

	/**
	 * A topic made fit for a filename on any system the file may be saved to:
	 * no separators or reserved characters, one line, and short enough that
	 * Files does not cut it to nothing.
	 */
	private static function fileSafe(string $title): string {
		// A separator stands between two words; the other reserved characters
		// belong to the word they touch and simply go.
		$title = preg_replace('/[\\\\\/:]+/u', ' ', $title) ?? '';
		$title = preg_replace('/[*?"<>|\x00-\x1F]+/u', '', $title) ?? '';
		$title = trim(preg_replace('/\s+/u', ' ', $title) ?? '', " .");
		return mb_strlen($title) > 120 ? rtrim(mb_substr($title, 0, 120)) . '…' : $title;
	}

	/**
	 * Put the bot account in the session for the length of one artifact, and
	 * give back the closure that undoes it.
	 *
	 * Nextcloud tells the recipient who shared with them — "{actor} shared
	 * {file} with you" in their notifications and activity feed. It takes that
	 * actor from the session, not from the share's own sharedBy, which we set
	 * correctly. A cron job has no session, so from 2026-08-02 — the day this
	 * app took the writing over from the service, which had been sharing over
	 * HTTP as a signed-in user — every recipient was told a file had been
	 * shared with them by nobody.
	 *
	 * **Held around the write, not just the share, and that is the whole
	 * point.** The activity app asks for the name once and keeps the answer for
	 * the rest of the process. Creating the file is itself an activity and
	 * happens first, so wrapping only the share — the 2026-09-05 attempt —
	 * arrived after the blank had already been cached. It changed nothing: 781
	 * anonymous notifications in September, the last of them three weeks after
	 * the fix shipped.
	 *
	 * The pattern is Nextcloud's own (apps/forms does the same in its
	 * background job). The same caching cuts the other way too: a job running
	 * later in the same cron process can inherit this name. Mis-naming
	 * somebody else's activity occasionally beats naming none of ours ever —
	 * but it is a trade, not a clean win.
	 */
	private function signInAs(string $uid): \Closure {
		$previous = $this->userSession->getUser();
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return static function (): void {
			};
		}
		$this->userSession->setUser($user);
		return function () use ($previous): void {
			$this->userSession->setUser($previous);
		};
	}

	/** Create the folder chain if it is not there yet. */
	private function folderAt(Folder $root, string $path): Folder {
		$folder = $root;
		foreach (array_filter(explode('/', $path)) as $part) {
			$folder = $folder->nodeExists($part)
				? $folder->get($part)
				: $folder->newFolder($part);
		}
		return $folder;
	}

	private function folderFor(string $kind): string {
		$base = 'Talk';
		return match ($kind) {
			'transcript' => $base . '/' . $this->folderName(
				AdminSettings::KEY_TRANSCRIPTS_FOLDER,
				AdminSettings::DEFAULT_TRANSCRIPTS_FOLDER),
			// Summary and the rest of the analysis set live together: one
			// meeting, one folder, which is what lets the archive pair them.
			default => $base . '/' . $this->folderName(
				AdminSettings::KEY_ANALYSIS_FOLDER,
				AdminSettings::DEFAULT_ANALYSIS_FOLDER),
		};
	}

	private function folderName(string $key, string $default): string {
		$name = trim($this->appConfig->getValueString(Application::APP_ID, $key, ''));
		return $name !== '' ? trim($name, '/') : $default;
	}

	/**
	 * The gateway suggests a name; it lands in someone's files, so it is checked
	 * here rather than trusted. Path separators and traversal are stripped —
	 * the folder is ours to choose, not the sender's.
	 */
	private function safeName(string $name): string {
		$name = str_replace(['\\', "\0"], '', $name);
		$parts = array_filter(explode('/', $name),
			static fn (string $p) => $p !== '' && $p !== '.' && $p !== '..');
		// A listing may name a file inside a per-meeting directory
		// ("2026-07-31/001_planerka/01_Executive_Summary.md"); that structure is
		// kept, only the dangerous parts of it are not.
		return implode('/', $parts);
	}
}
