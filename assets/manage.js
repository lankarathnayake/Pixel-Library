/* Manage page: create / rename / delete categories and their terms. */
(function () {
	'use strict';

	const { api, el, toast } = BA;
	const TAX = 'api/taxonomy.php';
	const byName = (a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });

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

		const terms = [...cat.terms].sort(byName).map((t) => el('span', { className: 'term' },
			el('span', {}, t.name),
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
			}, '×')));

		return el('section', { className: 'cat-card' },
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
			cat.terms.length ? el('div', { className: 'term-list' }, terms) : el('p', { className: 'muted small' }, 'No terms yet.'));
	}

	async function load() {
		const d = await api(TAX);
		const box = document.getElementById('cats');
		box.replaceChildren(...(d.categories.length
			? d.categories.map(categoryCard)
			: [el('p', { className: 'muted' }, 'No categories yet - create one above.')]));
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
