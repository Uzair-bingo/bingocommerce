/*
 * Products → Starter Products: Import Starter Products, Continue Import and
 * Retry Remaining Products. One request at a time; the server re-checks
 * everything on every request and writes every message, so this script only
 * drives the steps and shows the text (always as text, never as HTML).
 */
'use strict';

(() => {
	const root = document.getElementById('bc-starter-import');
	const config = window.bcStarterImport;
	if (!root || !config) return;

	const strings = config.strings || {};
	const intro = root.querySelector('[data-bc-intro]');
	const status = root.querySelector('[data-bc-status]');
	const reload = root.querySelector('[data-bc-reload]');
	const startButton = root.querySelector('[data-bc-import="start"]');
	const continueButton = root.querySelector('[data-bc-import="continue"]');
	const buttons = () => root.querySelectorAll('[data-bc-import]');
	let busy = false;

	const setBusy = (value) => {
		busy = value;
		buttons().forEach((button) => {
			button.disabled = value;
		});
	};

	// Which button fits the state the server reported.
	const show = (mode) => {
		if (startButton) startButton.hidden = mode !== 'start';
		if (continueButton) {
			continueButton.hidden = mode !== 'continue' && mode !== 'retry';
			if (mode === 'continue') continueButton.textContent = strings.continue;
			if (mode === 'retry') continueButton.textContent = strings.retry;
		}
	};

	const send = async (step) => {
		const body = new URLSearchParams({ action: config.action, _ajax_nonce: config.nonce, step });

		let json = null;
		try {
			const response = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
			json = await response.json();
		} catch (error) {
			json = null;
		}
		if (!json || typeof json !== 'object' || typeof json.data !== 'object' || json.data === null) {
			throw new Error(strings.failed);
		}
		if (!json.success) {
			const failure = new Error(json.data.message ? String(json.data.message) : strings.failed);
			failure.importStatus = json.data.import_status || null;
			throw failure;
		}
		return json.data;
	};

	const run = async (step) => {
		setBusy(true);
		if (intro) intro.hidden = true;
		status.textContent = '';

		let last = null;
		try {
			let result = await send(step);
			last = result;
			// Each request does at least one piece of work; the cap only guards
			// against a server that keeps answering without progress.
			for (let rounds = 0; result.status === 'importing' && rounds < 5000; rounds++) {
				status.textContent = String(result.text || '');
				result = await send('continue');
				last = result;
			}
			status.textContent = String(result.text || '');
			if (result.status === 'importing') {
				show('continue');
			} else if (result.status === 'failed') {
				// An import that had already failed (for example a Start from a
				// second tab): the server's text names the problem.
				show('retry');
				reload.hidden = false;
			} else {
				// Complete or needs attention: the reloaded page shows the details.
				show('done');
				reload.hidden = false;
			}
		} catch (error) {
			status.textContent = error && error.message ? error.message : strings.failed;
			const saved = error && error.importStatus ? error.importStatus : last && last.status;
			if (saved === 'failed') show('retry');
			else if (saved === 'importing') show('continue');
			else if (saved === 'complete' || saved === 'needs_attention') show('done');
			reload.hidden = false;
		} finally {
			setBusy(false);
		}
	};

	root.addEventListener('click', (event) => {
		const button = event.target.closest('[data-bc-import]');
		if (!button || busy || button.disabled) return;
		run(button.dataset.bcImport === 'start' ? 'start' : 'continue');
	});

	// With no JavaScript the buttons stay disabled rather than half-working.
	setBusy(false);
})();
