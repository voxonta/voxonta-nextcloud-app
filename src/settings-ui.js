/**
 * The bot-account buttons on the settings page.
 *
 * Plain JS on purpose: the settings block is a PHP template, and giving it a
 * Vue bundle once split the app's stylesheet into a chunk the page never loaded.
 * This wires two buttons and shows the password that comes back — nothing that
 * needs a framework.
 *
 * The app password is shown once. Nextcloud cannot read it back after minting,
 * so losing it means regenerating; the copy shown here is a convenience, not
 * the only copy the service has (it fetches its own).
 */
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { translate as t } from '@nextcloud/l10n'

/**
 * Wire the bot-account buttons, if this page has them. Called from main.js so
 * there is one bundle, loaded on both the archive and the settings page — a
 * second Vite entry split the app's CSS into a chunk the page never loaded.
 */
export function wireBotAccount() {
	const el = document.getElementById('voxonta_bot')
	if (el) {
		setup(el)
	}
}

function setup(el) {
	el.querySelector('[data-action="provision"]')?.addEventListener('click', () => run(el, 'provision'))
	el.querySelector('[data-action="regenerate"]')?.addEventListener('click', () => run(el, 'regenerate'))
	el.querySelector('[data-action="existing"]')?.addEventListener('click', () => useExisting(el))
}

async function run(el, endpoint) {
	setBusy(el, true)
	try {
		// The endpoints are PasswordConfirmationRequired: Nextcloud wants the
		// admin's own password re-entered before an app password is minted, the
		// same guard as everywhere else it happens.
		await confirmPassword()
		const data = await post(`/api/v1/bot/${endpoint}`)
		showPassword(el, data.password)
	} catch (e) {
		showError(el, e)
	} finally {
		setBusy(el, false)
	}
}

async function useExisting(el) {
	const user = el.querySelector('[data-field="user"]')?.value?.trim()
	const password = el.querySelector('[data-field="password"]')?.value
	if (!user || !password) {
		showError(el, new Error(t('voxonta', 'Enter the account name and its app password.')))
		return
	}
	setBusy(el, true)
	try {
		await confirmPassword()
		await post('/api/v1/bot/existing', { user, password })
		el.querySelector('[data-role="result"]').textContent =
			t('voxonta', 'Saved. Reload to see the connection state.')
	} catch (e) {
		showError(el, e)
	} finally {
		setBusy(el, false)
	}
}

async function post(path, body) {
	const response = await fetch(generateUrl(`/apps/voxonta${path}`), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			requesttoken: getRequestToken(),
		},
		body: body ? JSON.stringify(body) : undefined,
	})
	if (!response.ok) {
		const detail = await response.json().catch(() => ({}))
		throw new Error(detail.message || `HTTP ${response.status}`)
	}
	return response.json()
}

function showPassword(el, password) {
	const box = el.querySelector('[data-role="result"]')
	box.textContent = ''
	const label = document.createElement('p')
	label.textContent = t('voxonta', 'App password — copy it now, it is not shown again:')
	const code = document.createElement('code')
	code.className = 'voxonta-bot__password'
	code.textContent = password
	box.append(label, code)
}

function showError(el, error) {
	el.querySelector('[data-role="result"]').textContent =
		t('voxonta', 'Could not set up the account: {reason}', { reason: error.message })
}

function setBusy(el, busy) {
	el.querySelectorAll('button').forEach((b) => { b.disabled = busy })
}
