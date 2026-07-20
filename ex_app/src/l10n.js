/**
 * Translations, carried in the bundle.
 *
 * Nextcloud's usual mechanism (OC.L10N.register + l10n/*.js) is not wired up for
 * external apps — AppAPI has no route that serves an ExApp's translation files —
 * so shipping them inside the bundle is what actually works. The shape is kept
 * close to the standard one so moving to it later is a matter of extracting this
 * object, not rewriting call sites.
 *
 * English is the source language and lives in the call sites themselves; a
 * missing translation therefore degrades to readable English rather than to a
 * key like "meetings.empty.title".
 */

const TRANSLATIONS = {
	ru: {
		Meetings: 'Встречи',
		'Loading meetings…': 'Загрузка встреч…',
		'Try again': 'Повторить',
		'No transcribed meetings yet.': 'Расшифрованных встреч пока нет.',
		'Calls you take part in appear here once they end and the transcript is ready.':
			'Звонки, в которых вы участвовали, появятся здесь после завершения, '
			+ 'когда будет готова расшифровка.',
		'Could not load your meetings. The transcription service may be unavailable.':
			'Не удалось загрузить встречи. Возможно, сервис расшифровки недоступен.',
		'Show older meetings': 'Показать более ранние',
		'Select a meeting to read its transcript.':
			'Выберите встречу, чтобы прочитать расшифровку.',
		'Untitled call': 'Без названия',
		Today: 'Сегодня',
		Summary: 'Итоги',
		Transcript: 'Расшифровка',
		'Loading…': 'Загрузка…',
		'Could not load the transcript.': 'Не удалось загрузить расшифровку.',
		'Nobody spoke during this call, or the audio could not be captured.':
			'В этом звонке никто не говорил, либо не удалось получить звук.',
		'The summary is being prepared. The transcript below is complete.':
			'Итоги готовятся. Расшифровка ниже уже полная.',
		Analysing: 'Анализ',
		'{count} min': '{count} мин',
		'{hours} h {minutes} min': '{hours} ч {minutes} мин',
		'{names} and {count} others': '{names} и ещё {count}',
	},
}

/**
 * The language Nextcloud is showing the interface in.
 *
 * @return {string} two-letter code
 */
function currentLanguage() {
	// OC.getLanguage() is the authoritative source; the html lang attribute is
	// the fallback for contexts where OC is not exposed. Both can carry a
	// region ("ru-RU"), and translations are keyed by language alone.
	const raw = (typeof OC !== 'undefined' && OC.getLanguage && OC.getLanguage())
		|| document.documentElement.lang
		|| 'en'
	return String(raw).split(/[-_]/)[0].toLowerCase()
}

const dictionary = TRANSLATIONS[currentLanguage()] || {}

/**
 * Translate a string, substituting {placeholders}.
 *
 * @param {string} text English source string
 * @param {object} [vars] values for {placeholders}
 * @return {string}
 */
export function translate(text, vars = {}) {
	const translated = dictionary[text] || text
	return translated.replace(/\{(\w+)\}/g, (match, name) =>
		(name in vars ? vars[name] : match),
	)
}
