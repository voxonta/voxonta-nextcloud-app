/**
 * Talking to the backend that stores the transcripts.
 *
 * Requests do not go straight there: they pass through this app, which holds
 * the credentials. The browser never sees the backend token — a user opening
 * devtools should not walk away with the key to every meeting in the company.
 *
 * Paths go through the AppAPI proxy. A plain '/apps/...' URL resolves against
 * Nextcloud itself and quietly 404s, which is the single easiest way to lose an
 * afternoon here.
 */

const PROXY = '/apps/app_api/proxy/done_transcription'

async function request(path, { signal } = {}) {
	const response = await fetch(`${PROXY}${path}`, {
		headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
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
 * @param {string} [options.user] only meetings this person attended
 * @param {AbortSignal} [options.signal] to cancel a superseded search
 * @return {Promise<object[]>}
 */
export async function fetchMeetings({ limit = 50, offset = 0, user, signal } = {}) {
	const params = new URLSearchParams({ limit, offset })
	if (user) {
		params.set('user', user)
	}
	const data = await request(`/v1/meetings?${params}`, { signal })
	return data.meetings || []
}

/**
 * One meeting's metadata.
 *
 * @param {string} sessionId meeting id
 * @return {Promise<object>}
 */
export function fetchMeeting(sessionId) {
	return request(`/v1/meetings/${encodeURIComponent(sessionId)}`)
}

/**
 * The transcript itself — who said what, in order.
 *
 * @param {string} sessionId meeting id
 * @return {Promise<object>}
 */
export function fetchTranscript(sessionId) {
	return request(`/v1/meetings/${encodeURIComponent(sessionId)}/transcript`)
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
	const data = await request(`/v1/meetings/${encodeURIComponent(sessionId)}/analysis`)
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
		`/v1/meetings/${encodeURIComponent(sessionId)}/analysis/${encodeURIComponent(name)}`,
	)
}
