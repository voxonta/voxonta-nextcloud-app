<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The parts that only break in a real installation.
 *
 * Each check here guards a mistake whose symptom is an empty page or a missing
 * menu entry — nothing throws, nothing appears in a log, and the cause is a
 * name in one file not matching a name in another.
 */
class PackagingTest extends TestCase {
	private const APP_ID = 'done_transcription';

	private function root(): string {
		return dirname(__DIR__, 2);
	}

	private function read(string $relative): string {
		return file_get_contents($this->root() . '/' . $relative);
	}

	private function info(): \SimpleXMLElement {
		return simplexml_load_file($this->root() . '/appinfo/info.xml');
	}

	public function testTheBundleNameMatchesWhatThePageAsksFor(): void {
		// webpack emits {package name}-{entry}.js; PageController asks for a
		// name. When they drift, the script tag points at a file that is not
		// there and the page renders empty.
		preg_match("/Util::addScript\([^;]*?APP_ID \. '(-[\w-]+)'/",
			$this->read('lib/Controller/PageController.php'), $asked);
		$this->assertNotEmpty($asked, 'could not find the addScript call');

		preg_match('/"name":\s*"([^"]+)"/', $this->read('package.json'), $name);
		$this->assertSame(self::APP_ID, $name[1],
			"the package is named {$name[1]}, so webpack emits {$name[1]}-main.js "
			. 'while the page asks for ' . self::APP_ID . $asked[1] . '.js');
	}

	public function testTheNavigationEntryPointsAtARealRoute(): void {
		// A navigation route that does not resolve removes the entry from the
		// menu, silently.
		$nav = $this->info()->navigations->navigation;
		$this->assertNotEmpty($nav, 'the app would have no menu entry at all');
		$this->assertSame(self::APP_ID . '.page.index', trim((string)$nav->route));
		$this->assertStringContainsString("'name' => 'page#index'",
			$this->read('appinfo/routes.php'),
			'the navigation route has no matching entry in routes.php');
	}

	public function testTheNavigationIconExists(): void {
		$icon = trim((string)$this->info()->navigations->navigation->icon);
		$this->assertFileExists($this->root() . '/img/' . $icon,
			"img/$icon is missing, so the menu entry renders without an icon");
	}

	/** @return string[] */
	private function packagedItems(): array {
		preg_match('/for item in ([^;]+); do/', $this->read('build-release.sh'), $m);
		return preg_split('/\s+/', trim($m[1]));
	}

	public function testTheReleaseArchiveCarriesWhatTheAppNeedsToRun(): void {
		// Forgetting a directory here produces an app that installs and then
		// fails in a way that looks nothing like a packaging problem.
		foreach (['appinfo', 'lib', 'templates', 'img', 'js', 'l10n'] as $needed) {
			$this->assertContains($needed, $this->packagedItems(),
				"the archive would ship without $needed/");
		}
	}

	public function testTheReleaseArchiveLeavesDevelopmentFilesOut(): void {
		// node_modules and vendor are two orders of magnitude larger than the
		// app and contain code nobody reviewed for this release.
		foreach (['node_modules', 'vendor', 'src', 'tests'] as $excluded) {
			$this->assertNotContains($excluded, $this->packagedItems(),
				"$excluded/ would end up in the archive");
		}
	}

	/** @return string[] */
	private function colours(string $icon): array {
		preg_match_all('/#[0-9a-fA-F]{6}/', $this->read("img/$icon"), $m);
		return array_values(array_unique(array_map('strtolower', $m[0])));
	}

	public function testTheMenuIconIsLight(): void {
		// Nextcloud puts img/app.svg in the header, and the header is dark.
		// Every shipped app does this — Talk and Files both use fill="#fff".
		// A dark icon there is invisible against the bar.
		$this->assertSame(['#ffffff'], $this->colours('app.svg'),
			'app.svg is the icon Nextcloud shows in the dark header');
	}

	public function testTheLightBackgroundIconIsDark(): void {
		$this->assertSame(['#000000'], $this->colours('app-dark.svg'),
			'app-dark.svg is used where the background is light');
	}

	public function testTheTwoIconsAreTheSameDrawing(): void {
		// They differ in colour only. A redrawn variant drifts silently and the
		// app ends up with two different logos depending on the theme.
		$shapes = '/\s(?:d|x|y|width|height|rx)="[^"]*"/';
		preg_match_all($shapes, $this->read('img/app.svg'), $light);
		preg_match_all($shapes, $this->read('img/app-dark.svg'), $dark);

		$this->assertSame($light[0], $dark[0],
			'the light and dark icons are no longer the same drawing');
	}
}
