<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Controller;

use OCA\DoneTranscription\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		// Nextcloud's own sharing panel, so permissions are managed here the way
		// they are managed everywhere else in the instance rather than through
		// a second, half-built dialog of our own.
		Util::addScript('files', 'sidebar');
		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		return new TemplateResponse(Application::APP_ID, 'main');
	}
}
