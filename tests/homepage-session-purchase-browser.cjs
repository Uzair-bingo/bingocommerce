/**
 * Live purchase-step checks, run BY tests/homepage-session-purchase.php while its
 * disposable fixture Halls exist (BC_SESSION_PURCHASE_BROWSER=1).
 *
 * Every Hall request is answered by a real php-cgi request through
 * tests/homepage-session-purchase-request.php, which boots that Hall directly.
 * The router forwards the browser's real method, query, body, Cookie, Nonce and
 * Content-Type headers, and returns the worker's real status, every Set-Cookie
 * header (with its own attributes) and the Store API Nonce/Cart-Token headers.
 * There is no web server and no cookie injection: guest cookies set by one
 * response are sent by the browser on the next homepage, API, cart-page and
 * reload request. Theme, plugin and WordPress assets are read from disk; fixture
 * media answer with read-only reference images; every other request is refused.
 *
 * A few scenarios deliberately change what the browser sends or receives
 * (an expired page nonce in the rendered document, a dropped header, an aborted
 * or held request, a lost response, a 5xx, the Hall gate's own refusal page),
 * to check how the page reacts. Each one says so in its labels.
 *
 * Hall A's cart page is the Cart block page WooCommerce's installer creates (as
 * HallSiteProvisioner provisions a Hall); Hall B's is the classic
 * [woocommerce_cart] shortcode. Both are exercised, as is each Hall's Shop page.
 * No checkout is run.
 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {spawn, execFile} = require('node:child_process');
const {chromium} = require(process.env.BC_PLAYWRIGHT_MODULE || 'playwright');

const config = JSON.parse(fs.readFileSync(process.env.BC_HSP_CONFIG, 'utf8'));
const A = config.a;
const B = config.b;
const byHost = new Map([[A.host, A], [B.host, B]]);
const mime = {'.css': 'text/css', '.js': 'application/javascript', '.woff2': 'font/woff2', '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.jpg': 'image/jpeg', '.gif': 'image/gif'};
const NS = 'bingocommerce-selection';
const EXT = '/wc/store/v1/cart/extensions';
const CART = '/wc/store/v1/cart';
let passes = 0;
let cgiRequests = 0;
const api = [];
const refused = [];
let intercept = null;

function check(condition, label) {
	assert.ok(condition, label);
	passes++;
	console.log('PASS [Browser] ' + label);
}
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/* ---- Transport: one real php-cgi request per Hall request ---- */

function cgi(hall, url, method, headers, body) {
	const env = {
		PATH: process.env.PATH, BC_TEST_DB: 'bingocommerce_state_recon', BC_FIXTURE_CGI: '1', BC_FIXTURE_WOOCOMMERCE: '1',
		BC_HSP_SITE: String(hall.site), BC_HSP_OWNER: config.owner, BC_HSP_CART_PATH: hall.cart_path, BC_HSP_SHOP_PATH: hall.shop_path, BC_HSP_CATEGORY_PATH: hall.category_path,
		HTTP_HOST: hall.host, SERVER_NAME: hall.host, SERVER_PORT: '80', REQUEST_URI: url.pathname + url.search,
		QUERY_STRING: url.search.replace(/^\?/, ''), REQUEST_METHOD: method, SCRIPT_NAME: '/index.php', PHP_SELF: '/index.php',
		SCRIPT_FILENAME: config.worker, REDIRECT_STATUS: '1',
	};
	for (const [name, variable] of [['cookie', 'HTTP_COOKIE'], ['nonce', 'HTTP_NONCE'], ['accept', 'HTTP_ACCEPT'], ['user-agent', 'HTTP_USER_AGENT'], ['x-requested-with', 'HTTP_X_REQUESTED_WITH'], ['referer', 'HTTP_REFERER']]) {
		if ('string' === typeof headers[name] && '' !== headers[name]) env[variable] = headers[name];
	}
	if (body && body.length) {
		env.CONTENT_TYPE = headers['content-type'] || 'application/octet-stream';
		env.CONTENT_LENGTH = String(body.length);
	}
	return new Promise((resolve, reject) => {
		const child = spawn(config.cgi, [config.worker], {env, cwd: config.root});
		const out = [];
		const err = [];
		const timer = setTimeout(() => child.kill('SIGKILL'), 90000);
		child.stdout.on('data', chunk => out.push(chunk));
		child.stderr.on('data', chunk => err.push(chunk));
		child.on('close', code => {
			clearTimeout(timer);
			if (0 !== code) return reject(new Error(`CGI ${method} ${url.search} refused (${code}): ${Buffer.concat(err).toString().trim()}`));
			const output = Buffer.concat(out);
			let split = output.indexOf('\r\n\r\n');
			let gap = 4;
			if (split < 0) { split = output.indexOf('\n\n'); gap = 2; }
			const head = split < 0 ? output.toString('latin1') : output.subarray(0, split).toString('latin1');
			const responseBody = split < 0 ? Buffer.alloc(0) : output.subarray(split + gap);
			const lines = head.split(/\r?\n/).filter(Boolean).map(line => [line.slice(0, line.indexOf(':')).trim(), line.slice(line.indexOf(':') + 1).trim()]);
			const statusLine = lines.find(([name]) => 'status' === name.toLowerCase());
			resolve({status: statusLine ? Number(statusLine[1].slice(0, 3)) : 200, headers: lines.filter(([name]) => 'status' !== name.toLowerCase()), body: responseBody});
		});
		child.stdin.on('error', () => {});
		if (body && body.length) child.stdin.write(body);
		child.stdin.end();
	});
}

/** Response headers for route.fulfill(); every Set-Cookie line keeps its own attributes. */
function fulfillHeaders(lines) {
	const headers = {};
	for (const [name, value] of lines) {
		const key = name.toLowerCase();
		if (['content-length', 'transfer-encoding'].includes(key)) continue;
		headers[key] = undefined === headers[key] ? value : headers[key] + ('set-cookie' === key ? '\n' : ', ') + value;
	}
	return headers;
}
const header = (lines, name) => (lines.find(([key]) => key.toLowerCase() === name) || [])[1];

