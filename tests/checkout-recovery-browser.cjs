'use strict';
/**
 * Order-page layout checks, run BY tests/checkout-recovery.php while its disposable fixture Halls exist
 * (BC_CHECKOUT_RECOVERY_BROWSER=1). Each page request is one real php-cgi request through
 * tests/checkout-recovery-request.php with the customer's real logged-in cookie; theme, plugin and
 * WordPress assets are read from disk; every other request is refused.
 *
 * For Order Received and the My Account order view at 320, 375 and 1440px it reports the page
 * overflow, words split across lines in the single-paragraph Bingo Session label (the long time
 * zone name excepted: it is wider than the column), and whether the two paragraphs of ordinary
 * merchandise metadata stay separate block paragraphs. Prints one JSON object.
 */
const fs = require('node:fs');
const path = require('node:path');
const {spawn} = require('node:child_process');
const {chromium} = require(process.env.BC_PLAYWRIGHT_MODULE || 'playwright');

const ROOT = path.resolve(__dirname, '..');
const env = name => { const v = process.env[name]; if (!v) { throw new Error(`missing ${name}`); } return v; };
const HOST = env('BC_CR_HOST');
const PAGES = {received: env('BC_CR_RECEIVED_URL'), 'view-order': env('BC_CR_VIEW_URL')};
const LONG_WORD = env('BC_CR_LONG_WORD');
const SHOTS = process.env.BC_CR_SHOTS || '';
const MIME = {'.css': 'text/css', '.js': 'application/javascript', '.woff2': 'font/woff2', '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.jpg': 'image/jpeg', '.gif': 'image/gif'};
const refused = [];

function cgi(url, headers) {
	const child_env = {
		PATH: process.env.PATH, BC_TEST_DB: 'bingocommerce_state_recon', BC_FIXTURE_CGI: '1', BC_FIXTURE_WOOCOMMERCE: '1', BC_FIXTURE_NETWORK_ACTIVE: '1',
		BC_CR_SITE: env('BC_CR_SITE'), BC_CR_OWNER: env('BC_CR_OWNER'), BC_CR_CHECKOUT_PATH: env('BC_CR_CHECKOUT_PATH'), BC_CR_ACCOUNT_PATH: env('BC_CR_ACCOUNT_PATH'),
		HTTP_HOST: HOST, SERVER_NAME: HOST, SERVER_PORT: '80', REQUEST_METHOD: 'GET', REQUEST_URI: url.pathname + url.search, QUERY_STRING: url.search.replace(/^\?/, ''),
		SCRIPT_NAME: '/index.php', PHP_SELF: '/index.php', SCRIPT_FILENAME: path.join(__dirname, 'checkout-recovery-request.php'), REDIRECT_STATUS: '1',
	};
	if (headers.cookie) { child_env.HTTP_COOKIE = headers.cookie; }
	return new Promise((resolve, reject) => {
		const child = spawn(process.env.BC_PHP_CGI || 'php-cgi', [path.join(__dirname, 'checkout-recovery-request.php')], {env: child_env, cwd: ROOT});
		const out = []; const err = [];
		const timer = setTimeout(() => child.kill('SIGKILL'), 120000);
		child.stdout.on('data', c => out.push(c)); child.stderr.on('data', c => err.push(c));
		child.on('close', code => {
			clearTimeout(timer);
			if (0 !== code) { return reject(new Error(`CGI ${url.pathname} exited ${code}: ${Buffer.concat(err).toString().slice(0, 300)}`)); }
			const output = Buffer.concat(out); let split = output.indexOf('\r\n\r\n'); let gap = 4;
			if (split < 0) { split = output.indexOf('\n\n'); gap = 2; }
			const head = output.subarray(0, split).toString('latin1');
			const status = (head.match(/^Status:\s*(\d{3})/mi) || [])[1];
			resolve({status: status ? Number(status) : 200, body: output.subarray(split + gap)});
		});
		child.stdin.end();
	});
}

