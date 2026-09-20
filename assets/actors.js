/* Actors page: search / sort / create / delete actors, star ratings, profiles, custom fields and talent lists. */
(function () {
	'use strict';

	const { api, el, toast, debounce, withLoading } = BA;
	const $ = (id) => document.getElementById(id);
	const API = 'api/actors.php';
	const PAGE = 60;

	const state = {
		cat: 0, q: '', sort: 'name_asc', min: 0, list: 0,
		rows: [], total: 0, hasMore: false, req: 0,
		selected: new Set(),
		meta: { categories: [], fields: [], lists: [] },
		editing: null, // the profile being edited
		mgr: null,     // 'fields' | 'lists'
	};

	// ---------------------------------------------------------------- helpers

	/** 5 stars. `onPick(n)` is called with 1-5 (the same star again clears: 0). */
	function stars(rating, onPick, cls) {
		const box = el('span', { className: 'stars ' + (cls || ''), role: 'group', 'aria-label': rating ? rating + ' of 5 stars' : 'not rated' });
		for (let i = 1; i <= 5; i++) {
			box.append(el('button', {
				type: 'button', className: 'star' + (rating >= i ? ' on' : ''), title: i + (i === 1 ? ' star' : ' stars'),
				onclick: (e) => { e.stopPropagation(); onPick(rating === i ? 0 : i); },
			}, '★'));
		}
		return box;
	}

	const csrf = document.querySelector('meta[name="csrf"]').content;
	const photoUrl = (id, photo) => 'photo.php?id=' + id + '&v=' + encodeURIComponent(photo); // the name changes on every upload

	/** The actor's photo (round), or a coloured circle with their initial. */
	function avatarEl(id, name, photo, big) {
		const size = big ? ' big' : '';
		if (photo) return el('img', { className: 'avatar' + size, src: photoUrl(id, photo), alt: name, loading: 'lazy', dataset: big ? {} : { hoverSrc: photoUrl(id, photo), hoverName: name, hoverSub: '' } });
		let h = 0;
		for (const ch of name) h = (h * 31 + ch.charCodeAt(0)) % 360;
		return el('span', { className: 'avatar ph' + size, style: { background: 'hsl(' + h + ', 40%, 36%)' } }, (name.trim()[0] || '?').toUpperCase());
	}

	async function postForm(fd) {
		const res = await fetch('api/actor_photo.php', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd });
		let d;
		try { d = await res.json(); } catch (e) { throw new Error('Unexpected server response (' + res.status + ').'); }
		if (!d.success) throw new Error(d.message || 'Upload failed.');
		return d;
	}

	function query(offset) {
		const p = new URLSearchParams({ cat: state.cat, sort: state.sort, offset, limit: PAGE });
		if (state.q) p.set('q', state.q);
		if (state.min > 0) p.set('min_rating', state.min);
		if (state.min < 0) p.set('unrated', '1');
		if (state.list) p.set('list', state.list);
		return p;
	}

	// ---------------------------------------------------------------- list

	async function loadMeta() {
		const m = await api(API + '?meta=1');
		state.meta = m;
		const params = new URLSearchParams(location.search);
		state.cat = Number(params.get('cat')) || m.default_category || 0;
		fillSelects();
	}

	function fillSelects() {
		const cats = $('a-cat');
		cats.replaceChildren(...state.meta.categories.map((c) => el('option', { value: c.id }, c.name)));
		cats.value = String(state.cat);
		const listOpts = (first) => [el('option', { value: 0 }, first), ...state.meta.lists.map((l) => el('option', { value: l.id }, l.name + ' (' + l.members + ')'))];
		const cur = $('a-list').value;
		$('a-list').replaceChildren(...listOpts('Any list'));
		$('a-list').value = state.meta.lists.some((l) => String(l.id) === cur) ? cur : '0';
		state.list = Number($('a-list').value) || 0;
		const bulk = $('a-bulk-list').value;
		$('a-bulk-list').replaceChildren(...(state.meta.lists.length ? state.meta.lists.map((l) => el('option', { value: l.id }, l.name)) : [el('option', { value: 0 }, '(no lists yet)')]));
		if (bulk) $('a-bulk-list').value = bulk;
	}

	async function load(reset, keepSelection) {
		const req = ++state.req;
		if (reset && !keepSelection) { state.selected.clear(); }
		try {
			const d = await api(API + '?' + query(reset ? 0 : state.rows.length));
			if (req !== state.req) return;
			state.rows = reset ? d.actors : state.rows.concat(d.actors);
			state.total = d.total;
			state.hasMore = d.has_more;
			render();
		} catch (e) { if (req === state.req) toast(e.message, true); }
	}

	function render() {
		const box = $('a-rows');
		box.replaceChildren(...state.rows.map(rowEl));
		const catName = (state.meta.categories.find((c) => c.id === state.cat) || {}).name || '';
		const filtered = state.q || state.min || state.list;
		$('a-status').textContent = state.total.toLocaleString() + (state.total === 1 ? ' entry' : ' entries') + (catName ? ' in "' + catName + '"' : '') + (filtered ? ' match' : '')
			+ (state.rows.length < state.total ? '  ·  showing ' + state.rows.length.toLocaleString() : '');
		$('a-more').hidden = !state.hasMore;
		if (!state.rows.length) {
			box.append(el('div', { className: 'empty' }, el('p', {}, filtered ? 'Nothing matches.' : 'No entries yet - add one above.')));
		}
		updateBulk();
	}

	function rowEl(r) {
		const cb = el('input', {
			type: 'checkbox', checked: state.selected.has(r.id), title: 'Select',
			onchange: (e) => { e.target.checked ? state.selected.add(r.id) : state.selected.delete(r.id); updateBulk(); },
		});
		return el('div', { className: 'arow', dataset: { id: r.id } },
			cb,
			el('div', { className: 'a-who' }, avatarEl(r.id, r.name, r.photo, false), el('div', { className: 'a-name' },
				el('a', { href: '#', className: 'a-title', onclick: (e) => { e.preventDefault(); openProfile(r.id); } }, r.name),
				r.full_name ? el('span', { className: 'muted small' }, r.full_name) : null)),
			el('span', { className: 'a-age', title: r.dob || '' }, r.age !== null ? String(r.age) : '-'),
			stars(r.rating || 0, (n) => rate(r, n)),
			el('a', { className: 'a-files', href: 'index.php?term=' + r.id, title: 'Show these files in the library' }, r.files.toLocaleString()),
			el('div', { className: 'chip-list small' }, r.lists.map((l) => el('span', { className: 'chip static' }, l.name))),
			el('div', { className: 'a-actions' },
				el('button', { className: 'btn small', type: 'button', onclick: () => openProfile(r.id) }, 'Open'),
				el('button', { className: 'btn small danger', type: 'button', onclick: () => deleteActors([r]) }, 'Delete')));
	}

	async function rate(r, n) {
		try {
			await api(API, { action: 'rate', id: r.id, rating: n });
			r.rating = n || null;
			const row = document.querySelector('.arow[data-id="' + r.id + '"]');
			if (row) row.querySelector('.stars').replaceWith(stars(r.rating || 0, (m) => rate(r, m)));
			if (/rating/.test(state.sort) || state.min) load(true); // the order / filter may have changed
		} catch (e) { toast(e.message, true); }
	}

	// ---------------------------------------------------------------- create / delete / bulk

	async function createActors(e) {
		e.preventDefault();
		const names = $('a-new').value.split(',').map((s) => s.trim()).filter(Boolean);
		if (!names.length) return;
		await withLoading(e.submitter || null, async () => {
			try {
				const r = await api(API, { action: 'create', category_id: state.cat, names });
				$('a-new').value = '';
				toast((r.created.length ? 'Added ' + r.created.length + (r.created.length === 1 ? ' entry' : ' entries') : 'Nothing new')
					+ (r.existing.length ? (r.created.length ? '; ' : ': ') + r.existing.length + ' already existed' : '') + '.');
				state.q = ''; $('a-q').value = '';
				state.sort = 'added_desc'; $('a-sort').value = 'added_desc'; // show what you just added first
				await load(true);
				if (r.created.length === 1) openProfile(r.created[0]);
			} catch (err) { toast(err.message, true); }
		});
	}

	async function deleteActors(rows) {
		const files = rows.reduce((n, r) => n + r.files, 0);
		const msg = rows.length === 1
			? 'Delete "' + rows[0].name + '"?'
			: 'Delete ' + rows.length + ' entries?';
		if (!confirm(msg + '\n\nTheir profile information is deleted and the tag is removed from ' + files.toLocaleString() + ' file' + (files === 1 ? '' : 's')
			+ '. The files themselves are NOT touched. This cannot be undone.')) return false;
		try {
			const r = await api(API, { action: 'delete', ids: rows.map((x) => x.id) });
			toast('Deleted ' + r.deleted + (r.deleted === 1 ? ' entry' : ' entries') + ' (tag removed from ' + r.files_untagged.toLocaleString() + ' files).');
			rows.forEach((x) => state.selected.delete(x.id));
			await Promise.all([load(true), loadMetaOnly()]);
			return true;
		} catch (err) { toast(err.message, true); return false; }
	}

	async function loadMetaOnly() {
		state.meta = await api(API + '?meta=1');
		fillSelects();
	}

	function updateBulk() {
		const n = state.selected.size;
		$('a-bulk').hidden = n === 0;
		$('a-bulk-count').textContent = n + ' selected';
	}

	async function bulkList(add, btn) {
		const listId = Number($('a-bulk-list').value);
		if (!listId) return toast('Create a talent list first (button at the top right).', true);
		await withLoading(btn, async () => {
			try {
				await api(API, { action: 'assign_list', list_id: listId, ids: [...state.selected], add });
				toast((add ? 'Added ' : 'Removed ') + state.selected.size + (add ? ' to' : ' from') + ' the list.');
				await Promise.all([load(true, true), loadMetaOnly()]);
			} catch (err) { toast(err.message, true); }
		});
	}

	// ---------------------------------------------------------------- profile dialog

	async function openProfile(id) {
		try {
			const d = await api(API + '?id=' + id);
			state.editing = d.actor;
			state.editing.newRating = d.actor.rating || 0;
			fillProfile();
			$('profile').hidden = false;
			$('p-name').focus();
		} catch (e) { toast(e.message, true); }
	}

	function fillProfile() {
		const a = state.editing;
		$('p-title').textContent = a.name + '  ·  ' + a.category;
		showPhoto();
		$('p-name').value = a.name;
		$('p-full').value = a.full_name || '';
		$('p-dob').value = a.dob || '';
		$('p-dob').max = new Date().toISOString().slice(0, 10);
		$('p-notes').value = a.notes || '';
		showAge();
		showStars();
		$('p-fields').replaceChildren(...a.fields.map((f) => el('label', { className: 'f-row' + (f.is_long ? ' top' : '') },
			el('span', {}, f.name),
			f.is_long
				? el('textarea', { rows: 3, maxLength: 2000, dataset: { field: f.id } }, f.value)
				: el('input', { type: 'text', maxLength: 2000, value: f.value, dataset: { field: f.id } }))));
		if (!a.fields.length) $('p-fields').replaceChildren(el('p', { className: 'muted small' }, 'No custom fields yet. Add some with "Custom fields..." at the top of the page (e.g. Nationality, Height).'));
		showLists();
		$('p-files').href = 'index.php?term=' + a.id;
		$('p-files').textContent = 'Show the ' + a.files.toLocaleString() + ' file' + (a.files === 1 ? '' : 's') + ' in the library';
	}

	function showPhoto() {
		const a = state.editing;
		$('p-photo').replaceChildren(avatarEl(a.id, a.name, a.photo, true));
		$('p-photo-remove').disabled = !a.photo;
	}

	async function uploadPhoto(file) {
		if (!file) return;
		if (file.size > 5 * 1024 * 1024) return toast('The photo is too large (max 5 MB).', true);
		const fd = new FormData();
		fd.append('id', state.editing.id);
		fd.append('photo', file);
		await withLoading($('p-upload'), async () => {
			try {
				const d = await postForm(fd);
				state.editing.photo = d.photo;
				showPhoto();
				toast('Photo saved.');
				load(true, true);
			} catch (e) { toast(e.message, true); }
		});
	}

	async function removePhoto() {
		const fd = new FormData();
		fd.append('id', state.editing.id);
		fd.append('action', 'remove');
		try {
			await postForm(fd);
			state.editing.photo = null;
			showPhoto();
			load(true, true);
		} catch (e) { toast(e.message, true); }
	}

	function showAge() {
		const v = $('p-dob').value;
		if (!v) { $('p-age').textContent = ''; return; }
		const b = new Date(v + 'T00:00:00'), now = new Date();
		let age = now.getFullYear() - b.getFullYear();
		if (now.getMonth() < b.getMonth() || (now.getMonth() === b.getMonth() && now.getDate() < b.getDate())) age--;
		$('p-age').textContent = Number.isNaN(age) || age < 0 ? '' : 'age ' + age;
	}

	function showStars() {
		$('p-stars').replaceWith(Object.assign(stars(state.editing.newRating, (n) => { state.editing.newRating = n; showStars(); }, 'big'), { id: 'p-stars' }));
	}

	function showLists() {
		const a = state.editing;
		$('p-lists').replaceChildren(...(state.meta.lists.length ? state.meta.lists.map((l) => el('label', { className: 'chip pick' },
			el('input', { type: 'checkbox', checked: a.lists.includes(l.id), onchange: (e) => {
				a.lists = e.target.checked ? [...a.lists, l.id] : a.lists.filter((x) => x !== l.id);
			} }), ' ' + l.name)) : [el('span', { className: 'muted small' }, 'No lists yet - add one below.')]));
	}

	async function saveProfile(e) {
		e.preventDefault();
		const a = state.editing;
		const fields = {};
		$('p-fields').querySelectorAll('[data-field]').forEach((x) => { fields[x.dataset.field] = x.value; });
		await withLoading(e.submitter || null, async () => {
			try {
				await api(API, {
					action: 'save', id: a.id, name: $('p-name').value, full_name: $('p-full').value, dob: $('p-dob').value,
					rating: a.newRating || null, notes: $('p-notes').value, fields, lists: a.lists,
				});
				closeProfile();
				toast('Saved.');
				await Promise.all([load(true), loadMetaOnly()]);
			} catch (err) { toast(err.message, true); }
		});
	}

	function closeProfile() { $('profile').hidden = true; state.editing = null; }

	async function addListInline() {
		const name = $('p-newlist').value.trim();
		if (!name) return;
		try {
			const r = await api(API, { action: 'list_add', name });
			$('p-newlist').value = '';
			await loadMetaOnly();
			state.editing.lists.push(r.id);
			showLists();
		} catch (e) { toast(e.message, true); }
	}

	// ---------------------------------------------------------------- managers (custom fields, talent lists)

	function openMgr(kind) {
		state.mgr = kind;
		const fields = kind === 'fields';
		$('m-title').textContent = fields ? 'Custom fields' : 'Talent lists';
		$('m-hint').textContent = fields
			? 'A field you add here appears on every profile (e.g. Nationality, Height, Agency). Deleting a field deletes what was typed into it.'
			: 'Named collections of actors, e.g. "Favourites" or "To watch". An actor can be on several. Deleting a list does not delete the actors.';
		$('m-name').placeholder = fields ? 'New field name...' : 'New list name...';
		$('m-long-wrap').hidden = !fields;
		renderMgr();
		$('mgr').hidden = false;
		$('m-name').focus();
	}

	function renderMgr() {
		const fields = state.mgr === 'fields';
		const items = fields ? state.meta.fields : state.meta.lists;
		$('m-list').replaceChildren(...(items.length ? items.map((it) => el('div', { className: 'm-row' },
			el('span', { className: 'm-name' }, it.name),
			el('span', { className: 'muted small' }, fields ? (it.is_long ? 'multi-line' : 'single line') : it.members + (it.members === 1 ? ' actor' : ' actors')),
			el('button', { className: 'btn small', type: 'button', onclick: () => renameMgr(it) }, 'Rename'),
			el('button', { className: 'btn small danger', type: 'button', onclick: () => deleteMgr(it) }, 'Delete'))) : [el('p', { className: 'muted small' }, 'None yet.')]));
	}

	async function renameMgr(it) {
		const name = prompt('Rename "' + it.name + '" to:', it.name);
		if (name === null || !name.trim() || name.trim() === it.name) return;
		try {
			await api(API, { action: state.mgr === 'fields' ? 'field_rename' : 'list_rename', id: it.id, name });
			await loadMetaOnly(); renderMgr(); load(true);
		} catch (e) { toast(e.message, true); }
	}

	async function deleteMgr(it) {
		const fields = state.mgr === 'fields';
		if (!confirm('Delete ' + (fields ? 'the field' : 'the list') + ' "' + it.name + '"?' + (fields ? '\n\nEverything typed into it is deleted.' : '\n\nThe actors on it are not deleted.'))) return;
		try {
			await api(API, { action: fields ? 'field_delete' : 'list_delete', id: it.id });
			await loadMetaOnly(); renderMgr(); load(true);
		} catch (e) { toast(e.message, true); }
	}

	async function addMgr(e) {
		e.preventDefault();
		const name = $('m-name').value.trim();
		if (!name) return;
		const fields = state.mgr === 'fields';
		try {
			await api(API, fields ? { action: 'field_add', name, is_long: $('m-long').checked } : { action: 'list_add', name });
			$('m-name').value = '';
			await loadMetaOnly(); renderMgr();
		} catch (err) { toast(err.message, true); }
	}

	// ---------------------------------------------------------------- wiring

	async function init() {
		$('a-q').addEventListener('input', debounce((e) => { state.q = e.target.value.trim(); load(true); }, 250));
		$('a-cat').addEventListener('change', (e) => { state.cat = Number(e.target.value); load(true); });
		$('a-sort').addEventListener('change', (e) => { state.sort = e.target.value; load(true); });
		$('a-min').addEventListener('change', (e) => { state.min = Number(e.target.value); load(true); });
		$('a-list').addEventListener('change', (e) => { state.list = Number(e.target.value); load(true); });
		$('a-more').addEventListener('click', () => withLoading($('a-more'), () => load(false)));
		$('a-create').addEventListener('submit', createActors);
		$('a-fields').addEventListener('click', () => openMgr('fields'));
		$('a-lists').addEventListener('click', () => openMgr('lists'));

		$('a-bulk-all').addEventListener('click', () => { state.rows.forEach((r) => state.selected.add(r.id)); render(); });
		$('a-bulk-none').addEventListener('click', () => { state.selected.clear(); render(); });
		$('a-bulk-add').addEventListener('click', () => bulkList(true, $('a-bulk-add')));
		$('a-bulk-remove').addEventListener('click', () => bulkList(false, $('a-bulk-remove')));
		$('a-bulk-delete').addEventListener('click', () => deleteActors(state.rows.filter((r) => state.selected.has(r.id))));

		$('p-form').addEventListener('submit', saveProfile);
		$('p-close').addEventListener('click', closeProfile);
		$('p-cancel').addEventListener('click', closeProfile);
		$('p-dob').addEventListener('input', showAge);
		$('p-upload').addEventListener('click', () => $('p-file').click());
		$('p-file').addEventListener('change', (e) => { uploadPhoto(e.target.files[0]); e.target.value = ''; });
		$('p-photo-remove').addEventListener('click', removePhoto);
		const drop = $('p-photo');
		drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
		drop.addEventListener('dragleave', () => drop.classList.remove('over'));
		drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('over'); uploadPhoto(e.dataTransfer.files[0]); });
		$('p-clear-rating').addEventListener('click', () => { state.editing.newRating = 0; showStars(); });
		$('p-addlist').addEventListener('click', addListInline);
		$('p-newlist').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addListInline(); } });
		$('p-delete').addEventListener('click', async () => {
			const a = state.editing;
			if (await deleteActors([{ id: a.id, name: a.name, files: a.files }])) closeProfile();
		});

		$('m-close').addEventListener('click', () => { $('mgr').hidden = true; });
		$('m-form').addEventListener('submit', addMgr);
		document.addEventListener('keydown', (e) => {
			if (e.key !== 'Escape') return;
			if (!$('mgr').hidden) $('mgr').hidden = true;
			else if (!$('profile').hidden) closeProfile();
		});

		try {
			await loadMeta();
			await load(true);
			const open = Number(new URLSearchParams(location.search).get('open'));
			if (open) openProfile(open);
		} catch (e) { toast(e.message, true); }
	}

	init();
})();
