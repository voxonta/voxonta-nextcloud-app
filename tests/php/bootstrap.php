<?php

declare(strict_types=1);

/**
 * These are unit tests: they exercise the controller against mocked Nextcloud
 * interfaces rather than a running instance, because the thing under test —
 * who may read which meeting — is pure logic, and logic that deserves to be
 * checked on every commit rather than on every deploy.
 */
require_once __DIR__ . '/../../vendor/autoload.php';
