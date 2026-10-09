'use strict';
/**
 * Browser stage of tests/customer-ticket-wait.php: the production customer-ticket.js on real Order Received and
 * My Account order pages of a disposable Hall. Every page, form post and status check is one real php-cgi request
 * through tests/customer-ticket-wait-request.php (theme, plugin and WordPress assets are read from disk; anything
 * else is refused), except the stated fixture responses of the failure cases. Each real status request is
 * observed inside php-cgi: whether WooCommerce started a session or loaded a cart, and every write statement.
 * Server state changes between steps through tests/customer-ticket-wait-control.php (native payment completion
 * standing in for the webhook). Time is Playwright's fake clock, so each automatic attempt is exactly the
 * script's own 5-second timer and each timeout its own 10-second timer.
 *
 * Input: BC_TW_CONFIG (JSON written by the PHP suite), BC_PLAYWRIGHT_MODULE, BC_CHROME_EXECUTABLE.
 * Output: one JSON object {checks: [{group, label, ok, detail}], refused: [...]}. Ticket references, credentials
 * and nonces read from the page are compared here and never printed.
 */
const fs = require('node:fs');
const path = require('node:path');
const {spawn, execFile} = require('node:child_process');
const http = require('node:http');
const {chromium} = require(process.env.BC_PLAYWRIGHT_MODULE || 'playwright');

const config = JSON.parse(process.env.BC_TW_CONFIG || '{}');
const HOST = config.host;
const ROOT = config.root;
const ROUTE_URL = `http://${HOST}${config.route}`;
// A synthetic cross-origin destination for the redirect tests: a real local server (Playwright does not route a
// redirect's target), reached by host-name mapping, that permits CORS with credentials, so a CORS refusal can never
// hide a request that a followed redirect forwarded to it. It records only what arrived, never the values.
const COLLECTOR = 'bc-redirect-collector.test';
const collected = [];
const MIME = {'.css': 'text/css', '.js': 'application/javascript', '.woff2': 'font/woff2', '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.jpg': 'image/jpeg', '.gif': 'image/gif'};
const TEXT = {
	payment: 'Payment hasn’t been confirmed. If you have already submitted payment, please don’t pay again while confirmation is pending.',
	confirmed: 'Your payment is confirmed, but your Digital Bingo ticket isn’t available yet.',
	unavailable: 'Your Digital Bingo ticket can’t be checked right now. If you have already submitted payment, please don’t pay again.',
	noscript: 'Reload this page later to check for your Digital Bingo ticket.',
	checking: 'This page will check again automatically for a short time.',
	exhausted: 'Your ticket still isn’t shown. Use Check again or reload this page later. If it doesn’t appear, contact the Hall.',
	network: 'We couldn’t check for your ticket just now. Use Check again or reload this page later.',
	unexpected: 'We couldn’t check for your ticket automatically. Use Check again, or reload this page to see the latest details.',
	storage: 'Automatic checking isn’t available in this browser. Use Check again or reload this page later.',
	still: 'Your ticket still isn’t available. Use Check again later or reload this page.',
	manual: 'Use Check again or reload this page later.',
	reload: 'Reload this page later to check for your ticket.',
	denied: 'This page can no longer check for your ticket. Reload this page to check again.',
	closed: 'A Digital Bingo ticket can’t be shown for this order. Reload this page to see the latest order details.',
	ready: 'Your Digital Bingo ticket is now shown.',
	confirming: 'Thank you. We’re confirming your payment. This usually takes a few seconds.',
	received: 'Thank you. Your order has been received.',
	checkAgain: 'Check again',
	checkingNow: 'Checking…',
	heading: 'Digital Bingo ticket',
	intro: 'This QR represents the digital part of your Bingo Package only. Keep it private.',
};
const CREDENTIAL = /^[1-9][0-9]*\.[1-9][0-9]{9,10}\.[a-f0-9]{64}$/;
const refused = [];
const checks = [];
const observations = [];
const routeCookies = [];
const lingering = [];
const OBSERVE = fs.mkdtempSync('/private/tmp/bc-ticket-wait-observe.');
let observed = 0;
let group = '';
let fault = '';
let noAccount = false;
function progress(line) { if (config.shots) { fs.appendFileSync(path.join(config.shots, 'progress.log'), `${new Date().toISOString()} [${group}] ${line}\n`); } }
const check = (ok, label, detail = '') => { progress(`${ok ? 'PASS' : 'FAIL'} ${label}`); checks.push({group, label, ok: !!ok, detail: ok ? '' : (typeof detail === 'string' ? detail : JSON.stringify(detail))}); };
const same = (expected, actual, label) => check(JSON.stringify(expected) === JSON.stringify(actual), label, {expected, actual});
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

/* ---- Real requests ---- */

function cgi(url, {method = 'GET', headers = {}, body = null, observe = ''} = {}) {
	const env = {
		PATH: process.env.PATH, BC_TEST_DB: 'bingocommerce_state_recon', BC_FIXTURE_CGI: '1', BC_FIXTURE_WOOCOMMERCE: '1', BC_FIXTURE_NETWORK_ACTIVE: '1',
		BC_TW_SITE: String(config.site), BC_TW_OWNER: config.owner, BC_TW_CHECKOUT_PATH: config.checkout_path, BC_TW_ACCOUNT_PATH: config.account_path, BC_TW_FAULT: fault,
		BC_TW_NO_ACCOUNT: noAccount ? '1' : '', BC_TW_OBSERVE: observe,
		HTTP_HOST: HOST, SERVER_NAME: HOST, SERVER_PORT: '80', REQUEST_METHOD: method, REQUEST_URI: url.pathname + url.search, QUERY_STRING: url.search.replace(/^\?/, ''),
		SCRIPT_NAME: '/index.php', PHP_SELF: '/index.php', SCRIPT_FILENAME: config.worker, REDIRECT_STATUS: '1',
		CONTENT_TYPE: body ? String(headers['content-type'] || '') : '', CONTENT_LENGTH: String(body ? body.length : 0),
	};
	if (headers.cookie) { env.HTTP_COOKIE = headers.cookie; }
	if (headers['x-wp-nonce']) { env.HTTP_X_WP_NONCE = headers['x-wp-nonce']; }
	return new Promise((resolve, reject) => {
		const child = spawn(config.php_cgi, [config.worker], {env, cwd: ROOT});
		const out = []; const err = [];
		const timer = setTimeout(() => child.kill('SIGKILL'), 120000);
		child.stdout.on('data', chunk => out.push(chunk)); child.stderr.on('data', chunk => err.push(chunk));
		child.on('close', code => {
			clearTimeout(timer);
			if (0 !== code) { return reject(new Error(`CGI ${method} ${url.pathname} exited ${code}: ${Buffer.concat(err).toString().slice(0, 300)}`)); }
			const output = Buffer.concat(out); let split = output.indexOf('\r\n\r\n'); let gap = 4;
			if (split < 0) { split = output.indexOf('\n\n'); gap = 2; }
			const fields = {}; let status = 200;
			for (const line of output.subarray(0, split).toString('latin1').split(/\r?\n/)) {
				const at = line.indexOf(':'); if (at < 1) { continue; }
				const name = line.slice(0, at).trim().toLowerCase(); const value = line.slice(at + 1).trim();
				if ('status' === name) { status = Number(value.slice(0, 3)); } else { fields[name] = value; }
			}
			resolve({status, headers: fields, body: output.subarray(split + gap)});
		});
		child.stdin.end(body || undefined);
	});
}

