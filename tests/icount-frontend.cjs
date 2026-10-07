/* Local browser regression: every fiscal REST request is intercepted. No provider
 * request, document issuance, email, or fixture database mutation is performed. */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { isDeepStrictEqual: equal } = require('node:util');

(async () => {
 const base = process.env.LIMU_TEST_URL || 'http://127.0.0.1:8090';
 assert.equal(new URL(base).hostname, '127.0.0.1', 'Fiscal UI regression requires local fixtures');
 const managerLogin = JSON.parse(fs.readFileSync(process.env.LIMU_TEST_LOGIN, 'utf8'));
 const memberLogin = JSON.parse(fs.readFileSync(process.env.LIMU_MEMBER_LOGIN, 'utf8'));
 const browser = await chromium.launch({headless:true, executablePath:process.env.LIMU_CHROMIUM || '/usr/bin/chromium', args:['--no-sandbox']});
 const context = await browser.newContext({locale:'he-IL', viewport:{width:1440, height:1000}});
 const page = await context.newPage();
 const checks = [], errors = [], posts = [], external = [];
 const hostile = '<img src=x onerror="window.__icountXss=true">';
 const clone = value => JSON.parse(JSON.stringify(value));
 const connection = {configured:true, verified:true, company:{name:hostile, vat_id:'123456789'}, bank_accounts:[{id:7, title:hostile}], automatic:false, doctypes:['deal','invoice','invrec','receipt']};
 const bootstrap = {manager:true, today:'2026-10-07', month:'2026-10', settings:{duplicate_mode:'calendar', duplicate_days:30, start_date:'', automatic:false}, icount:clone(connection), institutions:[{id:1, name:'מוסד בדיקה', agreement:{credit_days:30, rates:[{from:'2026-01-01', price:1000, vat_bp:1800}]}, icount_client:null}]};
 const originalBill = {id:92, institution:1, month:'2026-09', state:'approved', subtotal:1000, vat:180, total:1180, paid:0, issued:'2026-10-01', due:'2026-10-31', duplicates:0, historical:0, lines:[{delivery:91, price:1000}], documents:[]};
 const originalPayment = {id:301, bill:92, institution:1, amount:1180, date:'2026-10-07', method:'cash', reference:hostile, documents:[]};
 let bill = clone(originalBill), payments = [], paymentDoctype = 'invrec', failIssue = false;
 function issued(doctype, target = 92, docnum = 701) {
  return {key:doctype + ':' + target, state:'issued', doctype, docnum, target, total:target === 92 ? bill.total : payments.find(payment => payment.id === target).amount, url:'https://app.icount.co.il/docs/fixture?x=1&y=2', issued:'2026-10-07'};
 }
 function waiting(doctype = 'invoice', target = 92) {
  return {key:doctype + ':' + target, state:'unknown', doctype, docnum:0, target, total:1180, url:'', issued:''};
 }
 const json = (route, body, status = 200) => route.fulfill({status, contentType:'application/json', body:JSON.stringify(body)});
 const lastPost = endpoint => posts.filter(post => post.endpoint === endpoint).at(-1)?.body;
 const postCount = endpoint => posts.filter(post => post.endpoint === endpoint).length;
 async function check(condition, label) {assert.ok(condition, label); checks.push(label); console.log('PASS', label);}
 async function saved(p = page) {await p.waitForFunction(() => {const notification=document.getElementById('notification');return notification.textContent.trim().length>0&&!notification.classList.contains('error');});}
 async function fiscalSaved(p = page) {
  await p.waitForFunction(() => !document.getElementById('modal').open && document.getElementById('screen').getAttribute('aria-busy') === 'false');
 }
 async function commit(selector, result = 'fiscal') {
  const previousHeading = await page.locator('#screen .page-heading').elementHandle();
  await page.locator(selector).click();
  await page.waitForFunction(element => !element.isConnected, previousHeading);
  if (result === 'saved') await saved();
  if (result === 'fiscal') await fiscalSaved();
  await previousHeading.dispose();
 }
 async function login(p, account) {
  await p.goto(base + '/crm/');
  if (await p.locator('#privacy-ack').isVisible()) await p.locator('#privacy-ack').click();
  await p.getByLabel('שם משתמש או כתובת אימייל').fill(account.user);
  await p.getByLabel('סיסמה', {exact:true}).fill(account.password);
  await p.getByRole('button', {name:'כניסה למערכת'}).click();
  await p.getByRole('heading', {name:'סקירה כללית', exact:true}).waitFor();
 }
 async function openBill(newBill, newPayments = [], doctype = 'invrec') {
  if (newBill) bill = {...clone(originalBill), ...clone(newBill)};
  payments = clone(newPayments); paymentDoctype = doctype;
  if (await page.locator('#modal').isVisible()) await page.keyboard.press('Escape');
  await page.locator('[data-view="bills"]').click();
  await page.waitForFunction(() => document.getElementById('screen').getAttribute('aria-busy') === 'false');
  const loaded = page.waitForResponse(response => response.url().endsWith('/icount/bill') && response.request().method() === 'POST');
  await page.locator('[data-bill="92"]').click(); await loaded;
  await page.waitForFunction(() => !document.getElementById('modal-content').textContent.includes('טוען תשלומים ומסמכים'));
 }
 async function noInjection(p = page) {return await p.evaluate(() => !window.__icountXss) && await p.locator('#screen img,#modal img').count() === 0;}
 page.on('pageerror', error => errors.push(error.message));
 try {
  await context.route(url => url.hostname !== '127.0.0.1', route => {external.push(new URL(route.request().url()).hostname); return route.abort('blockedbyclient');});
  await page.route('**/limu-crm/v1/**', async route => {
   const request = route.request(), endpoint = new URL(request.url()).pathname.split('/limu-crm/v1/')[1];
   if (request.method() === 'POST') {
    const body = request.postDataJSON(); posts.push({endpoint, body});
    if (endpoint === 'icount/bill') return json(route, {bill, payments});
    if (endpoint === 'icount/check') return json(route, bootstrap.icount);
    if (endpoint === 'icount/automatic') {bootstrap.icount.automatic = body.enabled; return json(route, {saved:true});}
    if (endpoint === 'icount/client-preview') return json(route, {id:321, name:hostile, vat_id:'123456789'});
    if (endpoint === 'icount/client') {bootstrap.institutions[0].icount_client = {id:321, name:hostile, vat_id:'123456789', verified_at:'2026-10-07'}; return json(route, bootstrap.institutions[0].icount_client);}
    if (endpoint === 'icount/issue') {
     if (failIssue) {bill.documents = [waiting(body.doctype)]; return json(route, {message:'תוצאת ההפקה אינה ודאית. ' + hostile}, 409);}
     const document = issued(body.doctype); bill.documents.push(document); return json(route, document);
    }
    if (endpoint === 'icount/reconcile') {const document = issued(body.doctype, body.target, 888); bill.documents = [document]; return json(route, document);}
    if (endpoint === 'icount/payment') {
     const document = issued(paymentDoctype, 301, 702); payments[0].documents.push(document); bill.documents.push(document); return json(route, document);
    }
    return json(route, {message:'Unexpected mocked fiscal endpoint'}, 500);
   }
   if (endpoint === 'bootstrap') return json(route, bootstrap);
   if (endpoint === 'summary') return json(route, {leads:0, confirmed:0, approved:1180, paid:0, overdue:0, draft:0, duplicates:0, pending:0, unmapped:0, historical:0, by_institution:{}});
   if (endpoint === 'bills') return json(route, {items:[bill], total:1, pages:1});
   return json(route, {items:[], total:0, pages:1});
  });
  await login(page, managerLogin);
  await page.locator('[data-view="settings"]').click();
  await page.getByRole('heading', {name:'הגדרות והרשאות', exact:true}).waitFor();
  await check(await page.locator('#icount-check-form').isVisible() && await page.locator('input[type="password"],input[name*="token"],input[name*="secret"]').count() === 0 && await noInjection() && (await page.locator('#screen').textContent()).includes(hostile), 'Connection status escapes provider company data and exposes no API secret input');
  await commit('#icount-check-form button', 'saved');
  await check(equal(lastPost('icount/check'), {}) && (await page.locator('#screen').textContent()).includes('החיבור אומת'), 'Connection verification is an explicit empty-payload action');
  await page.locator('#icount-automatic-form input[name="enabled"]').check();
  await commit('#icount-automatic-form button', 'saved');
  await check(equal(lastPost('icount/automatic'), {enabled:true}) && await page.locator('#icount-automatic-form input[name="enabled"]').isChecked(), 'Automatic demands require an explicit setting and persist after refresh');
  await openBill({});
  await check(await page.locator('[data-fiscal-issue]').count() === 0 && (await page.locator('#modal').textContent()).includes('יש לשייך למוסד לקוח iCount'), 'An institution without a verified fiscal client has no document issuance actions');
  await page.keyboard.press('Escape');
  await page.locator('[data-view="institutions"]').click();
  await page.getByRole('heading', {name:'מוסדות ותעריפים', exact:true}).waitFor();
  await page.locator('[data-icount-client="1"]').click();
  await page.locator('#icount-client-id').fill('321');
  await page.locator('#icount-client-form button').click();
  await page.locator('#icount-client-confirm-form').waitFor();
  await check(equal(lastPost('icount/client-preview'), {client_id:321}) && bootstrap.institutions[0].icount_client === null && (await page.locator('#modal').textContent()).includes(hostile) && await noInjection(), 'Client preview verifies and escapes provider identity before any institution mapping is saved');
  await commit('#icount-client-confirm-form button[type="submit"]', 'saved');
  await check(equal(lastPost('icount/client'), {institution:1, client_id:321}) && (await page.locator('#screen').textContent()).includes(hostile) && await noInjection(), 'Institution mapping requires explicit confirmation of the verified client and sends only both IDs');

  await openBill({state:'draft'});
  await check(await page.locator('[data-fiscal-issue],[data-fiscal-payment]').count() === 0, 'An unapproved monthly bill offers no fiscal issuance actions');
  await openBill({});
  await check(equal(lastPost('icount/bill'), {bill:92}) && await page.locator('[data-fiscal-issue="deal"]').isEnabled() && await page.locator('[data-fiscal-issue="invoice"]').isEnabled(), 'Opening an approved bill loads current fiscal records using only its bill ID');
  await page.locator('[data-fiscal-issue="deal"]').click();
  await check(await page.locator('#fiscal-issue-form input,#fiscal-issue-form select').count() === 0 && (await page.locator('#modal').textContent()).includes('18%'), 'Fiscal confirmation shows fixed VAT and has no editable amount, price, or client fields');
  await commit('#fiscal-issue-form button');
  await check(equal(lastPost('icount/issue'), {bill:92, doctype:'deal'}), 'Demand issuance sends only the immutable bill ID and document type');
  await openBill(null, payments);
  await check(await page.locator('[data-fiscal-issue="deal"]').isDisabled() && await page.locator('[data-fiscal-issue="invoice"]').isEnabled(), 'An issued demand cannot be issued again and still permits its invoice');
  await page.locator('[data-fiscal-issue="invoice"]').click();
  await commit('#fiscal-issue-form button');
  await check(equal(lastPost('icount/issue'), {bill:92, doctype:'invoice'}), 'Invoice issuance sends no browser-provided financial amounts or client metadata');

  await openBill({documents:[waiting()]}, [clone(originalPayment)]);
  await check(await page.locator('[data-fiscal-issue="deal"]').isDisabled() && await page.locator('[data-fiscal-issue="invoice"]').isDisabled() && await page.locator('[data-fiscal-payment="301"]').isDisabled() && await page.locator('[data-fiscal-reconcile="92"]').isVisible(), 'An uncertain document blocks demand, invoice, and payment issuance and offers reconciliation');
  await page.locator('[data-fiscal-reconcile="92"]').click();
  await check(await page.locator('#fiscal-reconcile-form input').count() === 0, 'Reconciliation offers no manual document number or fabricated provider ID');
  await commit('#fiscal-reconcile-form button');
  await check(equal(lastPost('icount/reconcile'), {target:92, doctype:'invoice'}), 'Reconciliation sends only the original target and document type');

  await openBill({}); failIssue = true;
  await page.locator('[data-fiscal-issue="invoice"]').click(); await commit('#fiscal-issue-form button', 'failed');
  await page.waitForFunction(() => document.querySelector('#fiscal-issue-form .form-error').textContent.includes('תוצאת ההפקה אינה ודאית'));
  await check(await page.locator('#fiscal-issue-form button').isDisabled() && await noInjection() && (await page.locator('#fiscal-issue-form .form-error').textContent()).includes(hostile), 'An uncertain issuance error is escaped and disables a repeated confirmation');
  failIssue = false; await openBill(null, []);
  await check(await page.locator('[data-fiscal-issue="invoice"]').isDisabled() && await page.locator('[data-fiscal-reconcile="92"]').isVisible(), 'Reopening after an uncertain failure refreshes its state and keeps issuance blocked');

  await openBill({paid:1180}, [clone(originalPayment)]);
  await page.locator('[data-fiscal-payment="301"]').click();
  await check((await page.locator('#modal-title').textContent()).includes('חשבונית מס / קבלה') && await page.locator('#fiscal-payment-form input,#fiscal-payment-form select').count() === 0, 'A single full cash payment offers a tax invoice and receipt without editable payment amounts');
  await commit('#fiscal-payment-form button');
  await check(equal(lastPost('icount/payment'), {payment:301}), 'Full cash fiscal issuance sends only the recorded payment ID');
  await openBill(null, payments);
  await check(await page.locator('[data-fiscal-issue="invoice"]').isDisabled() && await page.locator('[data-fiscal-payment="301"]').count() === 0, 'An issued invoice and receipt prevents another invoice or payment document');

  await openBill({paid:590}, [{...clone(originalPayment), amount:590}]);
  await check(await page.locator('[data-fiscal-payment="301"]').isDisabled() && await page.locator('[data-fiscal-issue="invoice"]').isEnabled() && (await page.locator('#modal').textContent()).includes('לתשלום חלקי יש להפיק תחילה חשבונית מס'), 'A partial payment requires the full bill invoice before its receipt');
  await openBill({paid:590, documents:[issued('invoice')]}, [{...clone(originalPayment), amount:590}], 'receipt');
  await page.locator('[data-fiscal-payment="301"]').click();
  await check((await page.locator('#modal-title').textContent()).trim() === 'הפקת קבלה', 'A recorded partial payment with an invoice offers a receipt');
  await commit('#fiscal-payment-form button');
  await check(equal(lastPost('icount/payment'), {payment:301}) && payments[0].documents[0].doctype === 'receipt', 'Receipt issuance derives its type and amount from the recorded payment');

  await openBill({paid:1180}, [{...clone(originalPayment), method:'transfer'}]);
  await page.locator('[data-fiscal-payment="301"]').click();
  const transferPosts = postCount('icount/payment');
  await page.locator('#fiscal-payment-form button').click();
  await check(postCount('icount/payment') === transferPosts && !(await page.locator('#fiscal-payment-form').evaluate(form => form.checkValidity())) && await noInjection() && (await page.locator('#fiscal-account').textContent()).includes(hostile), 'Transfer issuance requires a verified bank account and escapes its provider label');
  await page.locator('#fiscal-account').selectOption('7');
  await commit('#fiscal-payment-form button');
  await check(equal(lastPost('icount/payment'), {payment:301, account:'7'}), 'Transfer issuance sends only its recorded payment ID and selected provider bank account');
  bootstrap.icount.bank_accounts = [];
  await page.reload(); await page.getByRole('heading', {name:'סקירה כללית', exact:true}).waitFor();
  await openBill({paid:1180}, [{...clone(originalPayment), method:'transfer'}]);
  await page.locator('[data-fiscal-payment="301"]').click();
  await check(await page.locator('#fiscal-payment-form button').isDisabled() && (await page.locator('#modal').textContent()).includes('לא נמצאו חשבונות בנק'), 'Missing verified bank accounts prevent fiscal transfer submission');
  bootstrap.icount.bank_accounts = clone(connection.bank_accounts);

  const unsafeUrls = ['javascript:window.__icountXss=true', 'http://app.icount.co.il/x', 'https://icount.co.il.example.com/x', 'https://fixture:fixture@app.icount.co.il/x', 'https://app.icount.co.il:444/x'];
  const documents = unsafeUrls.map((url, index) => ({...issued('invoice', 92, index + 10), url, docnum:hostile, issued:hostile}));
  documents.push({...issued('deal'), url:'https://app.icount.co.il/docs/fixture?x=1&y=2'});
  await openBill({documents});
  const links = await page.locator('#modal a').evaluateAll(elements => elements.map(element => ({href:element.href, target:element.target, rel:element.rel})));
  await check(links.length === 1 && links[0].href === 'https://app.icount.co.il/docs/fixture?x=1&y=2' && links[0].target === '_blank' && links[0].rel.includes('noopener') && links[0].rel.includes('noreferrer') && await noInjection() && (await page.locator('#modal').textContent()).includes(hostile), 'Document labels are escaped and only trusted HTTPS provider URLs receive safe external links');

  const memberContext = await browser.newContext({locale:'he-IL', viewport:{width:1440, height:1000}});
  const member = await memberContext.newPage();
  member.on('pageerror', error => errors.push(error.message));
  await memberContext.route(url => url.hostname !== '127.0.0.1', route => {external.push(new URL(route.request().url()).hostname); return route.abort('blockedbyclient');});
  let memberPosts = 0;
  const memberBootstrap = {...clone(bootstrap), manager:false, settings:null, icount:null, institutions:[{id:1, name:'מוסד בדיקה', agreement:clone(bootstrap.institutions[0].agreement)}]};
  const memberBill = {...clone(originalBill), documents:[{...issued('invrec'), target:301}]};
  await member.route('**/limu-crm/v1/**', route => {
   const request = route.request(), endpoint = new URL(request.url()).pathname.split('/limu-crm/v1/')[1];
   if (request.method() === 'POST') {memberPosts++; return json(route, {message:'Read-only fixture'}, 403);}
   if (endpoint === 'bootstrap') return json(route, memberBootstrap);
   if (endpoint === 'summary') return json(route, {leads:0, confirmed:0, approved:1180, paid:0, overdue:0, draft:0, duplicates:0, pending:0, unmapped:0, historical:0, by_institution:{}});
   if (endpoint === 'bills') return json(route, {items:[memberBill], total:1, pages:1});
   return json(route, {items:[], total:0, pages:1});
  });
  await login(member, memberLogin);
  await member.locator('[data-view="bills"]').click(); await member.getByRole('heading', {name:'חיובים ותשלומים', exact:true}).waitFor();
  await member.locator('[data-bill="92"]').click();
  await check(await member.locator('[data-view="settings"],[data-view="institutions"],#prepare,#approve,[data-payment],[data-fiscal-issue],[data-fiscal-payment],[data-fiscal-reconcile]').count() === 0 && await member.locator('#modal a[href^="https://app.icount.co.il/"]').isVisible() && memberPosts === 0, 'A real institution login only views its issued document link and sends no fiscal actions');
  await check(errors.length === 0 && external.length === 0, 'Fiscal UI has no JavaScript errors and sends no requests outside the local intercepted fixtures');
  await memberContext.close();
  console.log(checks.length + ' nonmutating iCount frontend checks passed.');
 } finally {
  await browser.close();
 }
})().catch(error => {console.error(error.message); process.exit(1);});
