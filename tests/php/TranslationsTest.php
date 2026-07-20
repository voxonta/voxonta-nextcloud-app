<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Translations must stay in step with the code.
 *
 * The failure mode is silent: reword a string at the call site, forget the
 * translation file, and the interface quietly reverts to English. Nothing
 * throws, no behavioural test notices, and it surfaces only when a Russian
 * speaker opens the page.
 *
 * So these read the source strings straight out of the code — Vue and PHP alike
 * — and check each against the files Nextcloud actually loads.
 */
class TranslationsTest extends TestCase {
	private const APP_ID = 'done_transcription';

	private function root(): string {
		return dirname(__DIR__, 2);
	}

	/** @return array<string, string> */
	private function translations(): array {
		$json = json_decode(
			file_get_contents($this->root() . '/l10n/ru.json'), true);
		return $json['translations'];
	}

	/** @return string[] strings passed to t('done_transcription', '…') */
	private function frontendStrings(): array {
		$found = [];
		foreach ($this->files($this->root() . '/src', ['vue', 'js']) as $file) {
			preg_match_all(
				"/\bt\(\s*'" . self::APP_ID . "',\s*'((?:[^'\\\\]|\\\\.)*)'/",
				file_get_contents($file), $matches);
			foreach ($matches[1] as $string) {
				$found[] = stripslashes($string);
			}
		}
		return array_unique($found);
	}

	/** @return string[] strings passed through the app's translation helpers */
	private function serverStrings(): array {
		$found = [];
		foreach ($this->files($this->root() . '/lib', ['php']) as $file) {
			preg_match_all(
				"/(?:\\\$this->l|\\\$this->l10n->t)\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
				file_get_contents($file), $matches);
			foreach ($matches[1] as $string) {
				$found[] = stripslashes($string);
			}
		}
		return array_unique($found);
	}

	/**
	 * Strings Nextcloud translates from info.xml. The navigation label never
	 * appears in a t() call — Nextcloud looks it up in the app's own
	 * translations — so without this it reads as a dead entry and gets deleted,
	 * taking the translated menu name with it.
	 *
	 * @return string[]
	 */
	private function infoXmlStrings(): array {
		$xml = simplexml_load_file($this->root() . '/appinfo/info.xml');
		$found = [trim((string)$xml->name)];
		foreach ($xml->navigations->navigation as $nav) {
			$found[] = trim((string)$nav->name);
		}
		return array_filter($found);
	}

	/** @return string[] */
	private function files(string $dir, array $extensions): array {
		$found = [];
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir));
		foreach ($it as $file) {
			if (in_array($file->getExtension(), $extensions, true)) {
				$found[] = $file->getPathname();
			}
		}
		return $found;
	}

	public function testEveryInterfaceStringIsTranslated(): void {
		$known = $this->translations();
		$missing = array_values(array_filter($this->frontendStrings(),
			static fn ($s) => !isset($known[$s])));

		$this->assertSame([], $missing,
			'these show up in English in the archive UI');
	}

	public function testEveryServerStringIsTranslated(): void {
		$known = $this->translations();
		$missing = array_values(array_filter($this->serverStrings(),
			static fn ($s) => !isset($known[$s])));

		$this->assertSame([], $missing, 'these reach the user in English');
	}

	public function testNoStaleTranslations(): void {
		$used = array_merge($this->frontendStrings(), $this->serverStrings(),
			$this->infoXmlStrings());
		$stale = [];
		foreach ($this->translations() as $source => $translated) {
			// Proper nouns deliberately identical in both languages are not dead.
			if ($source !== $translated && !in_array($source, $used, true)) {
				$stale[] = $source;
			}
		}

		$this->assertSame([], $stale,
			'translated, but nothing uses them — usually the leftover half of a '
			. 'reworded message');
	}

	public function testTheJavascriptAndPhpTranslationFilesAgree(): void {
		// Nextcloud loads the .json for PHP and the .js for the browser.
		// Updating one and not the other leaves half the interface translated.
		$js = file_get_contents($this->root() . '/l10n/ru.js');
		$body = substr($js, (int)strpos($js, '{'),
			strrpos($js, '}') - strpos($js, '{') + 1);

		$this->assertSame($this->translations(), json_decode($body, true),
			'l10n/ru.js and l10n/ru.json disagree');
	}

	public function testPlaceholdersSurviveTranslation(): void {
		foreach ($this->translations() as $source => $translated) {
			preg_match_all('/\{(\w+)\}/', $source, $inSource);
			preg_match_all('/\{(\w+)\}/', $translated, $inTranslation);

			$this->assertSame($inSource[1], $inTranslation[1],
				"a translation that drops a {placeholder} loses the value it "
				. "was meant to show: '$source'");
		}
	}
}
