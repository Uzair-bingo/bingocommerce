/* Render the actual isolated Woo order-view HTML and the installed encoder. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
(async () => {
  const directory = fs.realpathSync(process.argv[2]);
  assert(directory.startsWith('/private/tmp/bc-qr-test.'));
  const root = path.resolve(__dirname, '..');
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BC_QR_BROWSER });
  try {
    const page = await browser.newPage();
    await page.route('**/*', route => route.abort()); // Never request a Hall or an external QR service.
    await page.setContent(fs.readFileSync(path.join(directory, 'order.html'), 'utf8'));
    for (const script of [
      'wp-includes/js/jquery/jquery.min.js',
      'wp-content/plugins/woocommerce/assets/js/jquery-qrcode/jquery.qrcode.min.js',
      'wp-content/plugins/bingocommerce-digital-tickets/assets/customer-ticket.js',
    ]) { await page.addScriptTag({ path: path.join(root, script) }); }
    const canvas = page.locator('canvas[data-bc-ticket]');
    assert.equal(await canvas.count(), 1);
    assert.equal(await canvas.getAttribute('data-bc-rendered'), 'true');
    assert.equal(await canvas.getAttribute('data-bc-ticket'), fs.readFileSync(path.join(directory, 'reference.txt'), 'utf8'));
    const pixels = await canvas.evaluate(c => {
      const data = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
      let black = 0, quiet = true;
      for (let y = 0; y < c.height; y++) for (let x = 0; x < c.width; x++) {
        const i = (y * c.width + x) * 4;
        if (data[i] === 0) black++;
        if ((x < 24 || y < 24 || x >= c.width - 24 || y >= c.height - 24) && (data[i] !== 255 || data[i + 3] !== 255)) quiet = false;
      }
      return { black, quiet, square: c.width === c.height };
    });
    assert(pixels.black > 1000 && pixels.quiet && pixels.square);
    await canvas.screenshot({ path: path.join(directory, 'qr.png') });
    const before = await canvas.evaluate(c => c.toDataURL());
    await page.addScriptTag({ path: path.join(root, 'wp-content/plugins/bingocommerce-digital-tickets/assets/customer-ticket.js') });
    assert.equal(await canvas.evaluate(c => c.toDataURL()), before);
    let checks = 6;
    const unauthorized = path.join(directory, 'unauthorized.html');
    if (fs.existsSync(unauthorized)) {
      assert.equal(await page.locator('.wc-block-order-confirmation-totals__table').count(), 1);
      await page.setContent(fs.readFileSync(unauthorized, 'utf8'));
      await page.addScriptTag({ path: path.join(root, 'wp-content/plugins/bingocommerce-digital-tickets/assets/customer-ticket.js') });
      assert.equal(await page.locator('canvas[data-bc-ticket]').count(), 0);
      assert.equal(await page.locator('.wc-block-order-confirmation-totals__table').count(), 0);
      assert.equal((await page.content()).includes(fs.readFileSync(path.join(directory, 'reference.txt'), 'utf8')), false);
      checks += 4;
    }
    console.log(`QR browser rendering: ${checks} passed, 0 failed (actual Woo HTML, local encoder, quiet zone, repeat${checks > 6 ? ', authorized/unauthorized blocks' : ''}).`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
