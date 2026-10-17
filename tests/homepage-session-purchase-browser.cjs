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
/** Cart Session labels: the request worker adds fixture item-data entries before and after the Bingo entry (BC_HSP_ITEM_NOTES). */
let itemNotes = false;

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
		BC_HSP_CHECKOUT_PATH: hall.checkout_path || '', BC_HSP_MINICART_PATH: hall.minicart_path || '', BC_HSP_ITEM_NOTES: itemNotes ? '1' : '0',
		HTTP_HOST: hall.host, SERVER_NAME: hall.host, SERVER_PORT: '80', REQUEST_URI: url.pathname + url.search,
		QUERY_STRING: url.search.replace(/^\?/, ''), REQUEST_METHOD: method, SCRIPT_NAME: '/index.php', PHP_SELF: '/index.php',
		SCRIPT_FILENAME: config.worker, REDIRECT_STATUS: '1',
	};
	for (const [name, variable] of [['cookie', 'HTTP_COOKIE'], ['nonce', 'HTTP_NONCE'], ['accept', 'HTTP_ACCEPT'], ['user-agent', 'HTTP_USER_AGENT'], ['x-requested-with', 'HTTP_X_REQUESTED_WITH'], ['referer', 'HTTP_REFERER'], ['x-http-method-override', 'HTTP_X_HTTP_METHOD_OVERRIDE']]) {
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
	if (!hall || !('/' === url.pathname || rest || [hall.cart_path, hall.shop_path, hall.category_path, hall.checkout_path, hall.minicart_path].filter(Boolean).includes(url.pathname))) return route.abort();
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
	await page.waitForFunction(selector => getComputedStyle(document.querySelector(selector).closest('.addon-card')).boxShadow.includes('rgb(31, 111, 196)'), box(A, 'paper', 'paper_shared'));
	check((await page.$eval(box(A, 'paper', 'paper_shared'), element => getComputedStyle(element.closest('.addon-card')).boxShadow)).includes('rgb(31, 111, 196)'), 'keyboard focus ring visible on the add-on card');
	check(await page.isVisible('text=Choose one Bingo Package. Add-ons are optional.') && await page.isVisible(`${panel(A, 'paper')} >> text=Add-ons are optional. Select an add-on, then choose its quantity.`), 'intro above the choices; add-ons are optional, quantity follows selection');
	check(!(await page.isDisabled(qty(A, 'paper', 'paper_shared'))) && await page.isDisabled(qty(A, 'digital', 'digital_shared')), 'quantity enabled only for a selected add-on of the chosen Package');
	check('Maximum quantity: 2' === await text(page, `#${qty(A, 'paper', 'paper_shared').slice(1)}-range`) && await page.isVisible(`li.addon-card:has(${box(A, 'paper', 'paper_chips')}) >> text=Maximum quantity: 1`), 'relationship maximum shown for both add-ons, including the maximum of one');
	await page.keyboard.press('Tab');
	check(qty(A, 'paper', 'paper_shared').slice(1) === await focused(page), 'keyboard: Tab moves from the selected add-on to its quantity');
	check('0' === await page.inputValue(qty(A, 'paper', 'paper_shared')), 'first selection starts at zero');
	await page.keyboard.press('ArrowUp');
	check('1' === await page.inputValue(qty(A, 'paper', 'paper_shared')), 'keyboard: first ArrowUp chooses one');
	await page.keyboard.press('ArrowUp');
	check('2' === await page.inputValue(qty(A, 'paper', 'paper_shared')), 'keyboard: ArrowUp in the quantity field steps it to 2');
	await page.keyboard.press('Tab');
	check(`bc-addon-${A.packages.paper}-${A.relations.paper_chips}` === await focused(page), 'keyboard: Tab skips the pointer −/+ buttons to the next add-on');
	await page.keyboard.press('Space');
	await page.keyboard.press('Tab');
	check(qty(A, 'paper', 'paper_chips').slice(1) === await focused(page) && '0' === await page.inputValue(qty(A, 'paper', 'paper_chips')), 'maximum-one add-on: keyboard reaches its quantity, starting at zero');
	await page.keyboard.press('ArrowUp');
	check('1' === await page.inputValue(qty(A, 'paper', 'paper_chips')) && await page.isDisabled(stepButton(A, 'paper', 'paper_chips', '1')), 'maximum-one add-on: choosing one disables +');
	await page.click(stepButton(A, 'paper', 'paper_chips', '-1'));
	check('0' === await page.inputValue(qty(A, 'paper', 'paper_chips')) && await page.isDisabled(stepButton(A, 'paper', 'paper_chips', '-1')), 'maximum-one add-on: − returns to zero');
	await page.click(stepButton(A, 'paper', 'paper_chips', '1'));
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
	const message = `Added to cart. ${pkgName} + 2 add-ons. Add-ons: ${shared.name} × 2, ${chips.name} × 1. To choose another package, remove this selection from your cart first.`;
	check(outcome.kind.includes('is-success') && outcome.message === `${pkgName} + 2 add-ons`, 'success describes the captured selection');
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
		await page.fill(qty(A, 'paper', 'paper_chips'), '1');
		await page.check(radio(A, 'digital'));
		await page.check(box(A, 'digital', 'digital_extra'));
		await page.fill(qty(A, 'digital', 'digital_extra'), '1');
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
	check(await page.isChecked(box(A, 'digital', 'digital_shared')) && '0' === await page.inputValue(field) && !(await page.isDisabled(field)) && await page.isDisabled(minus) && !(await page.isDisabled(plus)), 'clicking the card selects it: quantity 0, − unavailable, + available');
	await shot(page, '.bc-purchase__details', 'purchase-addon-initial-zero.png');
	const initial = api.length;
	await submit(page);
	check(0 === posts(initial).length && 'true' === await page.getAttribute(field, 'aria-invalid'), 'untouched zero: quantity required, no cart request');
	await page.click(plus);
	check('1' === await page.inputValue(field) && null === await page.getAttribute(field, 'aria-invalid') && !(await page.isDisabled(minus)), '+ chooses one and clears the quantity error');
	await page.click(minus);
	check('0' === await page.inputValue(field) && await page.isDisabled(minus) && await page.isChecked(box(A, 'digital', 'digital_shared')), '− returns to zero without unchecking or going negative');
	await page.fill(field, '3');
	await page.uncheck(box(A, 'digital', 'digital_shared'));
	check(await page.isDisabled(field) && await page.isDisabled(plus) && await page.isDisabled(minus), 'unchecking disables the quantity controls');
	await page.check(box(A, 'digital', 'digital_shared'));
	check('3' === await page.inputValue(field), 'rechecking preserves the customer’s chosen quantity');
	for (let i = 0; i < 6; i++) await page.click(plus, {force: true});
	check('5' === await page.inputValue(field) && await page.isDisabled(plus) && await page.isChecked(box(A, 'digital', 'digital_shared')), '+ stops at the relationship maximum (5) and never toggles the checkbox');
	await page.click(minus);
	check('4' === await page.inputValue(field) && (await text(page, '[data-summary-addons]')).includes('Extra paper pack × 4'), '− steps down; the summary follows');
	await page.fill(field, '9');
	check((await text(page, '[data-summary-addons]')).includes('Extra paper pack: check the quantity') && !(await page.isDisabled(minus)), 'a typed quantity over the maximum is flagged in the summary');
	await page.click(minus);
	check('4' === await page.inputValue(field), '− from an out-of-range value steps down from the maximum');
	await page.check(box(A, 'digital', 'digital_extra'));
	await page.fill(qty(A, 'digital', 'digital_extra'), '1');
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
			await page.fill(qty(A, 'digital', 'digital_shared'), '1');
		}, MSG.addonIneligible, [REFRESH], 'bc_cart_addon_ineligible'],
		['relationship ID that exists only in Hall B (forged in the page)', async page => {
			await page.check(radio(A, 'paper'));
			await page.$eval(box(A, 'paper', 'paper_chips'), (element, id) => element.setAttribute('data-addon-id', String(id)), B.only_relation);
			await page.check(box(A, 'paper', 'paper_chips'));
			await page.fill(qty(A, 'paper', 'paper_chips'), '1');
		}, MSG.addonIneligible, [REFRESH], 'bc_cart_addon_ineligible'],
		['unreadable relationship (forged to the fixture’s corrupted row)', async page => {
			await page.check(radio(A, 'partial'));
			await page.$eval(box(A, 'partial', 'partial_ok'), (element, id) => element.setAttribute('data-addon-id', String(id)), A.relations.partial_bad);
			await page.check(box(A, 'partial', 'partial_ok'));
			await page.fill(qty(A, 'partial', 'partial_ok'), '1');
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
	await page.fill(qty(A, 'digital', 'digital_stock'), '1');
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
		await page.fill(qty(A, 'paper', 'paper_chips'), '1');
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
		await page.fill(qty(A, 'paper', 'paper_chips'), '1');
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
		await page.fill(qty(A, 'paper', 'paper_chips'), '1');
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
		check(said === `Added to cart. ${A.names[A.packages.paper].name}. To choose another package, remove this selection from your cart first.`, 'the result is still announced outside the hidden block, for the captured selection');
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
	await page.fill(qty(B, 'paper', 'paper_shared'), '1');
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
		await page.fill(qty(A, 'digital', 'digital_shared'), '1');
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

/* ================================================================== *
 * Cart Session labels (ADR-045 / BR-084 addition 2026-10-02)
 *
 * One native "Bingo Session" item-data entry beneath the Package line in every
 * display WooCommerce 11.1.2 renders from `woocommerce_get_item_data`: the Cart,
 * Checkout and Mini-Cart blocks (Store API item_data) and the classic cart,
 * checkout review and mini-cart templates. Each reader below uses that
 * renderer's own markup; placement is checked by document order inside the
 * line's own container, and by screen position only where the entry shares that
 * container with the price and quantity it is compared to.
 * ================================================================== */

const LABEL = 'Bingo Session';
/** Every overflow measurement, written to the artifacts directory for the review. */
const overflow = [];
/** Page overflow that exists with or without the label (reported, not attributed to it). */
const preexisting = [];
/** Overflow the label causes, collected across displays and asserted at the end. */
const overflowFailures = [];
const DISPLAYS = {
	'Cart block': {root: '.wp-block-woocommerce-cart', rows: '.wp-block-woocommerce-cart .wc-block-cart-items__row', container: '.wc-block-cart-item__product', name: '.wc-block-components-product-name', prices: '.wc-block-cart-item__prices', quantity: '.wc-block-cart-item__quantity', kind: 'block'},
	'Checkout block': {root: '.wp-block-woocommerce-checkout', rows: '.wp-block-woocommerce-checkout .wc-block-components-order-summary-item', container: '.wc-block-components-order-summary-item__description', name: '.wc-block-components-product-name', prices: '.wc-block-cart-item__prices', quantity: null, kind: 'block'},
	'Mini-Cart block': {root: '.wc-block-mini-cart__drawer', rows: '.wc-block-mini-cart__drawer .wc-block-cart-items__row', container: '.wc-block-cart-item__product', name: '.wc-block-components-product-name', prices: '.wc-block-cart-item__prices', quantity: '.wc-block-cart-item__quantity', kind: 'block'},
	'classic cart': {root: 'form.woocommerce-cart-form', rows: 'form.woocommerce-cart-form tr.cart_item', container: 'td.product-name', name: 'td.product-name > a', prices: null, quantity: null, kind: 'classic'},
	'classic checkout': {root: 'table.woocommerce-checkout-review-order-table', rows: 'table.woocommerce-checkout-review-order-table tr.cart_item', container: 'td.product-name', name: 'td.product-name > strong.product-quantity', prices: null, quantity: null, kind: 'classic'},
};
/** WooCommerce 11.1.2's Mini-Cart (Interactivity API) item-data value: its first 15 whitespace-separated words, then "…" — only when there are more. */
const miniCartText = value => { const words = value.trim().split(/\s+/); return words.length <= 15 ? value : words.slice(0, 15).join(' ') + '…'; };

/** Each line of one rendered display: name, every item-data entry in order, and the Bingo entries' text, markup, order, position and overflow. */
async function displayLines(page, display) {
	return page.evaluate(({display, spec, LABEL}) => {
		const shown = element => Boolean(element) && !element.closest('[hidden]') && element.getClientRects().length > 0 && 'hidden' !== getComputedStyle(element).visibility;
		// WordPress core's emoji script may draw an emoji as <img class="emoji" alt="…"> from s.w.org; read it as its character.
		const coreEmoji = element => 'IMG' === element.tagName && element.classList.contains('emoji') && String(element.getAttribute('src')).startsWith('https://s.w.org/images/core/emoji/');
		const textOf = element => {
			if (!element) return '';
			const copy = element.cloneNode(true);
			copy.querySelectorAll('img').forEach(image => { if (coreEmoji(image)) image.replaceWith(image.getAttribute('alt') || ''); });
			return copy.textContent;
		};
		const clean = value => (typeof value === 'string' ? value : textOf(value)).replace(/\s+/g, ' ').trim();
		const precedes = (a, b) => Boolean(a && b && (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING));
		const rect = element => { const r = element.getBoundingClientRect(); return {top: r.top, bottom: r.bottom, left: r.left, right: r.right}; };
		// WooCommerce's Checkout block can render its order summary more than once (narrow screens); each copy is its own render.
		const summaries = [...document.querySelectorAll('.wc-block-components-order-summary')].filter(shown);
		return [...document.querySelectorAll(spec.rows)].filter(shown).map(row => {
			const container = row.querySelector(spec.container);
			const name = [...row.querySelectorAll(spec.name)].find(shown) || null;
			const prices = spec.prices ? row.querySelector(spec.prices) : null;
			const quantity = spec.quantity ? row.querySelector(spec.quantity) : null;
			const groups = [...row.querySelectorAll('.wc-block-components-product-details')];
			const entries = 'block' === spec.kind
				? [...row.querySelectorAll('.wc-block-components-product-details__name')].filter(shown).map(label => ({element: label.parentElement, key: clean(label.textContent).replace(/:$/, ''), value: clean(label.parentElement.querySelector('.wc-block-components-product-details__value')), group: groups.indexOf(label.closest('.wc-block-components-product-details'))}))
				: [...row.querySelectorAll('dl.variation > dt')].map(dt => ({element: dt.nextElementSibling, list: dt.parentElement, key: clean(dt.textContent).replace(/:$/, ''), value: clean(dt.nextElementSibling), group: 0}));
			const bingo = entries.filter(entry => entry.key === LABEL);
			const box = container ? rect(container) : null;
			return {
				name: clean(name && name.textContent),
				render: summaries.indexOf(row.closest('.wc-block-components-order-summary')),
				entries: entries.map(({key, value, group}) => ({key, value, group})),
				bingo: bingo.map(entry => {
					const element = entry.element;
					const value = 'block' === spec.kind ? element.querySelector('.wc-block-components-product-details__value') : element;
					const lines = [...element.getClientRects()];
					const own = rect(element);
					const holder = 'block' === spec.kind ? element.closest('.wc-block-components-product-details') : entry.list;
					return {
						value: entry.value,
						markup: value ? [...value.querySelectorAll('*:not(p)')].filter(element => !coreEmoji(element)).length : -1,
						inContainer: Boolean(container && container.contains(element)),
						otherCells: 'classic' === spec.kind ? [...row.children].filter(cell => cell !== container && cell.contains(element)).length : 0,
						afterName: precedes(name, element),
						afterPrices: null === spec.prices ? null : precedes(prices, element),
						beforeQuantity: null === spec.quantity ? null : precedes(element, quantity),
						beforeVariation: groups[1] ? precedes(element, groups[1]) : null,
						belowPrices: prices && shown(prices) ? own.top >= rect(prices).bottom - 1 : null,
						aboveQuantity: quantity && shown(quantity) ? own.bottom <= rect(quantity).top + 1 : null,
						withinContainer: Boolean(box) && lines.every(line => line.left >= box.left - 1 && line.right <= box.right + 1),
						holderFits: holder ? holder.scrollWidth <= holder.clientWidth + 1 : false,
						lines: lines.length,
						containerWidth: box ? Math.round(box.right - box.left) : null,
						overflowRight: box && lines.length ? Math.round(Math.max(...lines.map(line => line.right)) - box.right) : null,
						holderScroll: holder ? [holder.scrollWidth, holder.clientWidth] : null,
						words: [...(value ? value.textContent : '').matchAll(/\S+/g)].map(match => match[0]),
					};
				}),
				quantityControls: row.querySelectorAll('.wc-block-components-quantity-selector, .wc-block-cart-item__remove-link, input.qty, a.remove').length,
				cells: 'classic' === spec.kind ? [...row.children].map(cell => cell.className) : null,
			};
		});
	}, {display, spec: DISPLAYS[display], LABEL});
}

/** The classic mini-cart (woocommerce_mini_cart()) as WooCommerce's cart-fragments response delivers it; the theme shows no mini-cart widget. */
async function classicMiniCart(page) {
	return page.evaluate(async LABEL => {
		const response = await fetch('/?wc-ajax=get_refreshed_fragments', {method: 'POST', credentials: 'same-origin'});
		const json = await response.json();
		const html = (json.fragments || {})['div.widget_shopping_cart_content'] || '';
		const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
		const clean = value => (value || '').replace(/\s+/g, ' ').trim();
		const precedes = (a, b) => Boolean(a && b && (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING));
		return {status: response.status, lines: [...doc.querySelectorAll('li.mini_cart_item')].map(item => {
			const link = item.querySelector(':scope > a:not(.remove)');
			const entries = [...item.querySelectorAll('dl.variation > dt')].map(dt => ({key: clean(dt.textContent).replace(/:$/, ''), value: clean(dt.nextElementSibling && dt.nextElementSibling.textContent), dd: dt.nextElementSibling, dl: dt.parentElement}));
			return {
				name: clean(link && link.textContent),
				entries: entries.map(({key, value}) => ({key, value})),
				bingo: entries.filter(entry => entry.key === LABEL).map(entry => ({value: entry.value, markup: entry.dd.querySelectorAll('*:not(p)').length, afterRemove: precedes(item.querySelector(':scope > a.remove'), entry.dl), afterName: precedes(link, entry.dl), beforeQuantity: precedes(entry.dl, item.querySelector(':scope > span.quantity'))})),
			};
		})};
	}, LABEL);
}

/** Rendered, not a loading skeleton: every visible row of the display has its product name. */
async function displayReady(page, display) {
	await page.waitForFunction(selector => {
		if (document.querySelector('.wp-block-woocommerce-cart.is-loading, .wc-block-cart--is-loading, .wp-block-woocommerce-checkout.is-loading, .wc-block-mini-cart__drawer.is-loading')) return false;
		const rows = [...document.querySelectorAll(selector)].filter(row => row.getClientRects().length > 0);
		return rows.length > 0 && rows.every(row => [...row.querySelectorAll('.wc-block-components-product-name, td.product-name')].some(name => name.getClientRects().length > 0 && '' !== name.textContent.trim()));
	}, DISPLAYS[display].rows, {timeout: 60000});
}

/** A new viewport width; the Checkout block collapses its summary below its large breakpoint, so it is opened again. */
async function resizeDisplay(page, display, width) {
	await page.setViewportSize({width, height: 1000});
	await sleep(300);
	if ('Checkout block' === display) {
		const toggle = await page.$('.wc-block-components-checkout-order-summary__title[aria-expanded="false"]');
		if (toggle && await toggle.isVisible()) { await toggle.click(); }
	}
	await displayReady(page, display);
	await sleep(250);
}

async function gotoDisplay(page, hall, display, query = '') {
	if ('Cart block' === display || 'classic cart' === display) {
		await page.goto(hall.cart_url + query, {waitUntil: 'load'});
	} else if ('Checkout block' === display) {
		await page.goto(hall.checkout_url + query, {waitUntil: 'load'});
		await page.waitForFunction(() => document.querySelector('.wc-block-components-checkout-order-summary__title, .wc-block-components-order-summary-item') && !document.querySelector('.wp-block-woocommerce-checkout.is-loading'), null, {timeout: 60000});
		// Narrow screens collapse the order summary behind its own toggle.
		const toggle = await page.$('.wc-block-components-checkout-order-summary__title[aria-expanded="false"]');
		if (toggle && await toggle.isVisible()) { await toggle.click(); }
	} else if ('classic checkout' === display) {
		await page.goto(hall.checkout_url + query, {waitUntil: 'load'});
		// The classic checkout refreshes its review table once (?wc-ajax=update_order_review) after loading.
		await page.waitForFunction(() => document.querySelectorAll('table.woocommerce-checkout-review-order-table tr.cart_item').length > 0 && !document.querySelector('.blockUI, .woocommerce-checkout-review-order-table.processing, form.checkout.processing'), null, {timeout: 60000});
	} else if ('Mini-Cart block' === display) {
		await page.goto(hall.minicart_url, {waitUntil: 'load'});
		await page.click('.wc-block-mini-cart__button');
	}
	await displayReady(page, display);
	await sleep(400);
}

/** The Package line's one Bingo entry in this display (in each rendered copy of it): text, no markup, native placement. Returns that line. */
function checkPackageLine(lines, display, hall, expected, label) {
	const renders = [...new Set(lines.map(line => line.render))];
	if (renders.length > 1) {
		check(renders.every(render => 1 === lines.filter(line => line.render === render && line.bingo.length > 0).length), `${label} — ${display}: WooCommerce renders ${renders.length} copies of the order summary here; each shows the Package line with one entry`);
		renders.forEach(render => checkPackageLine(lines.filter(line => line.render === render), display, hall, expected, `${label} (summary ${render + 1} of ${renders.length})`));
		return lines.find(line => line.bingo.length > 0);
	}
	const packages = lines.filter(line => line.bingo.length > 0);
	const others = lines.filter(line => 0 === line.bingo.length);
	check(1 === packages.length && 1 === packages[0].bingo.length, `${label} — ${display}: exactly one Bingo Session entry, on one line (${lines.map(line => `${line.name}:${line.bingo.length}`).join(', ')})`);
	const line = packages[0];
	const entry = line.bingo[0];
	const shown = 'Mini-Cart block' === display ? miniCartText(expected) : expected;
	check(entry.value === shown && 0 === entry.markup, `${label} — ${display}: “${entry.value}”, plain text`);
	check(line.name.includes(hall.package_name) || 'classic checkout' === display, `${label} — ${display}: on the Package line (${line.name})`);
	check(others.every(other => other.entries.every(e => e.key !== LABEL)), `${label} — ${display}: add-on and merchandise lines have no Session entry`);
	if ('classic cart' === display) {
		check(entry.inContainer && 0 === entry.otherCells && entry.afterName && same(line.cells.filter(Boolean).map(cell => cell.split(' ').find(c => c.startsWith('product-'))), ['product-remove', 'product-thumbnail', 'product-name', 'product-price', 'product-quantity', 'product-subtotal']), `${label} — classic cart: inside td.product-name after the product link; the separate remove, price, quantity and subtotal columns unchanged`);
	} else if ('classic checkout' === display) {
		check(entry.inContainer && 0 === entry.otherCells && entry.afterName && 0 === line.quantityControls && same(line.cells.filter(Boolean).map(cell => cell.split(' ').find(c => c.startsWith('product-'))), ['product-name', 'product-total']), `${label} — classic checkout review: inside td.product-name after the name and its quantity text; no removal or quantity control; total column unchanged`);
	} else if ('Checkout block' === display) {
		check(entry.inContainer && entry.afterName && entry.afterPrices && false !== entry.belowPrices && 0 === line.quantityControls, `${label} — Checkout block: in the summary description after the name and per-item price (and below it); no editing control added`);
	} else {
		check(entry.inContainer && entry.afterName && entry.afterPrices && entry.beforeQuantity && false !== entry.belowPrices && false !== entry.aboveQuantity, `${label} — ${display}: in the product container after the name and per-item price, before the quantity wrapper (and on screen between them)`);
		if ('Mini-Cart block' === display) check(null === entry.beforeVariation || entry.beforeVariation, `${label} — Mini-Cart block: before the variation details`);
	}
	return line;
}

/** No CSS overflow: the page has no horizontal scroll, the entry stays inside its line's container and its list fits. Expected Mini-Cart truncation is not overflow. */
async function checkOverflow(page, lines, display, label) {
	const all = lines.flatMap(line => line.bingo);
	// Every rendered copy must fit; the first one's measurements are reported.
	const entry = {...all[0], withinContainer: all.every(e => e.withinContainer), holderFits: all.every(e => e.holderFits)};
	const width = page.viewportSize().width;
	// Overflow of the page and of the display's own box (the Mini-Cart drawer is its own fixed panel), with the
	// entry and then with the Bingo entries hidden for a moment: the label must add none to either.
	const page_ = await page.evaluate(({LABEL, root}) => {
		const box = document.querySelector(root);
		const visible = element => element.getClientRects().length > 0 && 'hidden' !== getComputedStyle(element).visibility;
		const inner = () => {
			if (!box) return 0;
			const right = box.getBoundingClientRect().right;
			return Math.max(0, Math.round(Math.max(right, ...[...box.querySelectorAll('*')].filter(visible).map(element => element.getBoundingClientRect().right)) - right));
		};
		const overflow = () => Math.max(0, document.documentElement.scrollWidth - window.innerWidth);
		const withLabel = overflow();
		const rootWith = inner();
		const hidden = [];
		for (const name of document.querySelectorAll('.wc-block-components-product-details__name, dl.variation > dt')) {
			if (name.textContent.trim().replace(/:$/, '') !== LABEL) continue;
			hidden.push(...('DT' === name.tagName ? [name, name.nextElementSibling] : [name.parentElement]));
		}
		hidden.forEach(element => element.style.setProperty('display', 'none', 'important'));
		const without = overflow();
		const rootWithout = inner();
		// What sticks out: the display's own descendants beyond its box, else page elements beyond the viewport.
		const beyond = rootWithout > 0 && box ? [...box.querySelectorAll('*')].filter(element => visible(element) && element.getBoundingClientRect().right > box.getBoundingClientRect().right + 0.5)
			: [...document.querySelectorAll('body *')].filter(element => visible(element) && element.getBoundingClientRect().right > window.innerWidth + 0.5);
		const culprits = beyond.map(element => `${element.tagName.toLowerCase()}.${String(element.className).trim().split(/\s+/).slice(0, 2).join('.')}`).slice(0, 4);
		hidden.forEach(element => element.style.removeProperty('display'));
		return {withLabel, without, rootWith, rootWithout, culprits, root: Boolean(box)};
	}, {LABEL, root: DISPLAYS[display].root});
	const fits = page_.root && entry.withinContainer && entry.holderFits && page_.withLabel <= page_.without && page_.rootWith <= page_.rootWithout;
	const measured = `${label} — ${display} at ${width}px: the entry (${entry.lines} line${1 === entry.lines ? '' : 's'}) stays inside its ${entry.containerWidth}px line container and adds no overflow to the page or the display (${JSON.stringify({withinContainer: entry.withinContainer, overflowRight: entry.overflowRight, holderScroll: entry.holderScroll, pageOverflow: page_.withLabel, pageOverflowWithoutLabel: page_.without, displayOverflow: page_.rootWith, displayOverflowWithoutLabel: page_.rootWithout})})`;
	if (fits) {
		check(true, measured);
	} else {
		// Collected, so every display's measurement is reported; the run still fails at the end.
		overflowFailures.push(measured);
		await page.screenshot({path: path.join(config.artifacts, `label-overflow-${display.toLowerCase().replace(/[^a-z]+/g, '-')}-${width}-${Date.now()}.png`), fullPage: true});
		console.log('OVERFLOW [Browser] ' + measured);
	}
	if (page_.without > 0 || page_.rootWithout > 0) preexisting.push(`${display} at ${width}px: ${page_.without}px page overflow and ${page_.rootWithout}px beyond the display's own box, with or without the label (${page_.culprits.join(', ')})`);
	return {pageOverflow: page_.withLabel, pageOverflowWithoutLabel: page_.without, displayOverflow: page_.rootWith, displayOverflowWithoutLabel: page_.rootWithout, culprits: page_.culprits, ...entry};
}

async function addSelection(page, hall, session, addons = []) {
	await page.goto(`http://${hall.host}/?bc_session=${session}#session-packages`, {waitUntil: 'load'});
	await page.waitForSelector('form[data-bingo-purchase]');
	const nonce = await nonceOf(page);
	return page.evaluate(async ({url, nonce, body}) => (await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Nonce: nonce}, body: JSON.stringify(body)})).status, {url: hall.endpoint, nonce, body: {namespace: NS, data: {session_id: session, package_id: hall.packages.paper, addons: addons.map(([rel, quantity]) => ({package_addon_id: hall.relations[rel], quantity}))}}});
}

