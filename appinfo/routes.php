<?php

declare(strict_types=1);

/**
 * The archive endpoints mirror the transcription service's own API, one level
 * removed. The browser never talks to that service directly: the token would
 * have to travel with it, and it grants access to every meeting in the
 * instance.
 */
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		['name' => 'archive#meetings', 'url' => '/api/v1/meetings', 'verb' => 'GET'],
		['name' => 'archive#meeting', 'url' => '/api/v1/meetings/{sessionId}', 'verb' => 'GET'],
		['name' => 'archive#transcript', 'url' => '/api/v1/meetings/{sessionId}/transcript', 'verb' => 'GET'],
		['name' => 'archive#analysis', 'url' => '/api/v1/meetings/{sessionId}/analysis', 'verb' => 'GET'],
		['name' => 'archive#artifact', 'url' => '/api/v1/meetings/{sessionId}/analysis/{name}', 'verb' => 'GET'],
	],
];
