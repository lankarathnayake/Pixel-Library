/* Playlists page: manage playlists, reorder, export .m3u8, open in VLC / PotPlayer. */
(function () {
	'use strict';

	const { api, el, toast, formatDuration, withLoading } = BA;
	const $ = (id) => document.getElementById(id);
	const API = 'api/playlists.php';

	const state = { lists: [], players: [], current: null, drag: null };

	const total = (s) => (s >= 3600 ? Math.floor(s / 3600) + ' h ' + Math.round((s % 3600) / 60) + ' min' : formatDuration(s));
	const linkFor = (id) => new URL('playlist.php?id=' + id, location.href).href;

	// ---------------------------------------------------------------- list of playlists

	async function loadLists() {
		const d = await api(API);
		state.lists = d.playlists;
		state.players = d.players;
		renderLists();
	}

	function renderLists() {
		$('pl-list').replaceChildren(...(state.lists.length ? state.lists.map((p) => el('a', {
			href: '#', className: 'pl-item' + (state.current && state.current.id === p.id ? ' active' : ''),
			onclick: (e) => { e.preventDefault(); openList(p.id); },
		}, el('span', { className: 'pl-name' }, p.name), el('span', { className: 'muted small' }, p.items + (p.items === 1 ? ' file' : ' files') + (p.duration ? '  ·  ' + total(p.duration) : '')))) : [el('p', { className: 'muted small' }, 'No playlists yet.')]));
	}

	async function createList(e) {
		e.preventDefault();
		const name = $('pl-new-name').value.trim();
		if (!name) return;
		try {
			const r = await api(API, { action: 'create', name });
			$('pl-new-name').value = '';
			await loadLists();
			openList(r.id);
		} catch (err) { toast(err.message, true); }
	}

	// ---------------------------------------------------------------- one playlist

	async function openList(id) {
		try {
			state.current = (await api(API + '?id=' + id)).playlist;
			history.replaceState(null, '', '?id=' + id);
			renderLists();
			renderCurrent();
		} catch (e) { toast(e.message, true); }
	}

	function renderCurrent() {
		const p = state.current;
		$('pl-empty').hidden = !!p;
		$('pl-view').hidden = !p;
		if (!p) return;
		$('pl-title').textContent = p.name;
		$('pl-meta').textContent = p.items.length + (p.items.length === 1 ? ' file' : ' files') + (p.duration ? '  ·  ' + total(p.duration) : '')
			+ (p.missing ? '  ·  ' + p.missing + ' missing (left out of the exported file)' : '');
		$('pl-download').href = 'playlist.php?id=' + p.id + '&download=1';
		$('pl-path').textContent = '';
		$('pl-players').replaceChildren(...state.players.map((pl) => el('button', {
			className: 'btn primary', type: 'button', title: 'Save the playlist and open it in ' + pl.name,
			onclick: (e) => withLoading(e.currentTarget, () => play(pl)),
		}, 'Play in ' + pl.name)));
		if (!state.players.length) $('pl-players').replaceChildren(el('span', { className: 'muted small' }, 'No VLC / PotPlayer found - download the .m3u8 and open it with your player. '));
		renderItems();
	}

	function thumb(i) {
		if (i.type === 'image') return el('img', { className: 'dthumb', src: 'thumb.php?id=' + i.id, alt: '', loading: 'lazy' });
		if (i.cover !== null) return el('img', { className: 'dthumb', src: 'thumb.php?id=' + i.id + '&n=' + i.cover, alt: '', loading: 'lazy' });
		return el('div', { className: 'dthumb ph' }, '▶');
	}

	function renderItems() {
		const p = state.current;
		const box = $('pl-items');
		box.replaceChildren(...p.items.map((i, idx) => {
			const row = el('div', { className: 'pl-row' + (i.is_missing ? ' missing' : ''), draggable: true, dataset: { id: i.id } },
				el('span', { className: 'pl-num' }, idx + 1),
				el('span', { className: 'pl-grip', title: 'Drag to reorder' }, '⋮⋮'),
				thumb(i),
				el('div', { className: 'dinfo' },
					el('div', { className: 'dname' }, i.name, i.is_missing ? el('span', { className: 'badge-keep warn' }, 'missing') : null),
					el('div', { className: 'dpath', title: i.path }, i.path)),
				el('span', { className: 'muted' }, i.duration ? formatDuration(i.duration) : ''),
				el('button', { className: 'btn small', type: 'button', title: 'Move up', disabled: idx === 0, onclick: () => move(idx, -1) }, '↑'),
				el('button', { className: 'btn small', type: 'button', title: 'Move down', disabled: idx === p.items.length - 1, onclick: () => move(idx, 1) }, '↓'),
				el('button', { className: 'btn small danger', type: 'button', title: 'Remove from this playlist (the file stays in the library)', onclick: () => removeItem(i) }, '×'));
			row.addEventListener('dragstart', (e) => { state.drag = i.id; row.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
			row.addEventListener('dragend', () => { state.drag = null; row.classList.remove('dragging'); });
			row.addEventListener('dragover', (e) => { if (state.drag !== null) { e.preventDefault(); row.classList.add('over'); } });
			row.addEventListener('dragleave', () => row.classList.remove('over'));
			row.addEventListener('drop', (e) => {
				e.preventDefault();
				row.classList.remove('over');
				const from = p.items.findIndex((x) => x.id === state.drag);
				if (from >= 0 && from !== idx) reorder(from, idx);
			});
			return row;
		}));
		if (!p.items.length) box.append(el('div', { className: 'empty' }, el('p', {}, 'This playlist is empty. In the library, tick some files and use "Add to playlist".')));
	}

	async function saveOrder(items) {
		const p = state.current;
		p.items = items;
		renderItems();
		try { await api(API, { action: 'reorder', id: p.id, ordered: items.map((i) => i.id) }); } catch (e) { toast(e.message, true); openList(p.id); }
	}

	const move = (idx, delta) => { const a = state.current.items.slice(); const [x] = a.splice(idx, 1); a.splice(idx + delta, 0, x); saveOrder(a); };
	const reorder = (from, to) => { const a = state.current.items.slice(); const [x] = a.splice(from, 1); a.splice(to, 0, x); saveOrder(a); };

	async function removeItem(i) {
		try {
			await api(API, { action: 'remove', id: state.current.id, media_ids: [i.id] });
			await Promise.all([openList(state.current.id), loadLists()]);
		} catch (e) { toast(e.message, true); }
	}

	// ---------------------------------------------------------------- toolbar

	async function play(pl) {
		try {
			const r = await api(API, { action: 'play', id: state.current.id, player: pl.key });
			$('pl-path').textContent = 'Saved as ' + r.file;
			toast('Opening in ' + r.player + '...');
		} catch (e) { toast(e.message, true); }
	}

	async function saveFile() {
		try {
			const r = await api(API, { action: 'save_file', id: state.current.id });
			$('pl-path').textContent = 'Saved as ' + r.file;
			toast('Saved: ' + r.file);
		} catch (e) { toast(e.message, true); }
	}

	async function copyLink() {
		const url = linkFor(state.current.id);
		try { await navigator.clipboard.writeText(url); toast('Link copied - in VLC: Media > Open Network Stream; in PotPlayer: Open URL.'); }
		catch (e) { prompt('Copy this link:', url); }
	}

	async function rename() {
		const p = state.current;
		const name = prompt('Rename "' + p.name + '" to:', p.name);
		if (name === null || !name.trim() || name.trim() === p.name) return;
		try { await api(API, { action: 'rename', id: p.id, name }); await Promise.all([loadLists(), openList(p.id)]); } catch (e) { toast(e.message, true); }
	}

	async function del() {
		const p = state.current;
		if (!confirm('Delete the playlist "' + p.name + '"?\n\nThe files stay in your library and on your computer.')) return;
		try {
			await api(API, { action: 'delete', id: p.id });
			state.current = null;
			history.replaceState(null, '', 'playlists.php');
			await loadLists();
			renderCurrent();
		} catch (e) { toast(e.message, true); }
	}

	async function sortList(v) {
		if (!v) return;
		const [by, dir] = v.split(':');
		$('pl-sort').value = '';
		try { await api(API, { action: 'sort', id: state.current.id, by, dir }); await openList(state.current.id); } catch (e) { toast(e.message, true); }
	}

	// ---------------------------------------------------------------- wiring

	async function init() {
		$('pl-new').addEventListener('submit', createList);
		$('pl-rename').addEventListener('click', rename);
		$('pl-delete').addEventListener('click', del);
		$('pl-save').addEventListener('click', () => withLoading($('pl-save'), saveFile));
		$('pl-copy').addEventListener('click', copyLink);
		$('pl-sort').addEventListener('change', (e) => sortList(e.target.value));
		try {
			await loadLists();
			const id = Number(new URLSearchParams(location.search).get('id'));
			if (id) await openList(id);
			else renderCurrent();
		} catch (e) { toast(e.message, true); }
	}

	init();
})();
