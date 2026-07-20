/**
 * Talking to the service that stores the transcripts.
 *
 * Requests do not go straight there: they pass through this app, which holds
 * the credentials. The browser never sees the service token — a user opening
 * devtools should not walk away with the key to every meeting in the company.
 *
 * The app also scopes the answer to whoever is asking. The service isolates by
 * tenant, not by user, so that filter exists only on the server side.
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
 * Meetings, newest first.
 *
 * @param {object} options filtering and paging
 * @param {number} [options.limit] how many to return
 * @param {number} [options.offset] where to start
 * @param {string} [options.scope] 'mine' or 'all' for the whole archive
 * @param {AbortSignal} [options.signal] to cancel a superseded search
 * @return {Promise<{meetings: object[], canSeeEverything: boolean}>}
 */
export async function fetchMeetings({ limit = 50, offset = 0, scope = 'mine', signal } = {}) {
	// No user parameter: the server derives it from the session and ignores
	// anything sent from here, so offering it would only invite the idea that
	// asking for someone else's meetings is a supported thing to do.
	//
	// scope=all is a request for the whole archive, which the server grants
	// only to accounts allowed it — asking without the right returns your own
	// meetings rather than an error.
	const params = new URLSearchParams({ limit, offset, scope })
	const data = await request(`/meetings?${params}`, { signal })
	return {
		meetings: data.meetings || [],
		canSeeEverything: !!data.can_see_everything,
	}
}

/**
 * One meeting's metadata.
 *
 * @param {string} sessionId meeting id
 * @return {Promise<object>}
 */
export function fetchMeeting(sessionId) {
	return request(`/meetings/${encodeURIComponent(sessionId)}`)
}

/**
 * The transcript itself — who said what, in order.
 *
 * @param {string} sessionId meeting id
 * @return {Promise<object>}
 */
export function fetchTranscript(sessionId) {
	return request(`/meetings/${encodeURIComponent(sessionId)}/transcript`)
}

/**
 * Analysis artefacts: summary, decisions, action items.
 *
 * Absent for a meeting that was only transcribed, and for one whose analysis is
 * still running — the caller distinguishes those by the meeting's
 * analysis_status rather than by an empty list here.
 *
 * @param {string} sessionId meeting id
 * @return {Promise<object[]>}
 */
export async function fetchAnalysis(sessionId) {
	const data = await request(`/meetings/${encodeURIComponent(sessionId)}/analysis`)
	return data.artifacts || []
}

/**
 * One analysis artefact, rendered as markdown by the backend.
 *
 * @param {string} sessionId meeting id
 * @param {string} name artefact name
 * @return {Promise<object>}
 */
export function fetchArtifact(sessionId, name) {
	return request(
		`/meetings/${encodeURIComponent(sessionId)}/analysis/${encodeURIComponent(name)}`,
	)
}
