/* Manage page: create / rename / delete categories and their terms; move terms to another category; merge terms into one. */
(function () {
	'use strict';

	const { api, el, toast, withLoading } = BA;
	const TAX = 'api/taxonomy.php';
	const byName = (a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });
	const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many || one + 's');
	const quoted = (list) => list.map((n) => '"' + n + '"').join(', ');

	let categories = [];
	const sel = { catId: null, ids: new Set() }; // ticked terms (always within one category)

	/** Runs an API call, reloads the list, and reports errors as a toast. */
	async function act(body, okMessage) {
		try {
			await api(TAX, body);
			if (okMessage) toast(okMessage);
			await load();
		} catch (e) {
			toast(e.message, true);
		}
	}

	// ---------------------------------------------------------------- selection

	function toggle(cat, term, on) {
		if (sel.catId !== cat.id) { sel.catId = cat.id; sel.ids.clear(); } // ticking in another category starts a new selection
		on ? sel.ids.add(term.id) : sel.ids.delete(term.id);
		if (!sel.ids.size) sel.catId = null;
		render();
	}

	function clearSelection() {
		sel.catId = null;
		sel.ids.clear();
		render();
	}

	// ---------------------------------------------------------------- move

	async function doMove(cat, dest, merge) {
		const ids = [...sel.ids];
		try {
			const r = await api(TAX, { action: 'term_move', ids, category_id: dest.id, merge_conflicts: merge });
			sel.catId = null;
			sel.ids.clear();
			await load();
			const parts = [];
			if (r.moved) parts.push('moved ' + plural(r.moved, 'term') + ' to "' + dest.name + '"');
			if (r.merged) parts.push('merged ' + plural(r.merged, 'term') + ' into the ' + (r.merged === 1 ? 'one' : 'ones') + ' already in "' + dest.name + '"');
			const text = parts.join(' and ');
			toast(text.charAt(0).toUpperCase() + text.slice(1) + '.');
		} catch (e) {
			if (!merge && e.data && e.data.conflicts) { // the same name is already in the destination: offer to merge
				const msg = 'Already in "' + dest.name + '": ' + quoted(e.data.conflicts) + '.\n\nMerge ' + (e.data.conflicts.length === 1 ? 'it' : 'them') + ' into the existing ' + (e.data.conflicts.length === 1 ? 'term' : 'terms')
					+ ' (their files are combined) and move the rest?';
				if (confirm(msg)) return doMove(cat, dest, true);
				return undefined;
			}
			toast(e.message, true);
			return undefined;
		}
	}

	// ---------------------------------------------------------------- merge

	function openMerge(cat, terms) {
		const best = [...terms].sort((a, b) => b.count - a.count || byName(a, b))[0];
		let keep = best.id;
		const summary = el('p', { className: 'muted small merge-summary' });
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey); };
		const onKey = (e) => { if (e.key === 'Escape') close(); };

		function refresh() {
			const keepTerm = terms.find((t) => t.id === keep);
			const others = terms.filter((t) => t.id !== keep);
			summary.textContent = quoted(others.map((t) => t.name)) + (others.length === 1 ? ' is' : ' are') + ' merged into "' + keepTerm.name + '" and then removed. '
				+ 'Every file that had ' + (others.length === 1 ? 'it' : 'one of them') + ' gets "' + keepTerm.name + '". For actors, profile details, photo, custom fields and lists are combined '
				+ '("' + keepTerm.name + '" wins where both have a value). Your files are not touched.';
		}

		const options = terms.map((t) => el('label', { className: 'merge-opt' },
			el('input', { type: 'radio', name: 'keep', value: t.id, checked: t.id === keep, onchange: () => { keep = t.id; refresh(); } }),
			el('span', { className: 'merge-name' }, t.name),
			el('span', { className: 'muted small' }, plural(t.count, 'file'))));

		const go = el('button', {
			className: 'btn primary', type: 'button',
			onclick: (e) => withLoading(e.currentTarget, async () => {
				try {
					const others = terms.filter((t) => t.id !== keep);
					const keepTerm = terms.find((t) => t.id === keep);
					const r = await api(TAX, { action: 'term_merge', target_id: keep, source_ids: others.map((t) => t.id) });
					close();
					sel.catId = null;
					sel.ids.clear();
					await load();
					toast('Merged ' + plural(r.merged, 'term') + ' into "' + keepTerm.name + '"' + (r.files_gained ? ' (' + plural(r.files_gained, 'file') + ' gained it)' : '') + '.');
				} catch (err) { toast(err.message, true); }
			}),
		}, 'Merge');

		const overlay = el('div', { className: 'modal picker', onclick: (e) => { if (e.target === overlay) close(); } },
			el('div', { className: 'dialog merge-dialog' },
				el('div', { className: 'dialog-head' }, el('h2', {}, 'Merge ' + terms.length + ' terms in "' + cat.name + '" into one'), el('button', { className: 'close-x', type: 'button', 'aria-label': 'Close', onclick: close }, '×')),
				el('p', { className: 'hint' }, 'Which one should stay? Its name is the one you keep.'),
				el('div', { className: 'merge-options' }, ...options),
				summary,
				el('div', { className: 'dialog-foot' }, el('button', { className: 'btn', type: 'button', onclick: close }, 'Cancel'), go)));
		document.body.append(overlay);
		document.addEventListener('keydown', onKey);
		refresh();
	}

	// ---------------------------------------------------------------- rendering

	function toolbar(cat, ticked) {
		const others = categories.filter((c) => c.id !== cat.id);
		const dest = el('select', { title: 'Move the ticked terms to this category', disabled: !others.length },
			...(others.length ? others.map((c) => el('option', { value: c.id }, c.name)) : [el('option', { value: '' }, '(no other category)')]));
		return el('div', { className: 'term-bar' },
			el('strong', {}, plural(ticked.length, 'term') + ' ticked'),
			el('span', { className: 'sep' }),
			el('label', { className: 'small muted' }, 'Move to '), dest,
			el('button', {
				className: 'btn small', type: 'button', disabled: !others.length,
				onclick: (e) => withLoading(e.currentTarget, () => doMove(cat, others.find((c) => String(c.id) === dest.value), false)),
			}, 'Move'),
			el('span', { className: 'sep' }),
			el('button', {
				className: 'btn small', type: 'button', disabled: ticked.length < 2, title: ticked.length < 2 ? 'Tick at least two terms to merge them' : 'Combine the ticked terms into one',
				onclick: () => openMerge(cat, ticked),
			}, 'Merge...'),
			el('button', { className: 'btn small', type: 'button', onclick: clearSelection }, 'Clear'));
	}

	function categoryCard(cat) {
		const input = el('input', { type: 'text', placeholder: 'Add a term to ' + cat.name + '...', maxLength: 100 });
		const form = el('form', {
			className: 'inline-form', style: { margin: 0 },
			onsubmit: (e) => {
				e.preventDefault();
				const name = input.value.trim();
				if (name) act({ action: 'term_add', category_id: cat.id, name });
			},
		}, input, el('button', { className: 'btn', type: 'submit' }, 'Add'));

		const ticked = sel.catId === cat.id ? cat.terms.filter((t) => sel.ids.has(t.id)).sort(byName) : [];
		const terms = [...cat.terms].sort(byName).map((t) => {
			const on = sel.catId === cat.id && sel.ids.has(t.id);
			return el('span', { className: 'term' + (on ? ' sel' : ''), dataset: { id: t.id } },
				el('input', { type: 'checkbox', className: 't-check', checked: on, title: 'Tick to move or merge', onchange: (e) => toggle(cat, t, e.target.checked) }),
				el('span', { className: 't-name', onclick: () => toggle(cat, t, !on) }, t.name),
				el('span', { className: 'n', title: 'Files using it' }, t.count),
				el('button', {
					type: 'button', title: 'Rename', onclick: () => {
						const name = prompt('Rename "' + t.name + '" to:', t.name);
						if (name !== null && name.trim() && name.trim() !== t.name) act({ action: 'term_rename', id: t.id, name });
					},
				}, '✎'),
				el('button', {
					type: 'button', className: 'del', title: 'Delete', onclick: () => {
						const msg = 'Delete "' + t.name + '"?' + (t.count ? '\n\nIt will be removed from ' + t.count + ' file(s). The files themselves are not touched.' : '');
						if (confirm(msg)) act({ action: 'term_delete', id: t.id }, 'Deleted "' + t.name + '".');
					},
				}, '×'));
		});

		return el('section', { className: 'cat-card', dataset: { cat: cat.id } },
			el('div', { className: 'cat-head' },
				el('h3', {}, cat.name),
				el('button', {
					className: 'btn small', type: 'button', onclick: () => {
						const name = prompt('Rename category "' + cat.name + '" to:', cat.name);
						if (name !== null && name.trim() && name.trim() !== cat.name) act({ action: 'category_rename', id: cat.id, name });
					},
				}, 'Rename'),
				el('button', {
					className: 'btn small danger', type: 'button', onclick: () => {
						const n = cat.terms.length;
						const msg = 'Delete the category "' + cat.name + '"' + (n ? ' and its ' + n + ' term' + (n === 1 ? '' : 's') : '') + '?\n\nAll of those labels are removed from your files. The files themselves are not touched.';
						if (confirm(msg)) act({ action: 'category_delete', id: cat.id }, 'Deleted "' + cat.name + '".');
					},
				}, 'Delete')),
			form,
			ticked.length ? toolbar(cat, ticked) : null,
			cat.terms.length ? el('div', { className: 'term-list' }, terms) : el('p', { className: 'muted small' }, 'No terms yet.'),
			cat.terms.length > 1 && !ticked.length ? el('p', { className: 'muted small term-tip' }, 'Tick terms to move them to another category or merge them into one.') : null);
	}

	function render() {
		const box = document.getElementById('cats');
		box.replaceChildren(...(categories.length
			? categories.map(categoryCard)
			: [el('p', { className: 'muted' }, 'No categories yet - create one above.')]));
	}

	async function load() {
		const d = await api(TAX);
		categories = d.categories;
		const cat = categories.find((c) => c.id === sel.catId); // forget ticks of terms that no longer exist
		if (!cat) { sel.catId = null; sel.ids.clear(); } else { for (const id of [...sel.ids]) if (!cat.terms.some((t) => t.id === id)) sel.ids.delete(id); if (!sel.ids.size) sel.catId = null; }
		render();
	}

	document.getElementById('new-cat').addEventListener('submit', (e) => {
		e.preventDefault();
		const input = document.getElementById('new-cat-name');
		const name = input.value.trim();
		if (!name) return;
		input.value = '';
		act({ action: 'category_add', name }, 'Category "' + name + '" created.');
	});

	load().catch((e) => toast(e.message, true));
})();
