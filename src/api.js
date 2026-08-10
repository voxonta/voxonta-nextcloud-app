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
	const response = await fetch(generateUrl(`/apps/voxonta/api/v1${path}`), {
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
 * @param {number} [options.offset] file offset to resume from
 * @param {string} [options.query] text to match against participants and title
 * @param {number} [options.from] earliest day, unix seconds (0 = open)
 * @param {number} [options.to] latest day, unix seconds (0 = open)
 * @param {string} [options.room] conversation name to restrict to
 * @param {AbortSignal} [options.signal] to cancel a superseded request
 * @return {Promise<{meetings: object[], nextOffset: number, hasMore: boolean}>}
 */
export async function fetchMeetings({ limit = 50, offset = 0, query = '', from = 0, to = 0, room = '', signal } = {}) {
	const params = new URLSearchParams({ limit, offset })
	// Sent only when set, to keep the common unfiltered request tidy.
	if (query) {
		params.set('query', query)
	}
	if (from) {
		params.set('from', from)
	}
	if (to) {
		params.set('to', to)
	}
	if (room) {
		params.set('room', room)
	}
	const data = await request(`/meetings?${params}`, { signal })
	return {
		meetings: data.meetings || [],
		// The offset to resume from is the server's to compute: pages are over
		// files, and some files drop out as non-transcripts, so it is not the
		// count of meetings shown.
		nextOffset: data.next_offset || 0,
		hasMore: !!data.has_more,
	}
}

/**
 * The group conversations to offer as a filter, most-used first.
 *
 * One-to-ones are left out by the server: they are most of the names and none
 * of the use — those are found by searching for the person.
 *
 * @return {Promise<{name: string, count: number}[]>}
 */
export async function fetchRooms() {
	const data = await request('/rooms')
	return data.rooms || []
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
