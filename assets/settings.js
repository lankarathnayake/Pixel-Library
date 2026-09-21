/* Settings page: video player master controls (kept in this browser) and which file formats the app registers. */
(function () {
	'use strict';

	const { api, el, toast, withLoading } = BA;
	const $ = (id) => document.getElementById(id);
	const API = 'api/formats.php';

	const state = { formats: [], suggestions: [], confirming: null };

	const filesLabel = (n) => (n ? n.toLocaleString() + (n === 1 ? ' file in your library' : ' files in your library') : 'none in your library');

	async function load() {
		const d = await api(API);
		state.formats = d.formats;
		state.suggestions = d.suggestions;
		if (state.confirming && !state.formats.some((f) => f.ext === state.confirming)) state.confirming = null;
		render();
	}

	// ---------------------------------------------------------------- the lists

	function formatRow(f) {
		const asking = state.confirming === f.ext;
		const actions = asking
			? [
				el('button', { className: 'btn small', type: 'button', title: 'Stop adding new ' + f.ext + ' files; the ones already in the library stay', onclick: (e) => withLoading(e.currentTarget, () => remove(f, false)) }, 'Remove format, keep the ' + f.files.toLocaleString() + ' file' + (f.files === 1 ? '' : 's')),
				el('button', { className: 'btn small danger', type: 'button', title: 'They leave the LIBRARY only; nothing is deleted from your computer', onclick: (e) => withLoading(e.currentTarget, () => remove(f, true)) }, 'Remove format and take the file' + (f.files === 1 ? '' : 's') + ' out of the library'),
				el('button', { className: 'btn small', type: 'button', onclick: () => { state.confirming = null; render(); } }, 'Cancel'),
			]
			: [el('button', {
				className: 'btn small', type: 'button', title: 'Stop adding .' + f.ext + ' files',
				onclick: (e) => (f.files > 0 ? ((state.confirming = f.ext), render()) : withLoading(e.currentTarget, () => remove(f, false))),
			}, 'Remove')];
		return el('div', { className: 'fmt-row' + (asking ? ' asking' : ''), dataset: { ext: f.ext } },
			el('code', { className: 'fmt-ext' }, '.' + f.ext),
			el('span', { className: 'fmt-mime muted' }, f.mime),
			el('span', { className: 'fmt-n muted small' }, filesLabel(f.files)),
			el('span', { className: 'fmt-actions' }, ...actions),
			f.ext === 'ts' ? el('div', { className: 'fmt-note muted small' }, 'Only real MPEG transport streams are added - TypeScript files that share this extension are skipped.') : null);
	}

	function render() {
		for (const [type, box, count] of [['video', $('s-videos'), $('s-vcount')], ['image', $('s-images'), $('s-icount')]]) {
			const list = state.formats.filter((f) => f.type === type);
			box.replaceChildren(...(list.length ? list.map(formatRow) : [el('p', { className: 'muted small' }, 'None - no ' + type + ' files will be added.')]));
			count.textContent = list.length ? '(' + list.length + ')' : '';
		}
		renderSuggestions();
	}

	function renderSuggestions() {
		$('s-suggest-wrap').hidden = state.suggestions.length === 0;
		$('s-suggest').replaceChildren(...state.suggestions.map((s) => el('button', {
			className: 'chip', type: 'button', title: 'Add .' + s.ext + ' (' + s.type + ', ' + s.mime + ')',
			onclick: (e) => withLoading(e.currentTarget, () => add(s.ext, s.type, s.mime)),
		}, '+ .' + s.ext + (s.type === 'image' ? '  (image)' : ''))));
	}

	// ---------------------------------------------------------------- changes

	async function add(ext, type, mime) {
		try {
			const r = await api(API, { action: 'add', ext, type, mime });
			toast('Added .' + r.format.ext + ' (' + r.format.type + '). Files with that extension are found when you add files, or use "Rescan all my folders now" for folders you added earlier.');
			await load();
			return true;
		} catch (e) { toast(e.message, true); return false; }
	}

	async function remove(f, removeFiles) {
		try {
			const r = await api(API, { action: 'remove', ext: f.ext, remove_files: removeFiles });
			state.confirming = null;
			toast('Removed .' + f.ext + (removeFiles ? ' and took ' + r.removed_files.toLocaleString() + ' file' + (r.removed_files === 1 ? '' : 's') + ' out of the library' : (f.files ? '; the ' + f.files.toLocaleString() + ' file' + (f.files === 1 ? '' : 's') + ' already in the library ' + (f.files === 1 ? 'stays' : 'stay') : '')) + '.');
			await load();
		} catch (e) { toast(e.message, true); }
	}

	async function submitForm(e) {
		e.preventDefault();
		const ext = $('s-ext').value.trim();
		if (!ext) return;
		if (await add(ext, $('s-type').value, $('s-mime').value.trim())) {
			$('s-ext').value = '';
			$('s-mime').value = '';
			$('s-mime').placeholder = $('s-type').value + '/...';
			$('s-ext').focus();
		}
	}

	// typing a known extension fills in the kind and shows the usual MIME type
	function hintFromExt() {
		const ext = $('s-ext').value.trim().replace(/^\./, '').toLowerCase();
		const known = state.suggestions.find((s) => s.ext === ext);
		if (known) $('s-type').value = known.type;
		const type = $('s-type').value;
		$('s-mime').placeholder = known && known.type === type ? known.mime : (type === 'video' ? 'video/mp4' : 'image/jpeg');
	}

	async function rescan(btn) {
		return withLoading(btn, async () => {
			try {
				const r = await api('api/folders.php', { action: 'scan_all' });
				if (!r.results.length) return toast('You have not added any folders yet, so there is nothing to rescan. Use "+ Add files" in the library.', true);
				toast(r.found ? 'Found ' + r.found.toLocaleString() + ' new file' + (r.found === 1 ? '' : 's') + ' in ' + r.results.length + ' folder' + (r.results.length === 1 ? '' : 's') + '.' : 'No new files in ' + r.results.length + ' folder' + (r.results.length === 1 ? '' : 's') + '.');
				await load();
			} catch (e) { toast(e.message, true); }
		});
	}

	async function resetList(btn) {
		if (!confirm('Reset the list to the built-in formats?\n\nFormats you added are taken out of the list, and built-in ones you removed come back. Files already in your library stay.')) return;
		return withLoading(btn, async () => {
			try {
				await api(API, { action: 'reset' });
				state.confirming = null;
				toast('The list is back to the built-in formats.');
				await load();
			} catch (e) { toast(e.message, true); }
		});
	}

	// ---------------------------------------------------------------- video player (kept in this browser)

	function showPlayer() {
		const s = BA.player.get();
		$('p-volume').value = Math.round(s.volume * 100);
		$('p-volume-out').textContent = Math.round(s.volume * 100) + '%';
		$('p-speed').value = String(s.speed);
		if ($('p-speed').value !== String(s.speed)) { // a speed set inside the player that is not in the list
			$('p-speed').append(el('option', { value: String(s.speed) }, s.speed + 'x'));
			$('p-speed').value = String(s.speed);
		}
		$('p-muted').checked = s.muted;
		$('p-autoplay').checked = s.autoplay;
		$('p-autonext').checked = s.autoNext;
		$('p-remember').checked = s.remember;
	}

	function initPlayer() {
		showPlayer();
		const saved = () => toast('Player settings saved. They apply the next time a video opens.');
		$('p-volume').addEventListener('input', (e) => { $('p-volume-out').textContent = e.target.value + '%'; });
		$('p-volume').addEventListener('change', (e) => { BA.player.save({ volume: Number(e.target.value) / 100 }); saved(); });
		$('p-speed').addEventListener('change', (e) => { BA.player.save({ speed: Number(e.target.value) }); saved(); });
		$('p-muted').addEventListener('change', (e) => { BA.player.save({ muted: e.target.checked }); saved(); });
		$('p-autoplay').addEventListener('change', (e) => { BA.player.save({ autoplay: e.target.checked }); saved(); });
		$('p-autonext').addEventListener('change', (e) => { BA.player.save({ autoNext: e.target.checked }); saved(); });
		$('p-remember').addEventListener('change', (e) => { BA.player.save({ remember: e.target.checked }); saved(); });
		$('p-reset').addEventListener('click', () => { BA.player.reset(); showPlayer(); toast('The player is back to its defaults.'); });
		window.addEventListener('focus', showPlayer); // values changed inside the player (another tab) show up when you come back
	}

	async function init() {
		initPlayer();
		$('s-form').addEventListener('submit', submitForm);
		$('s-ext').addEventListener('input', hintFromExt);
		$('s-type').addEventListener('change', hintFromExt);
		$('s-rescan').addEventListener('click', (e) => rescan(e.currentTarget));
		$('s-reset').addEventListener('click', (e) => resetList(e.currentTarget));
		try { await load(); } catch (e) { toast(e.message, true); }
	}

	init();
})();
