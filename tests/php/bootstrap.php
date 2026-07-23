<?php

declare(strict_types=1);

/**
 * These are unit tests: they exercise the controller against mocked Nextcloud
 * interfaces rather than a running instance, because the thing under test —
 * who may read which meeting — is pure logic, and logic that deserves to be
 * checked on every commit rather than on every deploy.
 */
require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * IRootFolder extends OC\Hooks\Emitter, which lives in Nextcloud's private
 * namespace and is therefore absent from the published stubs. Without it the
 * interface cannot be mocked at all. Declaring the marker here is a gap in the
 * stubs, not a shim around our own code.
 */
if (!interface_exists('OC\Hooks\Emitter')) {
	eval('namespace OC\Hooks; interface Emitter { public function listen($scope, $method, callable $callback); public function removeListener($scope = null, $method = null, ?callable $callback = null); }');
}

/**
 * The private token provider, for the same reason as Emitter above: minting an
 * app password is generateToken(), which lives on OC\Authentication\Token\IProvider
 * and not on the OCP interface, so it is absent from the published stubs.
 */
if (!interface_exists('OC\Authentication\Token\IProvider')) {
	eval('namespace OC\Authentication\Token; interface IProvider { public function generateToken(string $token, string $uid, string $loginName, ?string $password, string $name, int $type = 0, int $remember = 0, ?array $scope = null): \OCP\Authentication\Token\IToken; public function invalidateTokensOfUser(string $uid, ?string $clientName): void; }');
}
