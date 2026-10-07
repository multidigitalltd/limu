/* Read-only local REST regression. The actual 0.1.1 client is read from its published ZIP;
 * its settings POST is intercepted so the saved fixture configuration stays unchanged. */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

(async () => {
 const base = process.env.LIMU_TEST_URL || 'http://127.0.0.1:8090';
 assert.equal(new URL(base).hostname, '127.0.0.1', 'Cache regression requires local disposable fixtures');
 const credentials = JSON.parse(fs.readFileSync(process.env.LIMU_TEST_LOGIN, 'utf8'));
 const oldScript = execFileSync('python3', [
  '-c', 'import sys, zipfile; archive=zipfile.ZipFile(sys.argv[1]); sys.stdout.buffer.write(archive.read("limu-crm/assets/crm.min.js"))',
  path.resolve(__dirname, '../downloads/limu-crm-0.1.1.zip'),
 ]);
 assert.ok(oldScript.length > 10000, 'Published legacy script is available');
 const browser = await chromium.launch({headless:true, executablePath:process.env.LIMU_CHROMIUM || '/usr/bin/chromium', args:['--no-sandbox']});
 const context = await browser.newContext({locale:'he-IL', viewport:{width:1440, height:1000}});
 const page = await context.newPage();
 const checks = [], errors = [], requestedAssets = [];
 const fingerprint = /\/assets\/build\/(crm\.[a-f0-9]{12}\.min\.(?:js|css)|privacy\.[a-f0-9]{12}\.min\.js)$/;
 const legacyScriptUrl = /\/assets\/crm\.min\.js(?:\?|$)/;
 let stableAliasHits = 0;
 page.on('pageerror', error => errors.push(error.message));
 page.on('request', request => {
  if (/\/limu-crm\/assets\//.test(request.url())) requestedAssets.push(new URL(request.url()).pathname);
 });
 async function check(condition, label) {
  assert.ok(condition, label); checks.push(label); console.log('PASS', label);
 }
 try {
  // Simulate a cache that ignores query versions and keeps returning the 0.1.1 asset.
  await page.route(legacyScriptUrl, route => {
   stableAliasHits++;
   return route.fulfill({body:oldScript, contentType:'application/javascript'});
  });
  await page.goto(base + '/crm/');
  await check(await page.locator('link[rel="stylesheet"]').evaluateAll(elements => elements.filter(element => element.href.includes('/limu-crm/assets/')).every(element => /\/assets\/build\/crm\.[a-f0-9]{12}\.min\.css$/.test(new URL(element.href).pathname))) && requestedAssets.some(asset => /\/crm\.[a-f0-9]{12}\.min\.css$/.test(asset)) && requestedAssets.some(asset => /\/privacy\.[a-f0-9]{12}\.min\.js$/.test(asset)), 'Login uses content-addressed CSS and privacy assets');
  if (await page.locator('#privacy-ack').isVisible()) await page.locator('#privacy-ack').click();
  await page.getByLabel('שם משתמש או כתובת אימייל').fill(credentials.user);
  await page.getByLabel('סיסמה', {exact:true}).fill(credentials.password);
  await page.getByRole('button', {name:'כניסה למערכת'}).click();
  await page.getByRole('heading', {name:'סקירה כללית', exact:true}).waitFor();
  await check(stableAliasHits === 0 && requestedAssets.some(asset => /\/crm\.[a-f0-9]{12}\.min\.js$/.test(asset)) && requestedAssets.every(asset => fingerprint.test(asset)), 'Current portal bypasses a stale stable asset even when the cache ignores query versions');
  await page.locator('[data-view="settings"]').click();
  await page.getByRole('heading', {name:'הגדרות והרשאות', exact:true}).waitFor();
  await check(await page.locator('#settings-form').isVisible() && await page.locator('#load-error').count() === 0 && await page.locator('#add-form').count() === 0 && errors.length === 0, 'Current settings load through actual REST without the retired mapping UI or JavaScript errors');
  const actualSettings = await page.evaluate(async () => {
   const response = await fetch(window.LimuCRM.api + 'bootstrap', {headers:{'X-WP-Nonce':window.LimuCRM.nonce}});
   if (!response.ok) throw new Error('Actual bootstrap unavailable');
   return (await response.json()).settings;
  });
  await check(Array.isArray(actualSettings.forms) && actualSettings.forms.length === 0, 'Actual manager bootstrap retains an empty legacy forms array for cached clients');

  const legacyPage = await context.newPage();
  const legacyErrors = [], actualGetEndpoints = new Set();
  let legacyScriptHits = 0, legacyPayload, rewrittenDocument = false;
  legacyPage.on('pageerror', error => legacyErrors.push(error.message));
  await legacyPage.route(legacyScriptUrl, route => {
   legacyScriptHits++;
   return route.fulfill({body:oldScript, contentType:'application/javascript'});
  });
  await legacyPage.route('**/limu-crm/v1/**', route => {
   const request = route.request();
   if (request.method() !== 'POST') {
    actualGetEndpoints.add(new URL(request.url()).pathname.split('/').at(-1));
    return route.continue();
   }
   assert.equal(new URL(request.url()).pathname.split('/').at(-1), 'settings', 'Only the legacy settings write may be simulated');
   legacyPayload = request.postDataJSON();
   return route.fulfill({status:200, contentType:'application/json', body:JSON.stringify({saved:true})});
  });
  await legacyPage.route('**/crm/**', async route => {
   if (route.request().resourceType() !== 'document') return route.continue();
   const response = await route.fetch();
   const document = await response.text();
   const rewritten = document.replace(/(\/assets)\/build\/crm\.[a-f0-9]{12}\.min\.js(?=[?"'])/g, '$1/crm.min.js');
   rewrittenDocument = rewritten !== document;
   return route.fulfill({response, body:rewritten});
  });
  await legacyPage.goto(base + '/crm/');
  await legacyPage.getByRole('heading', {name:'התמונה המלאה, במקום אחד', exact:true}).waitFor();
  await legacyPage.locator('[data-view="settings"]').click();
  await legacyPage.getByRole('heading', {name:'הגדרות והרשאות', exact:true}).waitFor();
  await check(rewrittenDocument && legacyScriptHits === 1 && actualGetEndpoints.has('bootstrap') && actualGetEndpoints.has('summary') && await legacyPage.locator('#settings-form').isVisible() && await legacyPage.locator('#load-error').count() === 0 && legacyErrors.length === 0, 'Published 0.1.1 JavaScript renders settings against actual current REST without the undefined-length failure');
  await legacyPage.getByRole('button', {name:'שמירת הגדרות', exact:true}).click();
  await legacyPage.waitForFunction(() => document.getElementById('notification').textContent === 'הנתונים נשמרו');
  await check(legacyPayload && Object.prototype.hasOwnProperty.call(legacyPayload, 'forms') && Array.isArray(legacyPayload.forms) && legacyPayload.forms.length === 0 && legacyPayload.duplicate_mode === actualSettings.duplicate_mode && legacyPayload.start_date === actualSettings.start_date && legacyPayload.automatic === actualSettings.automatic && await legacyPage.locator('#settings-form').isVisible() && legacyErrors.length === 0, 'Cached 0.1.1 client sends the compatible empty forms array and refreshes after its simulated save');
  await legacyPage.close();
  console.log(checks.length + ' cache and settings compatibility browser checks passed.');
 } finally {
  await browser.close();
 }
})().catch(error => {console.error(error.message); process.exit(1);});
