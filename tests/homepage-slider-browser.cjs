/**
 * Browser checks against static output from tests/homepage-slider.php.
 * No live WordPress requests: every request is served from local files or refused.
 * BC_PLAYWRIGHT_MODULE=/path/to/playwright BC_SLIDER_PREVIEW_DIR=/private/tmp/... \
 * BC_CHROME_EXECUTABLE='/path/to/Chrome' node tests/homepage-slider-browser.cjs
 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.BC_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const theme = path.join(root, 'wp-content/themes/bingocommerce-bingothemev1');
const reference = process.env.BC_SLIDER_REFERENCE_DIR || path.join(root, '../bingocommer-frontendstore');
const preview = process.env.BC_SLIDER_PREVIEW_DIR;
if (!preview || !fs.existsSync(path.join(preview, 'multiple.html'))) throw Error('Export fresh fixture previews first.');
const pictures = ['slide-early-bird.webp', 'slide-cosmic.webp', 'slide-high-rollers.webp'];
let passes = 0;
function check(condition, label) { assert.ok(condition, label); passes++; console.log('PASS ' + label); }
function html(name) {
  let content = fs.readFileSync(path.join(preview, name + '.html'), 'utf8');
  // get_header/get_footer are once-only within the exporting PHP request. Use
  // only the actual front-page section, not unrelated WordPress bootstrap scripts.
  content = content.match(/<section class="(?:hero|bc-home-slider)"[\s\S]*?<\/section>/)[0];
  let index = 0;
  content = content.replace(/<img\b[^>]*bc-home-slider__image[^>]*>/g, tag => {
    const image = pictures[index++ % pictures.length];
    return tag.replace(/src="[^"]*"/, `src="/reference/assets/images/${image}"`).replace(/srcset="[^"]*"/, '');
  });
  // Fixture previews are fragments. Reference images are served read-only, not imported.
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/theme/assets/css/main.css"></head><body><a id="before" href="#after">Before slider</a><main>${content}</main><a id="after" href="#before">After slider</a><script src="/theme/assets/js/homepage-slider.js"></script></body></html>`;
}
async function routes(context) {
  await context.route('**/*', async route => {
    const u = new URL(route.request().url());
    if (u.hostname !== 'slider.test') return route.abort();
    const aliases = { '/theme/': theme, '/reference/': reference };
    for (const [prefix, base] of Object.entries(aliases)) {
      if (u.pathname.startsWith(prefix)) {
        const file = path.resolve(base, '.' + u.pathname.slice(prefix.length - 1));
        if (!file.startsWith(base + path.sep) || !fs.existsSync(file)) return route.fulfill({status:404,body:''});
        const mime = {'.css':'text/css','.js':'application/javascript','.woff2':'font/woff2','.webp':'image/webp','.png':'image/png','.html':'text/html'}[path.extname(file)] || 'application/octet-stream';
        return route.fulfill({contentType:mime,body:fs.readFileSync(file)});
      }
    }
    if (u.pathname === '/admin.js') return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.join(root,'wp-content/plugins/bingocommerce-core/assets/js/homepage-admin.js'))});
    if (u.pathname === '/admin') {
      // Test only our form orchestration with a media-frame test double. Native
      // Media Library query/isolation is tested separately by the PHP fixture.
      const source=fs.readFileSync(path.join(preview,'admin.html'),'utf8');
      const adapter=`window.bcHomepageAdmin={slide:'Slide %d',select:'Select image',use:'Use image',remove:'Remove slide?',changed:'Slide list updated'};
      window.wp={a11y:{speak:message=>{window.lastAnnouncement=message}},media:options=>{window.mediaOptions=options;const handlers={};let selection;
      const frame={on:(event,callback)=>{handlers[event]=callback},state:()=>({get:()=>({first:()=>({toJSON:()=>selection})})}),open:()=>{window.selectFixtureMedia=value=>{selection=value;handlers.select();handlers.close()}}};return frame}};`;
      return route.fulfill({contentType:'text/html',body:'<!doctype html><html><body>'+source+'<script>'+adapter+'</script><script src="/admin.js"></script></body></html>'});
    }
    if (['zero','one','multiple','long'].includes(u.pathname.slice(1))) return route.fulfill({contentType:'text/html',body:html(u.pathname.slice(1))});
    return route.fulfill({status:404,body:''});
  });
}
(async () => {
 const browser = await chromium.launch({headless:true, ...(process.env.BC_CHROME_EXECUTABLE ? {executablePath:process.env.BC_CHROME_EXECUTABLE} : {})});
 try {
  const context = await browser.newContext({viewport:{width:1440,height:1000}});
  await routes(context);
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.clock.install();
  const active = () => page.locator('[data-slide].is-active').getAttribute('aria-label');
  const advance = () => page.clock.fastForward(6100);
  await page.goto('http://slider.test/zero');
  check(await page.locator('.hero').count() === 1 && await page.locator('[data-homepage-slider]').count() === 0, 'zero slides retain banner');
  await page.goto('http://slider.test/one');
  check(await page.locator('[data-slide]').count() === 1 && await page.locator('[data-slider-controls], [data-rotation]').count() === 0, 'one slide has no controls');
  await page.goto('http://slider.test/multiple');
  await advance(); check(await active() === '2 of 3', 'six-second autoplay advances');
  await advance(); await advance(); check(await active() === '1 of 3', 'autoplay wraps');
  await page.locator('[data-homepage-slider]').hover(); const hovered = await active();
  await advance(); check(await active() === hovered, 'hover pauses');
  await page.mouse.move(0,0); await advance(); check(await active() !== hovered, 'leaving hover resumes');
  await page.locator('[data-rotation]').click(); await page.mouse.move(0,0); const paused = await active();
  await advance(); check(await active() === paused && await page.locator('[data-rotation]').innerText() === 'Resume slideshow', 'pointer Pause remains paused after focus and hover leave');
  await page.locator('[data-rotation]').press('Enter'); await advance(); check(await active() !== paused, 'keyboard Resume restarts');
  await page.keyboard.press('Tab'); const focused = await active(); await advance();
  check(await active() === focused, 'tabbing from Resume to content stops autoplay');
  await page.locator('[data-next]').click(); const manual = await active(); await page.mouse.move(0,0); await advance();
  check(await active() === manual, 'manual next pauses persistently');
  await page.locator('[data-dot="2"]').click(); check(await active() === '3 of 3', 'dot selects slide');
  check((await page.locator('[data-slider-status]').innerText()).includes('High Rollers'), 'manual slide change announced');
  await page.locator('[data-dot="2"]').press('ArrowRight'); check(await active() === '1 of 3', 'scoped ArrowRight wraps');
  await page.locator('[data-slide].is-active a').first().focus(); await page.keyboard.press('ArrowLeft');
  check(await active() === '3 of 3' && await page.locator('[data-prev]').evaluate(e=>e===document.activeElement), 'arrow key from outgoing link moves focus to persistent control');
  check(await page.locator('[data-slide]:not(.is-active)').evaluateAll(nodes=>nodes.every(n=>n.inert && n.getAttribute('aria-hidden')==='true')), 'inactive slides inert and hidden from assistive technology');
  await page.locator('#after').focus(); await page.keyboard.press('ArrowRight'); check(await active() === '3 of 3', 'outside arrow keys unaffected');
  await page.locator('[data-rotation]').focus(); await page.keyboard.press('Enter'); await page.mouse.move(0,0);
  await page.evaluate(()=>{ Object.defineProperty(document,'hidden',{configurable:true,value:true}); document.dispatchEvent(new Event('visibilitychange')); });
  const hidden = await active(); await advance(); check(await active() === hidden, 'hidden document suspends rotation');
  await page.evaluate(()=>{ delete document.hidden; document.dispatchEvent(new Event('visibilitychange')); });
  await advance(); check(await active() !== hidden, 'visible document resumes eligible rotation');
  await page.emulateMedia({reducedMotion:'reduce'}); await page.waitForFunction(()=>document.querySelector('[data-rotation]').hidden); const reduced = await active(); await advance();
  check(await active() === reduced && await page.locator('[data-rotation]').isHidden(), 'reduced motion disables autoplay');
  check(await page.locator('[data-slide]').first().evaluate(e=>parseFloat(getComputedStyle(e).transitionDuration)<0.001), 'reduced motion disables fade');
  await page.locator('[data-next]').click(); check(await active() !== reduced, 'reduced motion retains manual controls');
  await page.emulateMedia({reducedMotion:'no-preference'}); await page.waitForFunction(()=>!document.querySelector('[data-rotation]').hidden); const restored=await active(); await page.mouse.move(0,0); await advance();
  check(await active() === restored, 'leaving reduced motion does not silently resume');
  await page.locator('.bc-home-slider__image').first().evaluate(img=>{img.src='/missing.png';});
  await page.waitForFunction(()=>document.querySelector('.bc-home-slider__image').hidden);
  check(await page.locator('.bc-home-slider__image').first().isHidden(), 'failed image hidden over navy backdrop');
  const gallery=[];
  for (const width of [1440,1024,768,640,390,320]) {
    await page.setViewportSize({width,height:1000});
    await page.goto('http://slider.test/multiple');
    await page.emulateMedia({reducedMotion:'reduce'});
    await page.evaluate(()=>document.fonts.ready);
    const metrics=await page.locator('[data-homepage-slider]').evaluate(el=>({height:el.getBoundingClientRect().height, overflow:document.documentElement.scrollWidth>innerWidth, crop:getComputedStyle(el.querySelector('img')).objectPosition}));
    check(metrics.height===(width<=640?500:width<=1024?560:620) && !metrics.overflow, `reference height and no overflow at ${width}px`);
    check(metrics.crop==='90% 75%', `reference right-low crop at ${width}px`);
    await page.screenshot({path:path.join(preview,`slider-${width}.png`),fullPage:true});
    gallery.push(metrics);
    await page.locator('[data-dot="2"]').click();
    check(await page.locator('[data-slide].is-active img').evaluate(e=>getComputedStyle(e).objectPosition)==='50% 40%', `reference center crop at ${width}px`);
    await page.goto('http://slider.test/long');
    const fits=await page.locator('[data-homepage-slider]').evaluate(el=>{
      const r=el.getBoundingClientRect(), content=el.querySelector('.is-active .bc-home-slider__content').getBoundingClientRect(), dots=el.querySelector('.bc-home-slider__dots').getBoundingClientRect();
      return document.documentElement.scrollWidth<=innerWidth && content.left>=r.left && content.right<=r.right && content.bottom<=dots.top;
    });
    check(fits, `maximum content fits at ${width}px`);
  }
  await page.screenshot({path:path.join(preview,'slider-long-320.png'),fullPage:true});
  await page.addStyleTag({content:'html{font-size:200%} .bc-home-slider h1{font-size:64px} .bc-home-slider__text,.bc-home-slider .btn{font-size:32px}'});
  check(await page.locator('[data-homepage-slider]').evaluate(el=>el.querySelector('.is-active .bc-home-slider__content').getBoundingClientRect().bottom<=el.querySelector('.bc-home-slider__dots').getBoundingClientRect().top), 'enlarged text grows banner without clipping controls');
  const nojs=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:844}}); await routes(nojs);
  const plain=await nojs.newPage(); await plain.goto('http://slider.test/multiple');
  check(await plain.locator('[data-slide]:visible').count()===1 && await plain.locator('[data-slider-controls]').isHidden() && await plain.locator('[data-rotation]').isHidden(), 'without JavaScript first slide remains usable and controls hidden');
  await nojs.close();
  await page.goto('http://slider.test/admin');
  page.on('dialog', dialog=>dialog.accept());
  check(await page.locator('[data-slide-list] [data-slide]').count()===3, 'admin loads existing slides');
  await page.locator('[data-slide]').nth(2).locator('[data-move="up"]').press('Enter');
  check(await page.locator('[name="bc_homepage_slider[slides][1][heading]"]').inputValue()==='High Rollers', 'keyboard reorder preserves row values and reindexes form names');
  check(await page.locator('[data-move="up"]').first().isDisabled() && await page.locator('[data-move="down"]').last().isDisabled(), 'admin disables unavailable moves');
  for(let i=0;i<3;i++) await page.locator('[data-add-slide]').click();
  check(await page.locator('[data-add-slide]').isDisabled() && await page.locator('[name="bc_slider_count"]').inputValue()==='6', 'admin enforces six-slide limit');
  const last=page.locator('[data-slide-list] [data-slide]').last();
  await last.locator('[data-select-image]').click();
  check(await page.evaluate(()=>window.mediaOptions.library.type==='image' && window.mediaOptions.multiple===false), 'media frame configured for one native image');
  await page.evaluate(()=>window.selectFixtureMedia({id:900999,url:'/reference/assets/images/slide-cosmic.webp'}));
  await last.locator('[data-image-preview]').waitFor({state:'visible'});
  check(await last.locator('[data-image-id]').inputValue()==='900999' && await last.locator('[data-image-preview]').isVisible(), 'media selection result stored as ID and previewed');
  check(await last.locator('[data-select-image]').evaluate(e=>e===document.activeElement), 'media frame close returns focus');
  for(let i=0;i<6;i++) await page.locator('[data-slide-list] [data-remove-slide]').last().press('Enter');
  check(await page.locator('[name="bc_slider_count"]').inputValue()==='0' && await page.locator('[data-slide-list] [data-slide]').count()===0, 'removing all slides sends explicit zero count');
  check(await page.locator('[name="bc_slider_complete"]').inputValue()==='1' && await page.locator('[data-add-slide]').evaluate(e=>e===document.activeElement), 'empty form retains completion marker and keyboard focus');
  // Visual comparison uses the untouched reference folder. Supply the same
  // fonts locally because all non-local network requests are deliberately refused.
  const faces=fs.readFileSync(path.join(theme,'assets/css/main.css'),'utf8').split('/* ---- Tokens ---- */')[0]
    .replaceAll('Inter Variable','Inter').replaceAll('Baloo 2 Variable','Baloo 2').replaceAll('../fonts/','/theme/assets/fonts/');
  for(const width of [1440,390]) {
    await page.setViewportSize({width,height:1000}); await page.emulateMedia({reducedMotion:'no-preference'});
    await page.goto('http://slider.test/multiple'); await page.locator('[data-homepage-slider]').hover();
    await page.evaluate(()=>document.fonts.ready);
    await page.locator('[data-homepage-slider]').screenshot({path:path.join(preview,`implemented-${width}.png`)});
    await page.goto('http://slider.test/reference/front-page.html'); await page.addStyleTag({content:faces});
    await page.evaluate(()=>document.fonts.ready); await page.locator('#heroSlider').hover();
    await page.locator('#heroSlider').screenshot({path:path.join(preview,`reference-${width}.png`)});
  }
  check(errors.length===0, 'no browser JavaScript errors: ' + errors.join('; '));
  await context.close();
  console.log(`Homepage slider browser: ${passes} passed, 0 failed.`);
 } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
