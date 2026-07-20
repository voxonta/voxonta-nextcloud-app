<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCA\DoneTranscription\AppInfo\Application;
use OCA\DoneTranscription\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Who may read what.
 *
 * Two levels, and the gap between them is the point. Everyone reads the calls
 * they were in — that needs no configuration, it is what the archive is for.
 * Reading *other people's* calls is a different act: it is oversight, it is
 * asked for by management, HR or audit, and it is the kind of access that
 * should be granted deliberately and be visible in the settings.
 *
 * So the default is empty. An instance that installs this app and configures
 * nothing gives nobody the ability to read a conversation they were not part
 * of — including administrators, who can grant it to themselves but have to do
 * so explicitly, leaving a trace in the configuration.
 */
class ArchiveAccess {
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * May this person read meetings they did not attend?
	 *
	 * @throws BackendException when the answer cannot be determined — never
	 *                          silently "yes", and never silently "no" either:
	 *                          a failure here is an outage, not a permission.
	 */
	public function canSeeEverything(string $userId): bool {
		$groups = $this->archiveGroups();
		if ($groups === []) {
			return false;
		}

		try {
			foreach ($groups as $group) {
				if ($this->groupManager->isInGroup($userId, $group)) {
					return true;
				}
			}
		} catch (\Throwable $e) {
			// Falling back to "no" would look like a permission decision and
			// hide a broken group backend behind an empty archive.
			$this->logger->error('could not read group membership for {user}', [
				'user' => $userId,
				'exception' => $e,
			]);
			throw new BackendException('access rules unavailable',
				Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return false;
	}

	/**
	 * @return string[]
	 */
	private function archiveGroups(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID,
			AdminSettings::KEY_ARCHIVE_GROUPS);
		$groups = array_filter(array_map('trim', explode(',', $raw)));
		return array_values($groups);
	}
}
