/**
 * Static fixture output only; all requests intercepted. No live Hall or admin writes.
 * BC_PLAYWRIGHT_MODULE=/path/to/playwright BC_CATEGORY_PREVIEW_DIR=/private/tmp/... \
 * BC_CHROME_EXECUTABLE=/path/to/Chrome node tests/homepage-categories-browser.cjs
 * Main image URLs are adapted to read-only reference artwork. Native wp.media is
 * a test double here; the PHP suite exercises the real current-Hall media query.
 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.BC_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const theme = path.join(root, 'wp-content/themes/bingocommerce-bingothemev1');
const core = path.join(root, 'wp-content/plugins/bingocommerce-core');
const reference = path.join(root, '../bingocommer-frontendstore');
const preview = process.env.BC_CATEGORY_PREVIEW_DIR;
if (!preview || !fs.existsSync(path.join(preview, 'cards-5.html'))) throw Error('Export fresh guarded fixture previews first.');
const pictures = ['card-today.webp','card-sessions.webp','card-novelties.webp','card-supplies.webp'];
let passes = 0;
function check(condition, label) { assert.ok(condition, label); passes++; console.log('PASS ' + label); }
function adaptImages(html) {
 let index = 0;
 return html.replace(/<img\b[^>]*>/g, tag => {
  const file = tag.includes('bc-category-card__image') ? pictures[index++ % pictures.length] : 'category-bg.webp';
  // Match metadata to the substituted read-only artwork; WordPress auto-sizes uses these dimensions.
  const dimensions = file === 'category-bg.webp' ? [1959,803] : [1122,1402];
  return tag.replace(/src="[^"]*"/, `src="/reference/assets/images/${file}"`).replace(/srcset="[^"]*"/, '').replace(/\bwidth="[^"]*"/, `width="${dimensions[0]}"`).replace(/\bheight="[^"]*"/, `height="${dimensions[1]}"`);
 });
}
async function imagesReady(page) {
 // Decode all lazy artwork for full-page visual comparisons, including below the viewport.
 await page.locator('main img').evaluateAll(images=>Promise.all(images.map(image=>{image.loading='eager';return image.decode()})));
}
function body(name) {
 const source = fs.readFileSync(path.join(preview, name + '.html'),'utf8');
 const section = source.match(/<section class="bc-home-categories"[\s\S]*?<\/section>/);
 return section ? adaptImages(section[0]) : '';
}
const mediaDouble = `window.bcHomepageCategories={card:'Card %d',select:'Select image',use:'Use image',remove:'Remove card?',changed:'Category cards changed'};
window.bcHomepageAdmin={slide:'Slide %d',select:'Select image',use:'Use image',remove:'Remove slide?',changed:'Slider changed'};
window.wp={a11y:{speak:message=>window.lastAnnouncement=message},media:options=>{window.mediaOptions=options;const handlers={};let selected;
const frame={on:(key,fn)=>handlers[key]=fn,state:()=>({get:()=>({first:()=>({toJSON:()=>selected})})}),open:()=>{window.pickImage=image=>{selected=image;handlers.select();handlers.close()}}};return frame}};`;
async function routes(context) {
 await context.route('**/*', async route => {
  const url = new URL(route.request().url());
  if (url.hostname !== 'categories.test') return route.abort();
  for (const [prefix, base] of [['/theme/',theme],['/core/',core],['/reference/',reference],['/wp-admin/css/',path.join(root,'wp-admin/css')],['/wp-includes/css/',path.join(root,'wp-includes/css')]]) {
   if (url.pathname.startsWith(prefix)) {
    const file = path.resolve(base, '.' + url.pathname.slice(prefix.length - 1));
    if (!file.startsWith(base + path.sep) || !fs.existsSync(file)) return route.abort();
    const mime = {'.css':'text/css','.js':'application/javascript','.woff2':'font/woff2','.webp':'image/webp'}[path.extname(file)] || 'application/octet-stream';
    return route.fulfill({contentType:mime,body:fs.readFileSync(file)});
   }
  }
  if (url.pathname === '/admin' || url.pathname === '/wp-admin/themes.php') {
   // Render real exported PHP tabs, rewriting fixture-host links only. No browser POST is accepted.
   if (route.request().method()!=='GET') return route.abort();
   const category=url.pathname==='/admin' || url.searchParams.get('tab')==='categories';
   const source = fs.readFileSync(path.join(preview,category?'admin.html':'admin-slider.html'),'utf8')
    .replace(/https?:\/\/[^"\s]+\/wp-admin\/themes\.php/g,'http://categories.test/wp-admin/themes.php')
    .replace(/<img\b[^>]*>/g,tag=>tag.replace(/src="[^"]*"/,`src="/reference/assets/images/${tag.includes('data-image-preview')?'slide-early-bird.webp':'card-today.webp'}"`));
   return route.fulfill({contentType:'text/html',body:`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/wp-admin/css/common.css"><link rel="stylesheet" href="/wp-admin/css/forms.css"><link rel="stylesheet" href="/wp-includes/css/buttons.css"><link rel="stylesheet" href="/core/assets/css/homepage-admin.css"><link rel="stylesheet" href="/core/assets/css/homepage-categories-admin.css"></head><body class="wp-admin wp-core-ui">${source}<script>${mediaDouble}</script><script src="/core/assets/js/homepage-admin.js"></script><script src="/core/assets/js/homepage-categories-admin.js"></script></body></html>`});
  }
  if (!['/reference','/reference-match','/backgrounds','/long','/coverage-1','/coverage-5',...Array.from({length:6},(_,i)=>'/cards-'+i)].includes(url.pathname)) return route.fulfill({status:404,body:''});
  const isReference = url.pathname === '/reference';
  const content = isReference ? fs.readFileSync(path.join(reference,'front-page.html'),'utf8').match(/<section class="category-picker"[\s\S]*?<\/section>/)[0].replace(/src="assets\//g, 'src="/reference/assets/') : body(url.pathname.slice(1));
  return route.fulfill({contentType:'text/html',body:`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${isReference ? '/reference/assets/css/style.css' : '/theme/assets/css/main.css'}"><style>@font-face{font-family:'Inter';src:url('/theme/assets/fonts/inter/inter-latin-wght-normal.woff2')}@font-face{font-family:'Baloo 2';src:url('/theme/assets/fonts/baloo-2/baloo-2-latin-wght-normal.woff2')}.reveal{opacity:1;transform:none}</style></head><body><a id="before" href="#after">Before categories</a><main>${content}</main><a id="after" href="#before">After categories</a></body></html>`});
 });
}
(async () => {
 const browser = await chromium.launch({headless:true,...(process.env.BC_CHROME_EXECUTABLE ? {executablePath:process.env.BC_CHROME_EXECUTABLE} : {})});
 try {
  const context = await browser.newContext({viewport:{width:1440,height:1000}}); await routes(context);
  const page = await context.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  for (const width of [1440,1024,768,640,390,320]) {
   await page.setViewportSize({width,height:1000});
   for (let count=1;count<=5;count++) {
    await page.goto(`http://categories.test/cards-${count}`);
    await page.evaluate(()=>document.fonts.ready); await imagesReady(page);
    const cards = page.locator('.bc-category-card');
    const boxes = await cards.evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height}}));
    const columns = width<=640 ? 1 : width<=1024 ? 2 : 4;
    check(boxes.length===count && boxes.every(b=>b.width>0 && b.x>=0 && b.x+b.width<=width), `${width}px / ${count} cards: visible within viewport`);
    const lastRow = boxes.filter(b=>Math.abs(b.y-boxes.at(-1).y)<1);
    check(Math.abs(lastRow[0].x + lastRow.at(-1).x + lastRow.at(-1).width - width)<2, `${width}px / ${count} cards: last row centered`);
    check(new Set(boxes.map(b=>Math.round(b.y))).size===Math.ceil(count/columns), `${width}px / ${count} cards: expected wrapping`);
    check(await page.evaluate(()=>document.documentElement.scrollWidth===innerWidth), `${width}px / ${count} cards: no overflow`);
    if (width<=640) check(boxes.every(b=>Math.abs(b.width-280)<1), `${width}px / ${count} cards: mobile cards capped at 280px`);
   }
   for (const count of [1,5]) {
    await page.goto(`http://categories.test/coverage-${count}`);
    await page.evaluate(()=>document.fonts.ready); await imagesReady(page);
    const coverage = await page.locator('.bc-home-categories').evaluate(section=>{
     const background=section.querySelector('.bc-home-categories__background');
     const bounds=section.getBoundingClientRect(); const image=background.getBoundingClientRect();
     const cards=Array.from(section.querySelectorAll('.bc-category-card'));
     const lastBottom=Math.max(...cards.flatMap(card=>[card.getBoundingClientRect().bottom,card.querySelector('.bc-category-card__arrow').getBoundingClientRect().bottom]));
     return {count:cards.length,decoded:background.complete && background.naturalWidth>0,
      coversSection:['top','left','right','bottom'].every(edge=>Math.abs(bounds[edge]-image[edge])<1),
      coversCards:image.bottom>=lastBottom,fit:getComputedStyle(background).objectFit,
      noOverflow:section.scrollWidth<=section.clientWidth && document.documentElement.scrollWidth===innerWidth,
      textGap:cards.every(card=>card.querySelector('.bc-category-card__arrow').getBoundingClientRect().top-card.querySelector('.bc-category-card__text').getBoundingClientRect().bottom>=11.9)};
    });
    check(coverage.count===count && coverage.decoded && coverage.coversSection && coverage.fit==='cover', `${width}px / ${count} cards: decoded section background covers every edge`);
    check(coverage.coversCards, `${width}px / ${count} cards: background reaches last card row and arrows`);
    check(coverage.noOverflow, `${width}px / ${count} cards: full-height background has no overflow`);
    check(coverage.textGap, `${width}px / ${count} cards: text panels have at least 12px clearance from arrows`);
    const layers = () => page.locator('.bc-category-card__image, .bc-category-card__background').evaluateAll(images=>images.map(image=>{
     const rect=image.getBoundingClientRect(), style=getComputedStyle(image);
     return {className:image.className,src:image.currentSrc,width:rect.width,height:rect.height,x:rect.x,y:rect.y,
      fit:style.objectFit,radius:style.borderRadius,decoded:image.complete && image.naturalWidth>0};
    }));
    const after=await layers();
    check(after.filter(image=>image.className.includes('bc-category-card__image')).length===count && after.every(image=>image.decoded && image.fit===(image.className.includes('bc-category-card__image')?'contain':'cover')) && after.length===count+1, `${width}px / ${count} cards: main artwork and retained card background decode with unchanged image treatment`);
    // Compare with the former section-only rule: card artwork/background geometry must not change.
    await page.locator('.bc-home-categories__background').evaluate(image=>{image.style.cssText='inset:0 0 auto;height:auto;object-fit:fill'});
    check(JSON.stringify(after)===JSON.stringify(await layers()), `${width}px / ${count} cards: section coverage change leaves card layers unchanged`);
    await page.locator('.bc-home-categories__background').evaluate(image=>image.removeAttribute('style'));
    if ([1440,390].includes(width)) await page.screenshot({path:path.join(preview,`coverage-${count}-${width}.png`),fullPage:true});
   }
   if ([1440,390].includes(width)) {
    await page.goto('http://categories.test/reference-match'); await imagesReady(page); await page.evaluate(()=>document.fonts.ready); await page.screenshot({path:path.join(preview,`categories-${width}.png`),fullPage:true});
    const actual=await page.locator('.bc-category-card__image').first().boundingBox();
    await page.goto('http://categories.test/reference'); await imagesReady(page); await page.evaluate(()=>document.fonts.ready); await page.screenshot({path:path.join(preview,`reference-${width}.png`),fullPage:true});
    const original=await page.locator('.category-card img').first().boundingBox();
    if (width>640) check(Math.abs(actual.width-original.width)<1 && Math.abs(actual.height-original.height)<1, `${width}px: desktop image dimensions match original reference`);
    else check(Math.abs(actual.width-280)<1 && Math.abs(actual.height/actual.width-1402/1122)<0.001 && actual.width<original.width, `${width}px: mobile artwork is smaller while retaining reference proportions`);
   }
  }
  await page.goto('http://categories.test/cards-0'); check(await page.locator('.bc-home-categories').count()===0,'zero cards hide section');
  await page.goto('http://categories.test/backgrounds'); await imagesReady(page);
  check(await page.locator('.bc-home-categories__background').count()===1 && await page.locator('.bc-category-card__background').count()===1, 'section and card backgrounds are independent decorative layers');
  check(await page.locator('.bc-category-card__image').first().evaluate(e=>getComputedStyle(e).objectFit)==='contain', 'transparent main artwork is contained, not cropped');
  check(await page.locator('.bc-category-card').first().getAttribute('aria-label')==='Alpha supplies' && await page.locator('.bc-category-card').nth(1).getAttribute('aria-label')==='Alpha empty category', 'image-only cards have descriptive ALT/category-derived names');
  check(await page.locator('.bc-category-card__text').count()===0, 'image-only cards do not force visible labels');
  await page.locator('#before').focus(); await page.keyboard.press('Tab');
  check(await page.locator('.bc-category-card').first().evaluate(e=>e===document.activeElement && parseInt(getComputedStyle(e).outlineWidth)>=3), 'keyboard enters named card with visible focus');
  await page.keyboard.press('Tab'); check(await page.locator('.bc-category-card').nth(1).evaluate(e=>e===document.activeElement),'one tab stop per card; backgrounds/arrows not focusable');
  await page.goto('http://categories.test/long'); await imagesReady(page); await page.evaluate(()=>document.fonts.ready);
  check(await page.locator('.bc-category-card').evaluate(card=>card.querySelector('.bc-category-card__arrow').getBoundingClientRect().top-card.querySelector('.bc-category-card__text').getBoundingClientRect().bottom>=11.9),'wrapped long text remains clear of arrow');
  check(await page.evaluate(()=>document.documentElement.scrollWidth===innerWidth),'long visible text wraps without overflow');
  await page.emulateMedia({reducedMotion:'reduce'}); await page.locator('.bc-category-card').hover();
  check(await page.locator('.bc-category-card').evaluate(e=>getComputedStyle(e).transform)==='none','reduced motion suppresses card hover movement');
  const noJS=await browser.newContext({javaScriptEnabled:false}); await routes(noJS); const staticPage=await noJS.newPage();
  await staticPage.goto('http://categories.test/cards-5'); check(await staticPage.locator('.bc-category-card').count()===5 && await staticPage.locator('.bc-category-card').last().isVisible(),'all storefront cards visible without JavaScript');
  await page.goto('http://categories.test/admin');
  check(await page.locator('#bc-form').count()===0,'category tab does not render slider form');
  const form=page.locator('#bc-category-form');
  await form.locator('[data-add-card]').press('Enter');
  check(await form.locator('[data-category-card]').count()===2 && await page.locator('[data-category-card]').last().locator('[data-category-select]').first().evaluate(e=>e===document.activeElement),'keyboard Add focuses new main-image selector');
  const second=form.locator('[data-category-card]').nth(1);
  await second.locator('[data-category-select]').first().click();
  check(await page.evaluate(()=>window.mediaOptions.library.type==='image' && window.mediaOptions.multiple===false),'native media frame requested for one image');
  await page.evaluate(()=>window.pickImage({id:900005,url:'/reference/assets/images/card-today.webp'}));
  check(await second.locator('[data-category-image]').first().inputValue()==='900005','media selection stores attachment ID');
  check(await form.locator('[data-category-card] [data-category-media]').count()===2 && await form.getByText('Card background (optional)',{exact:true}).count()===0,'card-background picker removed from saved and new cards');
  check(await second.locator('input[name$="[background_id]"]').inputValue()==='0','new cards have no individual background');
  const retained=form.locator('[data-category-card]').first();
  check(await retained.locator('input[name$="[background_id]"]').inputValue()==='900002','existing card background remains in submitted form');
  await second.locator('[data-category-remove-image]').click();
  check(await second.locator('[data-category-image]').inputValue()==='0','main image removal clears only its reference');
  await second.locator('[data-category-select]').click(); await page.evaluate(()=>window.pickImage({id:900005,url:'/reference/assets/images/card-today.webp'}));
  const section=form.locator('[data-category-media]').first();
  await section.locator('[data-category-select]').click(); await page.evaluate(()=>window.pickImage({id:900006,url:'/reference/assets/images/category-bg.webp'}));
  check(await section.locator('[data-category-image]').inputValue()==='900006' && await retained.locator('input[name$="[background_id]"]').inputValue()==='900002','section background selection preserves existing card background');
  await section.locator('[data-category-remove-image]').click();
  check(await section.locator('[data-category-image]').inputValue()==='0' && await retained.locator('input[name$="[background_id]"]').inputValue()==='900002','section background removal preserves existing card background');
  await second.locator('[data-destination-type]').selectOption('category');
  await second.locator('select[name$="[category_id]"]').selectOption({label:'Alpha empty category'});
  await second.locator('[data-destination-type]').selectOption('url');
  check(await second.locator('select[name$="[category_id]"]').inputValue()==='0','switching to URL clears inactive category');
  await second.locator('input[type="url"]').fill('https://example.com/'); await second.locator('[data-destination-type]').selectOption('category');
  check(await second.locator('input[type="url"]').inputValue()==='','switching to category clears inactive URL');
  await second.locator('[data-category-move="up"]').press('Enter');
  check(await form.locator('[data-category-card]').first().locator('[data-category-image]').first().inputValue()==='900005','keyboard reorder preserves selected image with card');
  check(await form.locator('[data-category-card]').nth(1).locator('input[name="bc_homepage_categories[cards][1][background_id]"]').inputValue()==='900002','reorder preserves existing background with its card');
  check(await form.locator('[data-category-card]').first().locator('[data-category-move="down"]').evaluate(e=>e===document.activeElement),'reorder retains keyboard focus on enabled control');
  for(let i=0;i<3;i++) await form.locator('[data-add-card]').click();
  check(await form.locator('[data-category-card]').count()===5 && await form.locator('[data-add-card]').isDisabled(),'UI caps at five cards');
  check(await form.locator('[name^="bc_homepage_slider"]').count()===0,'category form submits no slider settings');
  page.on('dialog',dialog=>dialog.accept());
  for(let i=0;i<5;i++) await form.locator('[data-remove-card]').first().click();
  check(await form.locator('[name="bc_category_count"]').inputValue()==='0' && await form.locator('[name="bc_category_complete"]').inputValue()==='1','remove all retains explicit zero count and completion marker');
  check(await form.locator('[data-add-card]').evaluate(e=>e===document.activeElement),'removing final card returns focus to Add');
  for (const width of [1440,390]) {
   await page.setViewportSize({width,height:1000});
   await page.goto('http://categories.test/wp-admin/themes.php?page=bingocommerce-homepage');
   check(await page.locator('#bc-form').isVisible() && await page.locator('#bc-category-form').count()===0,`${width}px: default tab shows only slider editor`);
   check(await page.locator('.nav-tab-active').innerText()==='Homepage slider' && await page.locator('[aria-current="page"]').count()===1,`${width}px: native tabs identify current slider page`);
   await page.locator('.nav-tab-active').focus(); await page.keyboard.press('Tab');
   check(await page.getByRole('link',{name:'Shop by Category',exact:true}).evaluate(e=>e===document.activeElement && (getComputedStyle(e).boxShadow!=='none' || parseFloat(getComputedStyle(e).outlineWidth)>0)),`${width}px: keyboard reaches category tab with focus styling`);
   await page.screenshot({path:path.join(preview,`admin-slider-${width}.png`)});
   await page.keyboard.press('Enter'); await page.locator('#bc-category-form').waitFor();
   check(await page.locator('#bc-form').count()===0 && await page.locator('.nav-tab-active').innerText()==='Shop by Category',`${width}px: keyboard activation opens only category editor`);
   check(new URL(await page.locator('#bc-category-form').getAttribute('action')).searchParams.get('tab')==='categories',`${width}px: category form posts back to its own tab`);
   const tabs=await page.locator('.nav-tab').evaluateAll(nodes=>nodes.map(e=>{const r=e.getBoundingClientRect();return {top:r.top,right:r.right}}));
   check(Math.abs(tabs[0].top-tabs[1].top)<1 && tabs.every(tab=>tab.right<=width),`${width}px: native tabs fit side by side`);
   await page.screenshot({path:path.join(preview,`admin-categories-${width}.png`)});
   await page.getByRole('link',{name:'Homepage slider',exact:true}).click(); await page.locator('#bc-form').waitFor();
   check(new URL(await page.locator('#bc-form').getAttribute('action')).searchParams.get('tab')==='slider',`${width}px: slider form posts back to its own tab`);
  }
  await staticPage.goto('http://categories.test/wp-admin/themes.php?page=bingocommerce-homepage');
  await staticPage.getByRole('link',{name:'Shop by Category',exact:true}).click(); await staticPage.locator('#bc-category-form').waitFor();
  check(await staticPage.locator('#bc-form').count()===0 && await staticPage.locator('.nav-tab-active').innerText()==='Shop by Category','native tab navigation works with JavaScript disabled');
  check(errors.length===0,'no browser JavaScript errors: '+errors.join('; '));
  console.log(`Homepage categories browser: ${passes} passed, 0 failed.`);
 } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
