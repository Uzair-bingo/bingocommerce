/**
 * Live homepage checks, run BY tests/homepage-session-calendar.php while its
 * disposable fixture Halls exist (BC_SESSION_CALENDAR_BROWSER=1).
 *
 * Every document request for a fixture Hall is answered by a real php-cgi
 * homepage GET (tests/homepage-session-calendar-request.php): the CGI SAPI parses
 * the query string, WordPress handles the request and the BingoThemev1 template
 * renders. There is no web server. Theme and WordPress assets are read from disk;
 * fixture media URLs are answered with one read-only reference image (the fixture
 * writes no media files); every other request, including any POST, is refused.
 * The two three-month Halls' eligibility results (one ok, one partial) are
 * fixture doubles; their request parsing, resolution and rendering are real.
 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFile} = require('node:child_process');
const {chromium} = require(process.env.BC_PLAYWRIGHT_MODULE || 'playwright');

const config = JSON.parse(fs.readFileSync(process.env.BC_HSC_CONFIG, 'utf8'));
const {main, partial, empty, three, three_partial: threePartial} = config;
const byHost = new Map([main, partial, empty, three, threePartial].map(hall => [hall.host, hall]));
const mime = {'.css': 'text/css', '.js': 'application/javascript', '.woff2': 'font/woff2', '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.jpg': 'image/jpeg', '.gif': 'image/gif'};
let passes = 0;
let documents = 0;

function check(condition, label) {
	assert.ok(condition, label);
	passes++;
	console.log('PASS [Browser] ' + label);
}

function cgi(hall, url) {
	const env = {
		PATH: process.env.PATH, BC_TEST_DB: 'bingocommerce_state_recon', BC_FIXTURE_CGI: '1', BC_FIXTURE_WOOCOMMERCE: '1',
		BC_HSC_SITE: String(hall.site), BC_HSC_OWNER: config.owner, HTTP_HOST: hall.host, SERVER_NAME: hall.host,
		REQUEST_URI: url.pathname + url.search, QUERY_STRING: url.search.replace(/^\?/, ''), REQUEST_METHOD: 'GET',
		SCRIPT_NAME: '/index.php', PHP_SELF: '/index.php', SCRIPT_FILENAME: config.worker, REDIRECT_STATUS: '1',
	};
	if (hall.eligible) env.BC_HSC_ELIGIBLE = hall.eligible;
	return new Promise((resolve, reject) => {
		execFile(config.cgi, [config.worker], {env, cwd: config.root, maxBuffer: 64 * 1024 * 1024, timeout: 60000, encoding: 'buffer'}, (error, stdout, stderr) => {
			if (error) return reject(new Error('CGI homepage request failed: ' + stderr.toString()));
			const out = stdout.toString('utf8');
			const split = out.match(/\r?\n\r?\n/);
			const head = split ? out.slice(0, split.index) : out;
			const body = split ? out.slice(split.index + split[0].length) : '';
			const status = Number((head.match(/^Status: (\d{3})/mi) || [0, 200])[1]);
			const headers = {'content-type': (head.match(/^Content-Type: (.+)$/mi) || [0, 'text/html; charset=UTF-8'])[1].trim()};
			const location = head.match(/^Location: (.+)$/mi);
			if (location) headers.location = location[1].trim();
			resolve({status, headers, body});
		});
	});
}

async function router(route) {
	const request = route.request();
	const url = new URL(request.url());
	const hall = byHost.get(url.hostname);
	// The worker boots the fixture as its network main site before switching to the
	// Hall, so WP_CONTENT_URL (theme/plugin assets) points at the network host. Only
	// static files are ever served for that host.
	const network = url.hostname === config.network;
	if ((!hall && !network) || 'http:' !== url.protocol || 'GET' !== request.method()) return route.abort();
	if (hall && '/' === url.pathname && !url.searchParams.has('wc-ajax')) {
		documents++;
		return route.fulfill(await cgi(hall, url));
	}
	// Fixture media are metadata-only attachments in a `calendar-fixture/` folder
	// (the uploads path itself depends on the network's media settings).
	if (url.pathname.includes('/calendar-fixture/') && url.pathname.endsWith('.png')) {
		return route.fulfill({contentType: 'image/png', body: fs.readFileSync(config.image)});
	}
	if (['/wp-content/themes/', '/wp-content/plugins/', '/wp-includes/'].some(prefix => url.pathname.startsWith(prefix))) {
		const file = path.resolve(config.root, '.' + decodeURIComponent(url.pathname));
		const type = mime[path.extname(file)];
		if (!type || !file.startsWith(config.root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return route.abort();
		return route.fulfill({contentType: type, body: fs.readFileSync(file)});
	}
	return route.abort();
}

async function context(browser, options = {}) {
	const ctx = await browser.newContext({viewport: {width: 1440, height: 1000}, ...options});
	await ctx.route('**/*', router);
	return ctx;
}