async function router(route) {
	const request = route.request();
	const url = new URL(request.url());
	const hall = byHost.get(url.hostname);
	const network = url.hostname === config.network;
	if ((!hall && !network) || 'http:' !== url.protocol) return route.abort();
	if (url.pathname.includes('/purchase-fixture/') && config.images[path.basename(url.pathname)]) {
		return route.fulfill({contentType: 'image/png', body: fs.readFileSync(path.join(config.image_root, config.images[path.basename(url.pathname)]))});
	}
	if (['/wp-content/themes/', '/wp-content/plugins/', '/wp-includes/'].some(prefix => url.pathname.startsWith(prefix))) {
		const file = path.resolve(config.root, '.' + decodeURIComponent(url.pathname));
		const type = mime[path.extname(file)];
		if ('GET' !== request.method() || !type || !file.startsWith(config.root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return route.abort();
		return route.fulfill({contentType: type, body: fs.readFileSync(file)});
	}
	const rest = url.pathname.startsWith('/wp-json/') ? url.pathname.slice('/wp-json'.length) : ('/' === url.pathname ? url.searchParams.get('rest_route') : null);
	if (!hall || !('/' === url.pathname || rest || [hall.cart_path, hall.shop_path, hall.category_path].includes(url.pathname))) return route.abort();
	const info = {hall, url, method: request.method(), rest, headers: {...await request.allHeaders()}, body: request.postDataBuffer(), json: null, status: null, responseHeaders: [], at: api.length};
	if (info.body && /json/.test(info.headers['content-type'] || '')) {
		try { info.json = JSON.parse(info.body.toString('utf8')); } catch (error) { info.json = null; }
	}
	if (info.rest) api.push(info);
	const plan = intercept ? (await intercept(info)) || {} : {};
	if (plan.abort) { info.status = 'aborted'; return route.abort('failed'); }
	if (plan.fulfill) { info.status = plan.fulfill.status; return route.fulfill(plan.fulfill); }
	for (const [name, value] of Object.entries(plan.headers || {})) {
		if (null === value) delete info.headers[name]; else info.headers[name] = value;
	}
	cgiRequests++;
	let response;
	try {
		response = await cgi(hall, url, info.method, info.headers, info.body);
	} catch (error) {
		refused.push(error.message);
		info.status = 'refused';
		return route.abort('failed');
	}
	info.status = response.status;
	info.responseHeaders = response.headers;
	if (info.rest) {
		try { info.responseJson = JSON.parse(response.body.toString('utf8')); } catch (error) { info.responseJson = null; }
	}
	if (plan.after && 'abort' === await plan.after(response, info)) { info.lost = true; return route.abort('failed'); }
	return route.fulfill({status: response.status, headers: fulfillHeaders(response.headers), body: response.body});
}

async function context(browser, options = {}) {
	const ctx = await browser.newContext({viewport: {width: 1280, height: 900}, ...options});
	await ctx.route('**/*', router);
	return ctx;
}

/* ---- Page helpers ---- */

const radio = (hall, key) => `#bc-package-${hall.packages[key]}`;
const card = (hall, key) => `li.session-package:has(${radio(hall, key)})`;
const box = (hall, pkg, rel) => `#bc-addon-${hall.packages[pkg]}-${hall.relations[rel]}`;
const qty = (hall, pkg, rel) => `${box(hall, pkg, rel)}-quantity`;
const stepButton = (hall, pkg, rel, direction) => `li.addon-card:has(${box(hall, pkg, rel)}) button[data-step="${direction}"]`;
const panel = (hall, pkg) => `fieldset[data-addons-for="${hall.packages[pkg]}"]`;
const search = (hall, pkg) => `#bc-addon-search-${hall.packages[pkg]}`;
const posts = since => api.slice(since).filter(call => 'POST' === call.method && EXT === call.rest);
const reads = since => api.slice(since).filter(call => 'GET' === call.method && CART === call.rest);
const text = async (page, selector) => ((await page.textContent(selector)) || '').replace(/\s+/g, ' ').trim();

async function open(page, hall) {
	await page.goto(`http://${hall.host}/?bc_session=${hall.session}#session-packages`, {waitUntil: 'load'});
	await page.waitForSelector('form[data-bingo-purchase] [data-purchase-submit]:not([disabled])');
}
async function nonceOf(page) {
	return page.getAttribute('form[data-bingo-purchase]', 'data-nonce');
}
async function seed(page, hall) {
	const nonce = await nonceOf(page);
	return page.evaluate(async ({url, nonce, id}) => (await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Nonce: nonce}, body: JSON.stringify({id, quantity: 1})})).status, {url: `${hall.cart_endpoint}/add-item`, nonce, id: hall.merch});
}
async function cart(page, hall) {
	return page.evaluate(async url => (await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}})).json(), hall.cart_endpoint);
}
function lines(json) {
	return (json.items || []).map(item => ({name: item.name, quantity: item.quantity, price: item.prices && item.prices.price, regular: item.prices && item.prices.regular_price, sale: item.prices && item.prices.sale_price, x: (item.extensions || {})[NS] || {}, key: item.key}));
}
async function submit(page) {
	await page.click('[data-purchase-submit]');
}
async function settled(page) {
	await page.waitForFunction(() => {
		const form = document.querySelector('form[data-bingo-purchase]');
		const result = document.querySelector('[data-purchase-result]');
		return form && 'true' !== form.getAttribute('aria-busy') && result && !result.hidden;
	}, null, {timeout: 120000});
	await sleep(120);
}
async function announced(page) {
	await page.waitForFunction(() => '' !== (document.querySelector('[data-purchase-status]') || {}).textContent, null, {timeout: 10000});
	return text(page, '[data-purchase-status]');
}
async function result(page) {
	return page.$eval('[data-purchase-result]', element => ({
		kind: element.className, message: (element.querySelector('p') || {}).textContent || '',
		links: [...element.querySelectorAll('a')].map(link => ({text: link.textContent, href: link.getAttribute('href')})),
		live: element.hasAttribute('aria-live') || element.hasAttribute('role'),
	}));
}
async function focused(page) {
	return page.evaluate(() => {
		const active = document.activeElement;
		return active ? (active.id || active.getAttribute('data-purchase-submit') !== null && 'submit' || active.getAttribute('data-purchase-view-cart') !== null && 'view-cart' || active.tagName) : null;
	});
}
async function headerCount(page) {
	return text(page, '.bc-cart-link .cart-count');
}
function control(action, hall, value, state = '') {
	return new Promise((resolve, reject) => {
		execFile(config.php, [config.control, action, String(hall.site), String(value), state], {env: {PATH: process.env.PATH, HOME: process.env.HOME, BC_TEST_DB: 'bingocommerce_state_recon', BC_HSP_OWNER: config.owner}, cwd: config.root, timeout: 120000}, (error, stdout, stderr) => (error ? reject(new Error(stderr || error.message)) : resolve(stdout.trim())));
	});
}
function selection(hall, pkg, addons) {
	return {namespace: NS, data: {session_id: hall.session, package_id: hall.packages[pkg], addons: addons.map(([rel, quantity]) => ({package_addon_id: hall.relations[rel], quantity}))}};
}
async function decodeImages(page) {
	await page.locator('#session-packages img').evaluateAll(images => Promise.all(images.map(img => { img.loading = 'eager'; return img.decode().then(() => img.naturalWidth > 0, () => false); })));
	check(await page.locator('#session-packages img').evaluateAll(images => images.every(img => img.complete && img.naturalWidth > 0)), 'preview images decoded');
}
async function fresh(browser, options = {}) {
	const ctx = await context(browser, options);
	const page = await ctx.newPage();
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	return {ctx, page, errors};
}
const MSG = {
	packageLimit: 'Only one Bingo Package is allowed in your cart. To choose a different Package, open your cart and remove the current Package first.',
	selectionInvalid: 'This selection couldn’t be added to your cart. It may no longer be available, or something already in your cart may need attention. Refresh this Session’s Packages or check your cart.',
	addonIneligible: 'One of the add-ons you chose is no longer available with this Package. Refresh this Session’s Packages to see its current add-ons.',
	electronicLimit: 'Your selection exceeds this Hall’s digital bingo face limit. Reduce the digital add-ons or choose a different Package.',
	unchecked: 'This selection couldn’t be checked right now, so it wasn’t added to your cart. Please try again later.',
	notSent: 'This selection couldn’t be sent. Reload the page and try again.',
	fallback: 'This selection couldn’t be added to your cart.',
};
const REFRESH = 'Refresh this Session’s Packages';
/** The Hall's cart page, after WooCommerce's Cart block (or classic cart) has rendered its contents. */
async function cartPage(page, hall, filled = true) {
	await page.goto(hall.cart_url, {waitUntil: 'load'});
	try {
		await page.waitForFunction(expectFilled => {
			const block = document.querySelector('.wp-block-woocommerce-cart');
			if (!block) return !!document.querySelector(expectFilled ? '.woocommerce-cart-form__cart-item' : '.cart-empty, .wc-empty-cart-message');
			if (block.classList.contains('is-loading')) return false;
			if (expectFilled) return document.querySelectorAll('.wc-block-cart-items__row .wc-block-components-product-name').length > 0;
			const empty = document.querySelector('.wp-block-woocommerce-empty-cart-block');
			return !!empty && empty.getClientRects().length > 0;
		}, filled, {timeout: 60000});
	} catch (error) {
		await page.screenshot({path: path.join(config.artifacts, `cart-page-timeout-${Date.now()}.png`), fullPage: true});
		throw error;
	}
	await sleep(300);
	return {text: await text(page, 'main'), block: 0 < (await page.$$('.wp-block-woocommerce-cart')).length};
}
/** Each Cart block row: its product name, and whether it shows a previous price or a "Save" badge. */
async function cartRows(page) {
	return page.$$eval('.wc-block-cart-items__row', rows => rows.map(row => ({
		name: ((row.querySelector('.wc-block-components-product-name') || {}).textContent || '').trim(),
		previous: !!row.querySelector('.wc-block-components-product-price__regular'),
		save: !!row.querySelector('.wc-block-components-sale-badge') || /\bSave\b/.test(row.textContent),
		text: row.textContent.replace(/\s+/g, ' ').trim(),
	})));
}
/** The visible text of one Package card's "What's included". */
async function contentsOf(page, hall, key) {
	return text(page, `${card(hall, key)} .session-package__contents`);
}
/* Previews only: the sticky site header would otherwise be drawn across a tall element's capture. */
async function shot(page, selector, name) {
	await page.addStyleTag({content: '.site-header { position: static !important; }'});
	await page.locator(selector).screenshot({path: path.join(config.artifacts, name)});
}
async function cookieOf(ctx) {
	return (await ctx.cookies()).find(cookie => cookie.name.startsWith('wp_woocommerce_session_')) || null;
}
const UNCERTAIN = 'We couldn’t confirm your selection was added. Check your cart before trying again.';
const EXPIRED = 'This page has expired. Reload it and try again.';

/* ================================================================== *
 * Scenarios
 * ================================================================== */

