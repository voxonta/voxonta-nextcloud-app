<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Tests;

use OCA\DoneTranscription\Controller\ArchiveController;
use OCA\DoneTranscription\Service\BackendClient;
use OCA\DoneTranscription\Service\BackendException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * You may only read meetings you were in.
 *
 * The transcription service isolates by tenant, so on its own it returns the
 * whole company's calls. Scoping to the person asking happens here, which makes
 * these tests the only thing standing between one employee and everyone else's
 * conversations.
 */
class ArchiveControllerTest extends TestCase {
	private function controller(BackendClient $backend, ?string $user): ArchiveController {
		return new ArchiveController(
			'done_transcription',
			$this->createMock(IRequest::class),
			$backend,
			$this->createMock(LoggerInterface::class),
			$user,
		);
	}

	/**
	 * A backend that answers with canned data and records what it was asked.
	 */
	private function backend(array $meeting = [], array &$asked = null): BackendClient {
		$backend = $this->createMock(BackendClient::class);
		$backend->method('get')->willReturnCallback(
			function (string $path, array $query = []) use ($meeting, &$asked) {
				if ($asked !== null) {
					$asked[] = [$path, $query];
				}
				if ($path === '/v1/meetings') {
					return ['meetings' => [['session_id' => 's1']]];
				}
				if (str_ends_with($path, '/transcript')) {
					return ['segments' => [['text' => 'secret']]];
				}
				return $meeting;
			});
		return $backend;
	}

	public function testListingIsScopedToTheCaller(): void {
		$asked = [];
		$controller = $this->controller($this->backend([], $asked), 'alice');

		$controller->meetings();

		$this->assertSame('alice', $asked[0][1]['user'],
			"the whole company's meetings were requested, not just this person's");
	}

	public function testAMeetingYouDidNotAttendIsNotReadable(): void {
		$backend = $this->backend(['session_id' => 's1', 'participants' => ['bob']]);
		$response = $this->controller($backend, 'alice')->meeting('s1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(),
			'knowing an id is not permission — ids travel in links and chat');
	}

	public function testRefusalDoesNotRevealThatTheMeetingExists(): void {
		$backend = $this->backend(['session_id' => 's1', 'participants' => ['bob']]);
		$response = $this->controller($backend, 'alice')->meeting('s1');

		$this->assertNotSame(Http::STATUS_FORBIDDEN, $response->getStatus(),
			'a 403 confirms there is a call with that id');
	}

	public function testTheTranscriptIsCheckedToo(): void {
		$backend = $this->backend(['session_id' => 's1', 'participants' => ['bob']]);
		$response = $this->controller($backend, 'alice')->transcript('s1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(),
			'checking only the metadata endpoint would leave the actual words open');
	}

	public function testTheAnalysisIsCheckedToo(): void {
		$backend = $this->backend(['session_id' => 's1', 'participants' => ['bob']]);
		$response = $this->controller($backend, 'alice')->analysis('s1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(),
			'summaries paraphrase the conversation — same rule');
	}

	public function testAnArtifactIsCheckedToo(): void {
		$backend = $this->backend(['session_id' => 's1', 'participants' => ['bob']]);
		$response = $this->controller($backend, 'alice')->artifact('s1', 'summary');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAParticipantCanReadTheirOwnMeeting(): void {
		$backend = $this->backend(['session_id' => 's1', 'participants' => ['alice', 'bob']]);
		$response = $this->controller($backend, 'alice')->meeting('s1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('s1', $response->getData()['session_id']);
	}

	public function testAnUnidentifiedCallerGetsNothing(): void {
		$response = $this->controller($this->backend(), null)->meetings();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(),
			'if we cannot tell who is asking, show nothing rather than everything');
	}

	public function testPagingCannotBeUsedToPullTheWholeArchive(): void {
		$asked = [];
		$controller = $this->controller($this->backend([], $asked), 'alice');

		$controller->meetings(100000, -5);

		$this->assertLessThanOrEqual(200, $asked[0][1]['limit']);
		$this->assertGreaterThanOrEqual(0, $asked[0][1]['offset']);
	}

	public function testAnUnconfiguredServiceIsReportedRatherThanShownAsEmpty(): void {
		$backend = $this->createMock(BackendClient::class);
		$backend->method('get')->willThrowException(
			new BackendException('not configured', Http::STATUS_SERVICE_UNAVAILABLE));

		$response = $this->controller($backend, 'alice')->meetings();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus(),
			'an empty list would read as "you have no meetings", which is a '
			. 'different and misleading statement');
	}
}