async function router(route) {
	const request = route.request(); const url = new URL(request.url()); const method = request.method();
	if ('http:' !== url.protocol || url.hostname !== HOST) { return route.abort(); }
	if ('GET' === method && ['/wp-content/themes/', '/wp-content/plugins/', '/wp-includes/'].some(prefix => url.pathname.startsWith(prefix))) {
		const file = path.resolve(ROOT, '.' + decodeURIComponent(url.pathname)); const type = MIME[path.extname(file)];
		if (!type || !file.startsWith(ROOT + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { return route.abort(); }
		return route.fulfill({contentType: type, body: fs.readFileSync(file)});
	}
	if (!['document', 'fetch'].includes(request.resourceType())) { return route.abort(); }
	// Every page, form post and status request goes to php-cgi; the worker serves only its allowed shapes.
	const status = 'POST' === method && url.href === ROUTE_URL;
	const observe = status ? path.join(OBSERVE, `${++observed}.json`) : '';
	try {
		const headers = await request.allHeaders();
		if (status) { routeCookies.push((headers.cookie || '').split(/;\s*/).filter(Boolean).map(pair => pair.split('=')[0])); }
		const response = await cgi(url, {method, headers, body: 'POST' === method ? request.postDataBuffer() : null, observe});
		if (status) {
			let seen = null;
			try { seen = JSON.parse(fs.readFileSync(observe, 'utf8')); } catch (error) { seen = null; }
			fs.rmSync(observe, {force: true});
			observations.push({status: response.status, seen});
		}
		return route.fulfill({status: response.status, headers: response.headers, body: response.body});
	} catch (error) { refused.push(error.message); return route.abort('failed'); }
}

function control(action, id, extra = '') {
	return new Promise((resolve, reject) => {
		const args = [config.control, action, String(config.site), String(id)].concat(extra ? [extra] : []);
		execFile(config.php, args, {env: {PATH: process.env.PATH, HOME: process.env.HOME, BC_TEST_DB: 'bingocommerce_state_recon', BC_TW_OWNER: config.owner}, cwd: ROOT, timeout: 120000}, (error, stdout, stderr) => (error ? reject(new Error(stderr || error.message)) : resolve(stdout.trim())));
	});
}
const lastJson = output => JSON.parse(output.split('\n').pop());
const counts = async id => lastJson(await control('counts', id));
/** The owner's session cart (by user ID), or a guest session's: quantity, raw-value hash and expiry. */
const cart = async (id, guest = '') => lastJson(await control('cart', id, guest));
const seedCart = async id => lastJson(await control('seed-cart', id));

/* ---- Page helpers ---- */

// Records each distinct live-region text the customer's screen reader would be given, and the fake-clock start,
// duration and overlap of every fetch the page makes.
const RECORDER = () => {
	const original = window.fetch;
	window.__fetch = {starts: [], durations: [], active: 0, max: 0};
	window.fetch = function () {
		const f = window.__fetch, start = Date.now();
		f.starts.push(start); f.active++; f.max = Math.max(f.max, f.active);
		return original.apply(this, arguments).finally(() => { f.active--; f.durations.push(Date.now() - start); });
	};
	window.__announced = [];
	new MutationObserver(() => {
		document.querySelectorAll('section.bc-digital-ticket p.screen-reader-text[role="status"]').forEach(node => {
			const value = node.textContent;
			if (value && value !== node.__recorded) { node.__recorded = value; window.__announced.push(value); }
			if (!value) { node.__recorded = ''; }
		});
	}).observe(document, {subtree: true, childList: true, characterData: true});
};

async function context(browser, {cookie = true, js = true, viewport = {width: 1280, height: 900}} = {}) {
	const ctx = await browser.newContext({viewport, javaScriptEnabled: js});
	if (cookie) { await ctx.addCookies([{name: config.cookie_name, value: config.cookie_value, domain: HOST, path: '/'}]); }
	await ctx.route('**/*', router);
	if (js) { await ctx.addInitScript(RECORDER); }
	return ctx;
}

/** A tab with request tracking (its own sessionStorage) and, with JavaScript, a paused fake clock. */
async function tab(ctx, {js = true, init = null, arg = null} = {}) {
	const page = await ctx.newPage();
	const t = {page, fetches: [], posts: [], statuses: [], urls: [], secrets: [], other: [], inflight: 0, errors: [], stacks: []};
	// Status checks are POSTs to this Hall's status route; any other fetch is recorded separately (path only).
	const isCheck = request => 'fetch' === request.resourceType() && 'POST' === request.method() && request.url() === ROUTE_URL;
	page.on('pageerror', error => { t.errors.push(error.message); t.stacks.push(String(error.stack || error.message)); });
	page.on('request', request => {
		t.urls.push(request.url());
		if (isCheck(request)) {
			const headers = request.headers();
			t.fetches.push(request.url()); t.inflight++;
			t.posts.push({url: request.url(), body: request.postData(), type: headers['content-type'] || null, nonce: headers['x-wp-nonce'] || null, referer: headers.referer || null});
		} else if ('fetch' === request.resourceType()) { t.other.push(new URL(request.url()).pathname); }
	});
	page.on('response', response => { if (isCheck(response.request())) { t.statuses.push(response.status()); } });
	const done = request => { if (isCheck(request)) { t.inflight--; } };
	page.on('requestfinished', done); page.on('requestfailed', done);
	if (init) { await page.addInitScript(init, arg); }
	if (js) {
		// Paused before the page loads, so the script's timers start from a fixed time and only runFor() moves them.
		const base = Date.now();
		await page.clock.install({time: base});
		await page.clock.pauseAt(base + 1000);
	}
	return t;
}

async function until(fn, timeout = 20000) {
	const start = Date.now();
	while (Date.now() - start < timeout) { if (await fn()) { return true; } await sleep(40); }
	return false;
}
/** The credential and nonce this page load was given (kept here for comparison, never printed). */
async function remember(t) {
	const secret = await t.page.evaluate(() => {
		const s = document.querySelector('section.bc-digital-ticket[data-bc-ticket-credential]');
		return s ? {credential: s.getAttribute('data-bc-ticket-credential'), nonce: s.getAttribute('data-bc-ticket-nonce')} : null;
	});
	if (secret) { t.secrets.push(secret); }
}
// The paused clock stays paused across navigations and reloads.
async function open(t, url) { await t.page.goto(url, {waitUntil: 'load'}); progress(`open ${new URL(url).pathname}`); await remember(t); }
async function reload(t) { await t.page.reload({waitUntil: 'load'}); progress('reload'); await remember(t); }
/** Let a finished check settle, then run the announcement timer (100 ms) without reaching the next attempt. */
async function settle(t) {
	// Every status request must have finished or been released by now; one left open is recorded and reported.
	if (!await until(() => 0 === t.inflight)) { lingering.push(group); }
	await sleep(300); await t.page.clock.runFor(200); await sleep(60);
}
/** The check the page makes as it opens; true when exactly one request started. */
async function first(t) {
	const started = await until(() => t.fetches.length >= 1, 15000);
	await settle(t);
	return started && 1 === t.fetches.length;
}
/** Advance to the next automatic attempt (2 s after the previous one finished); true when exactly one more request started. */
async function next(t, ms = 2000) {
	const before = t.fetches.length;
	await t.page.clock.runFor(ms);
	const started = await until(() => t.fetches.length > before, 15000);
	await settle(t);
	return started && t.fetches.length === before + 1;
}
/** Advance a long time and report how many requests started meanwhile. */
async function idle(t, ms = 60000) { const before = t.fetches.length; await t.page.clock.runFor(ms); await sleep(500); await settle(t); return t.fetches.length - before; }

/**
 * Every status check of this tab: a POST of exactly one of its page loads' credentials, as JSON, to the status
 * route with no query string; that load's REST nonce only when signed in; no Referer; no credential in any URL.
 */
function shaped(t, signed, label) {
	const credentials = new Set(t.secrets.map(secret => secret.credential)); const nonces = new Set(t.secrets.map(secret => secret.nonce));
	const loads = t.secrets.length > 0 && [...credentials].every(value => CREDENTIAL.test(value || '')) && (signed ? [...nonces].every(value => /^[a-f0-9]{10}$/.test(value || '')) : [...nonces].every(value => null === value));
	const bodies = t.posts.every(post => { try { const body = JSON.parse(post.body); return 1 === Object.keys(body).length && credentials.has(body.credential); } catch (error) { return false; } });
	const headers = t.posts.every(post => ROUTE_URL === post.url && 'application/json' === post.type && null === post.referer && (signed ? nonces.has(post.nonce) : null === post.nonce));
	const urls = !t.urls.some(url => [...credentials].some(value => value && url.includes(value)));
	check(t.posts.length > 0 && loads && bodies && headers && urls, label, {posts: t.posts.length, loads, bodies, headers, urls});
}

async function ui(t) {
	return t.page.evaluate(() => {
		const s = document.querySelector('section.bc-digital-ticket');
		if (!s) { return null; }
		const shown = node => !!node && !node.hidden && node.getClientRects().length > 0;
		const extra = [...s.querySelectorAll(':scope > p')].filter(p => !p.hasAttribute('data-bc-ticket-message') && !p.classList.contains('screen-reader-text'));
		const waiting = s.hasAttribute('data-bc-ticket-wait');
		const [checking, guidance, action] = waiting ? extra : [null, null, null];
		const button = s.querySelector('button'); const live = s.querySelector('p.screen-reader-text[role="status"]');
		const canvas = s.querySelector('canvas[data-bc-ticket]'); const active = document.activeElement;
		const reference = canvas ? canvas.getAttribute('data-bc-ticket') : '';
		return {
			scope: s.getAttribute('data-bc-ticket-scope'), wait: s.getAttribute('data-bc-ticket-wait'), status: s.getAttribute('data-bc-ticket-status'),
			credential: s.hasAttribute('data-bc-ticket-credential'), nonce: s.hasAttribute('data-bc-ticket-nonce'),
			message: (s.querySelector('[data-bc-ticket-message]') || {}).textContent || null,
			checking: checking ? [shown(checking), checking.textContent] : null, guidance: guidance ? [shown(guidance), guidance.textContent] : null,
			button: button ? [shown(button) && shown(action), button.textContent, button.getAttribute('aria-disabled'), button.disabled] : null,
			live: live ? [live.textContent, live.getAttribute('aria-live')] : null,
			heading: (s.querySelector('h2') || {}).textContent || null, intro: canvas ? (s.querySelector(':scope > p:not(.screen-reader-text)') || {}).textContent : null,
			canvas: canvas ? [canvas.dataset.bcRendered === 'true', /^BC1\.[1-9][0-9]*\.[a-f0-9]{64}$/.test(reference) ? reference.split('.')[1] : 'malformed', !canvas.hidden] : null,
			focus: active === document.body || !active ? 'body' : (s.contains(active) ? active.tagName.toLowerCase() : 'outside'),
			validity: (() => { const p = s.querySelector('[data-bc-ticket-validity]'); return p ? p.textContent.replace(/\s+/g, ' ').trim() : null; })(),
			// Visible loading signs in the section: their animation and whether screen readers skip them.
			spinners: [...s.querySelectorAll('.bc-ticket-spinner')].filter(shown).map(node => [getComputedStyle(node).animationName, node.getAttribute('aria-hidden')]),
			// Order Received's thank-you line as the customer sees it (hidden text excluded).
			thanks: (() => { const line = document.querySelector('.woocommerce-thankyou-order-received'); return line ? line.innerText.trim() : null; })(),
		};
	});
}
const reference = t => t.page.evaluate(() => (document.querySelector('section.bc-digital-ticket canvas[data-bc-ticket]') || {getAttribute: () => ''}).getAttribute('data-bc-ticket'));
const announced = t => t.page.evaluate(() => window.__announced.slice());
const key = id => `bc-ticket-wait:${config.site}:${id}`;
const stored = (t, id) => t.page.evaluate(name => { try { return window.sessionStorage.getItem(name); } catch (error) { return 'unavailable'; } }, key(id));
const scope = id => `${config.site}:${id}`;
/** Fake-clock time from each request's end to the next request's start. */
const gaps = timing => timing.starts.slice(1).map((value, index) => value - timing.starts[index] - timing.durations[index]);
async function qrPixels(t) {
	return t.page.$eval('section.bc-digital-ticket canvas[data-bc-ticket]', c => {
		const data = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; let black = 0, quiet = true;
		for (let y = 0; y < c.height; y++) { for (let x = 0; x < c.width; x++) { const i = (y * c.width + x) * 4; if (0 === data[i]) { black++; } if ((x < 24 || y < 24 || x >= c.width - 24 || y >= c.height - 24) && (255 !== data[i] || 255 !== data[i + 3])) { quiet = false; } } }
		return black > 1000 && quiet && c.width === c.height;
	});
}
async function shot(t, name, full = true, js = true) {
	if (!config.shots) { return; }
	// addStyleTag waits for a load event that never fires without JavaScript.
	if (js) { await t.page.addStyleTag({content: '.site-header{position:static!important}'}); }
	await t.page.screenshot({path: path.join(config.shots, `${name}.png`), fullPage: full});
	const section = t.page.locator('section.bc-digital-ticket');
	if (await section.count()) { await section.screenshot({path: path.join(config.shots, `${name}-section.png`)}); }
}
/** One fixture response for the status route of one tab. */
function respond(t, handler) { return t.page.route(target => target.href === ROUTE_URL, handler); }
const json = (body, status = 200) => route => route.fulfill({status, contentType: 'application/json; charset=UTF-8', body: 'string' === typeof body ? body : JSON.stringify(body)});
const FAKE_REF = hall => `BC1.${hall}.${'0123456789abcdef'.repeat(4)}`;
// All six automatic attempts already used in this tab: only Check again remains (set before the script runs).
const SPENT = name => { try { if (/^https?:$/.test(location.protocol)) { window.sessionStorage.setItem(name, '6'); } } catch (error) { /* about:blank */ } };
// A browser that cannot cancel a timed-out request: removed before the production script runs.
const NO_ABORT = () => { Object.defineProperty(window, 'AbortController', {configurable: true, writable: true, value: undefined}); };

async function scenario(name, fn) {
	group = name;
	try { await fn(); } catch (error) { check(false, 'scenario completed without an exception', String(error && error.stack || error).slice(0, 600)); }
}

/* ---- Scenarios ---- */

async function delayedReceived(browser) {
	const o = config.orders.delayed; const ctx = await context(browser); const t = await tab(ctx);
	const stripeReturn = `${o.received}&order_id=${o.id}&wc_payment_method=stripe&_wpnonce=fixture&wc_stripe_cs=1&pay_for_order=no`;
	await open(t, stripeReturn);
	check(await first(t), 'first check as the page opens, with no waiting');
	const first0 = await ui(t);
	same([scope(o.id), 'payment', ROUTE_URL, true, true, TEXT.payment], [first0.scope, first0.wait, first0.status, first0.credential, first0.nonce, first0.message], 'signed-in owner opened from the Stripe return URL shape: payment-pending area with the status route, a credential and a REST nonce');
	same([[true, TEXT.checking], [false, ''], [false, TEXT.checkAgain, null, false], ['', 'polite'], null], [first0.checking, first0.guidance, first0.button, first0.live, first0.canvas], 'checking note shown; no guidance or button yet; an empty polite live region; no QR');
	same(TEXT.confirming, first0.thanks, 'the thank-you line says the payment is being confirmed, not that the order is done');
	same([['bc-ticket-spin', 'true']], first0.spinners, 'a loading sign spins beside the checking note while automatic checks run (screen readers get the text instead)');
	same([[ROUTE_URL], [200], '1', []], [t.fetches, t.statuses, await stored(t, o.id), await announced(t)], 'that first check: one answered POST to the status route, attempt 1 counted; nothing announced for an unchanged state');
	same({ok: true}, await seedCart(o.id), 'fixture: the owner fills a cart in another tab after this page loaded');
	const filled = await cart(o.id);
	same(2, filled.quantity, 'fixture: that cart holds two items');
	// first() already ran 200 ms of the clock after the first check finished.
	await t.page.clock.runFor(1700); await sleep(400);
	same(1, t.fetches.length, 'no second check before two seconds');
	await t.page.clock.runFor(100); await until(() => 2 === t.fetches.length); await settle(t);
	same([[ROUTE_URL, ROUTE_URL], [200, 200], '2'], [t.fetches, t.statuses, await stored(t, o.id)], 'second check exactly two seconds after the first finished');
	same(filled, await cart(o.id), 'the automatic check left the owner’s cart from the other tab byte-identical (contents and expiry)');
	same('ok', await control('pay', o.id), 'fixture: native payment completion (the webhook’s path) creates the entitlement and ticket');
	check(await next(t), 'third check two seconds later');
	const ready = await ui(t);
	same([null, null, false, false, TEXT.heading, TEXT.intro, [true, String(config.site), true]], [ready.wait, ready.status, ready.credential, ready.nonce, ready.heading, ready.intro, ready.canvas], 'the existing ticket replaced only the waiting section: heading, text and a rendered QR for this Hall; the status route, credential and nonce are gone');
	same([null, [TEXT.ready], 'body'], [await stored(t, o.id), await announced(t), ready.focus], 'attempt counter cleared; “now shown” announced once; focus not moved');
	same(TEXT.received, ready.thanks, 'with the QR, the thank-you line returns to WooCommerce’s “Your order has been received.”');
	check(/^Valid for one Session only\. This QR covers only the digital part of your purchase: one Package \(.+\) and its add-ons for the Bingo Session “.+” on \d{2}\/\d{2}\/\d{4}\. It can’t be used for any other Session, date or Package\.$/.test(ready.validity || ''), 'the validity message appears under the swapped-in QR', ready.validity);
	const swappedValidity = ready.validity;
	same([], ready.spinners, 'no loading sign once the QR is shown');
	check(await qrPixels(t), 'swapped QR is a square black-and-white code with its quiet zone');
	const swapped = await reference(t);
	await decode(t, swapped);
	same(0, await idle(t), 'no further requests after the ticket appears');
	same(filled, await cart(o.id), 'after the checks the owner’s cart is still byte-identical');
	shaped(t, true, 'all three checks were POSTs of exactly this page’s credential (JSON body) and REST nonce to the status route, without a Referer; the credential is in no URL');
	const afterSwap = await counts(o.id);
	same([1, 1, 1, 0], [afterSwap.entitlements, afterSwap.active, afterSwap.tickets, afterSwap.markers], 'exactly one entitlement and one ticket');
	await shot(t, 'swapped-desktop');
	await reload(t);
	const server = await ui(t);
	check(null === server.wait && swapped === await reference(t) && server.canvas && server.canvas[0], 'after reload the server shows the same saved ticket that was swapped in');
	same(swappedValidity, server.validity, 'after reload the server shows the same validity message as the swapped-in QR');
	same(afterSwap, await counts(o.id), 'swap and reload left the entitlement and ticket unchanged (same token hash)');
	same([], t.errors, 'no page errors');
	await ctx.close();
}

async function decode(t, expected) {
	const swift = '/usr/bin/swift';
	if (!fs.existsSync(swift)) { check(true, 'independent QR decode skipped (no swift)'); return; }
	const dir = fs.mkdtempSync('/private/tmp/bc-qr-test.');
	try {
		await t.page.locator('section.bc-digital-ticket canvas[data-bc-ticket]').screenshot({path: path.join(dir, 'qr.png')});
		fs.writeFileSync(path.join(dir, 'reference.txt'), expected, {mode: 0o600});
		const result = await new Promise(resolve => execFile(swift, [path.join(ROOT, 'tests/qr-decode.swift'), path.join(dir, 'qr.png'), path.join(dir, 'reference.txt')], {timeout: 180000}, (error, stdout, stderr) => resolve({error, stdout: String(stdout), stderr: String(stderr)})));
		check(!result.error && result.stdout.includes('QR independent decode: 1 passed, 0 failed'), 'Vision independently decodes the swapped QR to the exact saved reference', (result.stderr || result.stdout).slice(0, 300));
	} finally { fs.rmSync(dir, {recursive: true, force: true}); }
}

async function stateChangeAccount(browser) {
	const o = config.orders.state; const ctx = await context(browser); const t = await tab(ctx);
	await open(t, o.view);
	check(await first(t), 'first check as the page opens');
	const first0 = await ui(t);
	same([scope(o.id), 'payment', ROUTE_URL, true, true, TEXT.payment, null], [first0.scope, first0.wait, first0.status, first0.credential, first0.nonce, first0.message, first0.thanks], 'My Account view: payment-pending area with the status route (no thank-you line on this page)');
	same('ok', await control('pay-without-ticket', o.id), 'fixture: payment completed while the ticket insert fails');
	check(await next(t), 'second automatic check');
	const changed = await ui(t);
	same(['ticket', TEXT.confirmed, [true, TEXT.checking], [TEXT.confirmed]], [changed.wait, changed.message, changed.checking, await announced(t)], 'payment confirmed: message changed in place and announced once; checks continue');
	check(await next(t), 'third automatic check');
	same([TEXT.confirmed], await announced(t), 'an unchanged state is not announced again');
	same('ok', await control('redeliver', o.id), 'fixture: the payment event is delivered again and issues the missing ticket');
	check(await next(t), 'fourth automatic check');
	const ready = await ui(t);
	same([null, [true, String(config.site), true], [TEXT.confirmed, TEXT.ready], null], [ready.wait, ready.canvas, await announced(t), await stored(t, o.id)], 'ticket shown in place on My Account; announced; counter cleared');
	same([[ROUTE_URL, ROUTE_URL, ROUTE_URL, ROUTE_URL], [200, 200, 200, 200]], [t.fetches, t.statuses], 'all four checks were answered POSTs to the status route');
	shaped(t, true, 'each carried this page’s credential and nonce only in the body and header');
	same(0, await idle(t), 'checking stopped');
	const c = await counts(o.id);
	same([1, 1, 1], [c.entitlements, c.active, c.tickets], 'exactly one entitlement and one ticket');
	same([], t.errors, 'no page errors');
	await ctx.close();
}

async function budget(browser) {
	const o = config.orders.budget; const ctx = await context(browser); const t = await tab(ctx);
	await open(t, o.received);
	for (let attempt = 1; attempt <= 6; attempt++) {
		check(await (1 === attempt ? first(t) : next(t)), `automatic attempt ${attempt} of 6${1 === attempt ? ', as the page opens' : ''}`);
		same(String(attempt), await stored(t, o.id), `counter is ${attempt} after attempt ${attempt}`);
	}
	const timing = await t.page.evaluate(() => window.__fetch);
	same([[2000, 2000, 2000, 2000, 2000], 1], [gaps(timing), timing.max], 'after the first attempt (made as the page opened, before any timer ran), each next one started exactly two seconds after the previous one finished, one at a time');
	const exhausted = await ui(t);
	same([[false, TEXT.checking], [true, TEXT.exhausted], [true, TEXT.checkAgain, null, false]], [exhausted.checking, exhausted.guidance, exhausted.button], 'after six attempts: checking note hidden, exhausted guidance and Check again shown');
	same([TEXT.exhausted], await announced(t), 'the stop is announced once');
	same([], exhausted.spinners, 'no loading sign once automatic checks stop');
	same(0, await idle(t), 'no seventh automatic request');
	await reload(t);
	const reloaded = await ui(t);
	same([[false, TEXT.checking], [true, TEXT.exhausted], [true, TEXT.checkAgain, null, false], []], [reloaded.checking, reloaded.guidance, reloaded.button, await announced(t)], 'after reload the six-attempt limit holds: guidance and button at once, nothing announced');
	same(0, await idle(t, 120000), 'after reload no automatic request at all');
	// Order Received empties the session cart when it loads (native), so the other tab fills it after the reload.
	same({ok: true}, await seedCart(o.id), 'fixture: the owner fills a cart in another tab after the reload');
	const filled = await cart(o.id);
	same(2, filled.quantity, 'fixture: that cart holds two items');
	// One manual check at a time; overlapping clicks and keys do nothing; focus stays on the button.
	let release; const held = new Promise(resolve => { release = resolve; });
	const target = url => url.href === ROUTE_URL;
	await t.page.route(target, async route => { await held; await route.fallback(); });
	await t.page.click('section.bc-digital-ticket button');
	await until(() => 7 === t.fetches.length);
	const busy = await ui(t);
	same([TEXT.checkingNow, 'true', false, 'button'], [busy.button[1], busy.button[2], busy.button[3], busy.focus], 'during a manual check: “Checking…”, aria-disabled (not disabled), focus kept on the button');
	same([['bc-ticket-spin', 'true']], busy.spinners, 'during a manual check: a loading sign beside Check again');
	// A browser still delivers clicks to an aria-disabled button; force skips Playwright's own wait for "enabled".
	await t.page.click('section.bc-digital-ticket button', {force: true}); await t.page.click('section.bc-digital-ticket button', {force: true}); await t.page.keyboard.press('Enter'); await t.page.keyboard.press('Space');
	await sleep(400);
	same(7, t.fetches.length, 'overlapping clicks and keys start no second request');
	release(); await settle(t); await t.page.unroute(target);
	const still = await ui(t);
	same([[true, TEXT.still], [true, TEXT.checkAgain, null, false], 'button', '6', []], [still.guidance, still.button, still.focus, await stored(t, o.id), still.spinners], 'manual result: “still” guidance, button restored, focus still on it, counter unchanged, loading sign gone');
	same([TEXT.still], await announced(t), 'the manual result is announced');
	same(filled, await cart(o.id), 'the manual check left the owner’s cart from the other tab byte-identical (contents and expiry)');
	same(0, await idle(t), 'a manual check does not restart automatic checks');
	same('ok', await control('pay', o.id), 'fixture: native payment completion');
	await t.page.keyboard.press('Enter');
	await until(() => 8 === t.fetches.length); await settle(t);
	const ready = await ui(t);
	same([null, [true, String(config.site), true], 'h2', null], [ready.wait, ready.canvas, ready.focus, await stored(t, o.id)], 'keyboard Check again shows the ticket; focus moves to its heading because it was inside the replaced section');
	same([TEXT.still, TEXT.ready], await announced(t), '“now shown” announced');
	same(filled, await cart(o.id), 'after both manual checks the owner’s cart is still byte-identical');
	same([ROUTE_URL], [...new Set(t.fetches)], 'every check went to the status route');
	shaped(t, true, 'every check, before and after the reload, carried that load’s credential and nonce in the body and header, without a Referer');
	const c = await counts(o.id);
	same([1, 1], [c.entitlements, c.tickets], 'exactly one entitlement and one ticket');
	same([], t.errors, 'no page errors');
	await ctx.close();
}

async function storageBlocked(browser) {
	const o = config.orders.storage; const ctx = await context(browser);
	const t = await tab(ctx, {init: () => { Object.defineProperty(window, 'sessionStorage', {configurable: true, get() { throw new DOMException('Blocked', 'SecurityError'); }}); }});
	await open(t, o.received);
	const initial = await ui(t);
	same([[false, TEXT.checking], [true, TEXT.storage], [true, TEXT.checkAgain, null, false]], [initial.checking, initial.guidance, initial.button], 'storage unavailable: no automatic checks, storage guidance and Check again');
	same(0, await idle(t, 120000), 'no automatic request without storage');
	await t.page.click('section.bc-digital-ticket button'); await until(() => 1 === t.fetches.length); await settle(t);
	same([1, [true, TEXT.still]], [t.fetches.length, (await ui(t)).guidance], 'Check again still makes exactly one request');
	same(0, await idle(t), 'and does not start automatic checks');
	same([], t.errors, 'no page errors');
	await ctx.close();
}

async function noJavaScript(browser) {
	const o = config.orders.nojs; const ctx = await context(browser, {js: false, viewport: {width: 375, height: 812}});
	for (const [label, url] of [['Order Received', o.received], ['My Account view', o.view]]) {
		const t = await tab(ctx, {js: false});
		await t.page.goto(url, {waitUntil: 'load'});
		const state = await t.page.evaluate(() => {
			const s = document.querySelector('section.bc-digital-ticket');
			const note = s && s.querySelector('noscript p');
			return s && [s.getAttribute('data-bc-ticket-wait'), (s.querySelector('[data-bc-ticket-message]') || {}).textContent, s.querySelectorAll('button, p.screen-reader-text').length, note ? note.textContent : null];
		});
		same(['payment', TEXT.payment, 0, TEXT.noscript], state, `${label} without JavaScript: the message and the reload guidance, no button`);
		check(await t.page.isVisible('section.bc-digital-ticket noscript p'), `${label} without JavaScript: the reload guidance is visible`);
		same(0, t.fetches.length, `${label} without JavaScript: no request`);
		if ('Order Received' === label) { await shot(t, 'nojs-mobile', true, false); }
		await t.page.close();
	}
	await ctx.close();
}

async function faults(browser) {
	const o = config.orders.faults; const ctx = await context(browser);
	const stopped = async (t, reason, label, offer = true) => {
		const s = await ui(t);
		same([[false, TEXT.checking], [true, TEXT[reason]], [offer, TEXT.checkAgain, null, false]], [s.checking, s.guidance, s.button], `${label}: ${reason} guidance${offer ? ' with Check again' : ', without Check again'}`);
		check((await announced(t)).some(value => value.includes(TEXT[reason])), `${label}: announced`);
		return s;
	};

	// Timeouts: each attempt is abandoned exactly ten seconds after it starts; never two requests at once.
	let t = await tab(ctx);
	await respond(t, () => {});
	await open(t, o.received);
	for (let attempt = 1; attempt <= 6; attempt++) {
		if (attempt > 1) { await t.page.clock.runFor(2000); }
		await until(() => attempt === t.fetches.length);
		const {start, now} = await t.page.evaluate(() => ({start: window.__fetch.starts[window.__fetch.starts.length - 1], now: Date.now()}));
		await t.page.clock.runFor(start + 9999 - now); await sleep(300);
		check(attempt === t.fetches.length && 1 === t.inflight, `timeout attempt ${attempt}: still in flight 9.999 seconds after it started, none started beside it`, {checks: t.fetches.length, inflight: t.inflight, other: t.other});
		same(String(attempt), await stored(t, o.id), `timeout attempt ${attempt}: already counted while its request is in flight`);
		await t.page.clock.runFor(1); await until(() => 0 === t.inflight); await settle(t);
		check(String(attempt) === await stored(t, o.id), `timeout attempt ${attempt}: abandoned at ten seconds and counted`);
	}
	const timing = await t.page.evaluate(() => window.__fetch);
	same([[10000, 10000, 10000, 10000, 10000, 10000], 1], [timing.durations, timing.max], 'each timed-out attempt was abandoned exactly 10 s after it started; never two requests at once');
	same([2000, 2000, 2000, 2000, 2000], gaps(timing), 'each next attempt started exactly two seconds after the previous one was abandoned');
	await stopped(t, 'network', 'six timed-out attempts');
	same(0, await idle(t), 'no request after the sixth timeout');
	await t.page.close();

	for (const [label, handler] of [
		['HTTP 500 on every attempt', json({code: 'internal_server_error'}, 500)],
		['network failure on every attempt', route => route.abort('failed')],
	]) {
		t = await tab(ctx); await respond(t, handler); await open(t, o.received);
		for (let attempt = 1; attempt <= 6; attempt++) { check(await (1 === attempt ? first(t) : next(t)), `${label}: attempt ${attempt}`); }
		await stopped(t, 'network', label);
		same('payment', (await ui(t)).wait, `${label}: the order is not treated as changed`);
		await t.page.close();
	}

	// Refused: retrying the same request cannot succeed, so the page stops and offers only a reload.
	for (const [label, status] of [['HTTP 403 (refused credential)', 403], ['HTTP 401', 401], ['HTTP 400 (invalid request)', 400]]) {
		t = await tab(ctx);
		await respond(t, json({code: 'bc_ticket_status_unavailable', message: 'Ticket status isn’t available.', data: {status}}, status));
		await open(t, o.received);
		check(await first(t), `${label}: one automatic attempt`);
		const s = await stopped(t, 'denied', label, false);
		same(['payment', TEXT.payment, null, false, false, null], [s.wait, s.message, s.status, s.credential, s.nonce, s.canvas], `${label}: the order is not treated as changed; the page drops its status route, credential and nonce`);
		same(0, await idle(t), `${label}: no further request`);
		if (403 === status) { await shot(t, 'denied-desktop'); }
		await t.page.close();
	}

	const hall = Number(config.site);
	for (const [label, handler] of [
		['a login page (HTML)', route => route.fulfill({status: 200, contentType: 'text/html; charset=UTF-8', body: '<!doctype html><form class="woocommerce-form woocommerce-form-login login" method="post"><input name="username"><button name="login">Log in</button></form>'})],
		['malformed JSON', json('{"scope":')],
		['a JSON list', json([])],
		['another order', json({scope: `${hall}:${o.id + 100000}`, state: 'payment'})],
		['the same order ID in another Hall', json({scope: `${hall + 1}:${o.id}`, state: 'payment'})],
		['no state', json({scope: scope(o.id)})],
		['an unknown state', json({scope: scope(o.id), state: 'shipped'})],
		['ready without a reference', json({scope: scope(o.id), state: 'ready'})],
		['ready with a reference naming another Hall', json({scope: scope(o.id), state: 'ready', reference: FAKE_REF(hall + 1)})],
		['ready with markup around the reference', json({scope: scope(o.id), state: 'ready', reference: `<img src=x onerror="window.__pwned=1">${FAKE_REF(hall)}`})],
	]) {
		t = await tab(ctx); await respond(t, handler); await open(t, o.received);
		check(await first(t), `${label}: one automatic attempt`);
		const s = await stopped(t, 'unexpected', label);
		same(['payment', TEXT.payment, null, 'undefined'], [s.wait, s.message, s.canvas, await t.page.evaluate(() => typeof window.__pwned)], `${label}: not used; the order is not treated as changed; nothing ran`);
		same(0, await idle(t), `${label}: automatic checks stop`);
		await t.page.close();
	}

	// The route reports that the ticket cannot be checked right now: say so, stop, keep Check again.
	t = await tab(ctx); await respond(t, json({scope: scope(o.id), state: 'unavailable'})); await open(t, o.received);
	check(await first(t), 'unavailable answer: one automatic attempt');
	let s = await ui(t);
	same(['unavailable', TEXT.unavailable, [false, TEXT.checking], [true, TEXT.manual], [true, TEXT.checkAgain, null, false]], [s.wait, s.message, s.checking, s.guidance, s.button], 'unavailable answer: the neutral unavailable message, manual guidance and Check again; never “preparing”');
	same([`${TEXT.unavailable} ${TEXT.manual}`], await announced(t), 'unavailable answer: the changed message and the guidance are announced together');
	same(0, await idle(t), 'unavailable answer: automatic checks stop');
	await t.page.close();

	// A closed answer to a keyboard Check again: the button goes away and focus stays in the section.
	t = await tab(ctx, {init: SPENT, arg: key(o.id)}); await open(t, o.received); await respond(t, json({scope: scope(o.id), state: 'closed'}));
	await t.page.focus('section.bc-digital-ticket button'); await t.page.keyboard.press('Enter');
	await until(() => 1 === t.fetches.length); await settle(t);
	s = await ui(t);
	same(['closed', TEXT.closed, [false, TEXT.exhausted], [false, TEXT.checkAgain, null, false], null, false, 'h2'], [s.wait, s.message, s.guidance, s.button, s.status, s.credential, s.focus], 'closed answer to a keyboard check: the closed message, no guidance or button, credential dropped, focus moved to the section heading (not lost to the page)');
	same([TEXT.closed], await announced(t), 'closed answer: announced once');
	same(TEXT.received, s.thanks, 'closed answer: the thank-you line is WooCommerce’s own again, as a reload would show it');
	await t.page.close();

	// A valid answer for this Hall and order: only the validated reference is used; extra fields never reach the page.
	t = await tab(ctx);
	await respond(t, json({scope: scope(o.id), state: 'ready', reference: FAKE_REF(hall), heading: '<img src=x onerror="window.__pwned=2">', html: '<script>window.__pwned=3</script>'}));
	await open(t, o.received);
	check(await first(t), 'ready answer with extra fields: one automatic attempt');
	const injected = await t.page.evaluate(() => {
		const section = document.querySelector('section.bc-digital-ticket');
		return [typeof window.__pwned, section.querySelectorAll('script, img').length, [...section.querySelectorAll('*')].filter(n => [...n.attributes].some(a => a.name.startsWith('on'))).length, section.hasAttribute('data-bc-ticket-wait'), (section.querySelector('h2') || {}).textContent];
	});
	same(['undefined', 0, 0, false, TEXT.heading], injected, 'extra fields in an answer never run or reach the page; the heading is the page’s own');
	same([true, String(hall), true], (await ui(t)).canvas, 'only the validated reference became a freshly drawn QR');
	await t.page.close();
	same([], refused, 'fault cases needed no refused request');
	await ctx.close();
}

async function hiddenTab(browser) {
	const o = config.orders.hidden; const ctx = await context(browser);
	const t = await tab(ctx, {init: () => {
		let hidden = true;
		Object.defineProperty(document, 'hidden', {configurable: true, get: () => hidden});
		Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => (hidden ? 'hidden' : 'visible')});
		window.__setHidden = value => { hidden = value; document.dispatchEvent(new Event('visibilitychange')); };
	}});
	await open(t, o.received);
	same([0, null], [await idle(t), await stored(t, o.id)], 'page opened in a hidden tab: no check starts and no attempt is used');
	await t.page.evaluate(() => window.__setHidden(false));
	await until(() => 1 === t.fetches.length); await settle(t);
	same([1, '1'], [t.fetches.length, await stored(t, o.id)], 'visible again: the overdue check runs at once');
	await t.page.evaluate(() => window.__setHidden(true));
	same(0, await idle(t, 20000), 'hidden again: paused');
	await t.page.evaluate(() => window.__setHidden(false));
	await until(() => 2 === t.fetches.length); await settle(t);
	same([2, '2'], [t.fetches.length, await stored(t, o.id)], 'visible again: the overdue check runs once');
	same([], t.errors, 'no page errors');
	await ctx.close();
}

