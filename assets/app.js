/* Library page: filters, grid, selection + bulk actions, viewer, and the "Add files" picker. */
(function () {
	'use strict';

	const { api, el, toast, formatSize, formatDuration, debounce, withLoading, faceEl } = BA;
	const $ = (id) => document.getElementById(id);
	const LIB = 'api/library.php';
	const TAX = 'api/taxonomy.php';
	const byName = (a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });

	const state = {
		q: '', type: '', sort: 'added_desc', untagged: false, missing: false, nothumbs: false, dmin: '', dmax: '',
		terms: new Set(),
		items: [], els: [], start: 0, total: 0, cols: 1, pitch: 0, gap: 12, winBusy: false, req: 0, // the window: see "grid" below
		selected: new Map(), lastClicked: -1, // selected: id -> item (kept after its tile leaves the window); lastClicked: list position
		categories: [], collapsed: new Set(),
		view: -1, dirty: false, focusCat: null,
	};

	// ---------------------------------------------------------------- taxonomy

	let termById = new Map(); // term id -> {id, name, count, photo, full_name}

	async function loadTaxonomy() {
		const d = await api(TAX);
		state.categories = d.categories;
		termById = new Map(d.categories.flatMap((c) => c.terms.map((t) => [t.id, t])));
		for (const c of state.categories) c.terms.sort(byName);
		const valid = new Set(state.categories.flatMap((c) => c.terms.map((t) => t.id)));
		for (const id of [...state.terms]) if (!valid.has(id)) state.terms.delete(id); // term was deleted
		renderFacets();
		renderDatalists();
		renderBulkCategories();
	}

	function findTerm(catId, name) {
		const cat = state.categories.find((c) => c.id === catId);
		const n = name.trim().toLowerCase();
		return cat ? cat.terms.find((t) => t.name.toLowerCase() === n) : undefined;
	}

	function renderDatalists() {
		$('datalists').replaceChildren(...state.categories.map((c) =>
			el('datalist', { id: 'dl-' + c.id }, c.terms.map((t) => el('option', { value: t.name })))));
	}

	function renderFacets() {
		const box = $('facets');
		box.replaceChildren();
		if (!state.categories.length) {
			box.append(el('p', { className: 'muted small pad' }, 'No categories yet. Create one under "Manage tags".'));
			return;
		}
		for (const cat of state.categories) {
			const list = el('div', { className: 'facet-list' });
			const fill = (filter) => {
				const f = (filter || '').trim().toLowerCase();
				list.replaceChildren();
				for (const t of cat.terms) {
					if (f && !t.name.toLowerCase().includes(f) && !state.terms.has(t.id)) continue;
					list.append(el('label', { className: 'check facet-item' },
						el('input', { type: 'checkbox', checked: state.terms.has(t.id), onchange: (e) => toggleTerm(t.id, e.target.checked) }),
						el('span', { className: 'name', title: t.name }, t.photo ? faceEl(t.id, t.name, t.photo, t.full_name, 28) : null, el('span', { className: 'nm' }, t.name)), // photo (if any) + name; hover enlarges the photo
						el('span', { className: 'count' }, t.count)));
				}
				if (!list.children.length) list.append(el('div', { className: 'muted small' }, cat.terms.length ? 'No match' : 'Nothing here yet'));
			};
			fill();
			box.append(el('details', {
				className: 'facet', open: !state.collapsed.has(cat.id),
				ontoggle: (e) => { e.target.open ? state.collapsed.delete(cat.id) : state.collapsed.add(cat.id); },
			},
			el('summary', {}, cat.name),
			cat.terms.length > 8 ? el('input', { type: 'search', className: 'facet-filter', placeholder: 'Filter...', oninput: (e) => fill(e.target.value) }) : null,
			list));
		}
	}

	function toggleTerm(id, on) {
		on ? state.terms.add(id) : state.terms.delete(id);
		loadItems(true);
	}

	// ---- display settings (kept in this browser) ----------------------------------------------------
	//   pageSize  = how many files each scroll loads;  maxShown = the most tiles ever kept on the page;
	//   whole     = false: the scrollbar covers only the loaded files (default);  true: it covers the whole list.

	const SETTINGS_KEY = 'browser-app.display';

	function clampInt(v, lo, hi, dflt) {
		const n = parseInt(v, 10);
		return Number.isNaN(n) ? dflt : Math.min(hi, Math.max(lo, n));
	}

	/** pageSize 10-200; maxShown at least 2 pages (so there is always room to slide) and at most 1000. */
	function normalizeSettings(s) {
		const pageSize = clampInt(s && s.pageSize, 10, 200, 50);
		const maxShown = clampInt(s && s.maxShown, pageSize * 2, 1000, pageSize * 2);
		const cols = clampInt(s && s.cols, 0, 12, 0); // cards per row: 0 = automatic (as many as fit), else exactly this many
		return { pageSize, maxShown, whole: !!(s && s.whole), cols }; // whole: scrollbar covers the whole list (else only the loaded files)
	}

	function readSettings() {
		try { return normalizeSettings(JSON.parse(localStorage.getItem(SETTINGS_KEY))); } catch (e) { return normalizeSettings({}); }
	}

	let settings = readSettings();

	// ---------------------------------------------------------------- grid (a sliding window over the result list)
	//
	// Only a window of the results (at most `maxShown` tiles) is ever in the page. `state.items` / `state.els` are the
	// tiles in the window and `state.start` is the position of the first one in the full list. Empty spacer rows above
	// and below stand in for everything outside the window, so the scrollbar and scroll position behave as if all
	// results were on the page. The window always starts on a row boundary (start is a multiple of the column count),
	// so dropping or adding whole rows never reshuffles the tiles that stay.

	const topSp = el('div', { className: 'win-spacer', hidden: true });
	const botSp = el('div', { className: 'win-spacer', hidden: true });
	const MARGIN = 500; // px: start loading when the edge of the window is this close to the viewport
	const scrollState = { lastY: 0, ticking: false };
	const topbarHeight = () => document.querySelector('.topbar').getBoundingClientRect().height;

	function query() {
		const p = new URLSearchParams();
		if (state.q) p.set('q', state.q);
		if (state.type) p.set('type', state.type);
		if (state.untagged) p.set('untagged', '1');
		if (state.missing) p.set('missing', '1');
		if (state.nothumbs) p.set('nothumbs', '1');
		if (state.dmin !== '') p.set('dmin', state.dmin);
		if (state.dmax !== '') p.set('dmax', state.dmax);
		state.terms.forEach((id) => p.append('terms[]', id));
		p.set('sort', state.sort);
		return p;
	}

	function filtersActive() {
		return !!(state.q || state.type || state.untagged || state.missing || state.nothumbs || state.dmin !== '' || state.dmax !== '' || state.terms.size);
	}

	/** Fades the grid and shows the "Loading..." pill while a fresh result set is fetched. */
	function setGridBusy(on) {
		$('grid').classList.toggle('dim', on);
		$('grid-loader').hidden = !on;
	}

	/** Rows [offset, offset + limit) of the current results. The server caps one request at 200, so big ranges are split. */
	async function fetchRange(offset, limit) {
		const starts = [];
		for (let o = offset; o < offset + limit; o += 200) starts.push(o);
		const parts = await Promise.all(starts.map((o) => {
			const p = query();
			p.set('offset', o);
			p.set('limit', Math.min(200, offset + limit - o));
			return api(LIB + '?' + p);
		}));
		return { items: parts.flatMap((r) => r.items), total: parts[parts.length - 1].total };
	}

	function columns() {
		const t = getComputedStyle($('grid')).gridTemplateColumns;
		return Math.max(1, t && t !== 'none' ? t.trim().split(/\s+/).length : 1);
	}

	/** Reads the column count and the exact height of one row (tile + gap) from the rendered grid. */
	function measure() {
		state.cols = columns();
		state.gap = parseFloat(getComputedStyle($('grid')).rowGap) || 0;
		const els = state.els;
		if (els.length > state.cols) state.pitch = els[state.cols].getBoundingClientRect().top - els[0].getBoundingClientRect().top;
		else if (els.length) state.pitch = els[0].getBoundingClientRect().height + state.gap;
	}

	function setSpacer(sp, rows) {
		const on = rows > 0 && state.pitch > 0;
		sp.hidden = !on;
		sp.style.height = on ? (rows * state.pitch - state.gap) + 'px' : ''; // the grid's row gap supplies the last `gap`
	}

	/**
	 * "Whole list" mode: empty rows above/below stand in for every file outside the window, so the scrollbar covers the
	 * whole list and can be dragged anywhere. Otherwise (default) there are no spacers: the page is only as long as the
	 * loaded files, and captureAnchor/restoreAnchor keep what you are looking at still when rows are added or dropped.
	 */
	function applySpacers() {
		setSpacer(topSp, settings.whole ? Math.round(state.start / state.cols) : 0);
		setSpacer(botSp, settings.whole ? Math.ceil((state.total - state.start - state.items.length) / state.cols) : 0);
	}

	/** How many tiles may be in the page: the setting, but never less than what fits on screen plus some slack. */
	function windowMax() {
		const screen = state.pitch ? (Math.ceil(window.innerHeight / state.pitch) + 4) * state.cols : 0;
		return Math.max(settings.maxShown, 2 * settings.pageSize, screen);
	}

	/** Remembers a visible tile and where it is on screen, so its position can be restored after the layout changes. */
	function captureAnchor() {
		const top = topbarHeight();
		for (let i = 0; i < state.els.length; i++) {
			const r = state.els[i].getBoundingClientRect();
			if (r.bottom > top + 1) return { id: state.items[i].id, top: r.top };
		}
		return null;
	}

	function restoreAnchor(a) {
		if (!a) return;
		const i = state.items.findIndex((x) => x.id === a.id);
		if (i < 0) return;
		const d = state.els[i].getBoundingClientRect().top - a.top;
		if (Math.abs(d) > 0.5) window.scrollBy(0, d);
		scrollState.lastY = window.scrollY; // our own correction is not a user scroll
	}

	function renderStatus() {
		const n = state.total;
		let t = n ? n.toLocaleString() + (n === 1 ? ' file' : ' files') + (filtersActive() ? ' match the filters' : ' in the library') : '';
		if (n && state.items.length < n) t += '  ·  showing ' + (state.start + 1).toLocaleString() + '–' + (state.start + state.items.length).toLocaleString();
		$('status').textContent = t;
	}

	function renderEmpty() {
		$('grid').replaceChildren(filtersActive()
			? el('div', { className: 'empty' }, el('p', {}, 'Nothing matches these filters.'))
			: el('div', { className: 'empty' },
				el('p', {}, 'Your library is empty.'),
				el('p', { className: 'muted' }, 'Add files or folders from this computer. They stay where they are - the app only remembers their location.'),
				el('button', { className: 'btn primary', onclick: openPicker }, '+ Add files')));
		state.els = [];
	}

	/** (Re)creates every tile of the current window. */
	function buildDom() {
		if (!state.items.length) return renderEmpty();
		state.els = state.items.map((it) => makeTile(it));
		// Size the spacers for the NEW window before swapping the tiles in. Measuring forces a layout, and if the page
		// were momentarily shorter than the scroll position (old spacers + new tiles) the browser would clamp the scroll
		// and land on the wrong rows after a big jump. The previous row height is close enough for that moment.
		if (state.pitch > 0) {
			state.cols = columns();
			applySpacers();
		}
		$('grid').replaceChildren(topSp, ...state.els, botSp);
		measure();
		applySpacers();
	}

	/**
	 * Drops tiles from one end when the window is over its limit (whole rows at the top, so nothing reflows).
	 * A tile that is on screen is NEVER dropped, even if that leaves the window over its limit for a while: on a tall
	 * screen the visible tiles alone can exceed it, and dropping them would make the page jump and re-load forever.
	 */
	function dropExcess(side) {
		const excess = state.items.length - windowMax();
		if (excess <= 0) return;
		const cols = state.cols;
		const inView = state.view >= 0 ? state.view - state.start : -1; // never drop the video/photo open in the viewer
		const top = topbarHeight(), vh = window.innerHeight;
		if (side === 'top') {
			let above = 0; // tiles lying completely above the viewport
			while (above < state.els.length && state.els[above].getBoundingClientRect().bottom < top) above++;
			let k = Math.min(Math.ceil(excess / cols) * cols, state.items.length - cols, Math.floor(above / cols) * cols);
			if (inView >= 0) k = Math.min(k, Math.floor(inView / cols) * cols);
			if (k <= 0) return;
			for (let i = 0; i < k; i++) state.els[i].remove();
			state.els.splice(0, k);
			state.items.splice(0, k);
			state.start += k;
		} else {
			let below = 0; // tiles lying completely below the viewport
			for (let i = state.els.length - 1; i >= 0 && state.els[i].getBoundingClientRect().top > vh; i--) below++;
			let k = Math.min(excess, state.items.length - cols, below);
			if (inView >= 0) k = Math.min(k, state.items.length - 1 - inView);
			if (k <= 0) return;
			for (let i = state.els.length - k; i < state.els.length; i++) state.els[i].remove();
			state.els.splice(-k);
			state.items.splice(-k);
		}
	}

	/** (Re)starts from the first rows of the current results. Called whenever the filters, sort or settings change. */
	async function loadItems() {
		const req = ++state.req;
		state.selected.clear();
		state.lastClicked = -1;
		setGridBusy(true);
		try {
			const d = await fetchRange(0, settings.pageSize);
			if (req !== state.req) return; // a newer change superseded this request
			state.items = d.items;
			state.start = 0;
			state.total = d.total;
			buildDom();
			window.scrollTo(0, 0);
			scrollState.lastY = 0;
			renderStatus();
			updateBulk();
			afterRender();
		} catch (e) {
			if (req === state.req) toast(e.message, true);
		} finally {
			if (req === state.req) setGridBusy(false);
		}
	}

	/** Scrolled down to the end of the window: load the next rows below, drop rows from the top if over the limit. */
	async function loadMore() {
		if (state.winBusy || state.start + state.items.length >= state.total) return;
		if (columns() !== state.cols) return realign();
		state.winBusy = true;
		const req = state.req;
		try {
			const d = await fetchRange(state.start + state.items.length, settings.pageSize);
			if (req !== state.req) return;
			state.total = d.total;
			if (d.items.length) {
				const anchor = captureAnchor();
				const fresh = d.items.map((it) => makeTile(it));
				state.items.push(...d.items);
				state.els.push(...fresh);
				$('grid').insertBefore(fragmentOf(fresh), botSp);
				dropExcess('top');
				applySpacers();
				restoreAnchor(anchor);
			} else {
				applySpacers(); // the list got shorter meanwhile
			}
			renderStatus();
			updateBulk();
		} catch (e) {
			toast(e.message, true);
		} finally {
			state.winBusy = false;
			afterRender();
		}
	}

	/** Scrolled up to the start of the window: load the previous rows above, drop rows from the bottom if over the limit. */
	async function loadPrev() {
		if (state.winBusy || state.start <= 0) return;
		if (columns() !== state.cols) return realign();
		state.winBusy = true;
		const req = state.req;
		try {
			const m = Math.min(state.start, Math.ceil(settings.pageSize / state.cols) * state.cols); // whole rows
			const d = await fetchRange(state.start - m, m);
			if (req !== state.req) return;
			state.total = d.total;
			if (d.items.length !== m) { // the list changed under us: the row alignment can't be trusted, so re-window
				await rewindowRun(state.start, captureAnchor(), req);
				return;
			}
			const anchor = captureAnchor();
			const fresh = d.items.map((it) => makeTile(it));
			state.items.unshift(...d.items);
			state.els.unshift(...fresh);
			$('grid').insertBefore(fragmentOf(fresh), topSp.nextSibling);
			state.start -= m;
			applySpacers();
			restoreAnchor(anchor); // first put what you were looking at back where it was ...
			dropExcess('bottom'); // ... then drop only what is really below the screen
			applySpacers();
			renderStatus();
			updateBulk();
		} catch (e) {
			toast(e.message, true);
		} finally {
			state.winBusy = false;
			afterRender();
		}
	}

	function fragmentOf(els) {
		const f = document.createDocumentFragment();
		els.forEach((e) => f.append(e));
		return f;
	}

	/** Builds a fresh window around list position `target` (jumped there with the scrollbar, End/Home, or a resize). */
	async function rewindowRun(target, anchor, req) {
		state.cols = columns();
		const max = windowMax();
		const winStart = Math.max(0, Math.floor((target - Math.floor(max / 2)) / state.cols) * state.cols);
		const d = await fetchRange(winStart, max);
		if (req !== state.req) return;
		state.total = d.total;
		if (!d.items.length && d.total > 0) { loadItems(); return; } // fell off the end of a shrunken list
		state.items = d.items;
		state.start = winStart;
		buildDom();
		restoreAnchor(anchor);
		renderStatus();
		updateBulk();
	}

	async function rewindow(target, anchor) {
		if (state.winBusy) return;
		state.winBusy = true;
		$('grid-loader').hidden = false;
		try {
			await rewindowRun(target, anchor, state.req);
		} catch (e) {
			toast(e.message, true);
		} finally {
			state.winBusy = false;
			$('grid-loader').hidden = true;
			afterRender();
		}
	}

	/** After the window width changed: same columns = just re-measure; different columns = rows must be re-cut. */
	async function realign() {
		if (!state.els.length || state.winBusy) return;
		const anchor = captureAnchor();
		if (columns() === state.cols) {
			measure();
			applySpacers();
			restoreAnchor(anchor);
			return;
		}
		const abs = anchor ? state.start + state.items.findIndex((x) => x.id === anchor.id) : state.start;
		await rewindow(abs, anchor);
	}

	/** List position of the row in the middle of the viewport (from the scroll position alone; works over the spacers). */
	function indexAtViewportCenter() {
		const gridTop = $('grid').getBoundingClientRect().top + window.scrollY;
		const row = Math.floor((window.scrollY + window.innerHeight / 2 - gridTop) / state.pitch);
		return Math.min(state.total - 1, Math.max(0, row * state.cols));
	}

	/**
	 * Decides whether the window must move. `dir` is the scroll direction (>0 down, <0 up, 0 = no scrolling, just
	 * re-check). Only the direction being scrolled loads, which is what stops the window ping-ponging at the edges.
	 */
	function checkWindow(dir) {
		if (state.winBusy || !state.els.length || state.pitch <= 0) return;
		if (!$('viewer').hidden || !$('picker').hidden || !$('del').hidden || !$('settings').hidden || !$('folders').hidden) return;
		const vh = window.innerHeight, top = topbarHeight();
		const first = state.els[0].getBoundingClientRect();
		const last = state.els[state.els.length - 1].getBoundingClientRect();
		if (settings.whole && (last.bottom < top || first.top > vh)) { // the viewport is over a spacer: nothing to see there, re-window
			rewindow(indexAtViewportCenter());
			return;
		}
		if (dir >= 0 && state.start + state.items.length < state.total && last.bottom < vh + MARGIN) loadMore();
		else if (dir < 0 && state.start > 0 && first.top > top - MARGIN) loadPrev();
		// At the very top of a page that has earlier files, scrolling can't produce another scroll event, so don't wait for one.
		else if (!settings.whole && state.start > 0 && window.scrollY < 5) loadPrev();
	}

	/** Home key in "loaded files only" mode: cut a window at the very first files (the selection is kept) and go to the top. */
	async function jumpToStart() {
		await rewindow(0);
		window.scrollTo(0, 0);
		scrollState.lastY = 0;
	}

	/** End key in "loaded files only" mode: cut a window around the last files and go to the bottom of it. */
	async function jumpToEnd() {
		await rewindow(state.total - 1);
		window.scrollTo(0, document.documentElement.scrollHeight);
		scrollState.lastY = window.scrollY;
	}

	/** After anything that changed the tiles: re-check (e.g. a tall window can need another page to fill the screen). */
	function afterRender() {
		requestAnimationFrame(() => checkWindow(0));
	}

	function onScroll() {
		if (scrollState.ticking) return;
		scrollState.ticking = true;
		requestAnimationFrame(() => {
			scrollState.ticking = false;
			const y = window.scrollY;
			const dir = y - scrollState.lastY;
			scrollState.lastY = y;
			checkWindow(dir);
		});
	}

	const thumbUrl = (id, i) => 'thumb.php?id=' + id + '&n=' + i;

	/**
	 * Fills a video's thumb box with its preview frames. Moving the mouse across the tile scrubs through
	 * them left-to-right (like a timeline); a bar shows the position and the badge shows the time.
	 * Only the cover frame is loaded until the first hover, so a big grid stays light.
	 */
	function attachScrub(box, item, badge) {
		const times = item.thumbs;
		const n = times.length;
		const cover = Math.min(n - 1, Math.floor(n * 0.3)); // ~30% in: usually past intros/black frames
		const total = item.duration ? formatDuration(item.duration) : '';
		const restingBadge = badge ? badge.textContent : '';
		const fill = el('i');
		const frames = new Array(n);
		const frame = (i) => {
			if (!frames[i]) {
				frames[i] = el('img', { src: thumbUrl(item.id, i), alt: '', draggable: false, onerror: (e) => e.target.classList.add('broken') });
				box.insertBefore(frames[i], box.firstChild);
			}
			return frames[i];
		};
		let current = cover;
		const show = (i) => {
			if (i === current) return;
			frames[current].classList.remove('on');
			frame(i).classList.add('on');
			current = i;
		};

		box.classList.add('frames');
		frame(cover).classList.add('on');
		frames[cover].loading = 'lazy';
		box.append(el('div', { className: 'scrub' }, fill));

		box.addEventListener('mouseenter', () => { for (let i = 0; i < n; i++) frame(i); }); // preload the rest
		box.addEventListener('mousemove', (e) => {
			const r = box.getBoundingClientRect();
			const i = Math.min(n - 1, Math.floor(Math.max(0, Math.min(0.999, (e.clientX - r.left) / r.width)) * n));
			show(i);
			fill.style.width = ((i + 1) / n * 100) + '%';
			if (badge) badge.textContent = formatDuration(times[i]) + (total ? ' / ' + total : '');
		});
		box.addEventListener('mouseleave', () => {
			show(cover);
			fill.style.width = '0';
			if (badge) badge.textContent = restingBadge;
		});
	}

	const indexOf = (item) => state.items.findIndex((x) => x.id === item.id); // position in the window (it shifts as the window slides)

	function makeTile(item) {
		const box = el('div', { className: 'thumb' });
		let badge = null;
		if (item.type === 'image') {
			box.append(el('img', { src: 'thumb.php?id=' + item.id, loading: 'lazy', alt: '', draggable: false, onerror: (e) => e.target.classList.add('broken') }));
		} else {
			badge = el('span', { className: 'badge' }, item.duration ? formatDuration(item.duration) : item.ext.toUpperCase());
			if (item.thumbs.length) {
				attachScrub(box, item, badge);
			} else {
				box.append(el('div', { className: 'vph' }, el('span', { className: 'vicon' }, '▶'), el('span', {}, item.thumb_status === 2 ? 'Preview failed' : 'No preview yet')));
			}
		}
		box.append(
			el('input', { type: 'checkbox', className: 'sel', title: 'Select (shift-click for a range)', onclick: (e) => { e.stopPropagation(); onSelect(indexOf(item), e.shiftKey, e.target.checked); } }),
			badge,
			item.is_missing ? el('span', { className: 'badge warn' }, 'Missing') : null);
		const tile = el('div', {
			className: 'tile' + (item.is_missing ? ' missing' : ''), title: item.name,
			// "select mode": while anything is selected, a click on a tile ticks / unticks it (shift = range) instead of opening it;
			// double-click still opens it (its two clicks cancel out)
			onclick: (e) => {
				if (state.selected.size === 0) return openViewer(state.start + indexOf(item));
				onSelect(indexOf(item), e.shiftKey, !state.selected.has(item.id));
			},
			ondblclick: () => { if (state.selected.size > 0) openViewer(state.start + indexOf(item)); },
		},
			box,
			el('div', { className: 'cap' }, item.name));
		if (state.selected.has(item.id)) { // a tile rebuilt while selected (window slid back, or refreshed)
			tile.classList.add('selected');
			tile.querySelector('.sel').checked = true;
		}
		return tile;
	}

	/** Replaces a loaded item and its tile with fresh data from the server (keeps the selection). */
	function swapTile(fresh) {
		if (state.selected.has(fresh.id)) state.selected.set(fresh.id, fresh); // keep the selection's snapshot current
		const i = state.items.findIndex((x) => x.id === fresh.id);
		if (i < 0) return; // not in the window
		state.items[i] = fresh;
		const tile = makeTile(fresh);
		state.els[i].replaceWith(tile);
		state.els[i] = tile;
	}

	/** Re-fetches loaded videos that were waiting for previews and swaps their tiles in place. */
	async function refreshVideoTiles() {
		const waiting = state.items.filter((i) => i.type === 'video' && !i.thumbs.length && i.thumb_status !== 2 && !i.is_missing);
		if (!waiting.length) return;
		const d = await api(LIB + '?ids=' + waiting.slice(0, 200).map((i) => i.id).join(','));
		for (const fresh of d.items) {
			if (fresh.thumbs.length || fresh.thumb_status === 2) swapTile(fresh);
		}
		updateBulk();
	}

	/** For a selection run: swaps in the tiles of videos that finished (done or failed) and drops them from `waiting`. */
	async function refreshFinished(waiting) {
		const loaded = new Set(state.items.map((i) => i.id));
		for (const id of [...waiting]) if (!loaded.has(id)) waiting.delete(id); // not on screen: nothing to update
		const ids = [...waiting].slice(0, 200);
		if (!ids.length) return;
		const d = await api(LIB + '?ids=' + ids.join(','));
		for (const fresh of d.items) {
			if (fresh.thumb_status === 1 || fresh.thumb_status === 2) {
				swapTile(fresh);
				waiting.delete(fresh.id);
			}
		}
		updateBulk();
	}

	// ---------------------------------------------------------------- video preview generation

	const gen = { running: false, stop: false, stats: null, scope: null }; // scope = {total, left} while working on a selection

	function renderGen() {
		const s = gen.stats;
		const box = $('tb');
		box.hidden = !s || s.total === 0;
		if (box.hidden) return;
		const run = $('tb-run'), retry = $('tb-retry');
		const pct = gen.scope
			? Math.round(((gen.scope.total - gen.scope.left) / gen.scope.total) * 100)
			: (s.total ? Math.round((s.done / s.total) * 100) : 0);
		$('tb-bar').style.width = pct + '%';
		$('tb-progress').hidden = !s.ffmpeg;
		if (!s.ffmpeg) {
			$('tb-text').textContent = "ffmpeg was not found, so video previews can't be made. Install ffmpeg (or set FFMPEG_PATH in config.local.php) and reload.";
			run.hidden = retry.hidden = true;
			return;
		}
		$('tb-text').classList.toggle('spin', gen.running);
		$('tb-progress').classList.toggle('active', gen.running && !gen.stop);
		run.classList.toggle('spin', gen.running && !gen.stop); // (a spinner only - it must stay clickable as "Stop")
		$('tb-text').textContent = gen.scope
			? 'Selected videos: ' + (gen.scope.total - gen.scope.left) + ' of ' + gen.scope.total + ' done...'
			: s.done.toLocaleString() + ' of ' + s.total.toLocaleString() + ' videos have previews'
				+ (s.failed ? ' (' + s.failed + ' failed)' : '') + (gen.running ? ' - working...' : '');
		run.hidden = !gen.running && s.pending + s.running === 0;
		run.textContent = gen.running ? (gen.stop ? 'Stopping...' : 'Stop') : 'Generate previews (' + (s.pending + s.running).toLocaleString() + ')';
		run.disabled = gen.running && gen.stop;
		run.classList.toggle('primary', !gen.running);
		retry.hidden = gen.running || s.failed === 0;
		retry.textContent = 'Retry ' + s.failed + ' failed';
	}

	async function loadGenStats() {
		try {
			gen.stats = await api(LIB + '?thumbs=status');
		} catch (e) { gen.stats = null; }
		renderGen();
	}

	async function toggleGen() {
		if (gen.running) { gen.stop = true; renderGen(); return; }
		gen.running = true;
		gen.stop = false;
		renderGen();
		try {
			while (!gen.stop) {
				const r = await api(LIB, { action: 'thumbs_run', seconds: 3 });
				gen.stats = r;
				renderGen();
				await refreshVideoTiles();
				if (r.run.processed === 0) break; // queue drained (or another worker holds the rest)
			}
		} catch (e) {
			toast(e.message, true);
		} finally {
			gen.running = false;
			gen.stop = false;
			await loadGenStats();
			refreshVideoTiles().catch(() => {});
		}
	}

	/**
	 * Makes previews for just these videos (the selection / the open video). Videos that already have
	 * previews are skipped unless `force` (regenerate). Same Stop button and progress panel as the full run.
	 */
	async function generateFor(videos, force) {
		if (gen.running) return toast('Preview generation is already running - stop it first.', true);
		if (!gen.stats || !gen.stats.ffmpeg) return toast('ffmpeg was not found - see the "Video previews" panel in the sidebar.', true);
		gen.running = true;
		gen.stop = false;
		renderGen();
		updateBulk();
		let done = 0, failed = 0, total = 0;
		try {
			const q = await api(LIB, { action: 'thumbs_queue', ids: videos.map((v) => v.id), force: !!force });
			const ids = q.queued;
			const waiting = new Set(ids);
			total = ids.length;
			if (!total) {
				toast('Nothing to do - those videos already have previews.');
				return;
			}
			gen.scope = { total, left: total };
			gen.stats = q;
			renderGen();
			while (!gen.stop) {
				const r = await api(LIB, { action: 'thumbs_run', seconds: 3, ids });
				gen.stats = r;
				gen.scope.left = r.remaining;
				done += r.run.done;
				failed += r.run.failed;
				renderGen();
				await refreshFinished(waiting);
				if (r.remaining === 0 || r.run.processed === 0) break;
			}
			toast((gen.stop && done + failed < total ? 'Stopped: ' : '') + 'previews made for ' + done + ' of ' + total + ' video' + (total === 1 ? '' : 's')
				+ (failed ? ' (' + failed + ' failed)' : '') + '.', failed > 0);
		} catch (e) {
			toast(e.message, true);
		} finally {
			gen.running = false;
			gen.stop = false;
			gen.scope = null;
			await loadGenStats();
			updateBulk();
		}
	}

	async function retryFailedThumbs() {
		try {
			await api(LIB, { action: 'thumbs_retry' });
			state.items.forEach((it) => { if (it.type === 'video' && it.thumb_status === 2) it.thumb_status = 0; });
			await loadGenStats();
			toggleGen();
		} catch (e) { toast(e.message, true); }
	}

	// ---------------------------------------------------------------- selection + bulk

	function setSelected(i, on) { // i = position in the window
		const item = state.items[i];
		on ? state.selected.set(item.id, item) : state.selected.delete(item.id);
		const tile = state.els[i];
		if (tile) {
			tile.classList.toggle('selected', on);
			tile.querySelector('.sel').checked = on;
		}
	}

	function onSelect(index, shift, on) {
		const abs = state.start + index;
		if (shift && state.lastClicked >= 0) { // range: only the part of it that is in the window can be ticked
			const lo = Math.max(0, Math.min(state.lastClicked, abs) - state.start);
			const hi = Math.min(state.items.length - 1, Math.max(state.lastClicked, abs) - state.start);
			for (let i = lo; i <= hi; i++) setSelected(i, on);
		} else {
			setSelected(index, on);
		}
		state.lastClicked = abs;
		updateBulk();
	}
	function selectedVideos() {
		return [...state.selected.values()].filter((i) => i.type === 'video' && !i.is_missing);
	}

	function updateBulk() {
		const n = state.selected.size;
		$('bulkbar').hidden = n === 0;
		$('bulk-count').textContent = n + ' selected';
		// "Generate previews" for the selected videos: those without previews, or (if all have them) regenerate
		const vids = selectedVideos();
		const todo = vids.filter((v) => v.thumb_status !== 1);
		const btn = $('bulk-thumbs');
		btn.hidden = vids.length === 0 || !gen.stats || !gen.stats.ffmpeg;
		btn.textContent = todo.length ? 'Generate previews (' + todo.length + ')' : 'Regenerate previews (' + vids.length + ')';
		btn.disabled = gen.running;
		btn.classList.toggle('spin', gen.running);
		btn.title = gen.running ? 'Preview generation is already running' : 'Make hover previews for the selected videos only';
		renderBulkPlay();
	}

	function generateSelected() {
		const vids = selectedVideos();
		if (!vids.length) return;
		const todo = vids.filter((v) => v.thumb_status !== 1);
		if (todo.length) return generateFor(vids, false); // videos that already have previews are skipped
		if (!confirm('All ' + vids.length + ' selected video' + (vids.length === 1 ? ' has' : 's have') + ' previews already.\n\nRegenerate ' + (vids.length === 1 ? 'it' : 'them') + '?')) return;
		generateFor(vids, true);
	}

	function renderBulkCategories() {
		const sel = $('bulk-cat');
		const prev = sel.value;
		sel.replaceChildren(...state.categories.map((c) => el('option', { value: c.id }, c.name)));
		if (prev && state.categories.some((c) => String(c.id) === prev)) sel.value = prev;
		$('bulk-term').setAttribute('list', sel.value ? 'dl-' + sel.value : '');
	}

	function bulkTag(remove) {
		return withLoading($(remove ? 'bulk-unapply' : 'bulk-apply'), () => bulkTagRun(remove));
	}

	/**
	 * "beach, sunset, Actors: Jane Doe, Jane Roe" -> [{catId, name}, ...]. Names are separated by commas; a
	 * "Category:" in front of a name (only when it is a real category) switches to that category for it and
	 * for the names after it. Names without a prefix use the category picked in the bar.
	 */
	function parseTermList(text, defaultCatId) {
		const out = [];
		let catId = defaultCatId;
		for (const part of text.split(',')) {
			let name = part.trim();
			const colon = name.indexOf(':');
			if (colon > 0) {
				const cat = state.categories.find((c) => c.name.toLowerCase() === name.slice(0, colon).trim().toLowerCase());
				if (cat) { catId = cat.id; name = name.slice(colon + 1).trim(); }
			}
			if (name && !out.some((x) => x.catId === catId && x.name.toLowerCase() === name.toLowerCase())) out.push({ catId, name });
		}
		return out;
	}

	async function bulkTagRun(remove) {
		const list = parseTermList($('bulk-term').value, Number($('bulk-cat').value));
		if (!list.length || list.some((x) => !x.catId)) return toast('Choose a category and type one or more names (separated by commas) first.', true);
		const ids = [...state.selected.keys()];
		const catName = (id) => state.categories.find((c) => c.id === id).name;
		try {
			let done = list, missing = [];
			if (remove) {
				const found = list.map((x) => findTerm(x.catId, x.name));
				done = list.filter((x, i) => found[i]);
				missing = list.filter((x, i) => !found[i]).map((x) => '"' + x.name + '" (' + catName(x.catId) + ')');
				if (!done.length) return toast('Nothing to remove - not found: ' + missing.join(', ') + '.', true);
				await api(TAX, { action: 'unassign', media_ids: ids, term_ids: found.filter(Boolean).map((t) => t.id) });
			} else {
				const terms = {};
				list.forEach((x) => (terms[x.catId] = terms[x.catId] || []).push(x.name));
				await api(TAX, { action: 'assign_named', media_ids: ids, terms });
			}
			toast((remove ? 'Removed ' : 'Applied ') + done.map((x) => '"' + x.name + '"').join(', ') + (remove ? ' from ' : ' to ') + ids.length + ' file(s).'
				+ (missing.length ? ' Not found: ' + missing.join(', ') + '.' : ''), missing.length > 0);
			$('bulk-term').value = '';
			await loadTaxonomy();
			if (state.untagged || state.terms.size) loadItems(true); // results may have changed
		} catch (e) { toast(e.message, true); }
	}

	async function removeFromLibrary(ids, btn) {
		const msg = 'Remove ' + ids.length + ' file' + (ids.length === 1 ? '' : 's') + ' from the library?\n\nThe files on your computer are NOT deleted - only their entries and tags in this app.';
		if (!confirm(msg)) return false;
		return withLoading(btn, async () => {
			try {
				await api(LIB, { action: 'remove', ids });
				toast('Removed ' + ids.length + ' from the library.');
				await Promise.all([loadItems(true), loadTaxonomy(), loadGenStats()]);
				return true;
			} catch (e) { toast(e.message, true); return false; }
		});
	}

	// ---------------------------------------------------------------- permanent deletion

	const del = { onConfirm: null };

	/** Asks for confirmation (typing DELETE when more than one file), then runs onConfirm() with a spinner on the button. */
	function openDeleteDialog(items, onConfirm) {
		const n = items.length;
		const total = items.reduce((sum, i) => sum + (i.size || 0), 0);
		$('del-title').textContent = 'Delete ' + (n === 1 ? 'this file' : n.toLocaleString() + ' files') + ' permanently?';
		$('del-body').replaceChildren(
			el('p', {}, el('strong', {}, n === 1 ? 'This file' : 'These ' + n.toLocaleString() + ' files'), ' will be deleted from your computer (' + formatSize(total) + ').'),
			el('ul', { className: 'del-list' },
				items.slice(0, 6).map((i) => el('li', {}, i.name)),
				n > 6 ? el('li', { className: 'muted' }, '...and ' + (n - 6).toLocaleString() + ' more') : null),
			el('p', { className: 'del-warn' }, 'This cannot be undone. Files are NOT moved to the Recycle Bin. Their tags and previews are removed too.'));
		const needPhrase = n > 1;
		$('del-phrase-wrap').hidden = !needPhrase;
		$('del-phrase').value = '';
		$('del-go').disabled = needPhrase;
		del.onConfirm = onConfirm;
		$('del').hidden = false;
		(needPhrase ? $('del-phrase') : $('del-cancel')).focus(); // Enter must never confirm by accident
	}

	function closeDeleteDialog() {
		$('del').hidden = true;
		del.onConfirm = null;
	}

	function deleteFromDisk(items) {
		if (!items.length) return;
		openDeleteDialog(items, async () => {
			const viewerOpen = !$('viewer').hidden;
			if (viewerOpen) stopStage(); // stop streaming the video so it isn't held open while we delete it
			let r;
			try {
				r = await api(LIB, { action: 'delete_files', ids: items.map((i) => i.id), confirm: 'DELETE' });
			} catch (e) {
				toast(e.message, true);
				if (viewerOpen && !$('viewer').hidden) showViewer();
				return;
			}
			const f = r.failed.length;
			let msg = r.deleted || r.already_gone
				? 'Deleted ' + r.deleted + ' file' + (r.deleted === 1 ? '' : 's') + ' from disk' + (r.bytes ? ' (' + formatSize(r.bytes) + ' freed)' : '')
				: 'Nothing was deleted';
			if (r.already_gone) msg += '; ' + r.already_gone + ' were already gone (entries cleaned up)';
			if (f) msg += '. ' + f + ' could NOT be deleted and ' + (f === 1 ? 'was' : 'were') + ' kept: ' + r.failed.slice(0, 2).map((x) => x.name).join(', ') + (f > 2 ? ', ...' : '') + ' - ' + r.failed[0].reason;
			toast(msg, f > 0);
			if (viewerOpen && !$('viewer').hidden) (r.deleted || r.already_gone) ? closeViewer() : showViewer();
			// the dialog closes now; the grid refreshes behind it (not awaited: nothing more to confirm)
			Promise.all([loadItems(true), loadTaxonomy(), loadGenStats()]).catch(() => {});
		});
	}

	// ---------------------------------------------------------------- viewer

	function openViewer(i) {
		state.view = i;
		state.dirty = false;
		$('viewer').hidden = false;
		document.body.classList.add('noscroll');
		showViewer();
	}

	/** The item open in the viewer. state.view is its position in the FULL list, so it survives the window sliding. */
	const viewItem = () => state.items[state.view - state.start];

	function closeViewer() {
		stopStage();
		$('viewer').hidden = true;
		document.body.classList.remove('noscroll');
		const tile = state.els[state.view - state.start];
		state.view = -1;
		if (state.dirty && (state.untagged || state.terms.size)) loadItems(true);
		else if (tile && tile.isConnected) { // the viewer may have moved the window: bring the last-viewed tile back into view
			const r = tile.getBoundingClientRect();
			if (r.top < topbarHeight() || r.bottom > window.innerHeight) {
				tile.scrollIntoView({ block: 'center' });
				scrollState.lastY = window.scrollY;
			}
		}
		state.dirty = false;
		afterRender();
	}

	function stopStage() {
		const stage = $('v-stage');
		const v = stage.querySelector('video');
		if (v) { v.pause(); v.removeAttribute('src'); v.load(); }
		stage.classList.remove('loading');
		stage.replaceChildren();
	}

	async function stepViewer(delta) {
		const next = state.view + delta;
		if (next < 0 || next >= state.total) return;
		if (next >= state.start + state.items.length) await loadMore(); // walked off the end of the window: slide it
		else if (next < state.start) await loadPrev();
		if (!state.items[next - state.start]) return; // (a load was already in flight: press again)
		state.view = next;
		showViewer();
	}
	function showViewer() {
		const item = viewItem();
		if (!item) return closeViewer();
		stopStage();
		const missing = () => el('div', { className: 'v-missing' }, 'This file is no longer at its saved location.');
		let media;
		if (item.is_missing) media = missing();
		else if (item.type === 'image') media = el('img', { src: 'file.php?id=' + item.id, alt: item.name, onerror: (e) => e.target.replaceWith(missing()) });
		else media = el('video', { src: 'file.php?id=' + item.id, controls: true, autoplay: true, playsInline: true });
		$('v-stage').append(media);
		if (!item.is_missing) { // spinner until the image / first video frame has arrived (big videos take a moment)
			const stage = $('v-stage');
			const loaded = () => stage.classList.remove('loading');
			stage.classList.add('loading');
			media.addEventListener(item.type === 'image' ? 'load' : 'loadeddata', loaded);
			media.addEventListener('error', loaded);
		}
		$('v-prev').disabled = state.view === 0;
		$('v-next').disabled = state.view >= state.total - 1;
		renderPanel(item.id);
	}

	async function renderPanel(id) {
		const panel = $('v-panel');
		panel.replaceChildren(el('p', { className: 'muted spin' }, 'Loading...'));
		let d;
		try {
			d = (await api(LIB + '?id=' + id)).item;
		} catch (e) {
			panel.replaceChildren(el('p', { className: 'error-text' }, e.message));
			return;
		}
		if (!viewItem() || viewItem().id !== id) return; // user already moved on

		const meta = [d.type, d.duration ? formatDuration(d.duration) : null, d.width ? d.width + ' x ' + d.height : null, formatSize(d.size), d.file_mtime ? 'modified ' + d.file_mtime.slice(0, 10) : null, 'added ' + d.added_at.slice(0, 10)]
			.filter(Boolean).join('  ·  ');

		const refresh = async () => {
			state.dirty = true;
			await Promise.all([renderPanel(id), loadTaxonomy()]);
		};
		const sections = state.categories.map((cat) => {
			const mine = d.terms.filter((t) => t.category_id === cat.id);
			const add = async (input) => {
				const names = input.value.split(',').map((s) => s.trim()).filter(Boolean);
				if (!names.length) return;
				input.value = '';
				state.focusCat = cat.id;
				try {
					await api(TAX, { action: 'assign_named', media_ids: [id], terms: { [cat.id]: names } });
					await refresh();
				} catch (e) { toast(e.message, true); }
			};
			return el('div', { className: 'v-cat' },
				el('h4', {}, cat.name),
				el('div', { className: 'chips' }, mine.map((t) => { const info = termById.get(t.id) || {}; return el('span', { className: 'chip' + (info.photo ? ' has-face' : '') },
					el('a', { className: 'chip-link', href: 'actors.php?cat=' + cat.id + '&open=' + t.id, title: 'Open the profile' },
						info.photo ? faceEl(t.id, t.name, info.photo, info.full_name, 26) : null, t.name),
					el('button', {
						type: 'button', title: 'Remove', onclick: async () => {
							try { await api(TAX, { action: 'unassign', media_ids: [id], term_ids: [t.id] }); await refresh(); } catch (e) { toast(e.message, true); }
						},
					}, '×')); })),
				el('input', {
					type: 'text', list: 'dl-' + cat.id, placeholder: 'Add ' + cat.name.toLowerCase() + ' (Enter)', dataset: { cat: cat.id }, maxLength: 100,
					onkeydown: (e) => { if (e.key === 'Enter') { e.preventDefault(); add(e.target); } },
					onchange: (e) => add(e.target),
				}));
		});

		// videos: make (or redo) this one video's hover previews
		const listItem = viewItem();
		const hasFrames = !!(listItem && listItem.thumbs && listItem.thumbs.length);
		const plSelect = el('select', { className: 'v-pl', title: 'Playlist' });
		fillPlaylistSelect(plSelect);
		const plRow = el('div', { className: 'v-playlist' }, plSelect,
			el('button', { className: 'btn small', type: 'button', onclick: (e) => withLoading(e.currentTarget, () => addToPlaylist(plSelect.value, [id])) }, 'Add to playlist'));
		const videoButton = d.type === 'video' && !d.is_missing && gen.stats && gen.stats.ffmpeg
			? el('button', {
				className: 'btn small', type: 'button', disabled: gen.running,
				onclick: async (e) => {
					e.currentTarget.disabled = true;
					e.currentTarget.classList.add('loading'); // spinner (the panel re-renders when it finishes)
					await generateFor([listItem], hasFrames);
					if (viewItem() && viewItem().id === id) renderPanel(id);
				},
			}, hasFrames ? 'Regenerate previews' : 'Generate previews')
			: null;

		panel.replaceChildren(
			el('h3', { className: 'v-name' }, d.name),
			el('div', { className: 'muted small' }, meta),
			el('div', { className: 'v-path' },
				el('code', {}, d.path),
				el('button', { className: 'btn small', type: 'button', onclick: () => copyText(d.path) }, 'Copy path')),
			...sections,
			...(state.categories.length ? [] : [el('p', { className: 'muted small' }, 'Create categories under "Manage tags" to start labelling.')]),
			plRow,
			el('div', { className: 'v-actions' },
				...(d.type === 'video' && !d.is_missing ? players.map((pl) => el('button', {
					className: 'btn small primary', type: 'button', title: 'Play this video now (nothing is saved to your playlists)',
					onclick: (e) => playNow([id], pl, e.currentTarget),
				}, 'Play in ' + pl.name)) : []),
				videoButton,
				el('button', { className: 'btn small danger', type: 'button', title: 'Takes it out of the library only - the file stays on your computer', onclick: async (e) => { if (await removeFromLibrary([id], e.currentTarget)) closeViewer(); } }, 'Remove from library'),
				el('button', { className: 'btn small danger', type: 'button', title: 'PERMANENTLY deletes the file from your computer', onclick: () => deleteFromDisk([listItem]) }, 'Delete from disk')));

		if (state.focusCat !== null) {
			const box = panel.querySelector('input[data-cat="' + state.focusCat + '"]');
			if (box) box.focus();
			state.focusCat = null;
		}
	}

	async function copyText(text) {
		try { await navigator.clipboard.writeText(text); toast('Path copied.'); }
		catch (e) { toast('Could not copy - select the path and copy it manually.', true); }
	}

	// ---------------------------------------------------------------- add-files picker

	const picker = { path: '', parent: null, entries: [], truncated: false, selected: new Map() }; // selected: path -> isDir
	const LAST_KEY = 'browser-app.lastPath';

	function openPicker() {
		picker.selected.clear();
		$('picker').hidden = false;
		document.body.classList.add('noscroll');
		$('p-terms').replaceChildren(...state.categories.map((c) => el('label', {},
			el('span', {}, c.name),
			el('input', { type: 'text', list: 'dl-' + c.id, placeholder: 'comma separated', dataset: { cat: c.id }, maxLength: 500 }))));
		if (!state.categories.length) $('p-terms').append(el('p', { className: 'muted small' }, 'No categories yet.'));
		let last = '';
		try { last = localStorage.getItem(LAST_KEY) || ''; } catch (e) { /* storage unavailable */ }
		pickerGo(last, true);
		updatePickerSummary();
	}

	function closePicker() {
		$('picker').hidden = true;
		document.body.classList.remove('noscroll');
	}

	let pickerReq = 0;

	async function pickerGo(path, quiet) {
		const mine = ++pickerReq;
		$('p-list').classList.add('busy'); // dims the list + spinner while the folder is read (big / slow folders)
		try {
			const d = await api('api/fs.php?path=' + encodeURIComponent(path));
			Object.assign(picker, { path: d.path, parent: d.parent, entries: d.entries, truncated: d.truncated });
			try { localStorage.setItem(LAST_KEY, d.path); } catch (e) { /* ignore */ }
			renderPicker();
		} catch (e) {
			if (path !== '') { // fall back to the top level, e.g. when the remembered folder is gone
				if (!quiet) toast(e.message, true);
				return await pickerGo('', true);
			}
			toast(e.message, true);
		} finally {
			if (mine === pickerReq) $('p-list').classList.remove('busy'); // not if a newer navigation is still loading
		}
	}

	function renderPicker() {
		const list = $('p-list');
		list.replaceChildren();
		$('p-path').value = picker.path;
		$('p-up').disabled = picker.path === '';
		const atTop = picker.path === '';
		const files = picker.entries.filter((e) => !e.is_dir);

		if (files.length) {
			const all = files.every((f) => picker.selected.has(f.path));
			list.append(el('label', { className: 'prow head check' },
				el('input', {
					type: 'checkbox', checked: all, onchange: (e) => {
						for (const f of files) e.target.checked ? picker.selected.set(f.path, false) : picker.selected.delete(f.path);
						renderPicker(); updatePickerSummary();
					},
				}),
				el('span', {}, 'Select all ' + files.length + ' file' + (files.length === 1 ? '' : 's') + ' in this folder')));
		}

		for (const e of picker.entries) {
			const cb = atTop ? null : el('input', {
				type: 'checkbox', checked: picker.selected.has(e.path),
				onchange: (ev) => { ev.target.checked ? picker.selected.set(e.path, e.is_dir) : picker.selected.delete(e.path); updatePickerSummary(); },
			});
			const icon = e.is_dir ? '📁' : e.type === 'video' ? '🎬' : '🖼️';
			const name = e.is_dir
				? el('a', { href: '#', className: 'pname', onclick: (ev) => { ev.preventDefault(); pickerGo(e.path); } }, e.name)
				: el('span', { className: 'pname' }, e.name);
			list.append(el('div', { className: 'prow ' + (e.is_dir ? 'dir' : 'file') }, cb, el('span', { className: 'picon' }, icon), name, e.is_dir ? null : el('span', { className: 'psize' }, formatSize(e.size))));
		}
		if (!picker.entries.length) list.append(el('p', { className: 'muted pad' }, atTop ? 'No drives found.' : 'No sub-folders or supported media files here.'));
		if (picker.truncated) list.append(el('p', { className: 'muted pad' }, 'Only the first entries are shown - type a more specific path to narrow down.'));
		list.scrollTop = 0;
	}

	function updatePickerSummary() {
		let files = 0, folders = 0;
		picker.selected.forEach((isDir) => (isDir ? folders++ : files++));
		const parts = [];
		if (files) parts.push(files + ' file' + (files === 1 ? '' : 's'));
		if (folders) parts.push(folders + ' folder' + (folders === 1 ? '' : 's'));
		$('p-summary').textContent = parts.length ? parts.join(' + ') + ' selected' : 'Nothing selected';
		$('p-add').disabled = picker.selected.size === 0;
	}

	async function pickerAdd() {
		const files = [], folders = [];
		picker.selected.forEach((isDir, path) => (isDir ? folders : files).push(path));
		const terms = {};
		$('p-terms').querySelectorAll('input[data-cat]').forEach((inp) => {
			const names = inp.value.split(',').map((s) => s.trim()).filter(Boolean);
			if (names.length) terms[inp.dataset.cat] = names;
		});
		const btn = $('p-add');
		btn.disabled = true;
		btn.classList.add('loading');
		btn.textContent = 'Adding...';
		try {
			const r = await api(LIB, { action: 'add', files, folders, recursive: $('p-recursive').checked, terms });
			const bits = [r.added + ' added'];
			if (r.existing) bits.push(r.existing + ' already in the library');
			if (r.skipped.length) bits.push(r.skipped.length + ' skipped (' + r.skipped[0].reason.toLowerCase() + ')');
			if (r.truncated) bits.push('stopped at the per-request limit - add the folder again to continue');
			const ok = r.added + r.existing > 0;
			toast(bits.join(', ') + '.', !ok || r.truncated);
			if (ok) {
				closePicker();
				await Promise.all([loadItems(true), loadTaxonomy(), loadGenStats()]);
			}
		} catch (e) {
			toast(e.message, true);
		} finally {
			btn.classList.remove('loading');
			btn.textContent = 'Add to library';
			updatePickerSummary();
		}
	}

	// ---------------------------------------------------------------- display settings dialog

	function updateSettingsNote() {
		const p = clampInt($('set-page').value, 10, 200, 50);
		const m = parseInt($('set-max').value, 10);
		$('set-note').textContent = m < 2 * p
			? 'The most shown at once must be at least 2 x the per-scroll number, so it will be set to ' + (2 * p) + '.'
			: 'Each scroll adds ' + p + ' files. Once more than ' + m + ' would be on the page, the ones furthest from where you are are dropped (they reload when you scroll back). A tall screen always keeps enough files to fill it.';
	}

	function openSettings() {
		$('set-page').value = settings.pageSize;
		$('set-max').value = settings.maxShown;
		$('set-whole').checked = settings.whole;
		updateSettingsNote();
		$('settings').hidden = false;
		$('set-page').focus();
	}

	function closeSettings() {
		$('settings').hidden = true;
		afterRender();
	}

	function saveSettings() {
		settings = normalizeSettings({ pageSize: $('set-page').value, maxShown: $('set-max').value, whole: $('set-whole').checked, cols: settings.cols });
		try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings)); } catch (e) { /* not persisted, still applied */ }
		closeSettings();
		toast('Display settings saved: ' + settings.pageSize + ' per scroll, up to ' + settings.maxShown + ' shown.');
		loadItems();
	}

	// ---------------------------------------------------------------- cards per row

	/** Auto = as many as fit (CSS auto-fill). Otherwise exactly N equal columns; only the layout changes, not how many files load. */
	function applyColumns() {
		$('grid').style.gridTemplateColumns = settings.cols ? 'repeat(' + settings.cols + ', minmax(0, 1fr))' : '';
		$('cols').value = String(settings.cols);
	}

	function setColumns(n) {
		settings = normalizeSettings({ ...settings, cols: n });
		try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings)); } catch (e) { /* not persisted, still applied */ }
		applyColumns();
		loadPlaylists();
		realign(); // the rows are re-cut for the new column count around what you are looking at
	}

	async function applyUrlFilters() {
		const p = new URLSearchParams(location.search);
		for (const v of (p.get('term') || p.get('terms') || '').split(',')) {
			if (Number(v) > 0) state.terms.add(Number(v));
		}
		const list = Number(p.get('list'));
		if (list > 0) {
			try {
				const r = await api('api/actors.php?list_members=' + list);
				r.term_ids.forEach((id) => state.terms.add(id));
				if (!r.term_ids.length) toast('That talent list is empty.', true);
			} catch (e) { toast(e.message, true); }
		}
	}

	// ---------------------------------------------------------------- video length filter

	function applyCustomDuration() {
		const lo = $('f-dmin').value, hi = $('f-dmax').value;
		state.dmin = lo === '' ? '' : String(Math.max(0, Number(lo)) * 60); // the boxes are minutes, the server wants seconds
		state.dmax = hi === '' ? '' : String(Math.max(0, Number(hi)) * 60);
		loadItems(true);
	}

	// ---------------------------------------------------------------- folders / rescan

	const fmtWhen = (s) => (s ? s.replace('T', ' ').slice(0, 16) : 'never');

	async function openFolders() {
		$('folders').hidden = false;
		await renderFolders();
	}

	async function renderFolders() {
		let d;
		try { d = await api('api/folders.php'); } catch (e) { toast(e.message, true); return; }
		$('fo-list').replaceChildren(...(d.folders.length ? d.folders.map((f) => el('div', { className: 'fo-row' },
			el('div', { className: 'fo-main' },
				el('div', { className: 'fo-path', title: f.path }, f.path),
				el('div', { className: 'muted small' },
					f.files.toLocaleString() + ' files in the library  ·  scanned ' + fmtWhen(f.last_scan)
					+ (f.last_found ? '  ·  found ' + f.last_found + ' new last time' : '') + (f.exists ? '' : '  ·  FOLDER NOT FOUND'))),
			el('label', { className: 'check small', title: 'Look inside sub-folders too' },
				el('input', { type: 'checkbox', checked: f.recursive, onchange: async (e) => { try { await api('api/folders.php', { action: 'recursive', id: f.id, recursive: e.target.checked }); } catch (err) { toast(err.message, true); } } }), 'sub-folders'),
			el('button', { className: 'btn small', type: 'button', onclick: (e) => withLoading(e.currentTarget, () => rescanOne(f)) }, 'Rescan'),
			el('button', { className: 'btn small danger', type: 'button', title: 'Stop remembering this folder (its files stay in the library)', onclick: async () => {
				try { await api('api/folders.php', { action: 'forget', id: f.id }); renderFolders(); } catch (err) { toast(err.message, true); }
			} }, 'Forget'))) : [el('p', { className: 'muted small' }, 'No folders remembered yet. Add files with "+ Add files", or type a path below, or pick one of the suggestions.')]));
		$('fo-sugg-list').replaceChildren(...(d.suggestions.length ? d.suggestions.map((s) => el('div', { className: 'fo-row' },
			el('div', { className: 'fo-main' }, el('div', { className: 'fo-path', title: s.path }, s.path), el('div', { className: 'muted small' }, s.files.toLocaleString() + ' files in the library')),
			el('button', { className: 'btn small', type: 'button', onclick: (e) => withLoading(e.currentTarget, async () => { await registerFolder(s.path, false); }) }, 'Remember'))) : [el('p', { className: 'muted small' }, 'Nothing to suggest.')]));
	}

	async function registerFolder(path, recursive) {
		try {
			await api('api/folders.php', { action: 'register', path, recursive });
			toast('Folder remembered.');
			await renderFolders();
		} catch (e) { toast(e.message, true); }
	}

	function rememberFolder(e) {
		e.preventDefault();
		const path = $('fo-path').value.trim();
		if (!path) return;
		registerFolder(path, $('fo-rec').checked).then(() => { $('fo-path').value = ''; });
	}

	function scanMessage(r) {
		if (r.error) return r.path + ': ' + r.error;
		return (r.found ? 'Found ' + r.found + ' new file' + (r.found === 1 ? '' : 's') : 'No new files') + ' in ' + r.path + (r.truncated ? ' (stopped at the limit - rescan to continue)' : '') + '.';
	}

	async function rescanOne(f) {
		try {
			const r = await api('api/folders.php', { action: 'scan', id: f.id });
			toast(scanMessage(r), !!r.error);
			await Promise.all([renderFolders(), loadItems(true), loadGenStats()]);
		} catch (e) { toast(e.message, true); }
	}

	async function rescanAll() {
		try {
			const r = await api('api/folders.php', { action: 'scan_all' });
			const bad = r.results.filter((x) => x.error).length;
			toast((r.found ? 'Found ' + r.found + ' new file' + (r.found === 1 ? '' : 's') : 'No new files') + ' in ' + r.results.length + ' folder' + (r.results.length === 1 ? '' : 's') + (bad ? ' (' + bad + ' not found)' : '') + '.', bad > 0);
			await Promise.all([renderFolders(), loadItems(true), loadGenStats()]);
		} catch (e) { toast(e.message, true); }
	}

	// ---------------------------------------------------------------- playlists (add files to one)

	let playlists = [];
	let players = []; // VLC / PotPlayer found on this PC

	async function loadPlaylists() {
		try {
			const d = await api('api/playlists.php');
			playlists = d.playlists;
			players = d.players;
		} catch (e) { playlists = []; players = []; }
		fillPlaylistSelect($('bulk-pl'));
		renderBulkPlay();
	}

	/** "Play now": the given files, in this order, straight into a player - via one temp playlist that the next "Play now" overwrites. */
	async function playNow(ids, player, btn) {
		return withLoading(btn, async () => {
			try {
				const r = await api('api/playlists.php', { action: 'play_files', media_ids: ids, player: player.key });
				toast('Opening ' + r.count + ' video' + (r.count === 1 ? '' : 's') + ' in ' + r.player + '...');
			} catch (e) { toast(e.message, true); }
		});
	}

	function renderBulkPlay() {
		const vids = selectedVideos();
		$('bulk-play').replaceChildren(...(vids.length ? players.map((pl) => el('button', {
			className: 'btn small primary', type: 'button', title: 'Play the selected videos now, in the order you ticked them. Nothing is saved to your playlists.',
			onclick: (e) => playNow(vids.map((v) => v.id), pl, e.currentTarget),
		}, 'Play in ' + pl.name + (vids.length > 1 ? ' (' + vids.length + ')' : ''))) : []));
	}

	function fillPlaylistSelect(sel) {
		const prev = sel.value;
		sel.replaceChildren(...playlists.map((p) => el('option', { value: p.id }, p.name + ' (' + p.items + ')')), el('option', { value: 'new' }, '+ New playlist...'));
		if (prev && [...sel.options].some((o) => o.value === prev)) sel.value = prev;
	}

	/** Adds files to the chosen playlist (or asks for a name and creates one). `value` is a playlist id or 'new'. */
	async function addToPlaylist(value, ids) {
		if (!ids.length) return toast('Select some files first.', true);
		try {
			let id = Number(value), name = '';
			if (value === 'new' || !id) {
				name = (prompt('Name for the new playlist:') || '').trim();
				if (!name) return;
				id = (await api('api/playlists.php', { action: 'create', name })).id;
			}
			const r = await api('api/playlists.php', { action: 'add', id, media_ids: ids });
			const pl = (await api('api/playlists.php')).playlists;
			playlists = pl;
			fillPlaylistSelect($('bulk-pl'));
			$('bulk-pl').value = String(id);
			const target = pl.find((p) => p.id === id);
			toast('Added ' + r.added + ' to "' + (target ? target.name : name) + '"' + (r.added < ids.length ? ' (' + (ids.length - r.added) + ' were already in it)' : '') + '.');
		} catch (e) { toast(e.message, true); }
	}

	// ---------------------------------------------------------------- wiring

	function init() {
		$('q').addEventListener('input', debounce((e) => { state.q = e.target.value.trim(); loadItems(true); }, 250));
		$('type').addEventListener('change', (e) => { state.type = e.target.value; loadItems(true); });
		$('sort').addEventListener('change', (e) => { state.sort = e.target.value; loadItems(true); });
		$('f-untagged').addEventListener('change', (e) => { state.untagged = e.target.checked; loadItems(true); });
		$('f-missing').addEventListener('change', (e) => { state.missing = e.target.checked; loadItems(true); });
		$('f-dur').addEventListener('change', (e) => {
			const v = e.target.value;
			$('f-dur-custom').hidden = v !== 'custom';
			if (v === 'custom') { applyCustomDuration(); return; }
			const [lo, hi] = v ? v.split('-') : ['', ''];
			state.dmin = lo; state.dmax = hi;
			loadItems(true);
		});
		$('f-dmin').addEventListener('input', debounce(applyCustomDuration, 400));
		$('f-dmax').addEventListener('input', debounce(applyCustomDuration, 400));
		$('btn-folders').addEventListener('click', openFolders);
		$('fo-close').addEventListener('click', () => { $('folders').hidden = true; });
		$('fo-form').addEventListener('submit', rememberFolder);
		$('fo-scan-all').addEventListener('click', () => withLoading($('fo-scan-all'), rescanAll));
		$('bulk-pl-add').addEventListener('click', () => withLoading($('bulk-pl-add'), () => addToPlaylist($('bulk-pl').value, [...state.selected.keys()])));
		$('f-nothumbs').addEventListener('change', (e) => { state.nothumbs = e.target.checked; loadItems(true); });
		$('btn-clear').addEventListener('click', () => {
			Object.assign(state, { q: '', type: '', untagged: false, missing: false, nothumbs: false, dmin: '', dmax: '' });
			$('f-dur').value = ''; $('f-dur-custom').hidden = true; $('f-dmin').value = ''; $('f-dmax').value = '';
			state.terms.clear();
			$('q').value = ''; $('type').value = ''; $('f-untagged').checked = false; $('f-missing').checked = false; $('f-nothumbs').checked = false;
			renderFacets();
			loadItems(true);
		});
		$('btn-check').addEventListener('click', () => withLoading($('btn-check'), async () => {
			try {
				const r = await api(LIB, { action: 'check' });
				toast('Checked ' + r.checked + ' files: ' + r.missing + ' missing' + (r.newly_missing ? ' (' + r.newly_missing + ' newly)' : '') + (r.restored ? ', ' + r.restored + ' found again' : '') + '.');
				loadItems(true);
			} catch (e) { toast(e.message, true); }
		}));

		window.addEventListener('scroll', onScroll, { passive: true });
		window.addEventListener('resize', debounce(realign, 150));

		$('cols').addEventListener('change', (e) => setColumns(parseInt(e.target.value, 10)));
		$('btn-settings').addEventListener('click', openSettings);
		$('set-close').addEventListener('click', closeSettings);
		$('set-cancel').addEventListener('click', closeSettings);
		$('set-page').addEventListener('input', updateSettingsNote);
		$('set-max').addEventListener('input', updateSettingsNote);
		$('set-reset').addEventListener('click', () => { $('set-page').value = 50; $('set-max').value = 100; $('set-whole').checked = false; updateSettingsNote(); });
		$('set-form').addEventListener('submit', (e) => { e.preventDefault(); saveSettings(); });

		$('bulk-all').addEventListener('click', () => { state.items.forEach((_, i) => setSelected(i, true)); updateBulk(); });
		$('bulk-none').addEventListener('click', () => { // clears EVERY selected file, also those whose tiles are off the page
			state.items.forEach((_, i) => setSelected(i, false));
			state.selected.clear();
			state.lastClicked = -1;
			updateBulk();
		});
		$('bulk-cat').addEventListener('change', (e) => $('bulk-term').setAttribute('list', 'dl-' + e.target.value));
		$('bulk-apply').addEventListener('click', () => bulkTag(false));
		$('bulk-unapply').addEventListener('click', () => bulkTag(true));
		$('bulk-term').addEventListener('keydown', (e) => { if (e.key === 'Enter') bulkTag(false); });
		$('bulk-thumbs').addEventListener('click', generateSelected);
		$('bulk-delete').addEventListener('click', () => removeFromLibrary([...state.selected.keys()], $('bulk-delete')));
		$('bulk-purge').addEventListener('click', () => deleteFromDisk([...state.selected.values()]));

		$('del-cancel').addEventListener('click', closeDeleteDialog);
		$('del-phrase').addEventListener('input', (e) => { $('del-go').disabled = e.target.value.trim() !== 'DELETE'; });
		$('del-phrase').addEventListener('keydown', (e) => { if (e.key === 'Enter' && !$('del-go').disabled) $('del-go').click(); });
		$('del-go').addEventListener('click', () => withLoading($('del-go'), async () => {
			if (!del.onConfirm) return;
			$('del-cancel').disabled = true;
			try { await del.onConfirm(); } finally { $('del-cancel').disabled = false; closeDeleteDialog(); }
		}));

		$('v-close').addEventListener('click', closeViewer);
		$('v-prev').addEventListener('click', () => stepViewer(-1));
		$('v-next').addEventListener('click', () => stepViewer(1));

		$('btn-add').addEventListener('click', openPicker);
		$('p-close').addEventListener('click', closePicker);
		$('p-cancel').addEventListener('click', closePicker);
		$('p-up').addEventListener('click', () => pickerGo(picker.parent || ''));
		$('p-roots').addEventListener('click', () => pickerGo(''));
		$('p-form').addEventListener('submit', (e) => { e.preventDefault(); pickerGo($('p-path').value.trim()); });
		$('p-add').addEventListener('click', pickerAdd);

		document.addEventListener('keydown', (e) => {
			const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName);
			if (!$('folders').hidden) { // the folders dialog is modal too
				if (e.key === 'Escape') $('folders').hidden = true;
				return;
			}
			if (!$('settings').hidden) { // the settings dialog is modal: only it reacts
				if (e.key === 'Escape') closeSettings();
				return;
			}
			if (!$('del').hidden) { // the deletion dialog is on top of everything: only it reacts
				if (e.key === 'Escape' && !$('del-cancel').disabled) closeDeleteDialog();
				return;
			}
			if (!settings.whole && !typing && (e.key === 'Home' || e.key === 'End') && $('viewer').hidden && $('picker').hidden && !e.ctrlKey && !e.altKey && !e.metaKey) {
				e.preventDefault(); // scrollbar only covers the loaded files, so Home / End must load the real first / last files
				if (e.key === 'Home') jumpToStart();
				else jumpToEnd();
				return;
			}
			if (e.key === 'Escape') {
				if (!$('picker').hidden) closePicker();
				else if (!$('viewer').hidden) closeViewer();
				else if (!typing && state.selected.size) $('bulk-none').click(); // Esc leaves select mode
			} else if (!$('viewer').hidden && !typing && e.target.tagName !== 'VIDEO') {
				if (e.key === 'ArrowLeft') stepViewer(-1);
				else if (e.key === 'ArrowRight') stepViewer(1);
			}
		});

		applyColumns();
		loadPlaylists();
		$('tb-run').addEventListener('click', toggleGen);
		$('tb-retry').addEventListener('click', retryFailedThumbs);
		loadGenStats();
		// links from the Actors page: index.php?term=ID  or  ?terms=1,2  or  ?list=ID (everyone on a talent list)
		applyUrlFilters().finally(() => {
			loadTaxonomy().catch((e) => toast(e.message, true));
			loadItems(true);
		});
	}

	init();
})();