async function route(r) {
	const request = r.request(); const url = new URL(request.url());
	if ('http:' !== url.protocol || url.hostname !== HOST || 'GET' !== request.method()) { return r.abort(); }
	if (['/wp-content/themes/', '/wp-content/plugins/', '/wp-includes/'].some(p => url.pathname.startsWith(p))) {
		const file = path.resolve(ROOT, '.' + decodeURIComponent(url.pathname)); const type = MIME[path.extname(file)];
		if (!type || !file.startsWith(ROOT + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { return r.abort(); }
		return r.fulfill({contentType: type, body: fs.readFileSync(file)});
	}
	if ('document' !== request.resourceType()) { return r.abort(); }
	try { const res = await cgi(url, await request.allHeaders()); return r.fulfill({status: res.status, contentType: 'text/html; charset=UTF-8', body: res.body}); }
	catch (e) { refused.push(e.message); return r.abort('failed'); }
}

function measure(longWord) {
	const vw = document.documentElement.clientWidth;
	const table = document.querySelector('.woocommerce-table--order-details');
	const items = [...document.querySelectorAll('.woocommerce-table--order-details .wc-item-meta li')];
	const splitWords = li => {
		const split = []; const walker = document.createTreeWalker(li, NodeFilter.SHOW_TEXT);
		while (walker.nextNode()) {
			const node = walker.currentNode; const re = /\S+/g; let m;
			while ((m = re.exec(node.data))) {
				const r = document.createRange(); r.setStart(node, m.index); r.setEnd(node, m.index + m[0].length);
				if (new Set([...r.getClientRects()].map(x => Math.round(x.top))).size > 1) { split.push(m[0]); }
			}
		}
		return split;
	};
	const bingo = items.filter(li => /Bingo Session/.test(li.textContent));
	const notes = items.filter(li => /Gift message/.test(li.textContent));
	return {
		viewport: vw, pageOverflow: document.documentElement.scrollWidth - vw, tableFound: !!table,
		tableRight: table ? Math.round(table.getBoundingClientRect().right - vw) : null,
		bingo: bingo.map(li => ({paragraphs: li.querySelectorAll('p').length, display: [...li.querySelectorAll('p')].map(p => getComputedStyle(p).display), splitWords: splitWords(li).filter(w => w !== longWord), text: li.textContent.replace(/\s+/g, ' ').trim()})),
		notes: notes.map(li => {
			const ps = [...li.querySelectorAll('p')]; const a = ps[0] && ps[0].getBoundingClientRect(); const b = ps[1] && ps[1].getBoundingClientRect();
			return {paragraphs: ps.length, display: ps.map(p => getComputedStyle(p).display), separateLines: !!(a && b && b.top >= a.bottom - 0.5), splitWords: splitWords(li), text: li.textContent.replace(/\s+/g, ' ').trim()};
		}),
	};
}

(async () => {
	const browser = await chromium.launch({headless: true, executablePath: process.env.BC_CHROME_EXECUTABLE || undefined});
	const result = {pages: {}, refused};
	try {
		const ctx = await browser.newContext({viewport: {width: 1440, height: 900}});
		await ctx.addCookies([{name: env('BC_CR_COOKIE_NAME'), value: env('BC_CR_COOKIE_VALUE'), domain: HOST, path: '/'}]);
		await ctx.route('**/*', route);
		const page = await ctx.newPage();
		for (const [name, url] of Object.entries(PAGES)) {
			await page.goto(url, {waitUntil: 'load'});
			result.pages[name] = {title: (await page.title()).slice(0, 80)};
			for (const width of [320, 375, 1440]) {
				await page.setViewportSize({width, height: 900}); await page.waitForTimeout(300);
				result.pages[name][width] = await page.evaluate(measure, LONG_WORD);
				if (SHOTS) {
					await page.addStyleTag({content: '.site-header{position:static!important}'});
					await page.screenshot({path: path.join(SHOTS, `${name}-${width}.png`), fullPage: true});
				}
			}
		}
	} finally { await browser.close(); }
	process.stdout.write(JSON.stringify(result));
})().catch(e => { process.stdout.write(JSON.stringify({error: String(e && e.stack || e), refused})); process.exitCode = 1; });