const visible = page => page.$$eval('[data-calendar-month]:not([hidden]) a[data-calendar-date]', links => links.map(link => link.dataset.calendarDate));
const current = page => page.$$eval('a[aria-current="date"]', links => links.map(link => link.dataset.calendarDate));
const shownMonth = page => page.$eval('[data-calendar-month]:not([hidden])', month => month.dataset.calendarMonth);
// The visible message only: a month-only panel also carries its hidden alternative.
const message = page => page.$eval('.calendar-detail', detail => {
	const box = detail.querySelector('[data-calendar-message]');
	return box && !box.hidden ? box.innerText.replace(/\s+/g, ' ').trim() : '';
});
const selectedRows = page => page.$$('.calendar-session-row.is-selected, .calendar-session-row[aria-current]');
const shownPanels = page => page.$$eval('[data-calendar-date-panel]:not([hidden])', panels => panels.map(panel => panel.dataset.calendarDatePanel));
const arrow = (page, direction) => page.click(`[data-calendar-month]:not([hidden]) a[data-calendar-direction="${direction}"]`);
const hasArrow = async (page, direction) => 0 < (await page.$$(`[data-calendar-month]:not([hidden]) a[data-calendar-direction="${direction}"]`)).length;
/** Month arrows (no request) until the date's month is shown; dates and months are YYYY-MM-DD strings. */
async function showDate(page, date) {
	for (let i = 0; i < 3 && !(await page.isVisible(`a[data-calendar-date="${date}"]`)); i++) {
		await arrow(page, date < await shownMonth(page) ? 'prev' : 'next');
	}
}
async function go(page, target, predicate) {
	await Promise.all([page.waitForURL(predicate, {waitUntil: 'load'}), target()]);
}
const param = (page, key) => new URL(page.url()).searchParams.get(key);

