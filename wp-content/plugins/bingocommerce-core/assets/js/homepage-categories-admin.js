/* Independent category form; native Media Library remains in the current Hall. */
(function () {
	'use strict';
	var form = document.getElementById('bc-category-form');
	if (!form || !window.wp || !wp.media) { return; }
	var editor = form.closest('.bc-category-editor');
	var list = form.querySelector('[data-category-list]');
	var add = form.querySelector('[data-add-card]');
	var template = document.getElementById('bc-category-template');
	var labels = window.bcHomepageCategories;
	var rows = function () { return Array.from(list.querySelectorAll('[data-category-card]')); };
	var announce = function () { wp.a11y.speak(labels.changed); };
	function destination(row, changed) {
		var type = row.querySelector('[data-destination-type]').value;
		row.querySelectorAll('[data-destination]').forEach(function (group) {
			var inactive = group.dataset.destination !== type;
			if (inactive && changed) { group.querySelector('input, select').value = group.dataset.destination === 'url' ? '' : '0'; }
			// Preserve visibility of invalid submitted fields so errors can be corrected.
			group.hidden = inactive && !group.querySelector('[aria-invalid="true"]');
		});
	}
	function refresh() {
		var links = new Map();
		rows().forEach(function (row, index, all) {
			row.querySelectorAll('[id], [for], [aria-describedby], [name]').forEach(function (el) {
				['id', 'for', 'aria-describedby', 'name'].forEach(function (attr) {
					var before = el.getAttribute(attr);
					if (before === null) { return; }
					var after = before.replace(/card-(?:\d+|__INDEX__)-/g, 'card-' + index + '-').replace(/\[cards\]\[(?:\d+|__INDEX__)\]/g, '[cards][' + index + ']');
					el.setAttribute(attr, after);
					if (attr === 'id') { links.set('#' + before, '#' + after); }
				});
			});
			row.querySelector('[data-category-title]').textContent = labels.card.replace('%d', index + 1);
			row.querySelector('[data-category-tools]').hidden = false;
			row.querySelector('[data-category-move="up"]').disabled = index === 0;
			row.querySelector('[data-category-move="down"]').disabled = index === all.length - 1;
			var backgroundRemoval = row.querySelector('[data-remove-existing-background]');
			if (backgroundRemoval) { backgroundRemoval.hidden = row.querySelector('[data-category-background]').value === '0'; }
			destination(row, false);
		});
		editor.querySelectorAll('.notice a').forEach(function (link) {
			if (links.has(link.getAttribute('href'))) { link.setAttribute('href', links.get(link.getAttribute('href'))); }
		});
		form.querySelectorAll('[data-category-media]').forEach(function (media) {
			media.querySelector('[data-category-select]').hidden = false;
			var remove = media.querySelector('[data-category-remove-image]');
			remove.hidden = false;
			remove.disabled = media.querySelector('[data-category-image]').value === '0';
		});
		form.elements.bc_category_count.value = rows().length;
		form.querySelector('[data-category-count]').textContent = rows().length + ' / 5';
		add.disabled = rows().length >= 5;
	}
	add.hidden = false;
	add.addEventListener('click', function () {
		if (rows().length >= 5) { return; }
		list.appendChild(template.content.cloneNode(true)); refresh();
		rows().at(-1).querySelector('[data-category-select]').focus(); announce();
	});
	form.addEventListener('change', function (event) {
		if (event.target.matches('[data-destination-type]')) { destination(event.target.closest('[data-category-card]'), true); announce(); }
	});
	form.addEventListener('click', function (event) {
		var button = event.target.closest('button');
		if (!button) { return; }
		var row = button.closest('[data-category-card]');
		var media = button.closest('[data-category-media]');
		if (button.hasAttribute('data-remove-card') && row) {
			if (!window.confirm(labels.remove)) { return; }
			var target = row.nextElementSibling || row.previousElementSibling;
			row.remove(); refresh(); (target ? target.querySelector('[data-category-select]') : add).focus(); announce();
		} else if (button.hasAttribute('data-remove-existing-background') && row) {
			var background = button.closest('[data-category-legacy-background]');
			background.querySelector('[data-category-background]').value = '0';
			background.querySelector('[data-background-removal-pending]').hidden = false;
			button.hidden = true;
			background.focus(); wp.a11y.speak(labels.backgroundRemoved);
		} else if (button.hasAttribute('data-category-move') && row) {
			var up = button.dataset.categoryMove === 'up';
			var sibling = up ? row.previousElementSibling : row.nextElementSibling;
			if (!sibling) { return; }
			if (up) { list.insertBefore(row, sibling); } else { list.insertBefore(sibling, row); }
			refresh(); (button.disabled ? row.querySelector('[data-category-move="' + (up ? 'down' : 'up') + '"]') : button).focus(); announce();
		} else if (button.hasAttribute('data-category-remove-image') && media) {
			media.querySelector('[data-category-image]').value = '0';
			var preview = media.querySelector('[data-category-preview]'); preview.removeAttribute('src'); preview.hidden = true;
			refresh(); media.querySelector('[data-category-select]').focus(); announce();
		} else if (button.hasAttribute('data-category-select') && media) {
			var frame = wp.media({title: labels.select, button: {text: labels.use}, library: {type: 'image'}, multiple: false});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				media.querySelector('[data-category-image]').value = attachment.id;
				var preview = media.querySelector('[data-category-preview]');
				preview.src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
				preview.hidden = false; refresh(); announce();
			});
			frame.on('close', function () { button.focus(); }); frame.open();
		}
	});
	refresh();
}());