async function focusAndScroll(browser) {
	// Focus elsewhere on the page is never moved; a visible section does not scroll the page.
	let o = config.orders.focus; let ctx = await context(browser); let t = await tab(ctx);
	await open(t, o.received);
	check(await first(t), 'first check as the page opens');
	await t.page.evaluate(() => { const link = document.querySelector('.woocommerce-table--order-details a'); link.setAttribute('data-test-focus', '1'); link.focus(); });
	const scrolled = await t.page.evaluate(() => window.scrollY);
	same('ok', await control('pay', o.id), 'fixture: native payment completion');
	check(await next(t), 'automatic check');
	const result = await t.page.evaluate(() => [document.activeElement && document.activeElement.getAttribute('data-test-focus'), window.scrollY, !!document.querySelector('section.bc-digital-ticket canvas[data-bc-rendered="true"]')]);
	same(['1', scrolled, true], result, 'ticket shown; focus stays on the order link outside the section; scroll position unchanged');
	await ctx.close();
	// Scrolled past the section's start (entirely or partly above the viewport): what the customer is reading
	// below it stays still, with Chrome's scroll anchoring and with overflow-anchor: none (as in Safari).
	for (const [name, position, anchoring] of [
		['anchor', 'entirely above', true], ['anchornone', 'entirely above', false], ['anchorpart', 'partly above', true], ['anchorpartnone', 'partly above', false],
	]) {
		const label = `section ${position} the viewport, ${anchoring ? 'browser scroll anchoring on' : 'overflow-anchor: none (as in Safari)'}`;
		o = config.orders[name]; ctx = await context(browser, {viewport: {width: 375, height: 480}}); t = await tab(ctx);
		await open(t, o.received);
		check(await first(t), `${label}: first check as the page opens`);
		if (!anchoring) { await t.page.addStyleTag({content: 'html{overflow-anchor:none}'}); }
		const before = await t.page.evaluate(partly => {
			const s = document.querySelector('section.bc-digital-ticket');
			window.scrollTo(0, partly ? window.scrollY + s.getBoundingClientRect().top + 60 : document.documentElement.scrollHeight);
			const r = s.getBoundingClientRect();
			// A small element in view below the section, e.g. a line of the customer details or footer.
			const anchor = [...document.querySelectorAll('.woocommerce-customer-details *, footer *')].find(node => {
				const b = node.getBoundingClientRect();
				return b.height > 0 && b.height < 80 && b.top > Math.max(r.bottom, 0) && b.bottom < window.innerHeight;
			});
			if (anchor) { anchor.setAttribute('data-test-anchor', '1'); }
			return {sectionTop: r.top, sectionBottom: r.bottom, sectionHeight: r.height, anchorTop: anchor ? anchor.getBoundingClientRect().top : null, scrollY: window.scrollY};
		}, 'partly above' === position);
		check(('partly above' === position ? before.sectionTop < 0 && before.sectionBottom > 0 : before.sectionBottom <= 0) && null !== before.anchorTop, `${label}: arranged, with content in view below it`, before);
		same('ok', await control('pay', o.id), 'fixture: native payment completion');
		check(await next(t), `${label}: automatic check`);
		const after = await t.page.evaluate(() => ({anchorTop: document.querySelector('[data-test-anchor]').getBoundingClientRect().top, sectionHeight: document.querySelector('section.bc-digital-ticket').getBoundingClientRect().height, rendered: !!document.querySelector('section.bc-digital-ticket canvas[data-bc-rendered="true"]'), scrollY: window.scrollY}));
		check(after.rendered && after.sectionHeight > before.sectionHeight + 100, `${label}: the section grew by the QR`, {before, after});
		check(Math.abs(after.anchorTop - before.anchorTop) <= 1, `${label}: the content in view did not move`, {before, after});
		same([], t.errors, `${label}: no page errors`);
		await ctx.close();
	}
}

