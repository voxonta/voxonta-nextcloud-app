<?php

declare(strict_types=1);

namespace OCA\Voxonta\Service;

/**
 * How an attempt to tell a room about its meeting ended.
 *
 * The distinction that matters is not success and failure but whether asking
 * again could ever change the answer. "The bot is not in this conversation and
 * never can be" and "the chat API timed out" both leave the room untold, and
 * treating them alike gets one of them wrong: retry the first and a one-to-one
 * meeting sits in the queue for a fortnight for nothing; give up on the second
 * and a moment's network trouble costs somebody their result.
 */
enum Announcement {
	/** The room was told. */
	case Posted;

	/**
	 * Nothing was posted and nothing will be: no room, no bot account, nothing
	 * worth linking, or a conversation the bot is not a member of. The meeting
	 * is finished as far as collection is concerned.
	 */
	case Impossible;

	/** The attempt broke. Worth another go on a later tick. */
	case Failed;
}
