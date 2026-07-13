/**
 * batch — job submission & monitoring UI (NC34, vanilla JS, no build).
 *
 * Talks to the AppFramework REST API in lib/Controller/ApiController, which
 * returns { status:'success'|'error', data:... }. The batch service itself is
 * reached server-side over mutual-TLS with the user's X.509 cert.
 */
(function() {
	'use strict'

	const APP = 'batch'
	const $ = (sel) => document.querySelector(sel)
	const $$ = (sel) => Array.from(document.querySelectorAll(sel))

	const url = (path) => {
		const i = path.indexOf('?')
		const base = i === -1 ? path : path.slice(0, i)
		const query = i === -1 ? '' : path.slice(i)
		return OC.generateUrl('/apps/' + APP + '/' + base) + query
	}

	let pending = 0
	function loading(on) {
		pending += on ? 1 : -1
		const el = $('#batch-loading')
		if (el) { el.hidden = pending <= 0 }
	}

	// Single fetch path for every call. Guarantees the loading spinner is
	// cleared no matter what: the work is wrapped so a synchronous throw in
	// url()/fetch() still hits .finally(); an AbortController bounds the wait
	// (the server-side service curl tops out ~75s, so 120s is a safe ceiling)
	// so a stalled backend can never spin forever; and any network/timeout/
	// non-JSON error resolves to a normal { status:'error' } object so callers
	// surface a toast instead of dropping an unhandled rejection.
	const REQUEST_TIMEOUT_MS = 120000
	function apiFetch(path, init) {
		loading(true)
		const ctrl = new AbortController()
		const timer = setTimeout(() => ctrl.abort(), REQUEST_TIMEOUT_MS)
		return Promise.resolve()
			.then(() => fetch(url(path), Object.assign({ signal: ctrl.signal }, init)))
			.then((r) => r.json())
			.catch((e) => ({
				status: 'error',
				data: { message: (e && e.name === 'AbortError') ? 'The request timed out.' : 'Network error.' },
			}))
			.finally(() => { clearTimeout(timer); loading(false) })
	}

	function apiGet(path) {
		return apiFetch(path, {
			headers: { requesttoken: OC.requestToken, 'OCS-APIRequest': 'true' },
		})
	}

	function apiPost(path, params) {
		const body = new URLSearchParams()
		Object.keys(params || {}).forEach((k) => body.append(k, params[k] == null ? '' : params[k]))
		return apiFetch(path, {
			method: 'POST',
			headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		})
	}

	function toast(message, isError) {
		if (window.OCP && OCP.Toast) { isError ? OCP.Toast.error(message) : OCP.Toast.success(message) } else if (OC.Notification) { OC.Notification.showTemporary(message) } else { /* noop */ }
	}

	function shortId(id) {
		const s = String(id || '')
		const seg = s.replace(/\/+$/, '').split('/').pop()
		return seg || s
	}

	// ----------------------------------------------------------------- setup

	function populateSetup(status) {
		const nofs = !status.filesSharding
		$('#batch-setup-nofs').hidden = !nofs
		$('#batch-setup-steps').hidden = nofs
		$('#batch-cert-status').textContent = status.hasCert
			? (status.certDn + (status.certExpires ? ' (expires ' + status.certExpires + ')' : ''))
			: 'none yet'
		$('#batch-gen-cert').textContent = status.hasCert ? 'Regenerate' : 'Generate certificate'
		$('#batch-workfolder').value = status.workFolder || '/Batch'
	}

	// Setup is a modal overlaid on the main (jobs) view.
	function openSetup(status) {
		populateSetup(status)
		$('#batch-setup').hidden = false
	}

	function closeSetup() {
		$('#batch-setup').hidden = true
	}

	function showMain() {
		closeSetup()
		$('#batch-main').hidden = false
		loadScripts()
		loadJobs()
	}

	function refreshSetup() {
		return apiGet('api/setup').then((r) => {
			const s = r.data || {}
			showMain()                          // main is always the base view…
			if (!s.configured) { openSetup(s) } // …with the setup modal over it until configured
			return s
		})
	}

	function wireSetup() {
		$('#batch-gen-cert').addEventListener('click', () => {
			apiPost('api/cert', {}).then((r) => {
				if (r.status === 'success') { toast('Certificate generated'); refreshSetupKeepPanel() } else { toast(r.data.message, true) }
			})
		})
		$('#batch-browse-folder').addEventListener('click', () => {
			if (!window.OC || !OC.dialogs || !OC.dialogs.filepicker) { return }
			OC.dialogs.filepicker(
				t('batch', 'Choose work folder'),
				(path) => { $('#batch-workfolder').value = path || '/' },
				false,
				'httpd/unix-directory',
				true,
				OC.dialogs.FILEPICKER_TYPE_CHOOSE
			)
		})
		$('#batch-save-settings').addEventListener('click', () => {
			apiPost('api/settings', { work_folder: $('#batch-workfolder').value }).then((r) => {
				if (r.status === 'success') { toast('Saved'); refreshSetupKeepPanel() } else { toast(r.data.message, true) }
			})
		})
		$('#batch-get-templates').addEventListener('click', () => {
			const go = () => apiPost('api/templates/get', {}).then((r) => {
				if (r.status === 'success') { toast('Templates copied'); refreshSetupKeepPanel() } else { toast(r.data.message, true) }
			})
			const msg = t('batch', 'This copies the default job templates into your work folder and overwrites any existing templates with the same names. Continue?')
			if (window.OC && OC.dialogs && OC.dialogs.confirm) {
				OC.dialogs.confirm(msg, t('batch', 'Copy job templates'), (ok) => { if (ok) { go() } })
			} else if (window.confirm(msg)) {
				go()
			}
		})
		$('#batch-setup-link').addEventListener('click', (e) => {
			e.preventDefault()
			apiGet('api/setup').then((r) => openSetup(r.data || {}))
		})
		$('#batch-setup-close').addEventListener('click', () => closeSetup())
		$('#batch-setup').addEventListener('click', (e) => { if (e.target.id === 'batch-setup') { closeSetup() } })
	}

	// re-read status but stay on the setup panel (so the user can finish all steps)
	function refreshSetupKeepPanel() {
		return apiGet('api/setup').then((r) => openSetup(r.data || {}))
	}

	// --------------------------------------------------------------- editor

	function loadScripts() {
		apiGet('api/scripts').then((r) => {
			const scripts = (r.status === 'success' && Array.isArray(r.data)) ? r.data : []
			const sel = $('#batch-script-select')
			sel.innerHTML = '<option value="">— choose —</option>'
			scripts.forEach((p) => {
				const o = document.createElement('option')
				o.value = p
				o.textContent = p.replace(/^.*?\/job_templates\//, '').replace(/\/job_templates\//, '/')
				sel.appendChild(o)
			})
		})
	}

	function openEditor() {
		$('#batch-editor').hidden = false
		$('#batch-script-text').value = ''
		$('#batch-script-select').value = ''
		$('#batch-input-file').value = ''
		$('#batch-script-text').focus()
	}

	function wireEditor() {
		$('#batch-new').addEventListener('click', openEditor)
		$('#batch-cancel').addEventListener('click', () => { $('#batch-editor').hidden = true })
		$('#batch-script-select').addEventListener('change', (e) => {
			const path = e.target.value
			if (!path) { return }
			apiGet('api/script?path=' + encodeURIComponent(path)).then((r) => {
				if (r.status === 'success') { $('#batch-script-text').value = r.data || '' } else { toast(r.data.message, true) }
			})
		})
		$('#batch-save-script').addEventListener('click', () => {
			const path = $('#batch-script-select').value
			if (!path) { toast('Choose a script slot first (or use a template path).', true); return }
			apiPost('api/script', { path, text: $('#batch-script-text').value }).then((r) => {
				toast(r.status === 'success' ? 'Saved' : r.data.message, r.status !== 'success')
			})
		})
		$('#batch-submit').addEventListener('click', () => {
			const text = $('#batch-script-text').value
			const inputFile = $('#batch-input-file').value.trim()
			const params = { script_text: text }
			if (inputFile) { params.input_files = JSON.stringify([inputFile]) }
			apiPost('api/job', params).then((r) => {
				if (r.status === 'success') {
					toast('Submitted')
					$('#batch-editor').hidden = true
					loadJobs()
					// the batch service registers the job a moment after upload;
					// refresh again so it shows up without a manual reload
					window.setTimeout(loadJobs, 2500)
				} else { toast(r.data.message, true) }
			})
		})
	}

	// ----------------------------------------------------------------- jobs

	function statusClass(s) {
		s = (s || '').toLowerCase()
		if (s.indexOf('done') === 0) { return 'batch-st-done' }
		if (s.indexOf('running') === 0) { return 'batch-st-running' }
		if (s.indexOf('fail') !== -1 || s.indexOf('error') !== -1) { return 'batch-st-fail' }
		return 'batch-st-other'
	}

	let allJobs = []

	function loadJobs() {
		apiGet('api/jobs').then((r) => {
			allJobs = (r.status === 'success' && Array.isArray(r.data)) ? r.data : []
			if (r.status !== 'success') { toast(r.data.message, true) }
			applyJobFilter()
		})
	}

	function currentUid() {
		if (window.OC && typeof OC.getCurrentUser === 'function') { return (OC.getCurrentUser() || {}).uid || '' }
		return (window.OC && OC.currentUser) || ''
	}
	// Owner username from a job's userInfo DN (/CN=<uid>/O=… or CN=<uid>,O=…).
	function jobOwner(job) {
		const m = String(job.userInfo || '').match(/CN=([^,/]+)/i)
		return m ? m[1].trim() : ''
	}
	function ownsJob(job) {
		const u = currentUid()
		return u !== '' && jobOwner(job) === u
	}

	// Client-side filter over the full list: text match + "my jobs only".
	function applyJobFilter() {
		const q = ($('#batch-filter').value || '').trim().toLowerCase()
		const mineOnly = $('#batch-mine-only').checked
		const rows = allJobs.filter((j) => {
			if (mineOnly && !ownsJob(j)) { return false }
			if (q === '') { return true }
			return (String(j.name || '') + ' ' + String(j.identifier || '') + ' '
				+ String(j.csStatus || '') + ' ' + String(j.userInfo || '')).toLowerCase().indexOf(q) !== -1
		})
		renderJobs(rows)
	}

	function renderJobs(jobs) {
		const body = $('#batch-jobs-body')
		body.innerHTML = ''
		$('#batch-jobs-empty').hidden = jobs.length > 0
		jobs.forEach((job) => {
			const id = job.identifier || ''
			const mine = ownsJob(job)
			const tr = document.createElement('tr')

			// Only the job's owner may select/delete it.
			const tdC = document.createElement('td')
			if (mine) {
				const cb = document.createElement('input')
				cb.type = 'checkbox'; cb.className = 'batch-job-check'; cb.dataset.id = id
				cb.addEventListener('change', updateDeleteBtn)
				tdC.appendChild(cb)
			}
			tr.appendChild(tdC)

			const tdId = document.createElement('td')
			tdId.className = 'batch-col-id'; tdId.title = id; tdId.textContent = shortId(id)
			tr.appendChild(tdId)

			const tdName = document.createElement('td')
			tdName.textContent = job.name || ''
			tr.appendChild(tdName)

			const tdStatus = document.createElement('td')
			const badge = document.createElement('span')
			badge.className = 'batch-badge ' + statusClass(job.csStatus)
			badge.textContent = job.csStatus || ''
			tdStatus.appendChild(badge); tr.appendChild(tdStatus)

			// Inspect (fetch script/stdout/stderr) and delete both need the
			// caller to be the job's owner, so only render them for own jobs.
			const tdAct = document.createElement('td')
			tdAct.className = 'batch-col-actions'
			if (mine) {
				const more = document.createElement('button')
				more.className = 'button batch-icon'; more.textContent = '⋯'; more.title = 'Inspect'
				more.addEventListener('click', () => openInspect(job))
				const del = document.createElement('button')
				del.className = 'button batch-icon icon-delete'; del.title = 'Delete'
				del.addEventListener('click', () => deleteJobs([id]))
				tdAct.appendChild(more); tdAct.appendChild(del)
			}
			tr.appendChild(tdAct)

			body.appendChild(tr)
		})
		$('#batch-select-all').checked = false
		updateDeleteBtn()
	}

	function selectedIds() {
		return $$('.batch-job-check').filter((c) => c.checked).map((c) => c.dataset.id)
	}
	function updateDeleteBtn() {
		$('#batch-delete').disabled = selectedIds().length === 0
	}

	function deleteJobs(ids) {
		if (!ids.length) { return }
		if (!window.confirm('Delete ' + ids.length + ' job(s)?')) { return }
		apiPost('api/jobs/delete', { identifiers: JSON.stringify(ids) }).then((r) => {
			toast(r.status === 'success' ? 'Deleted' : r.data.message, r.status !== 'success')
			loadJobs()
		})
	}

	function wireJobs() {
		$('#batch-refresh').addEventListener('click', loadJobs)
		$('#batch-delete').addEventListener('click', () => deleteJobs(selectedIds()))
		$('#batch-select-all').addEventListener('change', (e) => {
			$$('.batch-job-check').forEach((c) => { c.checked = e.target.checked })
			updateDeleteBtn()
		})
		$('#batch-filter').addEventListener('input', applyJobFilter)
		$('#batch-mine-only').addEventListener('change', applyJobFilter)
	}

	// -------------------------------------------------------------- inspect

	let inspectJob = null

	function openInspect(job) {
		inspectJob = job
		$('#batch-inspect-title').textContent = (job.name || shortId(job.identifier)) + ' — ' + (job.csStatus || '')
		$('#batch-inspect-body').textContent = ''
		$('#batch-inspect').hidden = false
		showInspectFile('job')
	}

	function showInspectInfo() {
		if (!inspectJob) { return }
		$('#batch-inspect-body').textContent = 'Loading…'
		apiGet('api/job?identifier=' + encodeURIComponent(inspectJob.identifier)).then((r) => {
			if (r.status !== 'success') { $('#batch-inspect-body').textContent = r.data.message; return }
			$('#batch-inspect-body').textContent = Object.keys(r.data)
				.map((k) => k + ': ' + r.data[k]).join('\n')
		})
	}

	function showInspectFile(filename) {
		if (!inspectJob) { return }
		$('#batch-inspect-body').textContent = 'Loading…'
		const q = 'api/job/file?identifier=' + encodeURIComponent(inspectJob.identifier)
			+ '&filename=' + encodeURIComponent(filename)
			+ '&status=' + encodeURIComponent(inspectJob.csStatus || '')
		apiGet(q).then((r) => {
			if (r.status !== 'success') { $('#batch-inspect-body').textContent = r.data.message; return }
			$('#batch-inspect-body').textContent = (r.data && r.data.content) || '(empty)'
		})
	}

	function wireInspect() {
		$('#batch-inspect-close').addEventListener('click', () => { $('#batch-inspect').hidden = true })
		$('#batch-inspect').addEventListener('click', (e) => { if (e.target.id === 'batch-inspect') { $('#batch-inspect').hidden = true } })
		$$('.batch-tab').forEach((tab) => {
			tab.addEventListener('click', () => {
				if (tab.dataset.info) { showInspectInfo() } else { showInspectFile(tab.dataset.file) }
			})
		})
	}

	// ------------------------------------------------------------------ init

	document.addEventListener('DOMContentLoaded', () => {
		if (!$('#app-content-batch')) { return }
		wireSetup()
		wireEditor()
		wireJobs()
		wireInspect()
		refreshSetup()
	})
})()