/** A guest's cart filled in another tab of the same browser after the order page loaded: its real session cookie. */
async function guestCart(ctx, o, label) {
	const seeded = await seedCart(o.id);
	same([true, true], [true === seeded.ok, /^t_[A-Za-z0-9]{30}$/.test(seeded.guest || '')], `fixture: ${label} fills a cart in another tab after this page loaded (a new WooCommerce guest session)`);
	await ctx.addCookies([{name: seeded.name, value: seeded.value, domain: HOST, path: '/'}]);
	const filled = await cart(o.id, seeded.guest);
	same(2, filled.quantity, 'fixture: that guest cart holds two items');
	return {name: seeded.name, guest: seeded.guest, filled};
}

async function guest(browser) {
	const o = config.orders.guest; const ctx = await context(browser, {cookie: false}); const t = await tab(ctx);
	await open(t, o.received);
	check(await first(t), 'first check as the page opens');
	const first0 = await ui(t);
	same([scope(o.id), 'payment', ROUTE_URL, true, false, TEXT.payment, TEXT.confirming], [first0.scope, first0.wait, first0.status, first0.credential, first0.nonce, first0.message, first0.thanks], 'guest with the order key: payment-pending area with the status route and a credential, no REST nonce; the thank-you line says the payment is being confirmed');
	const c = await guestCart(ctx, o, 'the guest');
	const from = routeCookies.length;
	check(await next(t), 'automatic check as the guest');
	same(c.filled, await cart(o.id, c.guest), 'the automatic check left the guest’s cart byte-identical (contents and expiry)');
	same('ok', await control('pay', o.id), 'fixture: native payment completion');
	check(await next(t), 'second automatic check');
	const shown = await ui(t);
	same([[true, String(config.site), true], TEXT.received], [shown.canvas, shown.thanks], 'the guest sees the ticket in place without reloading or signing in; the thank-you line is WooCommerce’s own again');
	same(c.filled, await cart(o.id, c.guest), 'after both checks the guest’s cart is still byte-identical');
	same([2, true], [routeCookies.length - from, routeCookies.slice(from).every(names => names.includes(c.name))], 'both checks carried the guest’s WooCommerce session cookie, as a real browser sends it');
	same(3, t.fetches.length, 'three checks in all: as the page opened, then two seconds apart');
	shaped(t, false, 'the guest’s checks were POSTs of exactly this page’s credential to the status route, with no nonce and no Referer; the credential is in no URL');
	check(await qrPixels(t), 'the guest’s QR is a complete square code with its quiet zone');
	await decode(t, await reference(t));
	same([], t.errors, 'no page errors');
	await ctx.close();
}

