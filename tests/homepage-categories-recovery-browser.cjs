/**
 * Browser half of the guarded PHP recovery test. HTML arrives on stdin from the
 * active disposable Hall; the actual form submission returns to its PHP handler.
 * No HTTP request is allowed and no database is opened by this process.
 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
if (process.env.BC_TEST_DB !== 'bingocommerce_state_recon' || process.env.BC_CATEGORY_RECOVERY_BROWSER !== '1') {
 throw Error('Run through the guarded homepage-categories.php recovery fixture.');
}
const {chromium} = require(process.env.BC_PLAYWRIGHT_MODULE || 'playwright');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const script = path.resolve(__dirname, '../wp-content/plugins/bingocommerce-core/assets/js/homepage-categories-admin.js');
const checks = [];
function check(value, label) { assert.ok(value, label); checks.push(label); }
(async () => {
 const browser = await chromium.launch({headless:true,...(process.env.BC_CHROME_EXECUTABLE ? {executablePath:process.env.BC_CHROME_EXECUTABLE} : {})});
 try {
  const page = await browser.newPage(); const errors = [];
  page.on('pageerror', error=>errors.push(error.message));
  await page.route('**/*', route=>route.abort());
  await page.setContent(input.html);
  const form = page.locator('#bc-category-form');
  const entries = () => form.evaluate(form=>Array.from(new FormData(form, form.querySelector('[type="submit"]')).entries()));
  const initial = await entries();
  await page.evaluate(()=>{
   // Media is never opened here; the guard permits the real editor script to initialize.
   window.wp={media:()=>{throw Error('Unexpected media picker');},a11y:{speak:message=>window.announcement=message}};
   window.bcHomepageCategories={card:'Card %d',changed:'Category cards changed',backgroundRemoved:'Background removal pending save'};
  });
  await page.addScriptTag({content:fs.readFileSync(script,'utf8')});
  check(JSON.stringify(initial)===JSON.stringify(await entries()),'page initialization preserves all submitted values');
  check(await form.locator('[data-remove-existing-background]:visible').count()===initial.filter(([name,value])=>/\[cards\]\[\d+\]\[background_id\]$/.test(name) && Number(value)>0).length,'removal buttons appear only for retained positive IDs');
  let requests = 0; page.on('request',()=>requests++);
  const index = input.index ?? 1;
  if (input.action === 'remove') {
   const fieldName = `bc_homepage_categories[cards][${index}][background_id]`;
   const checkLinks = async rowIndex => {
    const target = `bc-category-card-${rowIndex}-background_id`;
    const links = page.locator(`.notice a[href="#${target}"]`);
    check(await links.count()>=2,'warning and validation-error links both target retained background');
    for (const link of await links.all()) {
     await link.focus(); await page.keyboard.press('Enter');
     check(await page.locator('#'+target).evaluate(e=>e===document.activeElement),'warning/error link focuses the recovery controls');
    }
   };
   if (input.errorLinks) {
    await checkLinks(index);
    await form.locator('[data-category-card]').nth(index).locator('[data-category-move="up"]').press('Enter');
    await checkLinks(index-1);
    await form.locator('[data-category-card]').nth(index-1).locator('[data-category-move="down"]').press('Enter');
    await checkLinks(index);
    check(JSON.stringify(initial)===JSON.stringify(await entries()),'reordering back retains every field and restores original order');
   }
   const row = form.locator('[data-category-card]').nth(index);
   await row.locator('[data-remove-existing-background]').focus(); await page.keyboard.press('Space');
   const expected = initial.map(([name,value])=>[name,name===fieldName?'0':value]);
   check(JSON.stringify(expected)===JSON.stringify(await entries()),'keyboard removal changes only the selected hidden background ID');
   check(await row.locator('[data-category-legacy-background]').evaluate(e=>e===document.activeElement),'focus remains in the recovered card');
   check(await row.locator('[data-background-removal-pending]').isVisible() && await row.locator('[data-remove-existing-background]').isHidden(),'pending-save status replaces removal button');
   check(await page.evaluate(()=>window.announcement)==='Background removal pending save','removal is announced for assistive technology');
   if (input.errorLinks) await checkLinks(index);
  } else if (input.action === 'reorder') {
   await form.locator('[data-category-card]').nth(index).locator('[data-category-move="up"]').press('Enter');
   check(await form.locator('[data-category-card]').count()===2,'keyboard reorder retains both cards');
  } else {
   assert.equal(input.action,'save');
  }
  check(requests===0,'editing sends no request or attachment deletion');
  const expected = await entries();
  await form.evaluate(form=>form.addEventListener('submit',event=>{
   event.preventDefault();
   window.submitted=new URLSearchParams(new FormData(form,event.submitter)).toString();
  }));
  await form.locator('[type="submit"]').press('Enter');
  const submission = await page.evaluate(()=>window.submitted);
  check(JSON.stringify(Array.from(new URLSearchParams(submission).entries()))===JSON.stringify(expected),'Save submits the actual rendered form fields');
  check(errors.length===0,'no browser JavaScript errors');
  process.stdout.write(JSON.stringify({submission,checks}));
 } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
