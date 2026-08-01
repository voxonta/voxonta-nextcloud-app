<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCA\DoneTranscription\AppInfo\Application;
use OCA\DoneTranscription\Settings\AdminSettings;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
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
	private const SHARED_BY_NAME = ['10_Original_Transcript.md'];

	public function __construct(
		private IRootFolder $rootFolder,
		private IManager $shareManager,
		private BotAccount $botAccount,
		private IAppConfig $appConfig,
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
		$relative = $this->folderFor($kind) . '/' . $name;

		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			if ($userFolder->nodeExists($relative)) {
				// Already there. Not an error and not something to redo: a file
				// named for a meeting is that meeting's.
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
			$this->share($uid, $relative, $participants);
		}
		return true;
	}

	private function shouldShare(string $kind, string $name): bool {
		return in_array($kind, self::SHARED, true)
			|| in_array($name, self::SHARED_BY_NAME, true);
	}

	/**
	 * Share with each participant individually.
	 *
	 * Failing to share is logged and left: the file is written, which is the
	 * part that matters, and a missing share can be fixed by hand.
	 *
	 * @param array<int, string> $participants user ids
	 */
	private function share(string $owner, string $path, array $participants): void {
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
				$this->shareManager->createShare($share);
			} catch (\Throwable $e) {
				// Already shared is the common case here, and it is fine.
				$this->logger->debug('could not share {path} with {uid}: {message}',
					['path' => $path, 'uid' => $uid, 'message' => $e->getMessage()]);
			}
		}
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