async function addVariation(page, hall) {
	const nonce = await nonceOf(page);
	return page.evaluate(async ({url, nonce, id}) => (await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Nonce: nonce}, body: JSON.stringify({id, quantity: 1, variation: [{attribute: 'Colour', value: 'Blue'}, {attribute: 'Size', value: 'Large'}, {attribute: 'Material', value: 'Cotton'}]})})).status, {url: `${hall.cart_endpoint}/add-item`, nonce, id: hall.variation});
}

/** Session A in the cart; Session B viewed; every display opened with B's parameters still names A. */
async function labelSessionSwitch(browser) {
	for (const [label, hall, displays, second] of [['A', A, ['Cart block', 'Checkout block', 'Mini-Cart block'], 'star'], ['B', B, ['classic cart', 'classic checkout'], 'saturday']]) {
		const {ctx, page, errors} = await fresh(browser);
		const first = hall.labels.friday;
		const other = hall.labels[second];
		await open(page, hall);
		check(201 === await seed(page, hall), `Hall ${label}: merchandise in the cart`);
		await page.check(radio(hall, 'paper'));
		await page.check(box(hall, 'paper', 'paper_shared'));
		await page.fill(qty(hall, 'paper', 'paper_shared'), '1');
		await submit(page);
		await settled(page);
		check((await result(page)).kind.includes('is-success'), `Hall ${label}: Session A (${first.name}) Package and add-on added through the purchase step`);
		await page.goto(`http://${hall.host}/?bc_session=${other.id}#session-packages`, {waitUntil: 'load'});
		await page.waitForSelector('form[data-bingo-purchase]');
		check(String(other.id) === await page.getAttribute('form[data-bingo-purchase]', 'data-session-id'), `Hall ${label}: Session B (${other.name}) is now the Session shown on the homepage`);
		const query = `?bc_session=${other.id}&bc_date=${other.date}`;
		const json = await cart(page, hall);
		const store = json.items.find(item => 'package' === ((item.extensions || {})[NS] || {}).role);
		check(Boolean(store) && same(store.item_data, [{key: LABEL, value: escapeHtml(first.label), display: escapeHtml(first.label)}]) && json.items.filter(item => item !== store).every(item => 0 === item.item_data.length), `Hall ${label}: Store API item_data — the Package’s one entry names Session A; add-on and merchandise none`);
		for (const display of displays) {
			await page.setViewportSize({width: 1280, height: 1000});
			await gotoDisplay(page, hall, display, query);
			const lines = await displayLines(page, display);
			checkPackageLine(lines, display, hall, first.label, `Hall ${label} A→B`);
			check(!JSON.stringify(lines).includes(other.name), `Hall ${label} A→B — ${display}: Session B’s name appears nowhere`);
			check(3 === lines.length || ('Checkout block' === display && lines.length >= 3), `Hall ${label} A→B — ${display}: merchandise, Package and add-on lines (${lines.length})`);
			for (const width of [1440, 375, 320]) {
				await resizeDisplay(page, display, width);
				const resized = await displayLines(page, display);
				checkPackageLine(resized, display, hall, first.label, `Hall ${label} A→B ${width}px`);
				await checkOverflow(page, resized, display, `Hall ${label} A→B`);
				if (1440 === width || 375 === width) await labelShot(page, display, `label-${display.toLowerCase().replace(/[^a-z]+/g, '-')}-${width}.png`);
			}
		}
		if ('B' === label) {
			const mini = await classicMiniCart(page);
			check(200 === mini.status && 1 === mini.lines.filter(line => line.bingo.length).length, 'Hall B A→B — classic mini-cart (cart fragments): one Package line with a Session entry');
			const entry = mini.lines.find(line => line.bingo.length).bingo[0];
			check(entry.value === first.label && 0 === entry.markup && entry.afterRemove && entry.afterName && entry.beforeQuantity, `Hall B A→B — classic mini-cart: “${entry.value}” after the remove control and name link, before the quantity and price text`);
			// The Mini-Cart block on this classic Checkout page renders WooCommerce's hidden Cart/Checkout fallback.
			await gotoDisplay(page, hall, 'classic checkout', query);
			check(await fallbackHidden(page), 'Hall B classic Checkout page with a Mini-Cart block: its fallback is hidden, holds no Session entry, and the page shows one visible entry');
		}
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
}

/** The Mini-Cart block's Cart/Checkout fallback: present but hidden, no Session entry inside, and exactly one visible Bingo entry on the page. */
async function fallbackHidden(page) {
	return page.evaluate(LABEL => {
		const fallback = document.querySelector('.wc-block-mini-cart');
		const visible = [...document.querySelectorAll('.wc-block-components-product-details__name, dl.variation > dt')].filter(label => label.textContent.trim().replace(/:$/, '') === LABEL && label.getClientRects().length > 0 && 'hidden' !== getComputedStyle(label).visibility);
		return Boolean(fallback) && 'hidden' === getComputedStyle(fallback).visibility && !fallback.textContent.includes(LABEL) && !fallback.querySelector('.wc-block-cart-items__row') && 1 === visible.length;
	}, LABEL);
}

async function labelShot(page, display, name) {
	const target = {'Cart block': '.wp-block-woocommerce-cart', 'Checkout block': '.wc-block-components-sidebar, .wp-block-woocommerce-checkout-order-summary-block', 'Mini-Cart block': '.wc-block-mini-cart__drawer', 'classic cart': 'form.woocommerce-cart-form', 'classic checkout': 'table.woocommerce-checkout-review-order-table'}[display];
	await page.addStyleTag({content: '.site-header { position: static !important; }'});
	const element = await page.$(target.split(', ').map(selector => selector).join(', '));
	if (element) await element.screenshot({path: path.join(config.artifacts, name)});
}

function escapeHtml(value) {
	return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

/** Other extensions' entries and variation details keep their places around the one Bingo entry. */
async function labelOtherMetadata(browser) {
	itemNotes = true;
	try {
		for (const [label, hall, displays] of [['A', A, ['Cart block', 'Checkout block', 'Mini-Cart block']], ['B', B, ['classic cart', 'classic checkout']]]) {
			const {ctx, page, errors} = await fresh(browser);
			const first = hall.labels.friday;
			check(200 === await addSelection(page, hall, first.id, [['paper_shared', 1]]), `Hall ${label} (fixture filters before/after): Package and add-on added`);
			check(201 === await seed(page, hall) && 201 === await addVariation(page, hall), `Hall ${label}: merchandise and a variation (Colour, Size, Material) added through WooCommerce’s add-item route`);
			const notes = [['Fixture before', 'kept'], [LABEL, first.label], ['Fixture after', 'kept']];
			const json = await cart(page, hall);
			const pkg = json.items.find(item => 'package' === ((item.extensions || {})[NS] || {}).role);
			check(same(pkg.item_data.map(entry => [entry.key, entry.value]), notes.map(([key, value]) => [key, escapeHtml(value)])), `Hall ${label} Store API: the Package’s entries in filter order — an earlier one, the Bingo entry once, a later one`);
			for (const display of displays) {
				await page.setViewportSize({width: 1280, height: 1000});
				await gotoDisplay(page, hall, display);
				const lines = await displayLines(page, display);
				const packageLine = lines.find(line => line.bingo.length);
				check(Boolean(packageLine) && same(packageLine.entries.map(entry => [entry.key, entry.value]), 'Mini-Cart block' === display ? notes.map(([key, value]) => [key, miniCartText(value)]) : notes), `Hall ${label} — ${display}: Package line shows “Fixture before”, the Bingo entry, then “Fixture after” (${packageLine && packageLine.entries.map(entry => entry.key).join(' / ')})`);
				const variationLine = lines.find(line => line.entries.some(entry => 'Colour' === entry.key));
				const variation = [['Colour', 'Blue'], ['Material', 'Cotton'], ['Size', 'Large']];
				const pairs = entries => entries.map(entry => [entry.key, entry.value]);
				// Blocks: item data, then WooCommerce's own variation details (in its own attribute order); classic: variation details first.
				const kept = variationLine && ('block' === DISPLAYS[display].kind
					? same(pairs(variationLine.entries.filter(entry => 0 === entry.group)), [['Fixture before', 'kept'], ['Fixture after', 'kept']]) && same(pairs(variationLine.entries.filter(entry => 1 === entry.group)).sort(), variation)
					: same(pairs(variationLine.entries.slice(0, 3)).sort(), variation) && same(pairs(variationLine.entries.slice(3)), [['Fixture before', 'kept'], ['Fixture after', 'kept']]));
				check(Boolean(kept) && 0 === variationLine.bingo.length, `Hall ${label} — ${display}: the variation line keeps its variation details and other entries, no Bingo entry (${variationLine && variationLine.entries.map(entry => entry.key).join(' / ')})`);
				check(lines.filter(line => line !== packageLine && line !== variationLine).every(line => same(line.entries.map(entry => entry.key), ['Fixture before', 'Fixture after'])), `Hall ${label} — ${display}: add-on and merchandise keep only their own entries`);
				if ('Cart block' === display) {
					const rendered = await page.$$eval(`${DISPLAYS[display].rows} .wc-block-components-product-metadata`, elements => elements.map(element => element.textContent.replace(/\s+/g, ' ').trim()).find(text => text.includes('Bingo Session:')) || '');
					check(rendered === `Fixture before: kept / Bingo Session: ${first.label} / Fixture after: kept`, `Cart block: several entries render as WooCommerce’s own inline “ / ”-separated list (${rendered})`);
					await labelShot(page, display, 'label-cart-block-with-other-metadata.png');
				}
			}
			if ('B' === label) {
				const mini = await classicMiniCart(page);
				const line = mini.lines.find(item => item.bingo.length);
				check(Boolean(line) && same(line.entries.map(entry => [entry.key, entry.value]), notes), 'Hall B classic mini-cart: the same three entries in order');
			}
			check(0 === errors.length, 'no page errors: ' + errors.join('; '));
			await ctx.close();
		}
	} finally {
		itemNotes = false;
	}
}

/** Long, multibyte and maximum-length names, a 30-character zone, unsafe markup and the legacy format: the five rendered displays at each width; the classic mini-cart's fragment HTML as text only. */
async function labelTexts(browser) {
	const dialogs = [];
	const cases = [['A', A, ['Cart block', 'Checkout block', 'Mini-Cart block']], ['B', B, ['classic cart', 'classic checkout']]];
	for (const [label, hall, displays] of cases) {
		for (const key of Object.keys(hall.labels).filter(name => ['long', 'multibyte', 'max', 'unsafe', 'legacy'].includes(name))) {
			const {ctx, page, errors} = await fresh(browser);
			page.on('dialog', dialog => { dialogs.push(dialog.message()); dialog.dismiss().catch(() => {}); });
			const session = hall.labels[key];
			check(200 === await addSelection(page, hall, session.id, [['paper_shared', 1]]), `Hall ${label} ${key}: Package added`);
			for (const display of displays) {
				await page.setViewportSize({width: 1440, height: 1000});
				await gotoDisplay(page, hall, display);
				for (const width of [1440, 375, 320]) {
					await resizeDisplay(page, display, width);
					const lines = await displayLines(page, display);
					checkPackageLine(lines, display, hall, session.label, `Hall ${label} ${key} ${width}px`);
					const measured = await checkOverflow(page, lines, display, `Hall ${label} ${key}`);
					overflow.push({hall: label, key, display, width, ...measured});
					if ('long' === key) await labelShot(page, display, `label-long-${display.toLowerCase().replace(/[^a-z]+/g, '-')}-${width}.png`);
				}
				if ('Mini-Cart block' === display) {
					const shown = (await displayLines(page, display)).find(line => line.bingo.length).bingo[0].value;
					const words = session.label.trim().split(/\s+/).length;
					check(words > 15 ? shown.endsWith('…') && shown.length < session.label.length : shown === session.label, `Hall ${label} ${key} — Mini-Cart block: ${words > 15 ? `WooCommerce’s own 15-word truncation applies (${words} words → “…”), date, time and zone still visible` : `${words} words, shown in full without an ellipsis`}`);
				}
			}
			if ('B' === label) {
				const mini = await classicMiniCart(page);
				const entry = (mini.lines.find(line => line.bingo.length) || {bingo: [{}]}).bingo[0];
				check(entry.value === session.label && 0 === entry.markup, `Hall B ${key} — classic mini-cart: full text, plain`);
			}
			check(0 === errors.length, 'no page errors: ' + errors.join('; '));
			await ctx.close();
		}
	}
	check(0 === dialogs.length, `unsafe Session names never execute: no dialog opened (${dialogs.join(', ')})`);
	const expectedLong = `${A.labels.long.label.split(' · ').slice(0, 2).join(' · ')} · Saturday Super Jackpot Early Bird Special Session with Bonus Rounds…`;
	check(miniCartText(A.labels.long.label) === expectedLong, `reviewed Mini-Cart example: “${expectedLong}”`);
}

/** The Mini-Cart block's Cart/Checkout fallback on Hall A's own Cart and Checkout block pages. */
async function labelMiniCartFallback(browser) {
	const {ctx, page, errors} = await fresh(browser);
	check(200 === await addSelection(page, A, A.labels.friday.id), 'fallback: Hall A Package added');
	check('ok' === await control('minicart', A, 'pages', 'on'), 'fixture: a Mini-Cart block appended to Hall A’s own Cart and Checkout pages');
	try {
		for (const display of ['Cart block', 'Checkout block']) {
			await page.setViewportSize({width: 1280, height: 1000});
			await gotoDisplay(page, A, display);
			check(await fallbackHidden(page), `${display} page with a Mini-Cart block: WooCommerce’s fallback is hidden and holds no Session entry; one visible entry on the page`);
		}
	} finally {
		check('ok' === await control('minicart', A, 'pages', 'off'), 'fixture: Hall A’s Cart and Checkout pages restored');
	}
	check(0 === errors.length, 'no page errors: ' + errors.join('; '));
	await ctx.close();
}

/** A signed-in customer (their own login cookie and session token) sees the same label, also in a later browser session. */
async function labelSignedIn(browser) {
	const login = async ctx => ctx.addCookies([{name: A.customer.name, value: A.customer.value, domain: A.host, path: '/', expires: A.customer.expires, httpOnly: true, sameSite: 'Lax'}]);
	{
		const {ctx, page, errors} = await fresh(browser);
		await login(ctx);
		await open(page, A);
		check(await page.evaluate(() => document.body.classList.contains('logged-in')), 'signed in as the disposable customer (WordPress verified the cookie and its session token)');
		check(0 === lines(await cart(page, A)).length, 'signed-in customer: starts with an empty cart');
		await page.check(radio(A, 'paper'));
		await page.check(box(A, 'paper', 'paper_shared'));
		await page.fill(qty(A, 'paper', 'paper_shared'), '1');
		await submit(page);
		await settled(page);
		const outcome = await result(page);
		check(outcome.kind.includes('is-success'), `signed-in customer: Package and add-on added through the purchase step (${outcome.message})`);
		for (const display of ['Cart block', 'Checkout block', 'Mini-Cart block']) {
			await gotoDisplay(page, A, display);
			checkPackageLine(await displayLines(page, display), display, A, A.labels.friday.label, 'signed-in customer');
		}
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
	{
		// A later visit in a new browser session: WooCommerce restores the signed-in customer's cart.
		const {ctx, page, errors} = await fresh(browser);
		await login(ctx);
		await gotoDisplay(page, A, 'Cart block');
		checkPackageLine(await displayLines(page, 'Cart block'), 'Cart block', A, A.labels.friday.label, 'signed-in customer, new browser session (restored cart)');
		check(0 === errors.length, 'no page errors: ' + errors.join('; '));
		await ctx.close();
	}
}

/** One browser, colliding Hall-local Session IDs: each Hall's cart names its own Session. */
async function labelCollisions(browser) {
	const {ctx, page, errors} = await fresh(browser);
	check(A.labels.star.id === B.labels.saturday.id, `Hall A “Star Evening” and Hall B “Saturday Bingo” share Session ID ${A.labels.star.id}`);
	check(200 === await addSelection(page, A, A.labels.star.id) && 200 === await addSelection(page, B, B.labels.saturday.id), 'the same numeric Session ID added in each Hall');
	await gotoDisplay(page, A, 'Cart block');
	checkPackageLine(await displayLines(page, 'Cart block'), 'Cart block', A, A.labels.star.label, 'colliding ID, Hall A');
	await gotoDisplay(page, B, 'classic cart');
	checkPackageLine(await displayLines(page, 'classic cart'), 'classic cart', B, B.labels.saturday.label, 'colliding ID, Hall B');
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
		await labelSessionSwitch(browser);
		await labelOtherMetadata(browser);
		await labelTexts(browser);
		await labelMiniCartFallback(browser);
		await labelSignedIn(browser);
		await labelCollisions(browser);
		fs.writeFileSync(path.join(config.artifacts, 'label-overflow.json'), JSON.stringify({measurements: overflow, preexisting: [...new Set(preexisting)]}, null, 2));
		for (const note of new Set(preexisting)) console.log('NOTE [Browser] pre-existing, not caused by the label: ' + note);
		check(0 === overflowFailures.length, `no Session label causes horizontal overflow in the five rendered displays at 1440, 375 or 320px (${overflowFailures.length} failing: ${overflowFailures.join(' | ')})`);
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
