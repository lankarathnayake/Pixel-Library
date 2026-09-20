/* Shared helpers for the library and manage pages. Exposes window.BA. */
(function () {
	'use strict';

	const csrf = document.querySelector('meta[name="csrf"]').content;

	// ---- global "something is loading" bar --------------------------------------------------------
	// Shown only if requests are still running after a short delay, so quick ones never flash it.
	let inflight = 0;
	let busyTimer = null;
	function busyStart() {
		if (++inflight === 1) busyTimer = setTimeout(() => document.body.classList.add('busy'), 250);
	}
	function busyEnd() {
		if (inflight > 0 && --inflight === 0) {
			clearTimeout(busyTimer);
			document.body.classList.remove('busy');
		}
	}

	/** JSON request to an api/ endpoint. Pass `body` to POST, omit for GET. Throws Error(message) on failure. */
	async function api(url, body) {
		const opts = body === undefined ? {} : {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
			body: JSON.stringify(body),
		};
		busyStart();
		try {
			let res, data;
			try {
				res = await fetch(url, opts);
				data = await res.json();
			} catch (e) {
				throw new Error(res ? 'Unexpected server response (' + res.status + ').' : 'Could not reach the server.');
			}
			if (!data.success) throw new Error(data.message || 'Request failed.');
			return data;
		} finally {
			busyEnd();
		}
	}

	/**
	 * Runs fn() while showing a spinner on the button. Ignores clicks while it is already running
	 * (so a double click can't fire the action twice). `btn` may be null.
	 */
	async function withLoading(btn, fn) {
		if (!btn) return fn();
		if (btn.classList.contains('loading')) return undefined;
		btn.classList.add('loading');
		btn.setAttribute('aria-busy', 'true');
		try {
			return await fn();
		} finally {
			btn.classList.remove('loading');
			btn.removeAttribute('aria-busy');
		}
	}

	/** el('div', {className:'x', onclick: fn, dataset:{a:1}}, child, 'text', ...) */
	function el(tag, props, ...kids) {
		const node = document.createElement(tag);
		for (const [k, v] of Object.entries(props || {})) {
			if (v === undefined || v === null || v === false) continue;
			if (k === 'dataset') Object.assign(node.dataset, v);
			else if (k === 'style' && typeof v === 'object') Object.assign(node.style, v);
			else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
			else if (k in node && k !== 'list') node[k] = v;
			else node.setAttribute(k, v === true ? '' : v);
		}
		for (const kid of kids.flat()) {
			if (kid === null || kid === undefined || kid === false) continue;
			node.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
		}
		return node;
	}

	let toastTimer;
	function toast(message, isError) {
		const t = document.getElementById('toast');
		t.textContent = message;
		t.className = 'toast' + (isError ? ' error' : '');
		t.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => { t.hidden = true; }, isError ? 7000 : 3500);
	}

	function formatSize(bytes) {
		if (bytes < 1024) return bytes + ' B';
		const units = ['KB', 'MB', 'GB', 'TB'];
		let v = bytes / 1024, i = 0;
		while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
		return (v >= 100 ? v.toFixed(0) : v.toFixed(1)) + ' ' + units[i];
	}

	/** 75 -> "1:15", 3725 -> "1:02:05" */
	function formatDuration(seconds) {
		const s = Math.max(0, Math.round(seconds));
		const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
		const pad = (n) => String(n).padStart(2, '0');
		return h ? h + ':' + pad(m) + ':' + pad(sec) : m + ':' + pad(sec);
	}

	function debounce(fn, ms) {
		let t;
		return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
	}

	// ---- actor faces --------------------------------------------------------------------------------
	// Where an actor is shown by name, an actor WITH a photo gets a small round photo beside the name; hovering the photo shows
	// a card with the big photo and the name (and full name). Anything with data-hover-src gets the card.

	const photoUrl = (termId, photo) => 'photo.php?id=' + termId + '&v=' + encodeURIComponent(photo); // the name changes on every upload

	function faceEl(termId, name, photo, fullName, size) {
		return el('img', {
			className: 'face', src: photoUrl(termId, photo), alt: '', loading: 'lazy', draggable: false,
			style: size ? { width: size + 'px', height: size + 'px' } : null,
			dataset: { hoverSrc: photoUrl(termId, photo), hoverName: name, hoverSub: fullName || '' },
		});
	}

	let card = null, cardTimer = null;

	function hideCard() {
		clearTimeout(cardTimer);
		if (card) card.hidden = true;
	}

	function showCard(target) {
		if (!card) {
			card = el('div', { className: 'hovercard', hidden: true, 'aria-hidden': 'true' },
				el('img', { alt: '' }), el('div', { className: 'hc-name' }), el('div', { className: 'hc-sub' }));
			document.body.append(card);
		}
		const d = target.dataset;
		card.querySelector('img').src = d.hoverSrc;
		card.querySelector('.hc-name').textContent = d.hoverName;
		const sub = card.querySelector('.hc-sub');
		sub.textContent = d.hoverSub;
		sub.hidden = !d.hoverSub;
		card.hidden = false;
		const r = target.getBoundingClientRect(), w = card.offsetWidth, h = card.offsetHeight;
		const left = Math.min(window.innerWidth - w - 8, Math.max(8, r.left + r.width / 2 - w / 2));
		const below = r.bottom + 8 + h <= window.innerHeight;
		card.style.left = left + 'px';
		card.style.top = Math.max(8, below ? r.bottom + 8 : r.top - h - 8) + 'px';
	}

	document.addEventListener('mouseover', (e) => {
		const t = e.target.closest && e.target.closest('[data-hover-src]');
		if (!t) return;
		clearTimeout(cardTimer);
		cardTimer = setTimeout(() => showCard(t), 120);
	});
	document.addEventListener('mouseout', (e) => {
		if (e.target.closest && e.target.closest('[data-hover-src]')) hideCard();
	});
	window.addEventListener('scroll', hideCard, true);

	document.body.append(el('div', { id: 'busybar', 'aria-hidden': 'true' }, el('i')));

	window.BA = { api, el, toast, formatSize, formatDuration, debounce, withLoading, faceEl, photoUrl };
})();
