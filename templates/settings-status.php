<?php

declare(strict_types=1);

use OCA\DoneTranscription\AppInfo\Application;
use OCP\Util;

/**
 * The connection block above the settings form. Rendered by the same bundle as
 * the app; this file only marks where it goes.
 */
Util::addScript(Application::APP_ID, Application::APP_ID . '-settings');
?>
<div id="done_transcription_status" class="section"></div>