async function guestManual(browser) {
	const o = config.orders.guestmanual; const ctx = await context(browser, {cookie: false}); const t = await tab(ctx, {init: SPENT, arg: key(o.id)});
	await open(t, o.received);
	const initial = await ui(t);
	same(['payment', [false, TEXT.checking], [true, TEXT.exhausted], [true, TEXT.checkAgain, null, false], false], [initial.wait, initial.checking, initial.guidance, initial.button, initial.nonce], 'guest whose six automatic attempts are used: exhausted guidance and Check again');
	const c = await guestCart(ctx, o, 'the guest');
	await t.page.focus('section.bc-digital-ticket button'); await t.page.keyboard.press('Enter');
	await until(() => 1 === t.fetches.length); await settle(t);
	const still = await ui(t);
	same([[true, TEXT.still], 'button', [200]], [still.guidance, still.focus, t.statuses], 'keyboard Check again as the guest: still waiting, focus kept on Check again');
	same(c.filled, await cart(o.id, c.guest), 'the manual check left the guest’s cart byte-identical');
	same('ok', await control('pay', o.id), 'fixture: native payment completion');
	await t.page.keyboard.press('Enter');
	await until(() => 2 === t.fetches.length); await settle(t);
	const ready = await ui(t);
	same([null, [true, String(config.site), true], 'h2', [TEXT.still, TEXT.ready]], [ready.wait, ready.canvas, ready.focus, await announced(t)], 'second keyboard Check again shows the ticket; focus moves to its heading; announced');
	same(c.filled, await cart(o.id, c.guest), 'after both manual checks the guest’s cart is still byte-identical');
	shaped(t, false, 'manual checks were POSTs of exactly this page’s credential, with no nonce and no Referer');
	same(0, await idle(t), 'no automatic checks start');
	same([], t.errors, 'no page errors');
	await ctx.close();
}