async function guestPurchase(browser) {
	const {ctx, page, errors} = await fresh(browser);
	await open(page, A);
	const rendered = await nonceOf(page);
	check(201 === await seed(page, A), 'ordinary merchandise added first through WooCommerce’s real add-item route');
	const cookies = await ctx.cookies();
	const session = cookies.find(cookie => cookie.name.startsWith('wp_woocommerce_session_'));
	check(Boolean(session) && session.httpOnly, `the worker’s own Set-Cookie headers reach the browser with their attributes (session cookie on ${session && session.domain})`);
	check(!(await page.isVisible('[data-purchase-nojs]')) && await page.isVisible('fieldset[data-purchase-packages] legend'), 'script running: the no-JS note gives way to the Package radio group');
	check(0 === (await page.$$('input[name="bc_package"]:checked')).length, 'nothing preselected');
	const paperContents = await contentsOf(page, A, 'paper');
	check(await page.isVisible(`${card(A, 'paper')} .session-package__contents`) && paperContents.startsWith('What’s included') && A.names[A.packages.paper].contents.items.every(item => paperContents.includes(item.name)), `before anything is chosen, each Package card shows its saved contents (${paperContents})`);
	check('No Package chosen yet' === await text(page, '[data-selected-name]') && await page.isHidden('[data-selected-badge]') && await page.isVisible('text=Choose a Package to see its optional add-ons.'), 'selected-Package panel: nothing chosen yet');
	const layout = await page.evaluate(() => {
		const rect = selector => document.querySelector(selector).getBoundingClientRect();
		const choices = rect('fieldset[data-purchase-packages]');
		const details = rect('.bc-purchase__details');
		return {narrow: choices.width < details.width, beside: details.left >= choices.right - 1, top: Math.abs(details.top - choices.top) < 2};
	});
	check(layout.narrow && layout.beside && layout.top, `1280px: a narrower Package column beside the wider details column (${JSON.stringify(layout)})`);

	await page.focus(radio(A, 'paper'));
	await page.keyboard.press('Space');
	check(await page.isChecked(radio(A, 'paper')) && await page.isVisible(panel(A, 'paper')) && await page.isHidden(panel(A, 'digital')), 'keyboard: Space chooses a Package and shows only its add-on panel');
	const selected = [await text(page, '[data-selected-name]'), await text(page, '[data-selected-meta]'), await text(page, '[data-selected-badge]')];
	check(same(selected, [A.names[A.packages.paper].name, `Paper Package · ${A.names[A.packages.paper].price}`, '1 Package']), `selected-Package panel: the chosen Package’s name, type and server-formatted price, one Package (${selected.join(' | ')})`);
	await sleep(300);
	check((await page.$eval(radio(A, 'paper'), element => getComputedStyle(element.closest('.session-package')).boxShadow)).includes('rgb(31, 111, 196)'), 'keyboard focus ring visible on the chosen Package card');
	await page.keyboard.press('Tab');
	check(`bc-addon-search-${A.packages.paper}` === await focused(page), 'keyboard: Tab moves from the Package choice to its add-on search');
	await page.keyboard.press('Tab');
	check(`bc-addon-${A.packages.paper}-${A.relations.paper_shared}` === await focused(page), 'keyboard: then to its first add-on card');
	await page.keyboard.press('Space');
	await sleep(300);
	check((await page.$eval(box(A, 'paper', 'paper_shared'), element => getComputedStyle(element.closest('.addon-card')).boxShadow)).includes('rgb(31, 111, 196)'), 'keyboard focus ring visible on the add-on card');
	check(await page.isVisible('text=Choose one Bingo Package. Add-ons are optional.') && await page.isVisible(`${panel(A, 'paper')} >> text=Add-ons are optional. Select an add-on, then choose its quantity.`), 'intro above the choices; add-ons are optional, quantity follows selection');
	check(!(await page.isDisabled(qty(A, 'paper', 'paper_shared'))) && await page.isDisabled(qty(A, 'digital', 'digital_shared')), 'quantity enabled only for a selected add-on of the chosen Package');
	check('Maximum quantity: 2' === await text(page, `#${qty(A, 'paper', 'paper_shared').slice(1)}-range`) && await page.isVisible(`li.addon-card:has(${box(A, 'paper', 'paper_chips')}) >> text=Quantity: 1`), '“Maximum quantity: 2”; the maximum-one add-on shows a fixed “Quantity: 1”');
	await page.keyboard.press('Tab');
	check(qty(A, 'paper', 'paper_shared').slice(1) === await focused(page), 'keyboard: Tab moves from the selected add-on to its quantity');
	await page.keyboard.press('ArrowUp');
	check('2' === await page.inputValue(qty(A, 'paper', 'paper_shared')), 'keyboard: ArrowUp in the quantity field steps it to 2');
	await page.keyboard.press('Tab');
	check(`bc-addon-${A.packages.paper}-${A.relations.paper_chips}` === await focused(page), 'keyboard: Tab skips the pointer −/+ buttons to the next add-on');
	await page.keyboard.press('Space');
	const pkgName = A.names[A.packages.paper].name;
	const [shared, chips] = A.names[A.packages.paper].addons;
	const summaryPackage = await text(page, '[data-summary-package]');
	const summaryAddons = await text(page, '[data-summary-addons]');
	check(summaryPackage.includes(pkgName) && summaryPackage.includes(A.names[A.packages.paper].price), `summary: Package with its server-formatted price (${summaryPackage})`);
	check(summaryAddons.includes(`${shared.name} × 2`) && summaryAddons.includes(shared.price) && summaryAddons.includes(`${chips.name} × 1`) && summaryAddons.includes(chips.price), `summary: add-ons with quantities and relationship prices (${summaryAddons})`);
	check(await page.isVisible('text=Your cart shows the total.') && !/total:/i.test(summaryAddons + summaryPackage), 'no browser total: the cart shows it');
	check((await page.$$eval('.bc-purchase__summary [data-purchase-price]', prices => prices.map(price => getComputedStyle(price).color))).every(color => 'rgb(255, 255, 255)' === color), 'summary prices are light text on the dark summary');
	const when = await page.getAttribute('form[data-bingo-purchase]', 'data-session-when');
	const dateLabel = `${A.date.slice(5, 7)}/${A.date.slice(8, 10)}/${A.date.slice(0, 4)}`;
	check((await text(page, '.bc-purchase__line dd')).startsWith(A.session_name) && when.startsWith(dateLabel) && when.includes('America/New_York'), `summary: the authoritative Session name and MM/DD/YYYY date with its saved time (${when})`);

	const before = api.length;
	await page.focus('[data-purchase-submit]');
	await page.keyboard.press('Enter');
	await settled(page);
	const sent = posts(before);
	check(1 === sent.length && 0 === reads(before).length, `one request, no refresh or retry after a success (${JSON.stringify(api.slice(before).map(call => [call.method, call.rest, call.status]))})`);
	check(same(sent[0].json, selection(A, 'paper', [['paper_shared', 2], ['paper_chips', 1]])), 'real JSON body: Session ID, Package ID, add-on relationship IDs and quantities only');
	check(sent[0].headers.nonce === rendered && /^application\/json/.test(sent[0].headers['content-type']) && sent[0].headers.cookie.includes(session.name), 'sent with the rendered Store API nonce, JSON and the guest session cookie (normal nonce validation passed)');
	const outcome = await result(page);
	const message = `Added ${pkgName} for ${A.session_name} on ${when} to your cart. Add-ons: ${shared.name} × 2, ${chips.name} × 1. This selection is in your cart. To choose a different Package, remove it from your cart first.`;
	check(outcome.kind.includes('is-success') && outcome.message === message, 'success describes the captured selection');
	check(await announced(page) === message && !outcome.live, 'announced once, by the polite region outside the block (the visible message is not a live region)');
	check(await page.isVisible('[data-purchase-view-cart]') && await page.isHidden('[data-purchase-submit]') && 'view-cart' === await focused(page), 'View cart shown and focused; Add to cart gone');
	check(await page.$eval('fieldset[data-purchase-packages]', element => element.disabled) && await page.$eval(panel(A, 'paper'), element => element.disabled), 'completed form locked');
	await page.setViewportSize({width: 1440, height: 1000});
	await decodeImages(page);
	await shot(page, '#session-packages', 'purchase-success-desktop.png');
	await page.setViewportSize({width: 390, height: 1000});
	await shot(page, '.bc-purchase__summary', 'purchase-success-mobile.png');
	await page.setViewportSize({width: 1280, height: 900});
	await page.waitForFunction(() => '5' === (document.querySelector('.bc-cart-link .cart-count') || {}).textContent, null, {timeout: 15000});
	check('5' === await headerCount(page), 'header cart count refreshed through the theme’s existing cart-fragment integration (1 merchandise + 1 Package + 2 + 1 add-ons)');
	const lockedAt = api.length;
	await page.evaluate(() => document.querySelector('form[data-bingo-purchase]').requestSubmit());
	await sleep(400);
	check(0 === posts(lockedAt).length, 'no further add from the completed form');

	await page.reload({waitUntil: 'load'});
	check('5' === await headerCount(page), 'reload: server-rendered header count from the same guest session');
	const stored = lines(await cart(page, A));
	const parent = stored.find(line => 'package' === line.x.role);
	check(4 === stored.length && stored[0].x.role === undefined && parent && parent.x.session_id === A.session && parent.x.package_id === A.packages.paper && 1 === parent.quantity, 'reload: cart keeps the merchandise and the Package for the exact Session');
	const children = stored.filter(line => 'addon' === line.x.role && line.x.parent_key === parent.key);
	check(same(children.map(line => [line.x.addon_id, line.quantity, line.price, line.regular, line.sale]), [[A.relations.paper_shared, 2, '150', '150', '150'], [A.relations.paper_chips, 1, '300', '300', '300']]), 'reload (cart restored from the session by a new request): both add-on lines persist under their Package, the relationship price as price, regular and sale price');
	const shown = await cartPage(page, A);
	check(shown.block && [pkgName, shared.name, chips.name, 'Bingo dauber, blue'].every(name => shown.text.includes(name)), `Cart BLOCK page (the provisioned mode) shows the Package, both add-ons and the merchandise from the same session (${shown.block}; ${shown.text.slice(0, 400)}; refused ${refused.join(' | ')})`);
	const rows = await cartRows(page);
	const chipsRow = rows.find(row => row.name === chips.name);
	check(Boolean(chipsRow) && !chipsRow.previous && !chipsRow.save && chipsRow.text.includes(chips.price), `Cart block: the add-on whose product has its own 5.00/4.00 prices shows only its ${chips.price} relationship price — no previous price, no “Save” (${chipsRow && chipsRow.text})`);
	check(rows.filter(row => [pkgName, shared.name].includes(row.name)).every(row => !row.previous && !row.save), 'Cart block: no sale shown for the Package (not on sale) or the other add-on');
	await page.setViewportSize({width: 1280, height: 1000});
	await shot(page, '.wp-block-woocommerce-cart', 'cart-block-addon-prices.png');
	await page.setViewportSize({width: 1280, height: 900});

	await open(page, A);
	await page.check(radio(A, 'digital'));
	const conflict = api.length;
	await submit(page);
	await settled(page);
	const conflictResult = await result(page);
	check(1 === posts(conflict).length && 'bc_cart_package_limit' === posts(conflict)[0].responseJson.code && conflictResult.message === MSG.packageLimit && same(conflictResult.links.map(link => link.text), ['View cart']), 'second Package: the actual bc_cart_package_limit code shown as the theme’s one-Package message, with View cart');
	check(await page.isVisible('[data-purchase-result] a.bc-purchase__cart-link') && (await page.$eval('[data-purchase-result] a.bc-purchase__cart-link', link => link.getBoundingClientRect().height)) >= 44, 'View cart is a prominent action beside the message');
	await page.setViewportSize({width: 1440, height: 1000});
	await decodeImages(page);
	await shot(page, '#session-packages', 'purchase-second-package-desktop.png');
	await page.setViewportSize({width: 390, height: 1000});
	await shot(page, '.bc-purchase__summary', 'purchase-second-package-mobile.png');
	await page.setViewportSize({width: 1280, height: 900});
	check(await page.isChecked(radio(A, 'digital')) && 'submit' === await focused(page) && !(await page.isDisabled('[data-purchase-submit]')), 'choices kept; Add to cart restored and focused');
	check(4 === lines(await cart(page, A)).length, 'cart unchanged by the refused add');
	check(0 === errors.length, 'no page errors: ' + errors.join('; '));
	await ctx.close();
}

