/* Local cache regression. The published 0.1.1 client may still read REST, while its
 * retired global duplicate-policy write must fail closed without changing settings. */
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
  await check(stableAliasHits === 0 && requestedAssets.some(asset => /\/crm\.[a-f0-9]{12}\.min\.js$/.test(asset)) && requestedAssets.filter(asset => /\.(?:js|css)$/.test(asset)).every(asset => fingerprint.test(asset)), 'Current portal bypasses a stale stable asset even when the cache ignores query versions');
  await page.evaluate(async()=>{await Promise.all([document.fonts.load('400 16px "CRM Ploni"'),document.fonts.load('700 16px "CRM Ploni"')]);});
  const fontAssets=[...new Set(requestedAssets.filter(asset => /\.woff2$/.test(asset)))];
  await check(requestedAssets.some(asset => /\/brand\/logo\.[a-f0-9]{12}\.png$/.test(asset)) && fontAssets.length === 2 && fontAssets.every(asset => /\/fonts\/ploni-(?:medium|black)\.[a-f0-9]{12}\.woff2$/.test(asset)), 'The original logo and both local font weights have immutable content fingerprints');
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
  let legacyScriptHits = 0, legacyPayload, legacyStatus, rewrittenDocument = false;
  legacyPage.on('pageerror', error => legacyErrors.push(error.message));
  await legacyPage.route(legacyScriptUrl, route => {
   legacyScriptHits++;
   return route.fulfill({body:oldScript, contentType:'application/javascript'});
  });
  await legacyPage.route('**/limu-crm/v1/**', async route => {
   const request = route.request();
   if (request.method() !== 'POST') {
    actualGetEndpoints.add(new URL(request.url()).pathname.split('/').at(-1));
    return route.continue();
   }
   assert.equal(new URL(request.url()).pathname.split('/').at(-1), 'settings', 'Only the legacy settings rejection may be exercised');
   legacyPayload = request.postDataJSON();
   const response = await route.fetch();
   legacyStatus = response.status();
   return route.fulfill({response});
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
  await legacyPage.waitForFunction(() => document.querySelector('#settings-form .form-error')?.textContent.length > 0);
  const afterLegacySettings = await page.evaluate(async()=>{const response=await fetch(window.LimuCRM.api+'bootstrap',{headers:{'X-WP-Nonce':window.LimuCRM.nonce}});if(!response.ok)throw new Error('Settings comparison unavailable');return (await response.json()).settings;});
  await check(legacyPayload && Object.prototype.hasOwnProperty.call(legacyPayload, 'forms') && Array.isArray(legacyPayload.forms) && legacyPayload.forms.length === 0 && legacyPayload.start_date === actualSettings.start_date && legacyPayload.automatic === actualSettings.automatic && legacyStatus === 409 && (await legacyPage.locator('#settings-form .form-error').textContent()).includes('לכל מוסד בנפרד') && (await legacyPage.locator('#notification').textContent()) !== 'הנתונים נשמרו' && JSON.stringify(afterLegacySettings) === JSON.stringify(actualSettings) && await legacyPage.locator('#settings-form').isVisible() && legacyErrors.length === 0, 'Cached global-policy clients receive a refresh error and cannot change current institution settings');
  await legacyPage.close();
  console.log(checks.length + ' cache and settings compatibility browser checks passed.');
 } finally {
  await browser.close();
 }
})().catch(error => {console.error(error.message); process.exit(1);});