async function noScript(browser) {
	const ctx = await context(browser, {javaScriptEnabled: false});
	const page = await ctx.newPage();
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));

	await page.goto(`http://${main.host}/`);
	check(JSON.stringify(await current(page)) === JSON.stringify([main.earliest]), 'no JavaScript: a bare homepage selects the earliest eligible date');
	check(await page.isVisible(`[data-calendar-date-panel="${main.earliest}"]`), 'no JavaScript: that date’s Sessions are shown');
	const before = documents;
	await page.click('[data-calendar-month]:not([hidden]) span.calendar-day.is-unavailable >> nth=0');
	await page.waitForTimeout(300);
	check(documents === before, 'clicking a greyed-out date does nothing');

	// Any other highlighted date in the shown month (independent of today's date).
	const second = (await visible(page)).find(date => date !== main.earliest);
	check(Boolean(second), 'the shown month has a second highlighted date');
	await go(page, () => page.click(`a[data-calendar-date="${second}"]`), url => url.searchParams.get('bc_date') === second);
	check(page.url().endsWith(`?bc_date=${second}#calendar`), 'date link is a homepage GET carrying a YYYY-MM-DD value');
	check(JSON.stringify(await current(page)) === JSON.stringify([second]) && await page.isVisible(`[data-calendar-date-panel="${second}"]`), 'the server marks the chosen date and shows its Sessions');

	await page.goto(`http://${main.host}/?bc_date=${main.session_date}`);
	await go(page, () => page.click(`[data-calendar-date-panel="${main.session_date}"] a[href*="bc_session=${main.session}"]`), url => url.searchParams.get('bc_session') === String(main.session));
	check(JSON.stringify([...new URL(page.url()).searchParams.keys()]) === '["bc_session"]', 'View Packages carries only the exact Session');
	const names = await page.$$eval('#session-packages .session-package__name', headings => headings.map(heading => heading.textContent.trim()));
	check(JSON.stringify(names) === JSON.stringify(main.packages), 'inline Package cards show the authoritative names');
	check(0 === (await page.$$('#session-packages a, #session-packages button, #session-packages form, #session-packages input')).length, 'Package cards are informational: no purchase controls');
	check(JSON.stringify(await current(page)) === JSON.stringify([main.session_date]), 'the Session determines the selected date');
	const image = page.locator('#session-packages .session-package__image').first();
	await image.scrollIntoViewIfNeeded();
	check(await image.evaluate(img => img.decode().then(() => img.naturalWidth > 0, () => false)), 'the Package card image loads from its WordPress attachment URL');

	// Keyboard: Tab through the calendar card; only links can take focus, with a visible ring.
	await page.goto(`http://${main.host}/`);
	const seen = [];
	for (let i = 0; i < 400; i++) {
		await page.keyboard.press('Tab');
		const inside = await page.evaluate(() => Boolean(document.activeElement && document.activeElement.closest('.calendar-card')));
		if (!inside) {
			if (seen.length) break;
			continue;
		}
		// The focus ring is a box-shadow transition (0.2-0.25s); read it once it has finished.
		await page.waitForTimeout(350);
		seen.push(await page.evaluate(() => {
			const active = document.activeElement;
			const number = active.querySelector('.calendar-day__num');
			return {tag: active.tagName, date: active.dataset.calendarDate || null, name: active.getAttribute('aria-label') || active.textContent.trim(), ring: getComputedStyle(number || active).boxShadow};
		}));
	}
	check(seen.length > 0 && seen.every(info => 'A' === info.tag), 'keyboard: only links inside the calendar receive focus');
	check(JSON.stringify(seen.filter(info => info.date).map(info => info.date)) === JSON.stringify(await visible(page)), 'keyboard: every highlighted date in the shown month is reachable; greyed dates are skipped');
	const ringless = seen.filter(info => 'none' === info.ring).map(info => info.name);
	check(0 === ringless.length, 'keyboard focus is visible on every calendar link' + (ringless.length ? ': missing on ' + ringless.join(', ') : ''));
	await page.focus(`a[data-calendar-date="${second}"]`);
	await go(page, () => page.keyboard.press('Enter'), url => url.searchParams.get('bc_date') === second);
	check(JSON.stringify(await current(page)) === JSON.stringify([second]), 'Enter on a highlighted date navigates without JavaScript');

	// Three months, served by the real resolver over a fixture eligibility result.
	await page.goto(`http://${three.host}/`);
	check('2026-01-01' === await shownMonth(page) && JSON.stringify(await current(page)) === '["2026-01-31"]', 'three-month span: January with 01/31/2026 selected');
	const next = () => page.click('[data-calendar-month]:not([hidden]) a[data-calendar-direction="next"]');
	const prev = () => page.click('[data-calendar-month]:not([hidden]) a[data-calendar-direction="prev"]');
	await go(page, next, url => url.searchParams.get('bc_month') === '2026-02-01');
	check('2026-02-01' === await shownMonth(page) && 0 === (await visible(page)).length, 'next month: February is reachable with no selectable dates');
	check((await message(page)).startsWith('No Sessions are available this month.') && 0 === (await current(page)).length, 'February states that it has no Sessions and selects nothing');
	await go(page, next, url => url.searchParams.get('bc_month') === '2026-03-01');
	check('2026-03-01' === await shownMonth(page) && (await message(page)) === 'Choose a highlighted date.', 'next month: March prompts for a highlighted date');
	check(0 === (await page.$$('[data-calendar-month]:not([hidden]) a[data-calendar-direction="next"]')).length, 'no navigation past the last reachable month');
	await go(page, () => page.click('a[data-calendar-date="2026-03-02"]'), url => url.searchParams.get('bc_date') === '2026-03-02');
	check(JSON.stringify(await current(page)) === '["2026-03-02"]', '03/02/2026, the last date of the window, is reachable and selectable');
	await go(page, prev, url => url.searchParams.get('bc_month') === '2026-02-01');
	await go(page, prev, url => url.searchParams.get('bc_month') === '2026-01-01');
	check('2026-01-01' === await shownMonth(page) && 0 === (await page.$$('[data-calendar-month]:not([hidden]) a[data-calendar-direction="prev"]')).length, 'previous month back to January; nothing before it');
	check(0 === errors.length, 'no page errors without JavaScript');
	await ctx.close();
}