async function verifiedGuest(browser) {
	const o = config.orders.verified; const ctx = await context(browser, {cookie: false}); const t = await tab(ctx);
	same('ok', await control('age', o.id), 'fixture: the guest returns after WooCommerce’s guest grace period');
	await open(t, o.received);
	same([null, true], [await ui(t), await t.page.isVisible('form.woocommerce-verify-email')], 'WooCommerce asks the guest to verify the order email; no ticket area and no credential yet');
	await t.page.fill('form.woocommerce-verify-email input[name="email"]', config.email);
	await Promise.all([t.page.waitForNavigation({waitUntil: 'load'}), t.page.click('form.woocommerce-verify-email button[name="verify"]')]);
	await remember(t);
	const verified = await ui(t);
	same([scope(o.id), 'payment', ROUTE_URL, true, false], [verified.scope, verified.wait, verified.status, verified.credential, verified.nonce], 'after WooCommerce’s own email form (a real POST): the waiting area with a credential and no nonce');
	check(await first(t), 'first check as that page opens, without the form, a cookie or a session');
	same(['payment', [200]], [(await ui(t)).wait, t.statuses], 'answered: payment still pending');
	same('ok', await control('pay', o.id), 'fixture: native payment completion');
	check(await next(t), 'second automatic check');
	same([true, String(config.site), true], (await ui(t)).canvas, 'the verified guest sees the ticket in place without verifying again');
	shaped(t, false, 'the verified guest’s checks were POSTs of exactly this page’s credential, with no nonce and no Referer');
	same([], t.errors, 'no page errors');
	await ctx.close();
}

async function noAccountPage(browser) {
	const o = config.orders.noaccount;
	noAccount = true;
	try {
		const ctx = await context(browser); const t = await tab(ctx);
		await open(t, o.received);
		check(await first(t), 'first check as the page opens');
		const first0 = await ui(t);
		same([scope(o.id), 'payment', ROUTE_URL, true, true], [first0.scope, first0.wait, first0.status, first0.credential, first0.nonce], 'signed-in owner in a Hall without a My Account page: the waiting area with the status route');
		same('ok', await control('pay', o.id), 'fixture: native payment completion');
		check(await next(t), 'second automatic check');
		same([[true, String(config.site), true], [200, 200]], [(await ui(t)).canvas, t.statuses], 'the ticket appears in place');
		shaped(t, true, 'checks were POSTs of this page’s credential and nonce to the status route');
		same([], t.errors, 'no page errors');
		await ctx.close();
	} finally { noAccount = false; }
}

async function tampered(browser) {
	const o = config.orders.tampered; const ctx = await context(browser);
	// All automatic attempts used, and the page's credential altered by one character before the script reads it.
	const t = await tab(ctx, {arg: key(o.id), init: name => {
		try { if (/^https?:$/.test(location.protocol)) { window.sessionStorage.setItem(name, '6'); } } catch (error) { /* about:blank */ }
		document.addEventListener('DOMContentLoaded', () => {
			const s = document.querySelector('section.bc-digital-ticket[data-bc-ticket-credential]');
			if (!s) { return; }
			const value = s.getAttribute('data-bc-ticket-credential');
			window.__tampered = value.slice(0, -1) + ('0' === value.slice(-1) ? '1' : '0');
			window.__changed = window.__tampered !== value;
			s.setAttribute('data-bc-ticket-credential', window.__tampered);
		});
	}});
	await open(t, o.received);
	await t.page.focus('section.bc-digital-ticket button'); await t.page.keyboard.press('Enter');
	await until(() => 1 === t.fetches.length); await settle(t);
	const sent = await t.page.evaluate(() => [window.__changed, JSON.stringify({credential: window.__tampered})]);
	const s = await ui(t);
	same([true, true, [403]], [sent[0], t.posts.length === 1 && sent[1] === t.posts[0].body, t.statuses], 'a credential altered by one character was sent and refused by the real route (403)');
	same(['payment', TEXT.payment, [true, TEXT.denied], [false, TEXT.checkAgain, null, false], null, false, false, 'h2'], [s.wait, s.message, s.guidance, s.button, s.status, s.credential, s.nonce, s.focus], 'denied: reload guidance without Check again; nothing about the order changes; focus moved to the section heading');
	same([TEXT.denied], await announced(t), 'denied: announced once');
	same(0, await idle(t), 'denied: no further request');
	const c = await counts(o.id);
	same(['pending', 0, 0], [c.status, c.entitlements, c.tickets], 'the refused request changed nothing');
	await ctx.close();
}

