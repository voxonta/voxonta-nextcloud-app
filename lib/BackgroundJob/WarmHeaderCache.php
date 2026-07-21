<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\BackgroundJob;

use OCA\DoneTranscription\Service\FileArchive;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Parse the headers of analysed calls ahead of anyone asking for them.
 *
 * A search has to look inside the files: the meeting's name and the people in
 * it are in the header and nowhere else. Reading them is some 20ms each, so the
 * first search over an archive of a couple of thousand calls took half a minute
 * — long enough that it read as broken. Doing it here means the reader never
 * pays for it.
 *
 * Deliberately small batches on a slow interval: the work is never urgent, and
 * a call analysed today is worth having by the time someone searches for it,
 * not within the minute.
 */
class WarmHeaderCache extends TimedJob {
	/**
	 * Files per run. Around ten seconds of work — long enough to refill a few
	 * thousand calls within minutes of an app update (which empties the cache),
	 * short enough not to hold up the other jobs sharing the cron run.
	 */
	private const BATCH = 750;

	public function __construct(
		ITimeFactory $time,
		private FileArchive $archive,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$warmed = $this->archive->warmUp(self::BATCH);
		if ($warmed > 0) {
			$this->logger->debug('warmed {n} call headers', ['n' => $warmed]);
		}
	}
}