async function switching(browser) {
	for (const back of [false, true]) {
		const {ctx, page, errors} = await fresh(browser);
		await open(page, A);
		await page.check(radio(A, 'paper'));
		await page.check(box(A, 'paper', 'paper_shared'));
		await page.fill(qty(A, 'paper', 'paper_shared'), '2');
		await page.check(box(A, 'paper', 'paper_chips'));
		await page.check(radio(A, 'digital'));
		await page.check(box(A, 'digital', 'digital_extra'));
		check(await page.$eval(panel(A, 'paper'), element => element.hidden && element.disabled) && await page.isChecked(box(A, 'paper', 'paper_shared')), `${back ? 'A→B→A' : 'A→B'}: Package A’s panel hidden and disabled, its choices kept`);
		check(A.names[A.packages.digital].name === await text(page, '[data-selected-name]') && (await page.$$('li.session-package.is-selected')).length === 1 && await page.$eval(card(A, 'digital'), element => element.classList.contains('is-selected')), `${back ? 'A→B→A' : 'A→B'}: the selected panel and the one selected card follow the choice`);
		const contents = {paper: await contentsOf(page, A, 'paper'), digital: await contentsOf(page, A, 'digital')};
		check(contents.digital.includes('Digital cards') && contents.digital.includes('Digital · Quantity: 6') && !contents.digital.includes('Paper sheets') && contents.paper.includes('Paper sheets') && !contents.paper.includes('Digital cards'), `${back ? 'A→B→A' : 'A→B'}: each card keeps its own contents (${contents.paper} | ${contents.digital})`);
		const addons = await text(page, '[data-summary-addons]');
		check(addons.includes('Extra digital cards × 1') && !addons.includes('Magnetic chips') && !addons.includes('× 2'), `${back ? 'A→B→A' : 'A→B'}: summary shows only Package B’s add-ons (${addons})`);
		if (back) {
			await page.check(radio(A, 'paper'));
			const restored = await text(page, '[data-summary-addons]');
			check(restored.includes('Extra paper pack × 2') && restored.includes('Magnetic chips × 1') && !restored.includes('Extra digital cards'), `A→B→A: Package A’s own choices return (${restored})`);
		}
		const before = api.length;
		await submit(page);
		await settled(page);
		const expected = back ? selection(A, 'paper', [['paper_shared', 2], ['paper_chips', 1]]) : selection(A, 'digital', [['digital_extra', 1]]);
		check(same(posts(before).map(call => call.json), [expected]), `${back ? 'A→B→A' : 'A→B'}: the submitted JSON holds only the selected Package’s relationships`);
		check((await result(page)).kind.includes('is-success'), `${back ? 'A→B→A' : 'A→B'}: added`);
		if (!back) {
			// The Digital Package's own product is on sale (18.00 from 20.00); its add-on (product 10.00) is priced 4.00 by its relationship.
			const stored = lines(await cart(page, A));
			check(same(stored.map(line => [line.x.role, line.price, line.regular, line.sale]), [['package', '1800', '2000', '1800'], ['addon', '400', '400', '400']]), `Store API cart: the Package’s genuine sale kept; the add-on shows only its relationship price (${JSON.stringify(stored.map(line => [line.price, line.regular, line.sale]))})`);
			await cartPage(page, A);
			const rows = await cartRows(page);
			const packageRow = rows.find(row => row.name === A.names[A.packages.digital].name);
			const addonRow = rows.find(row => row.name === 'Extra digital cards');
			check(Boolean(packageRow) && packageRow.previous && packageRow.save && Boolean(addonRow) && !addonRow.previous && !addonRow.save, `Cart block: the Package shows its genuine sale; the add-on shows no false previous price or “Save” (${packageRow && packageRow.text} | ${addonRow && addonRow.text})`);
			await shot(page, '.wp-block-woocommerce-cart', 'cart-block-package-sale.png');
		}
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
}

async function addonStates(browser) {
	const {ctx, page, errors} = await fresh(browser);
	await open(page, A);
	await page.check(radio(A, 'none'));
	check(await page.isVisible(`${panel(A, 'none')} >> text=No add-ons are available for this Package.`) && 'None. This Package has no add-ons.' === await text(page, '[data-summary-addons]'), 'successful empty list: “No add-ons are available for this Package.”');
	await page.check(radio(A, 'partial'));
	check(await page.isVisible(`${panel(A, 'partial')} >> text=Some add-ons for this Package couldn’t be checked right now.`) && 1 === (await page.$$(`${panel(A, 'partial')} input[type="checkbox"]`)).length, 'partial list: verified add-on shown with its notice');
	await page.check(radio(A, 'unavailable'));
	check(await page.isVisible(`${panel(A, 'unavailable')} >> text=Add-ons can’t be shown right now.`) && 0 === (await page.$$(`${panel(A, 'unavailable')} input`)).length, 'failed list: “Add-ons can’t be shown right now.”, never “no add-ons”');
	check('What’s included Package contents can’t be shown right now.' === await contentsOf(page, A, 'unavailable') && 0 === (await page.$$(`${card(A, 'unavailable')} .session-package__include`)).length, 'unverifiable contents (a base component’s product is in the trash): “Package contents can’t be shown right now.”, no partial list');
	check('None. Add-ons can’t be shown right now, so this adds the Package only.' === await text(page, '[data-summary-addons]'), 'summary states the Package-only purchase explicitly');
	const before = api.length;
	await submit(page);
	await settled(page);
	check(same(posts(before).map(call => call.json), [selection(A, 'unavailable', [])]) && (await result(page)).kind.includes('is-success') && await page.isVisible(`${panel(A, 'unavailable')} >> text=Add-ons can’t be shown right now.`), 'explicit Package-only purchase accepted by the backend, notice still visible');
	check(0 === errors.length, 'no page errors: ' + errors.join('; '));
	await ctx.close();
}

async function validation(browser) {
	const {ctx, page, errors} = await fresh(browser);
	await open(page, A);
	const before = api.length;
	await submit(page);
	await sleep(200);
	check(0 === posts(before).length && 'Choose a Package.' === await text(page, '[data-purchase-package-error]') && `bc-package-${A.packages.paper}` === await focused(page), 'no Package: “Choose a Package.”, focus on the Package controls, no request');
	check('' === await text(page, '[data-purchase-status]') && same((await page.getAttribute(radio(A, 'paper'), 'aria-describedby')).split(' '), ['bc-purchase-package-error', `bc-package-${A.packages.paper}-contents`]), 'validation is read as the control’s description (with its contents), not announced twice');
	await page.check(radio(A, 'digital'));
	check(await page.isHidden('[data-purchase-package-error]'), 'choosing a Package clears the message');
	await page.check(box(A, 'digital', 'digital_shared'));
	for (const value of ['9', '1.5', '0', '']) {
		await page.fill(qty(A, 'digital', 'digital_shared'), value);
		await submit(page);
		await sleep(150);
		const id = `bc-addon-${A.packages.digital}-${A.relations.digital_shared}-quantity`;
		check(0 === posts(before).length && 'Enter a whole number from 1 to 5 for Extra paper pack.' === await text(page, `#${id}-error`) && id === await focused(page) && 'true' === await page.getAttribute(`#${id}`, 'aria-invalid'), `quantity “${value}”: its permitted range, focus on that quantity, no request`);
	}
	await page.fill(qty(A, 'digital', 'digital_shared'), '3');
	check(await page.isHidden(`#bc-addon-${A.packages.digital}-${A.relations.digital_shared}-quantity-error`), 'a corrected quantity clears its message');
	check(0 === errors.length, 'no page errors: ' + errors.join('; '));
	await ctx.close();
}

/* Image cards: the checkbox, the pointer −/+ buttons and the quantity field work independently; local search keeps every choice. */
async function addonControls(browser) {
	const {ctx, page, errors} = await fresh(browser);
	await open(page, A);
	await page.check(radio(A, 'digital'));
	const field = qty(A, 'digital', 'digital_shared');
	const plus = stepButton(A, 'digital', 'digital_shared', '1');
	const minus = stepButton(A, 'digital', 'digital_shared', '-1');
	check(await page.isDisabled(field) && await page.isDisabled(plus) && await page.isDisabled(minus), 'before selection: the quantity and its buttons are unavailable');
	await page.click(`label[for="${box(A, 'digital', 'digital_shared').slice(1)}"] .addon-card__name`);
	check(await page.isChecked(box(A, 'digital', 'digital_shared')) && !(await page.isDisabled(field)) && await page.isDisabled(minus) && !(await page.isDisabled(plus)), 'clicking the card selects it: quantity 1, − unavailable, + available');
	for (let i = 0; i < 6; i++) await page.click(plus, {force: true});
	check('5' === await page.inputValue(field) && await page.isDisabled(plus) && await page.isChecked(box(A, 'digital', 'digital_shared')), '+ stops at the relationship maximum (5) and never toggles the checkbox');
	await page.click(minus);
	check('4' === await page.inputValue(field) && (await text(page, '[data-summary-addons]')).includes('Extra paper pack × 4'), '− steps down; the summary follows');
	await page.fill(field, '9');
	check((await text(page, '[data-summary-addons]')).includes('Extra paper pack: check the quantity') && !(await page.isDisabled(minus)), 'a typed quantity over the maximum is flagged in the summary');
	await page.click(minus);
	check('4' === await page.inputValue(field), '− from an out-of-range value steps down from the maximum');
	await page.check(box(A, 'digital', 'digital_extra'));
	const shown = () => page.$$eval(`${panel(A, 'digital')} li.addon-card`, cards => cards.filter(item => !item.hidden).map(item => item.querySelector('.addon-card__name').textContent.trim()));
	const status = `${search(A, 'digital')}-status`;
	await page.fill(search(A, 'digital'), 'dau');
	check(same(await shown(), ['Dauber']) && '' === await text(page, status), 'search “dau”: only the matching card is shown, no message');
	const summary = await text(page, '[data-summary-addons]');
	check(summary.includes('Extra paper pack × 4') && summary.includes('Extra digital cards × 1'), `selections hidden by the search keep their quantities and stay in the summary (${summary})`);
	await page.fill(search(A, 'digital'), 'zebra hat');
	check(same(await shown(), []) && 'No add-ons match “zebra hat”. Clear the search to see every add-on.' === await text(page, status) && 'polite' === await page.getAttribute(status, 'aria-live'), 'no matches: a clear message in a polite live region');
	await page.setViewportSize({width: 1280, height: 1000});
	await shot(page, '.bc-purchase__details', 'purchase-search-no-match.png');
	await page.setViewportSize({width: 1280, height: 900});
	const before = api.length;
	await page.focus(search(A, 'digital'));
	await page.keyboard.press('Enter');
	await sleep(300);
	check(0 === posts(before).length && await page.isVisible('[data-purchase-submit]:not([disabled])'), 'Enter in the search field filters only; it never submits');
	await page.fill(search(A, 'digital'), 'ÉXTRA');
	check(same(await shown(), ['Extra paper pack', 'Extra digital cards']), 'search ignores case and accents');
	await page.fill(search(A, 'digital'), '');
	check(3 === (await shown()).length && await page.isChecked(box(A, 'digital', 'digital_shared')) && '4' === await page.inputValue(field), 'clearing the search shows every card with its choices intact');
	await page.fill(field, '7');
	await page.fill(search(A, 'digital'), 'dauber');
	await submit(page);
	await sleep(200);
	const id = field.slice(1);
	check(0 === posts(before).length && '' === await page.inputValue(search(A, 'digital')) && await page.isVisible(field) && id === await focused(page) && 'Enter a whole number from 1 to 5 for Extra paper pack.' === await text(page, `#${id}-error`), 'an invalid quantity hidden by the search: the search clears, the message shows, focus moves there; no request');
	await page.fill(field, '2');
	await page.fill(search(A, 'digital'), 'dauber');
	await submit(page);
	await settled(page);
	check(same(posts(before).map(call => call.json), [selection(A, 'digital', [['digital_shared', 2], ['digital_extra', 1]])]) && (await result(page)).kind.includes('is-success'), 'submitted while the search still filters: every selected add-on and quantity is sent');
	check(0 === errors.length, 'no page errors: ' + errors.join('; '));
	await ctx.close();
}

async function rejections(browser) {
	const cases = [
		['relationship of another local Package (forged in the page)', async page => {
			await page.check(radio(A, 'digital'));
			await page.$eval(box(A, 'digital', 'digital_shared'), (element, id) => element.setAttribute('data-addon-id', String(id)), A.relations.paper_shared);
			await page.check(box(A, 'digital', 'digital_shared'));
		}, MSG.addonIneligible, [REFRESH], 'bc_cart_addon_ineligible'],
		['relationship ID that exists only in Hall B (forged in the page)', async page => {
			await page.check(radio(A, 'paper'));
			await page.$eval(box(A, 'paper', 'paper_chips'), (element, id) => element.setAttribute('data-addon-id', String(id)), B.only_relation);
			await page.check(box(A, 'paper', 'paper_chips'));
		}, MSG.addonIneligible, [REFRESH], 'bc_cart_addon_ineligible'],
		['unreadable relationship (forged to the fixture’s corrupted row)', async page => {
			await page.check(radio(A, 'partial'));
			await page.$eval(box(A, 'partial', 'partial_ok'), (element, id) => element.setAttribute('data-addon-id', String(id)), A.relations.partial_bad);
			await page.check(box(A, 'partial', 'partial_ok'));
		}, MSG.unchecked, [REFRESH], 'bc_cart_catalog_unavailable'],
		['over the electronic-face limit', async page => {
			await page.check(radio(A, 'digital'));
			await page.check(box(A, 'digital', 'digital_extra'));
			await page.fill(qty(A, 'digital', 'digital_extra'), '3');
		}, MSG.electronicLimit, [], 'bc_electronic_limit_exceeded'],
		['electronic-limit configuration unreadable (network maximum removed)', async page => {
			check('ok' === await control('limit', A, 'none'), 'fixture: network electronic maximum removed after rendering');
			await page.check(radio(A, 'digital'));
		}, MSG.unchecked, [REFRESH], 'bc_electronic_limit_unavailable'],
		['native WooCommerce stock refusal', async page => {
			await page.check(radio(A, 'digital'));
			await page.check(box(A, 'digital', 'digital_stock'));
			await page.fill(qty(A, 'digital', 'digital_stock'), '2');
		}, MSG.selectionInvalid, ['View cart', REFRESH], 'bc_cart_selection_invalid'],
		['Sales turned off after the page rendered', async page => {
			check('ok' === await control('sales', A, A.session, 'off'), 'fixture: Sales turned off after rendering (real SessionService writer)');
			await page.check(radio(A, 'paper'));
		}, MSG.selectionInvalid, ['View cart', REFRESH], 'bc_cart_selection_invalid'],
	];
	for (const [label, prepare, message, links, code] of cases) {
		const {ctx, page, errors} = await fresh(browser);
		await open(page, A);
		const checkedBefore = async () => page.$$eval('form[data-bingo-purchase] input:checked', inputs => inputs.map(input => input.id));
		await prepare(page);
		const chosen = await checkedBefore();
		const before = api.length;
		await submit(page);
		await settled(page);
		const outcome = await result(page);
		check(1 === posts(before).length && 400 === posts(before)[0].status && code === posts(before)[0].responseJson.code && outcome.message === message, `${label}: actual ${code} response, shown as the theme’s translated message`);
		check(!/stock/i.test(outcome.message), `${label}: no stock wording (the response does not identify that cause)`);
		check(same(outcome.links.map(link => link.text), links) && outcome.links.every(link => [A.session_url, A.cart_url].includes(link.href)), `${label}: recovery links (${links.join(', ') || 'none'})`);
		check(same(await checkedBefore(), chosen) && !(await page.isDisabled('[data-purchase-submit]')) && 'submit' === await focused(page), `${label}: choices preserved, nothing dropped, Add to cart restored`);
		check(await announced(page) === message, `${label}: announced once`);
		check(0 === lines(await cart(page, A)).length, `${label}: no partial new cart lines`);
		if ('Sales turned off after the page rendered' === label) {
			check('ok' === await control('sales', A, A.session, 'on'), 'fixture: Sales turned back on');
		}
		if (label.startsWith('electronic-limit configuration')) {
			check('ok' === await control('limit', A, '48'), 'fixture: network electronic maximum restored to the suite’s 48');
		}
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
}

/* A refused native add leaves no WooCommerce notice behind, so the same guest session can then buy. */
async function noticeRecovery(browser) {
	const {ctx, page, errors} = await fresh(browser);
	await open(page, A);
	check(201 === await seed(page, A), 'same-session recovery: guest session started with merchandise');
	const session = await cookieOf(ctx);
	await page.check(radio(A, 'digital'));
	await page.check(box(A, 'digital', 'digital_stock'));
	await page.fill(qty(A, 'digital', 'digital_stock'), '2');
	await submit(page);
	await settled(page);
	check(MSG.selectionInvalid === (await result(page)).message, 'native refusal (two of a one-in-stock add-on): the generic refusal');
	const after = await cart(page, A);
	check(same(after.errors || [], []) && 1 === lines(after).length, 'afterwards the Store API cart reports no error and still holds only the merchandise');
	const shown = await cartPage(page, A);
	check(shown.block && !shown.text.includes('not enough stock') && !shown.text.includes('You cannot add') && shown.text.includes('Bingo dauber, blue'), 'the Cart block page shows the merchandise and no retained WooCommerce refusal notice');
	await open(page, A);
	await page.check(radio(A, 'digital'));
	await page.check(box(A, 'digital', 'digital_stock'));
	await submit(page);
	await settled(page);
	check((await result(page)).kind.includes('is-success') && (await cookieOf(ctx)).value === session.value, 'a valid purchase then succeeds in the same guest session');
	const final = await cart(page, A);
	check(same(final.errors || [], []) && same(lines(final).map(line => line.x.role || 'merchandise'), ['merchandise', 'package', 'addon']), 'merchandise kept; Package and add-on added; no cart errors');
	check(0 === errors.length, 'no page errors: ' + errors.join('; '));
	await ctx.close();
}

/* First-time guests: no merchandise seeding, no cookie injection. */
async function firstTime(browser) {
	{
		const {ctx, page, errors} = await fresh(browser);
		await open(page, A);
		check(null === await cookieOf(ctx), 'first-time guest: no WooCommerce session cookie before adding');
		await page.check(radio(A, 'paper'));
		await page.check(box(A, 'paper', 'paper_chips'));
		await submit(page);
		await settled(page);
		const session = await cookieOf(ctx);
		check((await result(page)).kind.includes('is-success') && Boolean(session), 'first-time guest: added, and the add response itself set the session cookie');
		await page.reload({waitUntil: 'load'});
		check('2' === await headerCount(page) && same(lines(await cart(page, A)).map(line => line.x.role), ['package', 'addon']), 'first-time guest: header count and cart persist after reload');
		const shown = await cartPage(page, A);
		check(shown.block && shown.text.includes(A.names[A.packages.paper].name) && shown.text.includes('Magnetic chips'), 'first-time guest: the Cart block page shows the selection');
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
	{
		const {ctx, page, errors} = await fresh(browser);
		let lost = null;
		intercept = async info => (EXT === info.rest && null === lost ? {after: response => { lost = response.headers.filter(([name, value]) => 'set-cookie' === name.toLowerCase() && value.startsWith('wp_woocommerce_session_')).length; return 'abort'; }} : undefined);
		await open(page, A);
		await page.check(radio(A, 'paper'));
		const before = api.length;
		await submit(page);
		await settled(page);
		intercept = null;
		check(UNCERTAIN === (await result(page)).message && 1 === posts(before).length && 200 === posts(before)[0].status, 'first-time guest, lost response (simulated after the server added it): uncertain message, no automatic retry');
		check(lost > 0 && null === await cookieOf(ctx), 'the lost response carried the first session cookie, so the browser never received it');
		check(0 === lines(await cart(page, A)).length, 'cart inspection shows an empty cart: that first cart belongs to a session this browser cannot reach');
		await submit(page);
		await settled(page);
		check((await result(page)).kind.includes('is-success') && 1 === lines(await cart(page, A)).filter(line => 'package' === line.x.role).length, 'a manual retry adds the selection once to a new session; the unreachable first cart is left to WooCommerce’s guest-session expiry');
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
}

/* Each known refusal code's wording and links. Responses are REAL unless labelled simulated. */
async function mappings(browser) {
	const simulated = [
		['bc_electronic_faces_unknown', 'The digital faces in this Bingo selection could not be verified. Please select an available Package and eligible add-ons again.', MSG.unchecked, [REFRESH]],
		['invalid_hall_context', 'Bingo Packages may be read for customer eligibility only from a hall site.', MSG.unchecked, [REFRESH]],
		['catalog_schema_unavailable', 'The Catalog schema is not ready for this hall.', MSG.unchecked, [REFRESH]],
		['package_not_found', 'The Bingo Package was not found.', MSG.unchecked, [REFRESH]],
		['persistence_failed', 'The Bingo Package change could not be persisted.', MSG.unchecked, [REFRESH]],
		['rate_limit_exceeded', 'Too many requests. Please wait 30 seconds before trying again.', 'Too many requests. Please wait 30 seconds before trying again.', ['View cart', REFRESH]],
		['bc_unknown_refusal', '<strong>Unknown</strong> refusal &amp; <script>alert(1)</script>reason', 'Unknown refusal & alert(1)reason', ['View cart', REFRESH]],
		['bc_unknown_without_message', '', MSG.fallback, ['View cart', REFRESH]],
		['bc_unknown_overlong', 'x'.repeat(401), MSG.fallback, ['View cart', REFRESH]],
		['rest_invalid_param', 'Invalid parameter(s): data', MSG.notSent, [REFRESH]],
	];
	for (const [code, serverMessage, shownMessage, links] of simulated) {
		const {ctx, page, errors} = await fresh(browser);
		intercept = async info => (EXT === info.rest ? {fulfill: {status: 400, contentType: 'application/json', body: JSON.stringify({code, message: serverMessage, data: {status: 400}})}} : undefined);
		await open(page, A);
		await page.check(radio(A, 'paper'));
		const before = api.length;
		await submit(page);
		await settled(page);
		intercept = null;
		const outcome = await result(page);
		check(1 === posts(before).length && 0 === reads(before).length && outcome.message === shownMessage && same(outcome.links.map(link => link.text), links), `${code} (simulated 400 response): “${shownMessage.slice(0, 60)}${shownMessage.length > 60 ? '…' : ''}”, links ${links.join(', ')}`);
		check(0 === (await page.$$('[data-purchase-result] strong, [data-purchase-result] script')).length && !(await page.isDisabled('[data-purchase-submit]')), `${code}: rendered as plain text; Add to cart restored`);
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
}

/* Success is only an exactly consistent cart; every other 200 is uncertain. Responses are the REAL add, then altered (simulated). */
async function strictSuccess(browser) {
	const pick = (json, role) => json.items.filter(item => item.extensions && item.extensions[NS] && item.extensions[NS].role === role);
	const cases = [
		['child Session ID wrong', json => { pick(json, 'addon')[0].extensions[NS].session_id = 999999; }, false],
		['child Package ID wrong', json => { pick(json, 'addon')[0].extensions[NS].package_id = 999999; }, false],
		['child Session ID missing', json => { delete pick(json, 'addon')[0].extensions[NS].session_id; }, false],
		['child parent key not the Package line', json => { pick(json, 'addon')[0].extensions[NS].parent_key = 'e'.repeat(32); }, false],
		['extra Package line', json => { const extra = JSON.parse(JSON.stringify(pick(json, 'package')[0])); extra.key = 'f'.repeat(32); extra.extensions[NS].package_id = 999999; json.items.push(extra); }, false],
		['duplicate parent line', json => { const extra = JSON.parse(JSON.stringify(pick(json, 'package')[0])); extra.key = 'd'.repeat(32); json.items.push(extra); }, false],
		['orphan add-on line', json => { const orphan = JSON.parse(JSON.stringify(pick(json, 'addon')[0])); orphan.key = 'c'.repeat(32); orphan.extensions[NS].parent_key = 'b'.repeat(32); orphan.extensions[NS].addon_id = 999999; json.items.push(orphan); }, false],
		['extra add-on under the Package', json => { const extra = JSON.parse(JSON.stringify(pick(json, 'addon')[0])); extra.key = 'c'.repeat(32); extra.extensions[NS].addon_id = 999999; json.items.push(extra); }, false],
		['add-on quantity differs', json => { pick(json, 'addon')[0].quantity = 1; }, false],
		['unusable parent cart key', json => { const parent = pick(json, 'package')[0]; parent.key = 'not-a-cart-key'; pick(json, 'addon')[0].extensions[NS].parent_key = 'not-a-cart-key'; }, false],
		['malformed Bingo line', json => { pick(json, 'package')[0].extensions[NS].role = 'bundle'; }, false],
		['ordinary merchandise line present (control)', json => { json.items.push({key: 'a'.repeat(32), quantity: 3, name: 'Merchandise', extensions: {[NS]: []}}); }, true],
	];
	for (const [label, mutate, accepted] of cases) {
		const {ctx, page, errors} = await fresh(browser);
		intercept = async info => (EXT === info.rest ? {after: response => { const json = JSON.parse(response.body.toString('utf8')); mutate(json); response.body = Buffer.from(JSON.stringify(json)); }} : undefined);
		await open(page, A);
		await page.check(radio(A, 'paper'));
		await page.check(box(A, 'paper', 'paper_shared'));
		await page.fill(qty(A, 'paper', 'paper_shared'), '2');
		await submit(page);
		await settled(page);
		intercept = null;
		const outcome = await result(page);
		const said = await announced(page);
		if (accepted) {
			check(outcome.kind.includes('is-success') && said.startsWith('Added '), `${label} (simulated): still confirmed`);
		} else {
			check(UNCERTAIN === outcome.message && !said.startsWith('Added') && !(await page.isDisabled('[data-purchase-submit]')) && await page.isHidden('[data-purchase-view-cart]'), `${label} (simulated): uncertain message, no “Added”, no success lock`);
		}
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
}

async function nonces(browser) {
	// The delivered homepage with its rendered nonce replaced, as if the page had expired.
	const expired = value => info => ('GET' === info.method && !info.rest && info.url.searchParams.has('bc_session')
		? {after: response => { response.body = Buffer.from(response.body.toString('utf8').replace(/data-nonce="[^"]*"/, `data-nonce="${value}"`)); }}
		: undefined);
	const cases = [
		['expired page nonce (rewritten in the delivered document)', () => { const doc = expired('deadbeef00'); return info => doc(info); }, {posts: 2, reads: 1, first: 403, added: true}],
		['missing Nonce header (dropped by the router)', () => { let dropped = false; return info => { if (EXT === info.rest && !dropped) { dropped = true; return {headers: {nonce: null}}; } return undefined; }; }, {posts: 2, reads: 1, first: 401, added: true}],
		['refresh fails (cart read aborted)', () => { const doc = expired('deadbeef00'); return info => doc(info) || (CART === info.rest ? {abort: true} : undefined); }, {posts: 1, reads: 1, first: 403, added: false}],
		['second rejection (the refresh supplies another stale nonce)', () => { const doc = expired('deadbeef00'); return info => doc(info) || (CART === info.rest ? {after: response => { response.headers = response.headers.filter(([name]) => 'nonce' !== name.toLowerCase()).concat([['Nonce', 'cafebabe00']]); }} : undefined); }, {posts: 2, reads: 1, first: 403, added: false, retry: 'cafebabe00'}],
	];
	for (const [label, factory, expect] of cases) {
		const {ctx, page, errors} = await fresh(browser);
		intercept = factory();
		await open(page, A);
		await page.check(radio(A, 'paper'));
		await page.check(box(A, 'paper', 'paper_chips'));
		const before = api.length;
		await submit(page);
		await settled(page);
		intercept = null;
		const sent = posts(before);
		const refreshed = reads(before);
		check(sent.length === expect.posts && refreshed.length === expect.reads, `${label}: ${expect.posts} POST(s) and ${expect.reads} same-Hall cart read(s)`);
		check(sent.every(call => call.body.equals(sent[0].body)), `${label}: the retry resends the identical captured JSON`);
		const code = 401 === expect.first ? 'woocommerce_rest_missing_nonce' : 'woocommerce_rest_invalid_nonce';
		check(expect.first === sent[0].status && code === (sent[0].responseJson || {}).code && (401 === expect.first ? !sent[0].headers.nonce : 'deadbeef00' === sent[0].headers.nonce), `${label}: genuine ${expect.first} ${code} from the Store API`);
		if (2 === expect.posts) {
			const issued = expect.retry || header(refreshed[0].responseHeaders, 'nonce');
			check(Boolean(issued) && sent[1].headers.nonce === issued && sent[1].at > refreshed[0].at && sent[1].headers.cookie === sent[0].headers.cookie, `${label}: retried once, after the cart read, with its nonce and the same session cookies`);
		}
		const outcome = await result(page);
		if (expect.added) {
			check(outcome.kind.includes('is-success') && 1 === lines(await cart(page, A)).filter(line => 'package' === line.x.role).length, `${label}: added exactly once`);
		} else {
			check(outcome.message === EXPIRED && 0 === lines(await cart(page, A)).length, `${label}: “${EXPIRED}”, nothing added`);
		}
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}

	// A missing refresh header must not erase the last usable nonce.
	{
		const {ctx, page, errors} = await fresh(browser);
		const strip = response => { response.headers = response.headers.filter(([name]) => 'nonce' !== name.toLowerCase()); };
		const doc = expired('deadbeef00');
		intercept = info => doc(info) || (info.rest ? {after: strip} : undefined);
		await open(page, A);
		await page.check(radio(A, 'paper'));
		const before = api.length;
		await submit(page);
		await settled(page);
		check(1 === posts(before).length && EXPIRED === (await result(page)).message, 'no usable nonce from the rejection or the refresh: no retry, page expired');
		await submit(page);
		await settled(page);
		check(2 === posts(before).length && 'deadbeef00' === posts(before)[1].headers.nonce, 'a later submission still carries the last usable nonce (missing headers never erase it)');
		intercept = null;
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}

	// An unrelated authorization error is never retried.
	{
		const {ctx, page, errors} = await fresh(browser);
		intercept = async info => (EXT === info.rest ? {fulfill: {status: 403, contentType: 'application/json', headers: {Nonce: 'feedface00'}, body: JSON.stringify({code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.', data: {status: 403}})}} : undefined);
		await open(page, A);
		await page.check(radio(A, 'paper'));
		const before = api.length;
		await submit(page);
		await settled(page);
		check(1 === posts(before).length && 0 === reads(before).length && 'This selection couldn’t be sent. Reload the page and try again.' === (await result(page)).message, 'unrelated 403 (rest_forbidden, simulated by the router): no refresh, no retry, generic message');
		intercept = null;
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
}

async function uncertain(browser) {
	const cases = [
		['aborted before reaching the server', () => ({abort: true}), false, UNCERTAIN, ['View cart']],
		['lost response after the server added it', () => ({after: () => 'abort'}), true, UNCERTAIN, ['View cart']],
		['server error page (500)', () => ({fulfill: {status: 500, contentType: 'text/html', body: '<h1>Internal Server Error</h1><pre>Fatal error: secret path</pre>'}}), false, UNCERTAIN, ['View cart']],
		['malformed success response (200 without the selection)', () => ({fulfill: {status: 200, contentType: 'application/json', body: JSON.stringify({items: []})}}), false, UNCERTAIN, ['View cart']],
		['200 cart for a different Package (unexpected)', () => ({after: response => { const json = JSON.parse(response.body.toString('utf8')); for (const item of json.items || []) { if (item.extensions && item.extensions[NS]) item.extensions[NS].package_id = 999999; } response.body = Buffer.from(JSON.stringify(json)); }}), true, UNCERTAIN, ['View cart']],
		['extension route error after the callback (woocommerce_rest_cart_extensions_error)', () => ({fulfill: {status: 400, contentType: 'application/json', body: JSON.stringify({code: 'woocommerce_rest_cart_extensions_error', message: 'internal detail', data: {status: 400}})}}), false, UNCERTAIN, ['View cart']],
		['Hall commerce gate refusal (its exact refusal response)', () => ({fulfill: {status: config.gate.status, headers: Object.fromEntries(config.gate.headers.map(line => [line.slice(0, line.indexOf(':')), line.slice(line.indexOf(':') + 1).trim()])), body: config.gate.body}}), false, 'Purchasing isn’t available for this store right now. Please try again later.', []],
	];
	for (const [label, plan, added, message, links] of cases) {
		const {ctx, page, errors} = await fresh(browser);
		let first = true;
		intercept = async info => {
			if (EXT === info.rest && first) { first = false; return plan(info); }
			return undefined;
		};
		await open(page, A);
		// A guest who already has a cart session, so a lost response still leaves a cart to inspect.
		check(201 === await seed(page, A), `${label}: guest session started with merchandise`);
		await page.check(radio(A, 'paper'));
		const before = api.length;
		await submit(page);
		await settled(page);
		const outcome = await result(page);
		check(1 === posts(before).length && 0 === reads(before).length, `${label}: no automatic resubmission or refresh`);
		check(outcome.message === message && same(outcome.links.map(link => link.text), links) && !outcome.message.includes('secret') && !outcome.message.includes('internal'), `${label}: “${message}”`);
		check(!(await page.isDisabled('[data-purchase-submit]')) && await page.isHidden('[data-purchase-view-cart]'), `${label}: no success lock`);
		intercept = null;
		const packages = lines(await cart(page, A)).filter(line => 'package' === line.x.role);
		check(packages.length === (added ? 1 : 0), `${label}: the cart ${added ? 'does' : 'does not'} hold the Package (${packages.length})`);
		if ('lost response after the server added it' === label) {
			await submit(page);
			await settled(page);
			check(MSG.packageLimit === (await result(page)).message && 1 === lines(await cart(page, A)).filter(line => 'package' === line.x.role).length, 'existing-session lost response: trying again by hand gets the one-Package message, never a second Package');
		}
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
}

async function pending(browser) {
	// Repeated clicks and Enter while one request is held.
	{
		const {ctx, page, errors} = await fresh(browser);
		let release;
		const gate = new Promise(resolve => { release = resolve; });
		intercept = async info => (EXT === info.rest ? (await gate, undefined) : undefined);
		await open(page, A);
		await page.check(radio(A, 'paper'));
		const before = api.length;
		await page.click('[data-purchase-submit]');
		await page.click('[data-purchase-submit]', {force: true}).catch(() => {});
		await page.dblclick('[data-purchase-submit]', {force: true}).catch(() => {});
		await page.evaluate(() => document.querySelector('form[data-bingo-purchase]').requestSubmit());
		await sleep(300);
		check('Adding…' === await text(page, '[data-purchase-submit]') && await page.isDisabled('[data-purchase-submit]') && 'true' === await page.getAttribute('form[data-bingo-purchase]', 'aria-busy') && await page.$eval('fieldset[data-purchase-packages]', element => element.disabled), 'pending: “Adding…”, disabled, busy, choices locked');
		release();
		await settled(page);
		check(1 === posts(before).length, 'repeated clicks, a double click and a resubmission while pending: one request');
		intercept = null;
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
	// A calendar date click before success: the block hides; an old choice never comes back.
	{
		const {ctx, page, errors} = await fresh(browser);
		await open(page, A);
		await page.check(radio(A, 'paper'));
		await page.check(box(A, 'paper', 'paper_chips'));
		await page.click(`a[data-calendar-date="${A.date}"]`);
		check(await page.isHidden('#session-packages') && !new URL(page.url()).searchParams.has('bc_session'), 'date click before adding: the purchase block hides, the address drops the Session');
		await page.click(`a[data-calendar-date="${A.date}"]`);
		check(await page.isHidden('#session-packages'), 'the same date again: still hidden');
		await Promise.all([page.waitForURL(url => url.searchParams.get('bc_session') === String(A.session), {waitUntil: 'load'}), page.click(`[data-calendar-date-panel="${A.date}"] a[href*="bc_session=${A.session}"]`)]);
		await page.waitForSelector('[data-purchase-submit]:not([disabled])');
		check(0 === (await page.$$('form[data-bingo-purchase] input:checked')).length, 'View Packages renders afresh: no old Package or add-on selection reappears');
		await sleep(400);
		const frame = await page.evaluate(() => {
			const block = document.querySelector('#session-packages');
			const style = getComputedStyle(block);
			const header = getComputedStyle(document.querySelector('.bc-session-packages__header'));
			return {focused: document.activeElement === block, shadow: style.boxShadow, outline: style.outlineStyle, centered: header.textAlign, icon: !!document.querySelector('.bc-session-packages__session svg[aria-hidden="true"]')};
		});
		check(frame.focused && 'none' === frame.shadow && 'none' === frame.outline, `View Packages focuses the Package block without drawing a ring around it (${frame.shadow})`);
		check('center' === frame.centered && frame.icon, 'Packages heading and date/time centred, with a decorative calendar icon');
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
	// A calendar date click during a pending request: the result is still announced.
	{
		const {ctx, page, errors} = await fresh(browser);
		let release;
		const gate = new Promise(resolve => { release = resolve; });
		intercept = async info => (EXT === info.rest ? (await gate, undefined) : undefined);
		await open(page, A);
		await page.check(radio(A, 'paper'));
		await submit(page);
		await sleep(200);
		await page.click(`a[data-calendar-date="${A.date}"]`);
		check(await page.isHidden('#session-packages'), 'date click during the request hides the block');
		release();
		const said = await announced(page);
		check(said.startsWith(`Added ${A.names[A.packages.paper].name} for ${A.session_name}`), 'the result is still announced outside the hidden block, for the captured selection');
		await page.waitForFunction(() => '1' === (document.querySelector('.bc-cart-link .cart-count') || {}).textContent, null, {timeout: 15000});
		check(1 === lines(await cart(page, A)).filter(line => 'package' === line.x.role).length, 'and the header count and cart reflect the confirmed add');
		intercept = null;
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
}

async function collisions(browser) {
	const {ctx, page, errors} = await fresh(browser);
	await open(page, B);
	check(B.names[B.packages.paper].addons[0].price === '$9.50' && (await text(page, `${panel(B, 'paper')} .addon-card__price [data-purchase-price]`)) === '$9.50', 'Hall B renders its own relationship price for the colliding ID');
	await page.check(radio(B, 'paper'));
	await page.check(box(B, 'paper', 'paper_shared'));
	const before = api.length;
	await submit(page);
	await settled(page);
	check(same(posts(before)[0].json, selection(B, 'paper', [['paper_shared', 1]])) && same(posts(before)[0].json, selection(A, 'paper', [['paper_shared', 1]])), 'the same numeric request as Hall A’s');
	const bLines = lines(await cart(page, B));
	check((await result(page)).kind.includes('is-success') && same(bLines.map(line => line.price), ['1000', '950']), 'Hall B: its own Package and relationship prices in its cart');
	check(posts(before).every(call => call.url.hostname === B.host) && reads(before).every(call => call.url.hostname === B.host), 'every API request went to the page’s own Hall');
	check(0 === lines(await cart(page, A)).length, 'Hall A’s cart is separate for the same browser');
	const classic = await cartPage(page, B);
	check(!classic.block && classic.text.includes(B.names[B.packages.paper].name) && classic.text.includes('Hall B paper pack') && classic.text.includes('$9.50'), 'Hall B’s classic [woocommerce_cart] page shows its own Package and relationship price');
	check(0 === errors.length, 'no page errors: ' + errors.join('; '));
	await ctx.close();
}

async function noScript(browser) {
	const {ctx, page, errors} = await fresh(browser, {javaScriptEnabled: false});
	await page.goto(`http://${A.host}/?bc_session=${A.session}#session-packages`, {waitUntil: 'load'});
	const shown = await page.$$eval('#session-packages input, #session-packages button, #session-packages select, #session-packages a', controls => controls.filter(control => control.getClientRects().length > 0).length);
	check(0 === shown && 5 === (await page.$$('#session-packages .session-package')).length && await page.isVisible('[data-purchase-nojs]'), 'without JavaScript: informational cards and the no-JS note; no purchase control visible');
	check(5 === (await page.$$('#session-packages .session-package .session-package__contents')).length && (await contentsOf(page, A, 'digital')).includes('Digital cards Digital · Quantity: 6'), 'without JavaScript: every card still shows “What’s included”');
	// No script runs here, so the preview is the full page clipped to the block (opened without a fragment, unscrolled).
	await page.setViewportSize({width: 1280, height: 1000});
	await page.goto(`http://${A.host}/?bc_session=${A.session}`, {waitUntil: 'load'});
	await page.screenshot({path: path.join(config.artifacts, 'purchase-no-javascript.png'), fullPage: true, clip: await page.locator('#session-packages').boundingBox()});
	await page.focus('#session-packages');
	for (let i = 0; i < 3; i++) await page.keyboard.press('Tab');
	check(await page.evaluate(() => !document.activeElement.closest('#session-packages')), 'without JavaScript: keyboard focus never enters the Package block');
	check(0 === errors.length, 'no page errors');
	await ctx.close();
}

/* The ordinary Shop: merchandise only, by each Hall's own Catalog (real Shop pages). */
async function shopListing(browser) {
	const {ctx, page, errors} = await fresh(browser);
	for (const [label, hall] of [['A', A], ['B', B]]) {
		for (const width of [1280, 390]) {
			await page.setViewportSize({width, height: 1000});
			await page.goto(`http://${hall.host}${hall.shop_path}`, {waitUntil: 'load'});
			const titles = await page.$$eval('ul.products li.product .woocommerce-loop-product__title', headings => headings.map(heading => heading.textContent.trim()));
			const listed = titles.filter(title => hall.bingo_products.includes(title));
			check(titles.includes('Bingo dauber, blue') && 0 === listed.length, `Hall ${label} Shop page at ${width}px: merchandise listed, no Bingo Package or Play Product (${titles.join(', ')})`);
			check(await page.isVisible(`ul.products a[data-product_id="${hall.merch}"]`) && await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `Hall ${label} Shop page at ${width}px: the merchandise keeps its add-to-cart button; no horizontal overflow`);
			if ('A' === label) await page.screenshot({path: path.join(config.artifacts, `shop-hall-a-${width}.png`), fullPage: true});
		}
	}
	check(A.bingo_products.length > 0 && B.bingo_products.includes('Hall B colliding Play Product') && !A.bingo_products.includes('Hall A colliding merchandise'), 'each Hall excludes its own Catalog’s products; the colliding ID is merchandise in Hall A only');
	check(0 === errors.length, 'no page errors: ' + errors.join('; '));
	await ctx.close();
}

async function responsive(browser) {
	const {ctx, page, errors} = await fresh(browser);
	for (const width of [1440, 1280, 1025, 1024, 768, 641, 640, 390, 320]) {
		await page.setViewportSize({width, height: 1000});
		await open(page, A);
		await page.check(radio(A, 'digital'));
		await page.check(box(A, 'digital', 'digital_shared'));
		// Selected states are 0.2s border/box-shadow transitions: measure once they have finished.
		await sleep(350);
		const metrics = await page.evaluate(() => {
			const rect = selector => document.querySelector(selector).getBoundingClientRect();
			const all = selector => [...document.querySelectorAll(selector)];
			const inside = selector => all(selector).every(element => { const box = element.getBoundingClientRect(); return box.left >= -0.5 && box.right <= window.innerWidth + 0.5; });
			const choices = rect('fieldset[data-purchase-packages]');
			const details = rect('.bc-purchase__details');
			const selected = rect('.bc-purchase__selected');
			const grid = document.querySelector('.bc-addons:not([hidden]) .bc-addons__grid');
			const gridBox = grid.getBoundingClientRect();
			const summary = rect('.bc-purchase__summary');
			const visible = '.bc-addons:not([hidden])';
			return {
				overflow: document.documentElement.scrollWidth - window.innerWidth,
				inside: inside('li.session-package') && inside('.addon-card') && inside('.bc-purchase__selected') && inside('.bc-purchase__summary'),
				beside: details.left >= choices.right - 1 && details.width > choices.width && Math.abs(details.top - choices.top) < 2,
				stacked: details.top >= choices.bottom - 1,
				order: selected.bottom <= gridBox.top + 1 && summary.top >= gridBox.bottom - 1 && Math.abs(summary.left - details.left) < 2,
				columns: getComputedStyle(grid).gridTemplateColumns.split(' ').length,
				media: Math.min(...all(`${visible} .addon-card__media`).map(media => media.getBoundingClientRect().width)),
				placeholder: all(`${visible} .addon-card__media.is-placeholder svg`).every(svg => svg.getBoundingClientRect().width > 0),
				targets: Object.fromEntries([['label', `${visible} .addon-card__label`], ['stepper', `${visible} .addon-card__stepper`], ['step', `${visible} .addon-card__step`], ['check', `${visible} .addon-card__check`], ['radio', '.session-package__radio']]
					.map(([key, selector]) => [key, Math.min(...all(selector).map(element => Math.round(element.getBoundingClientRect().height * 10) / 10))])),
				button: rect('[data-purchase-submit]').height,
				thumb: Math.max(...all('fieldset[data-purchase-packages] .session-package__thumb').map(item => item.getBoundingClientRect().width)),
				cards: all('fieldset[data-purchase-packages] li.session-package').map(item => Math.round(item.getBoundingClientRect().height)),
				selectedRing: getComputedStyle(document.querySelector('.addon-card.is-selected')).boxShadow,
			};
		});
		const wide = width > 1024;
		const minimum = width >= 641 ? 3 : (width >= 390 ? 2 : 1);
		check(metrics.overflow <= 0 && metrics.inside, `${width}px: no horizontal overflow; Package cards, add-on cards, selected panel and summary inside the viewport`);
		check((wide ? metrics.beside : metrics.stacked) && metrics.order && metrics.button >= 44, `${width}px: ${wide ? 'narrow Package column beside the wider details column' : 'Package choices stacked above the details'}; selected panel, add-on grid, then the summary and a ${Math.round(metrics.button)}px Add to cart`);
		check(metrics.columns >= minimum && metrics.media >= 100 && metrics.placeholder, `${width}px: ${metrics.columns}-column add-on grid with readable ${Math.round(metrics.media)}px artwork; a missing image shows its placeholder`);
		const t = metrics.targets;
		check(t.label >= 44 && t.stepper >= 44 && t.step >= 40 && t.check >= 24 && t.radio >= 24, `${width}px: touch targets — card labels ${t.label}px and steppers ${t.stepper}px (44+), −/+ buttons ${t.step}px (40+), checkboxes ${t.check}px and radios ${t.radio}px (24+)`);
		check(metrics.selectedRing.includes('31, 111, 196'), `${width}px: the selected add-on card is marked (${metrics.selectedRing})`);
		check(metrics.thumb <= 88, `${width}px: compact Package choices (${Math.round(metrics.thumb)}px thumbnails; cards ${metrics.cards.join('/')}px with their contents)`);
		if ([1440, 768, 390].includes(width)) {
			await decodeImages(page);
			await shot(page, '#session-packages', `purchase-${1440 === width ? 'desktop' : (768 === width ? 'tablet' : 'mobile')}-${width}.png`);
		}
	}
	// Long names and messages wrap without overflow at the narrowest width.
	await page.setViewportSize({width: 320, height: 1000});
	await open(page, A);
	const long = 'An exceptionally long name that keeps going '.repeat(3).trim();
	await page.$$eval('.addon-card__name, .session-package__name, .session-package__include-name', (names, value) => names.forEach(name => { name.textContent = value; }), long);
	await page.$$eval('input[name="bc_package"]', (radios, value) => radios.forEach(radio => radio.setAttribute('data-package-name', value)), long);
	await page.check(radio(A, 'digital'));
	await page.check(box(A, 'digital', 'digital_shared'));
	await page.fill(qty(A, 'digital', 'digital_shared'), '9');
	await page.fill(search(A, 'digital'), long.repeat(2));
	await submit(page);
	await sleep(200);
	await page.fill(search(A, 'digital'), 'no such add-on, with a very long search phrase that should wrap');
	check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth && [...document.querySelectorAll('li.session-package, .addon-card, .addon-card__error, .bc-addons__no-match, .bc-purchase__selected, .bc-purchase__summary')].every(element => element.getBoundingClientRect().right <= window.innerWidth + 0.5)), '320px: long Package, contents and add-on names, a field error and a long no-match message wrap without overflow');
	await shot(page, '#session-packages', 'purchase-long-names-320.png');
	check(0 === errors.length, 'no page errors: ' + errors.join('; '));
	await ctx.close();
}

(async () => {
	const browser = await chromium.launch({headless: true, ...(process.env.BC_CHROME_EXECUTABLE ? {executablePath: process.env.BC_CHROME_EXECUTABLE} : {})});
	try {
		await guestPurchase(browser);
		await switching(browser);
		await addonStates(browser);
		await validation(browser);
		await addonControls(browser);
		await rejections(browser);
		await noticeRecovery(browser);
		await firstTime(browser);
		await mappings(browser);
		await strictSuccess(browser);
		await nonces(browser);
		await uncertain(browser);
		await pending(browser);
		await collisions(browser);
		await noScript(browser);
		await shopListing(browser);
		await responsive(browser);
		check(0 === refused.length, 'the worker refused no browser request: ' + refused.join(' | '));
	} finally {
		await browser.close();
	}
	console.log(`Browser session purchase: ${passes} passed, 0 failed (${cgiRequests} live CGI requests).`);
})().catch(error => {
	console.error(error.stack || String(error));
	if (refused.length) console.error('Refused: ' + refused.join(' | '));
	console.log(`Browser session purchase: ${passes} passed, 1 failed.`);
	process.exit(1);
});
