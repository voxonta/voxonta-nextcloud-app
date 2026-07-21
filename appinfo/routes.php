<?php

declare(strict_types=1);

/**
 * Two reads per meeting, because that is how they are used: the summary is what
 * people open, the transcript is what they reach for when the summary left a
 * question. Fetching them together would load the larger one every time for the
 * minority of cases that want it.
 */
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		['name' => 'archive#meetings', 'url' => '/api/v1/meetings', 'verb' => 'GET'],
		['name' => 'archive#rooms', 'url' => '/api/v1/rooms', 'verb' => 'GET'],
		['name' => 'archive#summary', 'url' => '/api/v1/meetings/{sessionId}/summary', 'verb' => 'GET'],
		['name' => 'archive#transcript', 'url' => '/api/v1/meetings/{sessionId}/transcript', 'verb' => 'GET'],

		// The transcription service, authenticating with the shared secret from
		// the admin settings rather than as a user.
		['name' => 'service#config', 'url' => '/api/v1/service/config', 'verb' => 'GET'],
		['name' => 'service#heartbeat', 'url' => '/api/v1/service/heartbeat', 'verb' => 'POST'],
	],
];