async function withScript(browser) {
	const ctx = await context(browser);
	const page = await ctx.newPage();
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));

	await page.goto(`http://${main.host}/?bc_date=${main.empty_date}`);
	check((await message(page)).startsWith('No Sessions are available for purchase on'), 'JavaScript: a requested empty date keeps its empty state on load');
	check(0 === (await current(page)).length && 1 === (await page.$$('span.is-requested[aria-current="date"]')).length, 'JavaScript: nothing is selected automatically');
	const choice = (await visible(page))[0];
	let before = documents;
	await page.click(`a[data-calendar-date="${choice}"]`);
	await page.waitForTimeout(200);
	check(documents === before, 'explicit date choice switches panels without a request');
	check(await page.isVisible(`[data-calendar-date-panel="${choice}"]`) && '' === await message(page), 'explicit choice shows its Sessions and replaces the empty-state message');
	check(param(page, 'bc_date') === choice && 0 === (await page.$$('.is-requested')).length, 'the address mirrors the explicit choice; the requested marker is cleared');
	check(JSON.stringify(await current(page)) === JSON.stringify([choice]), 'the chosen date is marked current');

	// A date choice, even of the Session's own date, ends the Session selection.
	const dateOnly = async (date, label) => {
		check(await page.isHidden('#session-packages'), `${label}: the whole Package block is hidden`);
		check(0 === (await selectedRows(page)).length, `${label}: no Session row keeps its selected class or aria-current`);
		check(JSON.stringify(await current(page)) === JSON.stringify([date]) && JSON.stringify(await shownPanels(page)) === JSON.stringify([date]), `${label}: the clicked date stays current with its Session list shown`);
		const href = await page.$eval(`a[data-calendar-date="${date}"]`, link => link.href);
		check(page.url() === href && JSON.stringify([...new URL(page.url()).searchParams.keys()]) === '["bc_date"]', `${label}: the address is the date link’s own URL, without bc_session`);
	};
	const viewPackages = async (date, label) => {
		await go(page, () => page.click(`[data-calendar-date-panel="${date}"] a[href*="bc_session=${main.session}"]`), url => url.searchParams.get('bc_session') === String(main.session));
		check(await page.isVisible('#session-packages') && 1 === (await page.$$('.calendar-session-row.is-selected[aria-current="true"]')).length, `${label}: View Packages selects the Session again and shows its Packages`);
	};
	const reload = async label => {
		before = documents;
		await page.reload({waitUntil: 'load'});
		check(documents === before + 1 && 0 === (await page.$$('#session-packages')).length && 0 === (await selectedRows(page)).length, `${label}: reloading the date-only URL renders no Session selection and no Package block`);
		check(JSON.stringify(await current(page)) === JSON.stringify([main.session_date]) && '' === await message(page), `${label}: the reloaded date is selected by the server, with no message`);
	};
	await page.goto(`http://${main.host}/?bc_session=${main.session}`);
	check(await page.isVisible('#session-packages') && 1 === (await selectedRows(page)).length, 'JavaScript: the Session link still renders Packages and marks its row from the server');
	before = documents;
	await page.click(`a[data-calendar-date="${main.session_date}"]`);
	check(documents === before, 'same highlighted date: switches without a request');
	await dateOnly(main.session_date, 'selected Session → its same date');
	await reload('same date');
	await viewPackages(main.session_date, 'after the same-date choice');

	before = documents;
	await showDate(page, main.earliest);
	await page.click(`a[data-calendar-date="${main.earliest}"]`);
	await dateOnly(main.earliest, 'selected Session on date A → date B');
	await showDate(page, main.session_date);
	await page.click(`a[data-calendar-date="${main.session_date}"]`);
	check(documents === before, 'A → B → A: no request');
	await dateOnly(main.session_date, 'A → B → back to A: Packages do not return');
	await reload('A → B → A');
	await viewPackages(main.session_date, 'after A → B → A');

	await page.goto(`http://${partial.host}/?bc_date=${partial.empty_date}`);
	check((await message(page)).includes('could be confirmed') && await page.isVisible('[data-calendar-notice]'), 'partial result: uncertain wording and notice');
	await page.click(`a[data-calendar-date="${(await visible(page))[0]}"]`);
	check('' === await message(page) && await page.isVisible('[data-calendar-notice]'), 'explicit choice replaces the message but the uncertainty notice stays');

	await page.goto(`http://${empty.host}/`);
	check((await message(page)) === 'No Sessions are currently available for purchase.' && 0 === (await visible(page)).length, 'successful empty read: the only "no Sessions" state, fully greyed');

	await page.goto(`http://${three.host}/`);
	before = documents;
	const next = () => page.click('[data-calendar-month]:not([hidden]) a[data-calendar-direction="next"]');
	await next();
	await next();
	check('2026-03-01' === await shownMonth(page) && documents === before && 'http://' + three.host + '/' === page.url(), 'JavaScript: month arrows switch rendered months without a request or a new address');
	check(JSON.stringify(await current(page)) === '["2026-01-31"]' && await page.isVisible('[data-calendar-date-panel="2026-01-31"]'), 'month arrows never change the selection');
	check('next' === await page.evaluate(() => document.activeElement.dataset.calendarDirection) || 'prev' === await page.evaluate(() => document.activeElement.dataset.calendarDirection), 'focus stays on a month control after switching');
	await page.click('a[data-calendar-date="2026-03-02"]');
	check(param(page, 'bc_date') === '2026-03-02' && await page.isVisible('[data-calendar-date-panel="2026-03-02"]'), 'explicit choice in the last month');
	await page.goto(`http://${three.host}/?bc_session=103`);
	const sessionUrl = page.url();
	before = documents;
	await arrow(page, 'prev');
	await arrow(page, 'prev');
	check('2026-01-01' === await shownMonth(page) && documents === before && page.url() === sessionUrl, 'Session selection: arrows show January without a request or a new address');
	check(JSON.stringify(await current(page)) === '["2026-03-02"]' && 1 === (await page.$$('.calendar-session-row.is-selected[aria-current="true"]')).length && await page.isVisible('#session-packages'), 'month arrows keep the selected Session, its row and its Package block');
	check('' === await message(page), 'month arrows never add a month-only prompt to a Session selection');
	await page.goto(`http://${three.host}/?bc_month=2026-04-01`);
	check((await message(page)).startsWith('That month can’t be shown.') && '2026-01-01' === await shownMonth(page), 'unreachable month: recovery message on the first reachable month');
	await next();
	check('2026-02-01' === await shownMonth(page) && (await message(page)).startsWith('That month can’t be shown.'), 'month arrows never clear a recovery message');
	check(0 === errors.length, 'no page errors with JavaScript: ' + errors.join('; '));
	await ctx.close();
}

