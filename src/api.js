/**
 * Reading meetings.
 *
 * Meetings are files in the user's own Nextcloud, so these endpoints carry no
 * notion of "whose" — the server reads the caller's files and can return
 * nothing else. There is no token here and no service to reach: what a person
 * sees is what has been shared with them.
 */

import { generateUrl } from '@nextcloud/router'

async function request(path, { signal } = {}) {
	const response = await fetch(generateUrl(`/apps/done_transcription/api/v1${path}`), {
		headers: { Accept: 'application/json' },
		signal,
	})
	if (!response.ok) {
		// The status matters to the caller: 404 on a meeting is a normal
		// outcome (someone opened a stale link), 500 is not.
		const error = new Error(`Request failed: ${response.status}`)
		error.status = response.status
		throw error
	}
	return response.json()
}

/**
 * Meetings this person can see, newest first.
 *
 * No user or scope parameter: the server reads the caller's own files, so the
 * question "whose meetings" has only one possible answer.
 *
 * @param {object} options paging
 * @param {number} [options.limit] how many to return
 * @param {number} [options.offset] where to start
 * @param {AbortSignal} [options.signal] to cancel a superseded request
 * @return {Promise<object[]>}
 */
export async function fetchMeetings({ limit = 50, offset = 0, signal } = {}) {
	const params = new URLSearchParams({ limit, offset })
	const data = await request(`/meetings?${params}`, { signal })
	return data.meetings || []
}

/**
 * The summary: what people open, and usually all they read.
 *
 * @param {string} sessionId meeting id
 * @return {Promise<string>} markdown
 */
export async function fetchSummary(sessionId) {
	const data = await request(`/meetings/${encodeURIComponent(sessionId)}/summary`)
	return data.content || ''
}

/**
 * The verbatim transcript.
 *
 * Fetched only when asked for: it is the larger of the two files, and most
 * visits never need it.
 *
 * @param {string} sessionId meeting id
 * @return {Promise<string>} markdown
 */
export async function fetchTranscript(sessionId) {
	const data = await request(`/meetings/${encodeURIComponent(sessionId)}/transcript`)
	return data.content || ''
}
