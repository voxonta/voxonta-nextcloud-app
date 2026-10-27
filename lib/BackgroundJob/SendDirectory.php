<?php

declare(strict_types=1);

namespace OCA\Voxonta\BackgroundJob;

use OCA\Voxonta\Service\GatewayClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Tell the gateway who has an account here.
 *
 * Meetings reach this archive from more than Talk, and only Talk knows the
 * people in its conversations by their logins. An Element call knows Matrix
 * ids, a Telemost meeting knows names as people typed them. The gateway turns
 * those into the logins it answers with in `access`, and to do that it needs
 * the accounts to match against — login, full name and address, and nothing
 * else.
 *
 * Hourly: a new colleague's first meeting is shared on the next collection
 * after this runs, and an account switched off stops receiving shares within
 * the hour. Only enabled accounts are sent, so a person who has left is not a
 * match for the next person with their name.
 */
class SendDirectory extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private GatewayClient $gateway,
		private IUserManager $users,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if (!$this->gateway->configured()) {
			return;
		}

		$accounts = [];
		$this->users->callForAllUsers(function (IUser $user) use (&$accounts): void {
			if (!$user->isEnabled()) {
				return;
			}
			$accounts[] = [
				'login' => $user->getUID(),
				'name' => $user->getDisplayName(),
				'email' => (string)($user->getEMailAddress() ?? ''),
			];
		});

		if ($accounts === []) {
			// Every installation has at least its administrator, so an empty
			// list is a user backend that failed to answer. Sending it would
			// replace the gateway's list with nothing and stop every share
			// that is not a Talk conversation until the next hour.
			$this->logger->warning('no accounts listed — the gateway keeps the previous directory');
			return;
		}

		if ($this->gateway->directory($accounts)) {
			$this->logger->debug('sent {count} account(s) to the gateway',
				['count' => count($accounts)]);
		}
	}
}
