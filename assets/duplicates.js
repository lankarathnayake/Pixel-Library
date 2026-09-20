/* Find duplicates: scan, review groups, keep one file per group, get rid of the others. */
(function () {
	'use strict';

	const { api, el, toast, formatSize, formatDuration, withLoading } = BA;
	const $ = (id) => document.getElementById(id);
	const API = 'api/duplicates.php';
	const PAGE = 20;

	const state = { mode: 'identical', groups: [], total: 0, wasted: 0, status: null, scanning: false, stop: false, req: 0 };

	// ---------------------------------------------------------------- loading

	async function loadStatus() {
		state.status = await api(API + '?status=1');
		renderSummary();
	}

	async function loadGroups(reset) {
		const req = ++state.req;
		try {
			const d = await api(API + '?mode=' + state.mode + '&offset=' + (reset ? 0 : state.groups.length) + '&limit=' + PAGE);
			if (req !== state.req) return;
			state.groups = reset ? d.groups : state.groups.concat(d.groups);
			state.total = d.total;
			state.wasted = d.wasted;
			renderGroups();
			renderSummary();
		} catch (e) { if (req === state.req) toast(e.message, true); }
	}

	function renderSummary() {
		const s = state.status;
		const bits = [];
		if (state.mode === 'identical') {
			if (s) {
				bits.push(s.candidates.toLocaleString() + ' file' + (s.candidates === 1 ? '' : 's') + ' share a size with another file');
				if (s.unhashed) bits.push(s.unhashed.toLocaleString() + ' still to check - press "Scan"');
			}
			bits.push(state.total.toLocaleString() + ' group' + (state.total === 1 ? '' : 's') + ' of identical files' + (state.wasted ? ' - ' + formatSize(state.wasted) + ' could be freed' : ''));
		} else {
			bits.push(state.total.toLocaleString() + ' group' + (state.total === 1 ? '' : 's') + ' of videos with the same length and resolution (only a hint)');
		}
		$('d-summary').textContent = bits.join('  ·  ');
		$('d-scan').hidden = state.mode !== 'identical' || state.scanning;
		$('d-stop').hidden = !state.scanning;
		$('d-more').hidden = state.groups.length >= state.total;
		$('d-auto').hidden = state.mode !== 'identical' || !state.groups.length;
	}

	// ---------------------------------------------------------------- scan

	async function scan() {
		state.scanning = true; state.stop = false;
		$('d-progress').hidden = false;
		renderSummary();
		const start = state.status ? state.status.unhashed : 0;
		try {
			while (!state.stop) {
				const r = await api(API, { action: 'scan', seconds: 3 });
				state.status = r;
				$('d-bar').style.width = (start ? Math.round(((start - r.unhashed) / start) * 100) : 100) + '%';
				renderSummary();
				if (r.scan.hashed === 0 || r.unhashed === 0) break;
			}
			toast(state.stop ? 'Scan stopped.' : 'Scan finished.');
		} catch (e) { toast(e.message, true); }
		state.scanning = false;
		$('d-progress').hidden = true;
		await loadStatus();
		await loadGroups(true);
	}

	// ---------------------------------------------------------------- groups

	function thumbEl(f) {
		if (f.type === 'image') return el('img', { className: 'dthumb', src: 'thumb.php?id=' + f.id, alt: '', loading: 'lazy' });
		if (f.cover !== null) return el('img', { className: 'dthumb', src: 'thumb.php?id=' + f.id + '&n=' + f.cover, alt: '', loading: 'lazy' });
		return el('div', { className: 'dthumb ph' }, '▶');
	}

	function fileRow(g, f, radioName) {
		const meta = [formatSize(f.size), f.duration ? formatDuration(f.duration) : null, f.width ? f.width + ' x ' + f.height : null,
			f.file_mtime ? 'modified ' + f.file_mtime.slice(0, 10) : null, f.tags ? f.tags + ' tag' + (f.tags === 1 ? '' : 's') : null,
			f.playlists ? 'in ' + f.playlists + ' playlist' + (f.playlists === 1 ? '' : 's') : null].filter(Boolean).join('  ·  ');
		return el('label', { className: 'dfile' + (f.id === g.keep ? ' keep' : '') },
			el('input', { type: 'radio', name: radioName, value: f.id, checked: f.id === g.keep, onchange: () => markKeep(g) }),
			thumbEl(f),
			el('div', { className: 'dinfo' },
				el('div', { className: 'dname' }, f.name, f.id === g.keep ? el('span', { className: 'badge-keep' }, 'suggested to keep') : null),
				el('div', { className: 'dpath', title: f.path }, f.folder),
				el('div', { className: 'muted small' }, meta)));
	}

	function markKeep(g) {
		g.node.querySelectorAll('.dfile').forEach((n) => n.classList.toggle('keep', n.querySelector('input').checked));
	}

	const chosen = (g) => Number(g.node.querySelector('input[type=radio]:checked').value);
	const others = (g) => g.files.map((f) => f.id).filter((id) => id !== chosen(g));

	function groupEl(g, i) {
		const name = 'keep-' + i + '-' + g.key;
		g.node = el('section', { className: 'dgroup' },
			el('div', { className: 'dhead' }, g.files.length + ' files' + (g.wasted ? '  ·  ' + formatSize(g.wasted) + ' wasted' : ''),
				el('span', { className: 'muted small' }, ' Pick the one to KEEP:')),
			...g.files.map((f) => fileRow(g, f, name)),
			el('div', { className: 'dactions' },
				el('button', { className: 'btn small', type: 'button', title: 'Take the others out of the library. Their files stay on your computer.', onclick: (e) => withLoading(e.currentTarget, () => resolve(g, 'remove')) }, 'Remove the others from the library'),
				el('button', { className: 'btn small danger', type: 'button', title: 'PERMANENTLY deletes the other files from your computer', onclick: (e) => withLoading(e.currentTarget, () => resolve(g, 'delete')) }, 'Delete the others from disk')));
		return g.node;
	}

	function renderGroups() {
		const box = $('d-groups');
		box.replaceChildren(...state.groups.map(groupEl));
		if (!state.groups.length) {
			box.append(el('div', { className: 'empty' }, el('p', {}, state.mode === 'identical'
				? (state.status && state.status.unhashed ? 'Press "Scan for identical files" to check them.' : 'No identical files found.')
				: 'No videos with the same length and resolution.')));
		}
	}

	async function resolve(g, action, silent) {
		const keep = chosen(g), list = others(g);
		if (!silent) {
			const names = g.files.filter((f) => list.includes(f.id)).map((f) => '  - ' + f.name).join('\n');
			const msg = action === 'delete'
				? 'PERMANENTLY delete ' + list.length + ' file' + (list.length === 1 ? '' : 's') + ' from your computer?\n\n' + names + '\n\nThey do NOT go to the Recycle Bin. The file you keep stays.'
				: 'Remove ' + list.length + ' file' + (list.length === 1 ? '' : 's') + ' from the library (the files stay on your computer)?\n\n' + names;
			if (!confirm(msg)) return false;
		}
		try {
			const r = await api(API, { action: 'resolve', keep, others: list, mode: action, confirm: action === 'delete' ? 'DELETE' : undefined, merge_tags: $('d-merge').checked });
			const failed = action === 'delete' ? r.result.failed.length : 0;
			if (!silent) toast(action === 'delete'
				? 'Deleted ' + r.result.deleted + ' file' + (r.result.deleted === 1 ? '' : 's') + (r.result.bytes ? ' (' + formatSize(r.result.bytes) + ' freed)' : '') + (failed ? '. ' + failed + ' could NOT be deleted: ' + r.result.failed[0].reason : '.')
				: 'Removed ' + r.handled + ' from the library.', failed > 0);
			if (!failed || r.handled > failed) {
				state.groups = state.groups.filter((x) => x !== g);
				state.total = Math.max(0, state.total - 1);
				g.node.remove();
				loadStatus().catch(() => {});
				renderSummary();
			}
			return true;
		} catch (e) { toast(e.message, true); return false; }
	}

	async function autoResolve() {
		const groups = state.groups.slice();
		const files = groups.reduce((n, g) => n + g.files.length - 1, 0);
		const typed = prompt('This keeps the SUGGESTED file of each of the ' + groups.length + ' groups shown and PERMANENTLY DELETES the other ' + files + ' files from your computer (not the Recycle Bin).\n\nType DELETE to do it:');
		if (typed === null) return;
		if (typed.trim() !== 'DELETE') return toast('Nothing was deleted (you have to type DELETE).', true);
		let done = 0, failed = 0;
		for (const g of groups) {
			g.node.querySelector('input[type=radio][value="' + g.keep + '"]').checked = true; // the suggestion, whatever was clicked
			(await resolve(g, 'delete', true)) ? done++ : failed++;
		}
		toast('Cleaned ' + done + ' group' + (done === 1 ? '' : 's') + (failed ? ', ' + failed + ' had problems' : '') + '.', failed > 0);
		await loadStatus();
		await loadGroups(true);
	}

	// ---------------------------------------------------------------- wiring

	async function init() {
		$('d-mode').addEventListener('change', (e) => { state.mode = e.target.value; state.groups = []; renderGroups(); loadGroups(true); });
		$('d-scan').addEventListener('click', scan);
		$('d-stop').addEventListener('click', () => { state.stop = true; });
		$('d-more').addEventListener('click', () => withLoading($('d-more'), () => loadGroups(false)));
		$('d-auto').addEventListener('click', autoResolve);
		try {
			await loadStatus();
			await loadGroups(true);
		} catch (e) { toast(e.message, true); }
	}

	init();
})();
