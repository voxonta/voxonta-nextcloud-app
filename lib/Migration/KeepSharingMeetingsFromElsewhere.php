<?php

declare(strict_types=1);

namespace OCA\Voxonta\Migration;

use OCA\Voxonta\AppInfo\Application;
use OCA\Voxonta\Settings\AdminSettings;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Switch the account list on for installations that ran an older version.
 *
 * Sending the accounts to the service is off for a new installation: it is
 * personal data leaving Nextcloud, and an administrator should choose that.
 * An installation upgrading from before 3.1.0 is different — its owner asked
 * for meetings from Element and Telemost to reach the people in them, and
 * that does not work without the list.
 *
 * Runs after every upgrade, while installed_version still names the version
 * being left, and never on a fresh install. A value already set is the
 * administrator's and is left alone.
 */
class KeepSharingMeetingsFromElsewhere implements IRepairStep {
	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
	) {
	}

	public function getName(): string {
		return 'Keep sharing meetings held outside Talk on an existing installation';
	}

	public function run(IOutput $output): void {
		$previous = $this->appConfig->getValueString(Application::APP_ID, 'installed_version', '');
		if ($previous === '' || version_compare($previous, '3.1.0', '>=')
			|| $this->appConfig->hasKey(Application::APP_ID, AdminSettings::KEY_SEND_DIRECTORY)) {
			return;
		}
		// Untyped, the way the settings form stores it: a typed value would
		// make the form's own save fail with a type conflict.
		$this->config->setAppValue(Application::APP_ID, AdminSettings::KEY_SEND_DIRECTORY, '1');
		$output->info('the account list is sent to the service, as before this upgrade');
	}
}