async function closedOrder(browser) {
	const o = config.orders.closed; const ctx = await context(browser); const t = await tab(ctx);
	await open(t, o.received);
	check(await first(t), 'first check as the page opens');
	same('ok', await control('cancel', o.id), 'fixture: a Hall manager cancels the order while its page is waiting');
	check(await next(t), 'second automatic check');
	const s = await ui(t);
	same(['closed', TEXT.closed, [false, TEXT.checking], [false, ''], [false, TEXT.checkAgain, null, false], null, false, false, null, [200]], [s.wait, s.message, s.checking, s.guidance, s.button, s.status, s.credential, s.nonce, s.canvas, t.statuses.slice(1)], 'cancelled while waiting: the closed message only; no guidance, button or QR; the page drops its status route, credential and nonce');
	same([TEXT.closed], await announced(t), 'announced once');
	same(TEXT.received, s.thanks, 'the thank-you line no longer says the payment is being confirmed');
	same(0, await idle(t), 'no further request');
	const c = await counts(o.id);
	same(['cancelled', 0, 0], [c.status, c.entitlements, c.tickets], 'no entitlement or ticket');
	await shot(t, 'closed-desktop');
	await reload(t);
	same(null, await ui(t), 'after reload the server shows no ticket area for the cancelled order');
	same([], t.errors, 'no page errors');
	await ctx.close();
}

async function unavailable(browser) {
	const o = config.orders.unavailable; const ctx = await context(browser); const t = await tab(ctx);
	fault = 'void-read';
	try { await open(t, o.received); } finally { fault = ''; }
	const initial = await ui(t);
	same(['unavailable', TEXT.unavailable, [false, TEXT.checking], [true, TEXT.manual], [true, TEXT.checkAgain, null, false]], [initial.wait, initial.message, initial.checking, initial.guidance, initial.button], 'a failed read: neutral unavailable text, manual guidance and Check again; never “preparing”');
	same(TEXT.received, initial.thanks, 'a failed read: WooCommerce’s thank-you line stays at the top (nothing is inferred)');
	same(0, await idle(t, 120000), 'unavailable: no automatic checks');
	await t.page.click('section.bc-digital-ticket button'); await until(() => 1 === t.fetches.length); await settle(t);
	const after = await ui(t);
	same(['payment', TEXT.payment, [true, TEXT.still]], [after.wait, after.message, after.guidance], 'Check again once the read works: the payment-pending text, still waiting');
	same([TEXT.payment, TEXT.confirming], [after.message, after.thanks], 'recovered to payment pending: BOTH the ticket section and the top line say the payment is being confirmed');
	same([`${TEXT.payment} ${TEXT.still}`], await announced(t), 'the changed message and the result are announced together');
	fault = 'ticket-read';
	try { await t.page.click('section.bc-digital-ticket button'); await until(() => 2 === t.fetches.length); await settle(t); } finally { fault = ''; }
	const failing = await ui(t);
	same(['unavailable', TEXT.unavailable, [true, TEXT.manual], [200, 200]], [failing.wait, failing.message, failing.guidance, t.statuses], 'Check again while the real route’s ticket read fails: unavailable, never a guess');
	same([`${TEXT.payment} ${TEXT.still}`, `${TEXT.unavailable} ${TEXT.manual}`], await announced(t), 'announced with its guidance');
	same(TEXT.confirming, failing.thanks, 'an unavailable answer leaves the top line as it was: no payment result is inferred');
	same(0, await idle(t), 'no automatic checks start afterwards');
	same('ok', await control('pay-without-ticket', o.id), 'fixture: payment confirmed while the ticket insert fails');
	await t.page.click('section.bc-digital-ticket button'); await until(() => 3 === t.fetches.length); await settle(t);
	const confirmed = await ui(t);
	same(['ticket', TEXT.confirmed, TEXT.received], [confirmed.wait, confirmed.message, confirmed.thanks], 'Check again after confirmation: the section says payment confirmed, and the top line is WooCommerce’s again');
	same('ok', await control('redeliver', o.id), 'fixture: the payment event is delivered again and issues the missing ticket');
	await t.page.click('section.bc-digital-ticket button'); await until(() => 4 === t.fetches.length); await settle(t);
	const ready = await ui(t);
	same([null, [true, String(config.site), true], TEXT.received, []], [ready.wait, ready.canvas, ready.thanks, ready.spinners], 'Check again once issued: the saved QR in place, WooCommerce’s line at the top, no loading sign');
	check(await qrPixels(t), 'that QR is a complete square code with its quiet zone');
	await ctx.close();
}

function collector() {
	const server = http.createServer((req, res) => {
		let body = ''; req.on('data', chunk => { body += chunk; });
		req.on('end', () => {
			collected.push({method: req.method, credential: body.includes('credential'), nonce: !!req.headers['x-wp-nonce']});
			const cors = {'Access-Control-Allow-Origin': req.headers.origin || '*', 'Access-Control-Allow-Credentials': 'true', 'Access-Control-Allow-Methods': 'POST, GET, OPTIONS', 'Access-Control-Allow-Headers': 'Accept, Content-Type, X-WP-Nonce', 'Access-Control-Allow-Private-Network': 'true', 'Vary': 'Origin'};
			if ('OPTIONS' === req.method) { res.writeHead(204, cors); res.end(); return; }
			res.writeHead(200, Object.assign({'Content-Type': 'application/json'}, cors)); res.end('{}');
		});
	});
	return new Promise(resolve => server.listen(0, '127.0.0.1', () => resolve(server)));
}

/** A redirect answer from the status route is never followed: no credential leaves the Hall, no Order Received load. */
async function redirects() {
	const server = await collector();
	// Only this browser maps the collector's name to the local server and lifts Chrome's private-network block,
	// which would otherwise stop a request to 127.0.0.1 before CORS is even consulted (a public Hall has no such block).
	const browser = await chromium.launch({headless: true, executablePath: process.env.BC_CHROME_EXECUTABLE || undefined, args: [
		`--host-resolver-rules=MAP ${COLLECTOR} 127.0.0.1:${server.address().port}`,
		'--disable-features=BlockInsecurePrivateNetworkRequests,PrivateNetworkAccessSendPreflights,PrivateNetworkAccessRespectPreflightResults,LocalNetworkAccessChecks',
	]});
	try {
		// The collector is reachable with CORS permitted: a direct cross-origin POST from a Hall page arrives and succeeds.
		// (Its own context serves only a blank page on the Hall's origin; the suite's router refuses other hosts.)
		const probe = await browser.newContext();
		await probe.route(url => url.hostname === HOST, route => route.fulfill({contentType: 'text/html', body: '<!doctype html><title>Hall origin</title>'}));
		const p = {page: await probe.newPage()};
		await p.page.goto(`http://${HOST}/collector-probe`, {waitUntil: 'load'});
		const direct = await p.page.evaluate(async url => { try { const r = await fetch(url, {method: 'POST', credentials: 'include', headers: {'Content-Type': 'application/json', 'X-WP-Nonce': 'synthetic'}, body: '{"credential":"synthetic"}'}); return r.status; } catch (error) { return String(error); } }, `http://${COLLECTOR}/probe`);
		same([200, true], [direct, collected.some(entry => 'POST' === entry.method && entry.credential && entry.nonce)], 'fixture: the synthetic cross-origin collector accepts a credentialed POST under CORS, so CORS cannot mask a forwarded credential');
		await probe.close();
		await redirectCases(browser);
	} finally { await browser.close(); server.close(); }
}

async function redirectCases(browser) {
	const o = config.orders.redirect; const ctx = await context(browser);
	const away = `http://${COLLECTOR}/collect`;
	for (const [label, status, location, all] of [
		['cross-origin 307', 307, away, true], ['cross-origin 308', 308, away, false],
		['same-origin 302 to Order Received', 302, o.received, false], ['same-origin 307 to Order Received', 307, o.received, false],
	]) {
		const t = await tab(ctx); const from = collected.length;
		await respond(t, route => route.fulfill({status, headers: {location, 'cache-control': 'no-store'}, body: ''}));
		await open(t, o.received);
		check(await first(t), `${label}: one automatic attempt`);
		await sleep(500);
		const reached = collected.slice(from);
		const s = await ui(t);
		same([0, false, []], [reached.length, reached.some(entry => entry.credential || entry.nonce), t.other], `${label}: not followed; nothing reached the other address (which permits CORS) and nothing else was requested, Order Received included`);
		same(['payment', TEXT.payment, null, '1', TEXT.confirming, true], [s.wait, s.message, s.canvas, await stored(t, o.id), s.thanks, s.credential], `${label}: no payment result is inferred; the attempt counts and the page keeps its credential`);
		if (all) {
			for (let attempt = 2; attempt <= 6; attempt++) { check(await next(t), `${label}: attempt ${attempt}`); }
			const done = await ui(t);
			same([[true, TEXT.network], 'payment', TEXT.payment, 0, []], [done.guidance, done.wait, done.message, collected.length - from, t.other], `${label}: after six attempts, the network guidance only; still nothing sent elsewhere`);
		}
		await t.page.close();
	}
	same([], refused, 'redirect cases needed no refused request');
	await ctx.close();
}

