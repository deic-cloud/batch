/**
 * "Process" action in Files: run a job template on the selected file(s), one
 * job per file (folders are skipped) - as the old service's Process action did.
 *
 * Registered via the official @nextcloud/files API, which is not exposed to
 * plain JS - hence this small bundle (the rest of the app needs no build).
 * Submission goes through the app's own API (api/scripts, api/job), the same
 * calls the Batch page makes.
 */
import { registerFileAction, FileType } from '@nextcloud/files'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

const APP = 'batch'
const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path fill="currentColor" d="M12,16L19.36,10.27L21,9L12,2L3,9L4.63,10.27M12,18.54L4.62,12.81L3,14.07L12,21.07L21,14.07L19.37,12.8L12,18.54Z"/></svg>'

// Dialog styles, injected once (the rest of the app ships plain CSS; this
// bundle is loaded on the Files page, which does not load it).
const STYLE = `
.batch-process-overlay { position: fixed; inset: 0; z-index: 10000; display: flex; align-items: center;
  justify-content: center; background: rgba(0, 0, 0, .35); }
.batch-process-box { background: var(--color-main-background); color: var(--color-main-text);
  border-radius: var(--border-radius-large, 10px); box-shadow: 0 0 40px rgba(0, 0, 0, .3);
  padding: 20px 24px; width: min(480px, calc(100vw - 32px)); }
.batch-process-box h3 { margin: 0 0 12px; font-weight: bold; }
.batch-process-body label { display: block; margin-bottom: 4px; }
.batch-process-body select { width: 100%; }
.batch-process-note { color: var(--color-text-maxcontrast); margin-top: 8px; }
.batch-process-buttons { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.batch-process-buttons button { width: auto; }
`
function addStyle() {
	if (!document.getElementById('batch-process-style')) {
		const s = document.createElement('style')
		s.id = 'batch-process-style'
		s.textContent = STYLE
		document.head.appendChild(s)
	}
}

const appUrl = (path) => window.OC.generateUrl('/apps/' + APP + '/' + path)

function toast(message, isError) {
	const T = window.OCP && window.OCP.Toast
	if (T) {
		isError ? T.error(message) : T.success(message)
	} else if (window.OC && window.OC.Notification) {
		window.OC.Notification.showTemporary(message)
	}
}

async function apiGet(path) {
	try {
		const r = await fetch(appUrl(path), { headers: { requesttoken: window.OC.requestToken, 'OCS-APIRequest': 'true' } })
		return await r.json()
	} catch (e) {
		return { status: 'error', data: { message: t(APP, 'Network error.') } }
	}
}

async function apiPost(path, params) {
	const body = new URLSearchParams()
	Object.keys(params).forEach((k) => body.append(k, params[k]))
	try {
		const r = await fetch(appUrl(path), {
			method: 'POST',
			headers: { requesttoken: window.OC.requestToken, 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		})
		return await r.json()
	} catch (e) {
		return { status: 'error', data: { message: t(APP, 'Network error.') } }
	}
}

// Path of a node relative to the user's files root, as the batch API expects it.
const filePath = (node) => node.path

// "Batch/job_templates/media/transcribe_danish.sh" -> "media/transcribe_danish.sh"
const label = (p) => p.replace(/^.*?\/job_templates\//, '').replace(/\/job_templates\//, '/')

function el(tag, attrs, children) {
	const e = document.createElement(tag)
	Object.entries(attrs || {}).forEach(([k, v]) => {
		if (k === 'text') { e.textContent = v } else { e.setAttribute(k, v) }
	})
	;(children || []).forEach((c) => e.appendChild(c))
	return e
}

/** The dialog: pick a template, submit one job per file. Resolves when closed. */
function openDialog(files) {
	addStyle()
	return new Promise((resolve) => {
		const overlay = el('div', { class: 'batch-process-overlay' })
		const box = el('div', { class: 'batch-process-box', role: 'dialog', 'aria-modal': 'true' })
		const title = el('h3', { text: n(APP, 'Process %n file', 'Process %n files', files.length) })
		const body = el('div', { class: 'batch-process-body', text: t(APP, 'Loading…') })
		const cancel = el('button', { type: 'button', text: t(APP, 'Cancel') })
		const submit = el('button', { type: 'button', class: 'primary', text: t(APP, 'Submit') })
		submit.disabled = true
		box.append(title, body, el('div', { class: 'batch-process-buttons' }, [cancel, submit]))
		overlay.appendChild(box)
		document.body.appendChild(overlay)

		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey); resolve() }
		const onKey = (e) => { if (e.key === 'Escape') { close() } }
		document.addEventListener('keydown', onKey)
		cancel.addEventListener('click', close)
		overlay.addEventListener('click', (e) => { if (e.target === overlay) { close() } })

		const openBatch = el('a', { href: appUrl(''), text: t(APP, 'Open Batch') })
		const say = (text, withLink) => {
			body.textContent = text
			if (withLink) { body.append(' ', openBatch) }
		}

		Promise.all([apiGet('api/setup'), apiGet('api/scripts')]).then(([setup, scripts]) => {
			if (setup.status !== 'success' || !setup.data || !setup.data.configured) {
				say(t(APP, 'Batch is not set up for you yet.'), true)
				return
			}
			const list = (scripts.status === 'success' && Array.isArray(scripts.data)) ? scripts.data : []
			if (!list.length) {
				say(t(APP, 'You have no job templates yet. Copy the default ones in Batch → Setup.'), true)
				return
			}
			const select = el('select', { id: 'batch-process-template', 'aria-label': t(APP, 'Job template') })
			list.forEach((p) => select.appendChild(el('option', { value: p, text: label(p) })))
			body.textContent = ''
			body.append(
				el('label', { for: 'batch-process-template', text: t(APP, 'Job template') }),
				select,
				el('p', { class: 'batch-process-note', text: n(APP, 'One job will be submitted.', 'One job per file - %n jobs will be submitted.', files.length) }),
			)
			submit.disabled = false
			select.focus()

			submit.addEventListener('click', async () => {
				submit.disabled = true
				cancel.disabled = true
				say(t(APP, 'Submitting…'))
				const r = await apiPost('api/job', {
					script_path: select.value,
					input_files: JSON.stringify(files.map(filePath)),
				})
				close()
				if (r.status === 'success') {
					const count = (r.data && Array.isArray(r.data.jobs)) ? r.data.jobs.length : files.length
					toast(n(APP, '%n job submitted - see Batch for its progress.', '%n jobs submitted - see Batch for their progress.', count))
				} else {
					toast((r.data && r.data.message) || t(APP, 'Submitting failed.'), true)
				}
			})
		})
	})
}

async function processNodes(nodes) {
	const files = (nodes || []).filter((node) => node.type === FileType.File)
	if (!files.length) {
		toast(t(APP, 'Select one or more files to process (folders are skipped).'), true)
		return
	}
	await openDialog(files)
}

registerFileAction({
	id: 'batch-process',
	displayName: () => t(APP, 'Process'),
	title: () => t(APP, 'Run a batch job template on the selected file(s)'),
	iconSvgInline: () => ICON,
	// Files only (not folders); not in the trash bin.
	enabled: ({ nodes, view }) => Array.isArray(nodes) && nodes.some((node) => node.type === FileType.File)
		&& !(view && view.id === 'trashbin'),
	exec: async ({ nodes }) => { await processNodes(nodes); return null },
	execBatch: async ({ nodes }) => { await processNodes(nodes); return nodes.map(() => null) },
	order: 60,
})