/*
 * Month-only requests: arrows re-word the month-only message from the server-rendered
 * highlighted dates of the month shown. Nothing else about the message changes.
 */
async function monthOnly(browser) {
	const ctx = await context(browser);
	const page = await ctx.newPage();
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const prompt = 'Choose a highlighted date.';
	const emptyMonth = 'No Sessions are available this month. See available dates';
	const unconfirmed = 'No Sessions could be confirmed this month. See available dates';
	const nothingSelected = async () => 0 === (await current(page)).length && 0 === (await shownPanels(page)).length && 0 === (await page.$$('[data-session-calendar] .is-selected')).length;
	const step = async (direction, month, text, label) => {
		await arrow(page, direction);
		check(month === await shownMonth(page) && text === await message(page), `${label}: ${month.slice(0, 7)} reads “${text}”`);
	};

	for (const start of ['2026-03-01', '2026-02-01']) {
		await page.goto(`http://${three.host}/?bc_month=${start}`);
		const address = page.url();
		const before = documents;
		check(start === await shownMonth(page) && ('2026-03-01' === start ? prompt : emptyMonth) === await message(page) && await nothingSelected(), `month-only ${start.slice(0, 7)} request: its own server message, nothing selected`);
		if ('2026-03-01' === start) {
			await step('prev', '2026-02-01', emptyMonth, 'from a month with dates to the empty month');
			await step('prev', '2026-01-01', prompt, 'from the empty month to a month with dates');
			await step('next', '2026-02-01', emptyMonth, 'back to the empty month');
			await step('next', '2026-03-01', prompt, 'back to the starting month');
		} else {
			await step('next', '2026-03-01', prompt, 'from the empty month');
			await step('prev', '2026-02-01', emptyMonth, 'back to the empty month');
			await step('prev', '2026-01-01', prompt, 'to the earlier month with dates');
			await step('next', '2026-02-01', emptyMonth, 'back again');
		}
		check(documents === before && page.url() === address && await nothingSelected(), 'month-only arrows: no request, no new address and nothing selected');
	}

	// After an explicit date choice there is no month-only message to bring back.
	await page.goto(`http://${three.host}/?bc_month=2026-02-01`);
	await arrow(page, 'prev');
	await page.click('a[data-calendar-date="2026-01-31"]');
	check('' === await message(page) && JSON.stringify(await current(page)) === '["2026-01-31"]', 'explicit date choice replaces the month-only message');
	const afterChoice = [];
	for (const direction of ['next', 'next', 'prev', 'prev']) {
		await arrow(page, direction);
		afterChoice.push(await message(page));
	}
	check(afterChoice.every(text => '' === text) && JSON.stringify(await current(page)) === '["2026-01-31"]', 'after an explicit choice, arrows into empty and populated months never bring a month-only message back');

	// Partial results keep the uncertain wording and their notice.
	await page.goto(`http://${threePartial.host}/?bc_month=2026-03-01`);
	check(prompt === await message(page) && await page.isVisible('[data-calendar-notice]'), 'partial month-only request: prompt with the uncertainty notice');
	await step('prev', '2026-02-01', unconfirmed, 'partial');
	check(await page.isVisible('[data-calendar-notice]') && 0 === (await page.$$('text=No Sessions are available')).length, 'partial empty month: notice kept; never claims there are no Sessions');
	await step('prev', '2026-01-01', prompt, 'partial');
	await step('next', '2026-02-01', unconfirmed, 'partial');
	check(await page.isVisible('[data-calendar-notice]'), 'partial: the notice survives every month change');

	// Recovery and requested-date messages stay, unchanged, until an explicit date choice.
	for (const [query, text, label] of [
		['bc_month=2026-04-01', 'That month can’t be shown. See available dates', 'unreachable month'],
		['bc_date=x', 'That selection isn’t valid. See available dates', 'invalid value'],
		['bc_session=999', 'That Session is no longer available for purchase. See available dates', 'stale Session'],
		['bc_session=103&bc_date=2026-01-31', 'That date and Session don’t match. See available dates', 'date/Session mismatch'],
		['bc_date=2026-02-15', 'No Sessions are available for purchase on 02/15/2026. See available dates', 'requested empty date'],
	]) {
		await page.goto(`http://${three.host}/?${query}`);
		check(text === await message(page), `${label}: “${text}”`);
		const seen = [];
		while (await hasArrow(page, 'prev')) { await arrow(page, 'prev'); seen.push(await message(page)); }
		while (await hasArrow(page, 'next')) { await arrow(page, 'next'); seen.push(await message(page)); }
		while (await hasArrow(page, 'prev')) { await arrow(page, 'prev'); seen.push(await message(page)); }
		check(seen.length >= 2 && seen.every(seenText => seenText === text), `${label}: every month change keeps the message (${seen.length} changes)`);
		await page.click('a[data-calendar-date="2026-01-31"]');
		check('' === await message(page), `${label}: an explicit date choice replaces it`);
	}
	check(0 === errors.length, 'no page errors during month-only checks: ' + errors.join('; '));
	await ctx.close();
}

