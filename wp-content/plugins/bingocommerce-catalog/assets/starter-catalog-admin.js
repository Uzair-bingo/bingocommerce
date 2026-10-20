/*
 * Network Admin → Starter Catalog: Publish New Version, Resume, Discard and
 * Finish Discard. One request at a time; the server re-checks everything on
 * every request, so this script only drives the steps and shows the results.
 * Server text is always inserted as text, never as HTML.
 */
'use strict';

(() => {
	const root = document.getElementById('bc-starter-publish');
	const config = window.bcStarterCatalog;
	if (!root || !config) return;

	const strings = config.strings || {};
	const intro = root.querySelector('[data-bc-intro]');
	const status = root.querySelector('[data-bc-status]');
	const problems = root.querySelector('[data-bc-problems]');
	const warnings = root.querySelector('[data-bc-warnings]');
	const warningsTitle = root.querySelector('[data-bc-warnings-title]');
	const reload = root.querySelector('[data-bc-reload]');
	const startButton = root.querySelector('[data-bc-publish="start"]');
	const resumeButton = root.querySelector('[data-bc-publish="continue"]');
	const discardButton = root.querySelector('[data-bc-publish="discard"][data-bc-confirm]');
	const buttons = () => root.querySelectorAll('[data-bc-publish]');
	let busy = false;
	let seenWarnings = [];

	const format = (template, ...values) =>
		values.reduce((text, value, index) => text.split('%' + (index + 1) + '$s').join(String(value)), String(template || ''));

	const fill = (list, items) => {
		list.replaceChildren(
			...items.map((item) => {
				const entry = document.createElement('li');
				entry.textContent = String(item);
				return entry;
			})
		);
		list.hidden = items.length === 0;
	};

	const setBusy = (value) => {
		busy = value;
		buttons().forEach((button) => {
			button.disabled = value;
		});
	};

	// Which main buttons fit the state the server reported.
	const showMode = (mode, version) => {
		if (startButton) startButton.hidden = mode !== 'idle';
		if (resumeButton) resumeButton.hidden = mode !== 'building';
		if (discardButton) {
			discardButton.hidden = mode !== 'building';
			if (version) discardButton.dataset.bcVersion = String(version);
		}
	};

	const send = async (step, version) => {
		const body = new URLSearchParams({ action: config.action, _ajax_nonce: config.nonce, step });
		if (version) body.set('version', String(version));

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
			throw new Error(json.data.message ? String(json.data.message) : strings.failed);
		}
		return json.data;
	};

	const addWarnings = (result) => {
		(Array.isArray(result.warnings) ? result.warnings : []).forEach((warning) => {
			if (!seenWarnings.includes(warning)) seenWarnings.push(warning);
		});
		fill(warnings, seenWarnings);
		if (warningsTitle) warningsTitle.hidden = seenWarnings.length === 0;
	};

	// `row` is the Finish Discard row the request came from, if any.
	const finish = (result, row) => {
		const listed = Array.isArray(result.problems) ? result.problems : [];
		switch (result.status) {
			case 'published':
				status.textContent = format(Number(result.total) === 1 ? strings.publishedOne : strings.published, result.version_id, result.total);
				showMode('done');
				reload.hidden = false;
				return;
			case 'blocked':
				status.textContent = strings.blocked;
				fill(problems, listed);
				showMode(result.version_id ? 'building' : 'idle', result.version_id);
				return;
			case 'changed':
				status.textContent = strings.changed;
				fill(problems, listed);
				showMode('building', result.version_id);
				return;
			case 'discarded':
				status.textContent = strings.discarded;
				if (row) {
					// That version's cleanup is finished; its row goes.
					row.remove();
				} else {
					// The Building version is gone, so a new Publish can start.
					showMode('idle');
				}
				return;
			default:
				status.textContent = strings.idle;
				showMode('done');
				reload.hidden = false;
		}
	};

	const run = async (button) => {
		const step = button.dataset.bcPublish;
		const version = step === 'discard' ? button.dataset.bcVersion : '';
		const row = button.closest('[data-bc-cleanup]');
		setBusy(true);
		if (intro) intro.hidden = true;
		fill(problems, []);
		if (step === 'start') seenWarnings = [];
		status.textContent = '';

		let last = null;
		try {
			let result = await send(step, version);
			addWarnings(result);
			while (result.status === 'building') {
				last = result;
				status.textContent = format(strings.progress, result.captured, result.total);
				result = await send('continue');
				addWarnings(result);
			}
			finish(result, row);
		} catch (error) {
			status.textContent = error && error.message ? error.message : strings.failed;
			if (step === 'discard' && !row) {
				// The server may have marked the version Discarded before the
				// failure, or not; the page cannot tell. Hide Resume and the rest,
				// and let a reload show the true state (Resume, or Finish Discard).
				showMode('done');
				reload.hidden = false;
			} else if (last && last.version_id) {
				// A version is already building on the server: offer Resume and Discard.
				showMode('building', last.version_id);
			}
		} finally {
			setBusy(false);
		}
	};

	root.addEventListener('click', (event) => {
		const button = event.target.closest('[data-bc-publish]');
		if (!button || busy || button.disabled) return;
		if (button.hasAttribute('data-bc-confirm') && !window.confirm(strings.confirm)) return;
		run(button);
	});

	// With no JavaScript the buttons stay disabled rather than half-working.
	setBusy(false);
})();
