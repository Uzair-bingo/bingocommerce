/* Presentation only. Hidden rows keep every identity, fingerprint and submitted value. */
'use strict';

(() => {
	const section = document.getElementById('bc-package-addons');
	if (!section) return;
	const search = section.querySelector('#bc-addon-search');
	search?.addEventListener('input', () => {
		const query = search.value.toLocaleLowerCase();
		section.querySelectorAll('[data-addon-row]').forEach((row) => {
			row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
		});
	});
	section.querySelectorAll('[data-addon-remove]').forEach((button) => {
		// With no JavaScript, removal is unavailable rather than unconfirmed.
		button.disabled = false;
	});
	section.querySelector('#bc-addons-form')?.addEventListener('submit', (event) => {
		const message = event.submitter?.getAttribute('data-addon-remove');
		if (message && !window.confirm(message)) event.preventDefault();
	});
})();