async function deviceClocks(browser) {
	for (const hall of [main, three]) {
		const markup = [];
		for (const timezoneId of ['Pacific/Kiritimati', 'Pacific/Pago_Pago', 'America/New_York', 'Asia/Tokyo']) {
			const ctx = await context(browser, {timezoneId});
			const page = await ctx.newPage();
			await page.goto(`http://${hall.host}/`);
			markup.push(await page.$eval('[data-session-calendar]', section => section.outerHTML));
			await ctx.close();
		}
		check(markup.every(html => html === markup[0]), `device time zone never changes the calendar (${hall === main ? 'live' : 'three-month'} Hall)`);
	}
}

async function responsive(browser) {
	const ctx = await context(browser);
	const page = await ctx.newPage();
	for (const width of [1440, 1024, 768, 641, 640, 600, 390, 320]) {
		await page.setViewportSize({width, height: 900});
		for (const [hall, query] of [[main, `?bc_session=${main.session}`], [three, '?bc_month=2026-02-01']]) {
			await page.goto(`http://${hall.host}/${query}`);
			await page.evaluate(() => document.fonts.ready);
			const metrics = await page.evaluate(() => {
				const card = document.querySelector('.calendar-card').getBoundingClientRect();
				const table = document.querySelector('[data-calendar-month]:not([hidden]) table').getBoundingClientRect();
				const label = document.querySelector('[data-calendar-month]:not([hidden]) .calendar-nav__label');
				const cards = [...document.querySelectorAll('.session-package')].map(item => item.getBoundingClientRect());
				const grid = document.querySelector('.bc-session-packages__grid');
				const style = grid && getComputedStyle(grid);
				// What the base rule, repeat(auto-fill, minmax(min(100%, 260px), 1fr)), yields for this width.
				const content = grid ? grid.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight) : 0;
				const gap = grid ? parseFloat(style.columnGap) || 0 : 0;
				return {
					overflow: document.documentElement.scrollWidth - window.innerWidth,
					day: getComputedStyle(document.querySelector('.calendar-day__num')).width,
					fits: table.right <= card.right + 0.5 && table.left >= card.left - 0.5,
					label: label ? getComputedStyle(label).display : null,
					columns: new Set(cards.map(rect => Math.round(rect.left))).size,
					inside: cards.every(rect => rect.left >= 0 && rect.right <= window.innerWidth + 0.5),
					cards: cards.length,
					tracks: grid ? style.gridTemplateColumns.trim().split(/\s+/).length : 0,
					autoFill: grid ? Math.max(1, Math.floor((content + gap) / (Math.min(content, 260) + gap))) : 0,
				};
			});
			const name = hall === main ? 'Packages view' : 'February view';
			check(metrics.overflow <= 0, `${width}px ${name}: no horizontal overflow`);
			check(metrics.fits, `${width}px ${name}: the month grid fits its card`);
			check((width <= 640 ? '30px' : '38px') === metrics.day, `${width}px ${name}: reference day size ${metrics.day}`);
			if (hall === three) check((width <= 640) === ('none' === metrics.label), `${width}px: month names beside the arrows ${width <= 640 ? 'hidden' : 'shown'}`);
			if (hall === main) {
				check(metrics.inside, `${width}px: Package cards stay inside the viewport`);
				check(metrics.cards >= 2, `${width}px: at least two Package cards rendered (${metrics.cards})`);
				if (width <= 640) {
					check(1 === metrics.tracks && 1 === metrics.columns, `${width}px: Package cards stack in one column (auto-fill alone would give ${metrics.autoFill})`);
				} else {
					check(metrics.tracks === metrics.autoFill && metrics.columns === Math.min(metrics.autoFill, metrics.cards), `${width}px: Package grid keeps its auto-fill layout (${metrics.tracks} tracks, ${metrics.columns} columns)`);
					if (width >= 1024) check(metrics.columns >= 2, `${width}px: Package cards sit side by side`);
				}
			}
			if ([1440, 390].includes(width)) {
				// Decode lazy images first so the full-page capture shows them.
				await page.locator('main img').evaluateAll(images => Promise.all(images.map(img => { img.loading = 'eager'; return img.decode().catch(() => null); })));
				await page.screenshot({path: path.join(config.artifacts, `${hall === main ? 'packages' : 'february'}-${width}.png`), fullPage: true});
			}
		}
	}
	await ctx.close();
}

(async () => {
	const browser = await chromium.launch({headless: true, ...(process.env.BC_CHROME_EXECUTABLE ? {executablePath: process.env.BC_CHROME_EXECUTABLE} : {})});
	try {
		await noScript(browser);
		await withScript(browser);
		await monthOnly(browser);
		await deviceClocks(browser);
		await responsive(browser);
	} finally {
		await browser.close();
	}
	console.log(`Browser session calendar: ${passes} passed, 0 failed (${documents} live CGI homepage requests).`);
})().catch(error => {
	console.error(error.stack || String(error));
	console.log(`Browser session calendar: ${passes} passed, 1 failed.`);
	process.exit(1);
});
