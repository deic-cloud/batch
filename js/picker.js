/**
 * Job template picker (plain JS, no build), shared by the Batch page and the
 * Files "Process" dialog: a search box, a "Featured" row, then one section per
 * category, each template as a card with title and summary - arranged like the
 * pod image gallery. Entries come from api/catalog:
 *   { path, title, category, summary, output, featured }
 *
 *   window.BatchPicker.render(container, entries, { selected, onSelect })
 */
(function() {
	'use strict'

	const STYLE = `
.batch-picker-search { width: 100%; margin: 0 0 8px; }
.batch-picker-scroll { max-height: min(60vh, 520px); overflow-y: auto; padding-right: 4px; }
.batch-picker-section h4 { margin: 12px 0 6px; font-weight: bold; color: var(--color-text-maxcontrast); }
.batch-picker-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 8px; }
.batch-picker .batch-picker-card { display: flex; flex-direction: column; align-items: flex-start; gap: 4px;
  width: auto; height: auto; min-height: 64px; margin: 0; padding: 8px 10px; text-align: left; white-space: normal;
  line-height: 1.3; cursor: pointer; background: var(--color-main-background); color: var(--color-main-text);
  border: 1px solid var(--color-border-dark); border-radius: var(--border-radius-large, 8px); }
.batch-picker .batch-picker-card:hover { border-color: var(--color-primary-element); }
.batch-picker .batch-picker-card.selected { border: 2px solid var(--color-primary-element);
  background: var(--color-primary-element-light, var(--color-background-hover)); }
.batch-picker-card-head { display: flex; align-items: center; gap: 10px; }
.batch-picker-card-icon { flex: 0 0 28px; width: 28px; height: 28px; background-color: var(--color-primary-element);
  -webkit-mask: var(--batch-icon) center / contain no-repeat; mask: var(--batch-icon) center / contain no-repeat; }
.batch-picker-card-title { font-weight: bold; }
.batch-picker-card-summary { color: var(--color-text-maxcontrast); font-size: 0.92em; }
.batch-picker-empty { color: var(--color-text-maxcontrast); padding: 8px 0; }
`
	function addStyle() {
		if (!document.getElementById('batch-picker-style')) {
			const s = document.createElement('style')
			s.id = 'batch-picker-style'
			s.textContent = STYLE
			document.head.appendChild(s)
		}
	}

	const tr = (s) => (window.t ? window.t('batch', s) : s)

	// Category -> icon (Material Design Icons in img/categories), chosen by a
	// keyword in the category name like the pod image gallery does, so new
	// categories get a fitting icon without code changes.
	const ICONS = [
		[['my scripts'], 'script-text-outline'],
		[['media', 'video', 'audio', 'sound'], 'play-box-multiple-outline'],
		[['biolog', 'genom', 'sequenc', 'dna'], 'dna'],
		[['wind', 'climate', 'weather', 'energy'], 'wind-turbine'],
		[['chem'], 'flask-outline'],
		[['sign', 'encrypt', 'certific', 'secur'], 'certificate-outline'],
		[['utilit', 'tool'], 'tools'],
	]
	function iconUrl(category) {
		const c = (category || '').toLowerCase()
		const hit = ICONS.find(([words]) => words.some((w) => c.includes(w)))
		const name = hit ? hit[1] : 'cube-outline'
		return window.OC && window.OC.imagePath ? window.OC.imagePath('batch', 'categories/' + name) : ''
	}

	function el(tag, cls, text) {
		const e = document.createElement(tag)
		if (cls) { e.className = cls }
		if (text !== undefined) { e.textContent = text }
		return e
	}

	function render(container, entries, opts) {
		addStyle()
		opts = opts || {}
		let selected = opts.selected || ''
		container.innerHTML = ''
		container.classList.add('batch-picker')
		const search = el('input', 'batch-picker-search')
		search.type = 'search'
		search.placeholder = tr('Search templates…')
		search.setAttribute('aria-label', tr('Search templates'))
		const scroll = el('div', 'batch-picker-scroll')
		container.append(search, scroll)

		const card = (e) => {
			const b = el('button', 'batch-picker-card' + (e.path === selected ? ' selected' : ''))
			b.type = 'button'
			b.dataset.path = e.path
			b.title = e.path
			const head = el('span', 'batch-picker-card-head')
			const icon = el('span', 'batch-picker-card-icon')
			icon.setAttribute('aria-hidden', 'true')
			const url = iconUrl(e.category)
			if (url) { icon.style.setProperty('--batch-icon', 'url("' + url + '")') }
			head.append(icon, el('span', 'batch-picker-card-title', e.title))
			b.append(head, el('span', 'batch-picker-card-summary', e.summary || ''))
			b.addEventListener('click', () => {
				selected = e.path
				container.querySelectorAll('.batch-picker-card').forEach((c) => c.classList.toggle('selected', c.dataset.path === selected))
				if (opts.onSelect) { opts.onSelect(e) }
			})
			b.addEventListener('dblclick', () => { if (opts.onChoose) { opts.onChoose(e) } })
			return b
		}
		const section = (title, list) => {
			const s = el('div', 'batch-picker-section')
			const grid = el('div', 'batch-picker-grid')
			list.forEach((e) => grid.appendChild(card(e)))
			s.append(el('h4', '', title), grid)
			return s
		}

		const draw = () => {
			const q = search.value.trim().toLowerCase()
			const shown = entries.filter((e) => !q
				|| (e.title + ' ' + e.summary + ' ' + e.category + ' ' + e.path).toLowerCase().includes(q))
			scroll.innerHTML = ''
			if (!shown.length) {
				scroll.appendChild(el('div', 'batch-picker-empty', tr('No matching templates.')))
				return
			}
			// Featured first (only when not searching), then every category in the
			// server's order (alphabetical; "Other" and "My scripts" last).
			const featured = q ? [] : shown.filter((e) => e.featured).sort((a, b) => a.featured - b.featured)
			if (featured.length) { scroll.appendChild(section(tr('Featured'), featured)) }
			const cats = []
			shown.forEach((e) => { if (!cats.includes(e.category)) { cats.push(e.category) } })
			cats.forEach((c) => scroll.appendChild(section(c, shown.filter((e) => e.category === c))))
		}
		search.addEventListener('input', draw)
		draw()
		return { focus: () => search.focus() }
	}

	window.BatchPicker = { render }
})()