/** The phone summary applies up to 640px; above it WooCommerce's own floated row is untouched. */
async function summaryWidths(browser) {
	const o = config.orders.widths;
	for (const width of [320, 375, 640, 641, 768, 1024]) {
		const ctx = await context(browser, {viewport: {width, height: 900}}); const t = await tab(ctx);
		await open(t, o.received);
		const summary = await t.page.evaluate(() => {
			const list = document.querySelector('ul.woocommerce-order-overview'); const box = list.getBoundingClientRect(); const style = getComputedStyle(list);
			const rows = [...list.children].map(li => { const r = li.getBoundingClientRect(), v = li.querySelector('strong').getBoundingClientRect(), c = getComputedStyle(li); return {left: Math.round(r.left), width: Math.round(r.width), top: Math.round(r.top), right: Math.round(r.right), valueRight: Math.round(v.right), float: c.float, separator: c.borderRightStyle}; });
			return {count: rows.length, border: style.borderTopStyle, inside: box.left >= 0 && box.right <= document.documentElement.clientWidth, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, rows};
		});
		if (width <= 640) {
			const stacked = summary.rows.every((row, i) => row.left === summary.rows[0].left && Math.abs(row.width - summary.rows[0].width) <= 1 && (0 === i || row.top > summary.rows[i - 1].top) && row.right - row.valueRight <= 17 && 'none' === row.float);
			check(summary.count >= 4 && 'solid' === summary.border && summary.inside && 0 === summary.overflow && stacked, `${width}px: one full-width row per fact (label left, value right) in a bordered box, nothing wider than the screen`, summary);
		} else {
			const native = summary.rows.every((row, i) => 'left' === row.float && (i === summary.rows.length - 1 ? 'none' === row.separator : 'dashed' === row.separator));
			check('none' === summary.border && native, `${width}px: the override stops; WooCommerce’s own floated row with dashed separators`, summary);
		}
		await ctx.close();
	}
}

async function noAbortController(browser) {
	const o = config.orders.noabort; const paid = config.orders.previewpaid; const ctx = await context(browser); const t = await tab(ctx, {init: NO_ABORT});
	await open(t, o.received);
	same('undefined', await t.page.evaluate(() => typeof window.AbortController), 'fixture: AbortController is unavailable before the production script runs');
	const s = await ui(t);
	same(['payment', TEXT.payment, [false, TEXT.checking], [true, TEXT.reload], [false, TEXT.checkAgain, null, false]], [s.wait, s.message, s.checking, s.guidance, s.button], 'without AbortController: the payment message and reload guidance; no automatic-checking note and no Check again');
	same(0, await idle(t, 120000), 'without AbortController: no status request in two minutes (longer than six attempts with their timeouts)');
	same(null, await stored(t, o.id), 'without AbortController: no automatic attempt is used');
	await t.page.evaluate(() => document.querySelector('section.bc-digital-ticket button').click()); await sleep(400);
	same([0, []], [t.fetches.length, await announced(t)], 'without AbortController: a scripted click on the hidden Check again starts no request; nothing announced');
	await open(t, paid.received);
	const ready = await ui(t);
	same([null, [true, String(config.site), true]], [ready.wait, ready.canvas], 'without AbortController: an existing ticket’s QR still renders');
	check(await qrPixels(t), 'without AbortController: that QR is a complete square code with its quiet zone');
	// Removing AbortController also breaks other page scripts that construct one; none of those errors may come from this script.
	same([0, []], [t.fetches.length, t.stacks.filter(stack => stack.includes('bingocommerce-digital-tickets/assets/customer-ticket.js'))], 'without AbortController: no request and no error from the ticket script throughout');
	check(true, `without AbortController: other page scripts reported ${t.stacks.length} error(s): ${[...new Set(t.stacks.map(stack => ((stack.split('\n').find(line => /https?:\/\//.test(line)) || '').match(/\/wp-(?:content|includes)\/[^?:)]+/) || ['unknown'])[0]))].join(', ')}`);
	await ctx.close();
}

async function excluded(browser) {
	const o = config.orders.excluded; const ctx = await context(browser); const t = await tab(ctx);
	await open(t, o.view);
	same([null, 'undefined'], [await ui(t), await t.page.evaluate(() => typeof window.bcCustomerTicket)], 'cancelled order: no ticket area and no waiting script data');
	same(0, await idle(t), 'cancelled order: no requests');
	await ctx.close();
}

async function previews(browser) {
	if (!config.shots) { check(true, 'previews skipped (no BC_TICKET_WAIT_SHOTS)'); return; }
	const o = config.orders.preview; const paid = config.orders.previewpaid;
	for (const [label, viewport] of [['desktop', {width: 1440, height: 900}], ['mobile', {width: 375, height: 812}]]) {
		const ctx = await context(browser, {viewport}); const t = await tab(ctx);
		await open(t, o.received);
		const layout = await t.page.evaluate(() => {
			const s = document.querySelector('section.bc-digital-ticket'); const message = s.querySelector('[data-bc-ticket-message]');
			const checking = s.querySelector('.bc-ticket-checking'), spinner = checking.querySelector('.bc-ticket-spinner');
			const panel = node => { const c = getComputedStyle(node); return c.backgroundColor !== 'rgba(0, 0, 0, 0)' && parseFloat(c.paddingTop) >= 12; };
			return {overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, message: panel(message), checking: panel(checking), spinner: spinner.getBoundingClientRect().width >= 24, inline: s.querySelectorAll('[style]').length};
		});
		same({overflow: 0, message: true, checking: true, spinner: true, inline: 0}, layout, `${label}: no horizontal overflow; coloured status panels and prominent loading indicator; no inline styles`);
		// The order summary (order number, date, email, total, payment method): one row per fact on phones,
		// label left and value right inside one box; WooCommerce's own single row on wider screens.
		const summary = await t.page.evaluate(() => {
			const list = document.querySelector('ul.woocommerce-order-overview'); const box = list.getBoundingClientRect();
			const rows = [...list.children].map(li => { const r = li.getBoundingClientRect(), v = li.querySelector('strong').getBoundingClientRect(); return {left: Math.round(r.left), width: Math.round(r.width), top: Math.round(r.top), valueRight: Math.round(v.right), right: Math.round(r.right), float: getComputedStyle(li).float}; });
			return {count: rows.length, box: Math.round(box.width), rows, inside: box.left >= 0 && box.right <= document.documentElement.clientWidth};
		});
		if ('mobile' === label) {
			const stacked = summary.rows.every((row, i) => row.left === summary.rows[0].left && Math.abs(row.width - summary.rows[0].width) <= 1 && (0 === i || row.top > summary.rows[i - 1].top) && row.right - row.valueRight <= 17 && 'none' === row.float);
			check(summary.count >= 4 && summary.inside && stacked, 'mobile: the order summary is one full-width row per fact (label left, value right) inside the screen', summary);
		} else {
			check(summary.rows.every(row => 'left' === row.float && row.top === summary.rows[0].top), 'desktop: the order summary keeps WooCommerce’s single row', summary);
		}
		await shot(t, `waiting-${label}`);
		await t.page.evaluate(name => window.sessionStorage.setItem(name, '6'), key(o.id));
		await reload(t);
		const button = await t.page.evaluate(() => { const b = document.querySelector('section.bc-digital-ticket button'); const r = b.getBoundingClientRect(); return [b.classList.contains('button'), r.left >= 0 && r.right <= document.documentElement.clientWidth, getComputedStyle(b).backgroundColor !== 'rgba(0, 0, 0, 0)']; });
		same([true, true, true], button, `${label}: Check again uses the theme’s WooCommerce button styling inside the viewport`);
		await shot(t, `exhausted-${label}`);
		const fallback = await tab(ctx, {init: NO_ABORT});
		await open(fallback, o.received);
		same([[false, TEXT.checking], [true, TEXT.reload], [false, TEXT.checkAgain, null, false]], [(await ui(fallback)).checking, (await ui(fallback)).guidance, (await ui(fallback)).button], `${label}: reload fallback without AbortController`);
		await shot(fallback, `reload-fallback-${label}`);
		await fallback.page.close();
		await open(t, paid.received);
		check((await ui(t)).canvas, `${label}: server-rendered ticket for comparison`);
		await shot(t, `ready-${label}`);
		await ctx.close();
	}
}

/** Every real status request, observed inside php-cgi: no WooCommerce session or cart, no write of any kind. */
function quiet() {
	group = 'Status requests';
	same([], lingering, 'no status request was left open once its answer was used (an unread refusal body included)');
	const clean = observations.filter(entry => entry.seen && null === entry.seen.session && null === entry.seen.cart && 0 === entry.seen.cart_loaded && Array.isArray(entry.seen.writes) && 0 === entry.seen.writes.length);
	const statuses = [...new Set(observations.map(entry => entry.status))].sort();
	check(observations.length >= 30 && clean.length === observations.length, `all ${observations.length} real status requests the browser made (answered ${statuses.join(', ')}) started no WooCommerce session, loaded no cart and wrote nothing`, observations.filter(entry => !clean.includes(entry)).slice(0, 3));
}

(async () => {
	const browser = await chromium.launch({headless: true, executablePath: process.env.BC_CHROME_EXECUTABLE || undefined});
	try {
		for (const [name, fn] of [
			['Delayed ticket, owner, Order Received', delayedReceived], ['Payment confirmed, then ticket, My Account', stateChangeAccount],
			['Six attempts, reload, manual check', budget], ['Storage unavailable', storageBlocked], ['AbortController unavailable', noAbortController], ['JavaScript disabled', noJavaScript],
			['Timeouts and unexpected responses', faults], ['Hidden tab', hiddenTab], ['Focus and scroll', focusAndScroll],
			['Guest, automatic', guest], ['Guest, manual Check again', guestManual], ['Guest verified by email', verifiedGuest], ['Hall without a My Account page', noAccountPage],
			['Tampered credential', tampered], ['Order cancelled while waiting', closedOrder],
			['Unavailable read', unavailable], ['Redirect answers', () => redirects()], ['Order summary widths', summaryWidths], ['Excluded order', excluded], ['Previews', previews],
		]) { await scenario(name, () => fn(browser)); }
		quiet();
	} finally {
		await browser.close();
		fs.rmSync(OBSERVE, {recursive: true, force: true});
	}
	process.stdout.write(JSON.stringify({checks, refused}));
})().catch(error => { process.stdout.write(JSON.stringify({error: String(error && error.stack || error), checks, refused})); process.exitCode = 1; });
