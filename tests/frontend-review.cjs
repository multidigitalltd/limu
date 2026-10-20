/* Nonmutating browser regressions: REST writes are mocked; credentials stay outside Git. */
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
 const credentials=JSON.parse(fs.readFileSync(process.env.LIMU_TEST_LOGIN,'utf8'));
 const browser=await chromium.launch({headless:true,executablePath:process.env.LIMU_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox']});
 const page=await browser.newPage({locale:'he-IL',viewport:{width:1440,height:1000}});
 const base=process.env.LIMU_TEST_URL||'http://127.0.0.1:8090';
 assert.equal(new URL(base).hostname,'127.0.0.1','Frontend regression harness is restricted to local fixtures');
 const hostile='<img src=x onerror="window.__limuXss=true">';
 const bootstrap={manager:true,today:'2026-10-07',month:'2026-10',settings:{duplicate_mode:'rolling',start_date:'',automatic:false},institutions:[
  {id:1,name:'מוסד הדגמה',duplicate_policy:{duplicate_mode:'calendar',duplicate_days:30},agreement:{credit_days:30,rates:[{from:'2026-01-01',price:1000,vat_bp:1800},{from:'2026-11-01',price:2500,vat_bp:1800}]}},
  {id:2,name:'מוסד עתידי',duplicate_policy:{duplicate_mode:'custom',duplicate_days:45},agreement:{credit_days:15,rates:[{from:'2026-11-15',price:3000,vat_bp:1800}]}},
  {id:3,name:hostile,agreement:null}
 ]};
 const lead={id:91,name:hostile,institution:3,date:'2026-09-15 12:00:00',phone:hostile,email:hostile,form:hostile,duplicate_of:hostile,state:'historical',billing_excluded:false,notes:[{at:'2026-09-15',text:hostile}],treatment:'new'};
 const pendingLead={...lead,id:93,name:'קליטה שלא הושלמה',state:'pending'};
 const bill={id:92,institution:3,month:'2026-09',state:'approved',total:1180,subtotal:1000,vat:180,paid:0,due:'2026-11-06',issued:'2026-10-07',duplicates:hostile,historical:hostile,lines:[{delivery:hostile,price:1000}]};
 let mode='normal',refreshFailure=false,pendingWrite=null,releaseWrite,writeStarted,releaseReport,reportStarted,lastSummaryQuery,lastReportQuery,lastExportQuery,lastDeliveryQuery,lastPolicyPayload,lastSettingsPayload,lastExclusionPayload,lastBillQuery,lastBulkRatePayload,releaseBulkRates,bulkRatesStarted,policyWrites=0,exclusionWrites=0,bulkRateWrites=0,memberView=false,capturePending=7;
 const writeStartedPromise=new Promise(resolve=>writeStarted=resolve);
 const reportStartedPromise=new Promise(resolve=>reportStarted=resolve);
 const bulkRatesStartedPromise=new Promise(resolve=>bulkRatesStarted=resolve);
 const checks=[],errors=[];page.on('pageerror',error=>errors.push(error.message));
 async function check(condition,label){assert.ok(condition,label);checks.push(label);console.log('PASS',label);}
 const json=(route,body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
 try {
 await page.route(/\/assets\/(?:build\/)?crm(?:\.[a-f0-9]{12})?\.min\.js(?:\?|$)/,route=>route.fulfill({path:path.resolve(__dirname,'../limu-crm/assets/crm.js'),contentType:'application/javascript'}));
 await page.route(/\/assets\/(?:build\/)?privacy(?:\.[a-f0-9]{12})?\.min\.js(?:\?|$)/,route=>route.fulfill({path:path.resolve(__dirname,'../limu-crm/assets/privacy.js'),contentType:'application/javascript'}));
 await page.route(/\/assets\/(?:build\/)?crm(?:\.[a-f0-9]{12})?\.min\.css(?:\?|$)/,route=>route.fulfill({path:path.resolve(__dirname,'../limu-crm/assets/crm.css'),contentType:'text/css'}));
 await page.route('**/limu-crm/v1/**',async route=>{
  const url=new URL(route.request().url()),endpoint=url.pathname.split('/').at(-1);
  if(route.request().method()==='POST'){
   if(endpoint==='agreements-bulk'){
    lastBulkRatePayload=route.request().postDataJSON();bulkRateWrites++;
    assert.equal(Object.keys(lastBulkRatePayload).sort().join(','),'from,institutions,price','Bulk tariffs send only the explicit institution set, price, and effective date');
    assert.equal(typeof lastBulkRatePayload.price,'string','Bulk price remains an exact decimal string in shekels');
    if(mode==='bulk-error')return json(route,{message:'התעריף מתנגש בחיוב מאושר. לא שונו תעריפים.'},409);
    if(mode==='bulk-malformed')return json(route,{ok:true});
    if(mode==='bulk-unchanged')return json(route,{updated:[],unchanged:lastBulkRatePayload.institutions,count:0});
    if(mode==='bulk-deferred'){
     const waiting=new Promise(resolve=>releaseBulkRates=resolve);bulkRatesStarted();await waiting;
    }
    for(const id of lastBulkRatePayload.institutions){
     const institution=bootstrap.institutions.find(item=>item.id===id);
     assert.ok(institution,'Bulk tariffs target existing institutions');
     const agreement=institution.agreement||{credit_days:30,rates:[]};
     institution.agreement={...agreement,rates:[...agreement.rates.filter(rate=>rate.from!==lastBulkRatePayload.from),{from:lastBulkRatePayload.from,price:Math.round(Number(lastBulkRatePayload.price)*100),vat_bp:1800}].sort((a,b)=>a.from.localeCompare(b.from))};
    }
    return json(route,{updated:lastBulkRatePayload.institutions,unchanged:[],count:lastBulkRatePayload.institutions.length});
   }
   if(endpoint==='billing-exclusion'){
    lastExclusionPayload=route.request().postDataJSON();exclusionWrites++;
    assert.equal(lastExclusionPayload.id,91,'Billing exclusion targets the selected delivery');
    assert.equal(typeof lastExclusionPayload.excluded,'boolean','Billing exclusion is a strict boolean');
    if(mode==='exclusion-error')return json(route,{message:'לא ניתן לשנות פנייה בתקופה שכבר אושרה.'},409);
    lead.billing_excluded=lastExclusionPayload.excluded;
    return json(route,{id:lead.id,billing_excluded:lead.billing_excluded,draft_requires_refresh:mode==='exclusion-draft'});
   }
   if(endpoint==='institution-policy'){
    lastPolicyPayload=route.request().postDataJSON();policyWrites++;
    if(mode==='policy-error')return json(route,{message:'לא ניתן לשמור את תקופת הכפילות.'},503);
    const institution=bootstrap.institutions.find(item=>item.id===Number(lastPolicyPayload.institution));
    assert.ok(institution,'Policy writes target an existing fixture institution');
    institution.duplicate_policy={duplicate_mode:lastPolicyPayload.duplicate_mode,duplicate_days:lastPolicyPayload.duplicate_days??30};
    return json(route,institution.duplicate_policy);
   }
   if(endpoint==='settings'){
    lastSettingsPayload=route.request().postDataJSON();Object.assign(bootstrap.settings,lastSettingsPayload);return json(route,bootstrap.settings);
   }
   assert.equal(endpoint,'agreement','Only mock tariff save is allowed');
   if(mode==='deferred-save'){pendingWrite=new Promise(resolve=>releaseWrite=resolve);writeStarted();await pendingWrite;}
   if(mode==='save-refresh-error')refreshFailure=true;
   return json(route,{saved:true});
  }
  if(mode==='expired')return json(route,{code:'rest_cookie_invalid_nonce',message:'Cookie check failed'},403);
  if(endpoint==='bootstrap')return refreshFailure?json(route,{message:'כשל רשת מדומה'},503):json(route,memberView?{...bootstrap,manager:false}:bootstrap);
  if(endpoint==='summary'||endpoint==='bills'||['report','export'].includes(endpoint)&&url.searchParams.get('target')==='bills')assert.equal(url.searchParams.has('billing_status'),false,'Financial summaries and reports never inherit lead billing-status filters');
  if(endpoint==='summary'){lastSummaryQuery=url.searchParams;const scoped=url.searchParams.get('institution')==='2';return json(route,{leads:hostile,received_leads:scoped?6:4622,institution_leads:scoped?9:4008,institution_historical:scoped?1:hostile,...(scoped?{}:{institution_live:hostile}),...(capturePending===undefined?{}:{capture_pending:capturePending}),confirmed:1,approved:1180,paid:0,overdue:0,draft:0,duplicates:0,pending:0,unmapped:scoped?0:3,historical:1,by_institution:{3:1}});}
  if(endpoint==='deliveries'){lastDeliveryQuery=url.searchParams;let rows=url.searchParams.get('institution')==='2'?[{...lead,id:95,institution:2,name:'פנייה למוסד הנבחר'}]:[lead,pendingLead];if(url.searchParams.get('billing_status')==='excluded')rows=rows.filter(item=>item.billing_excluded);return json(route,{items:rows,total:rows.length,pages:1});}
  if(endpoint==='report'&&url.searchParams.get('target')==='deliveries'){lastReportQuery=url.searchParams;if(mode==='deferred-report'){const pendingReport=new Promise(resolve=>releaseReport=resolve);reportStarted();await pendingReport;}const rows=[lead,pendingLead].filter(item=>url.searchParams.get('billing_status')!=='excluded'||item.billing_excluded);return json(route,{items:rows,total:rows.length,pages:1});}
  if(endpoint==='bills'||endpoint==='report'){if(endpoint==='report')lastReportQuery=url.searchParams;else lastBillQuery=url.searchParams;return json(route,{items:[bill],total:1,pages:1});}
  if(endpoint==='export'){lastExportQuery=url.searchParams;return json(route,{file:Buffer.from('Filtered export response fixture').toString('base64'),name:'filtered-fixture.xlsx',count:2});}
  if(endpoint==='audit')return json(route,{items:[{at:'2026-10-07',event:hostile,actor:hostile,target:hostile},{at:'2026-10-07',event:'delivery_billing_excluded',actor:1,target:91},{at:'2026-10-07',event:'delivery_billing_restored',actor:1,target:91}],total:3,pages:1});
  throw new Error('Unexpected fixture endpoint '+endpoint);
 });
 await page.goto(base+'/crm/');
 if(await page.locator('#user_login').count()){
  if(await page.locator('#privacy-ack').isVisible())await page.locator('#privacy-ack').click();
  await page.locator('#user_login').fill(credentials.user);await page.locator('#user_pass').fill(credentials.password);await page.getByRole('button',{name:'כניסה למערכת'}).click();
 }
 await page.getByRole('heading',{name:'סקירה כללית'}).waitFor();
 await check(!(await page.locator('body').textContent()).includes('מרחב ניהול מאובטח'),'Portal omits the removed topbar wording');
 await check((await page.locator('[data-metric="received"] .value').textContent())==='4622'&&(await page.locator('[data-metric="leads"] .value').textContent())==='4008'&&(await page.locator('[data-metric="received"] .hint').textContent()).includes('פעם אחת')&&(await page.locator('[data-metric="leads"] .hint').textContent()).includes('קליטה שוטפת: '+hostile)&&(await page.locator('[data-metric="leads"] .hint').textContent()).includes('בנפרד'),'Dashboard distinguishes site submissions from institution recipient deliveries with an explanation of each count');
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('#screen img').count()===0&&(await page.locator('[data-metric="leads"] .hint').textContent()).includes('קליטה שוטפת: '+hostile)&&(await page.locator('[data-metric="leads"] .hint').textContent()).includes('היסטוריה: '+hostile),'Dashboard live and historical counters and institution names escape hostile HTML');
 await check(await page.locator('#assignment-alert').isVisible()&&(await page.locator('#assignment-title').textContent()).includes('3 פניות ללא שיוך למוסד · כל התקופות')&&(await page.locator('#assignment-alert').textContent()).includes('מההיסטוריה ומהקליטה השוטפת'),'Manager dashboard prominently identifies unresolved institution assignment and explains which received leads are counted');
 await check(await page.locator('#capture-retry-alert').isVisible()&&(await page.locator('#capture-retry-title').textContent())==='7 פניות ממתינות לקליטה'&&(await page.locator('#capture-pending-count').textContent())==='7'&&(await page.locator('#capture-retry-alert').textContent()).includes('תקלות זמניות נבדקות אוטומטית')&&(await page.locator('#capture-retry-alert').textContent()).includes('החלטת מנהל')&&(await page.locator('#capture-retry-alert').textContent()).includes('פעם אחת')&&await page.locator('#open-capture-cases').count()===1,'Manager sees a truthful distinction between automatic retries and manual review with access to the pending list');
 await check(await page.locator('#access-tools,#reading-guide,a[href*="m-d.co.il"]').count()===0&&await page.getByRole('link',{name:'הצהרת נגישות',exact:true}).count()===0,'Portal omits the removed toolbar, accessibility statement and credit');
 await page.locator('#quick-search-input').fill('  בדיקת חיפוש  ');await page.getByRole('button',{name:'חיפוש לידים',exact:true}).click();await page.getByRole('heading',{name:'מרכז לידים',exact:true}).waitFor();
 await check(lastDeliveryQuery.get('search')==='בדיקת חיפוש'&&await page.locator('#search').inputValue()==='בדיקת חיפוש'&&await page.locator('[data-view="deliveries"]').getAttribute('aria-current')==='page','Header quick search opens the lead center and sends the trimmed search term');
 await page.locator('[data-view="dashboard"]').click();await page.getByRole('heading',{name:'סקירה כללית',exact:true}).waitFor();
 await page.locator('#missing-rates').click();await page.getByRole('heading',{name:'מוסדות ותעריפים',exact:true}).waitFor();
 await check(await page.locator('#institution-status').inputValue()==='missing'&&await page.locator('[data-rate="1"]').count()===0&&await page.locator('[data-rate="2"]').count()===1&&await page.locator('[data-rate="3"]').count()===1,'Missing-rate alert opens only institutions without a currently active tariff');
 await page.locator('#institution-search').fill('מוסד עתידי');await check(await page.locator('[data-rate="2"]').count()===1&&await page.locator('[data-rate="3"]').count()===0,'Institution search refines the missing-rate list');await page.locator('[data-view="dashboard"]').click();await page.getByRole('heading',{name:'סקירה כללית',exact:true}).waitFor();
 await page.locator('#filter-period').selectOption('year');await page.locator('#filter-year').fill('2026');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(lastSummaryQuery.get('year')==='2026'&&!lastSummaryQuery.get('month')&&(await page.locator('.period-chip').textContent()).includes('2026'),'Whole-year summary requests contain a year without a monthly constraint');
 await check(await page.locator('.metrics .label').evaluateAll(labels=>labels.length===5&&labels.every(label=>label.textContent.includes('שנת 2026'))),'Every dashboard metric title identifies the selected full year');
 await page.locator('#filter-period').selectOption('range');await page.locator('#filter-from').fill('2026-09-01');await page.locator('#filter-to').fill('2026-09-30');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(lastSummaryQuery.get('date_from')==='2026-09-01'&&lastSummaryQuery.get('date_to')==='2026-09-30'&&!lastSummaryQuery.get('month')&&!lastSummaryQuery.get('year')&&(await page.locator('.period-chip').textContent()).includes('2026-09-01 – 2026-09-30'),'Dashboard date-range request and label omit monthly and yearly constraints');
 await check((await page.locator('#assignment-title').textContent()).includes('2026-09-01 – 2026-09-30'),'Unassigned lead notice identifies the active date range');
 await page.locator('#open-unmapped').click();await page.getByRole('heading',{name:'מרכז לידים',exact:true}).waitFor();
 await check(lastDeliveryQuery.get('state')==='unmapped'&&lastDeliveryQuery.get('date_from')==='2026-09-01'&&lastDeliveryQuery.get('date_to')==='2026-09-30'&&!lastDeliveryQuery.get('year')&&!lastDeliveryQuery.get('month')&&await page.locator('#filter-state').inputValue()==='unmapped'&&await page.locator('#filter-from').inputValue()==='2026-09-01'&&await page.locator('#filter-to').inputValue()==='2026-09-30','Unassigned lead notice opens only matching records and preserves the selected date boundaries');
 await page.locator('[data-view="dashboard"]').click();await page.getByRole('heading',{name:'סקירה כללית',exact:true}).waitFor();
 await page.locator('#filter-period').selectOption('range');await page.locator('#filter-from').fill('2026-09-01');await page.locator('#filter-to').fill('2026-09-30');
 await page.locator('#filter-institution').selectOption('2');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(lastSummaryQuery.get('institution')==='2'&&await page.locator('.metrics .label').evaluateAll(labels=>labels.length===5&&labels.every(label=>label.textContent.includes('2026-09-01 – 2026-09-30')&&label.textContent.includes('מוסד עתידי'))),'Every dashboard metric title identifies both the selected date range and institution');
 await check((await page.locator('[data-metric="received"] .value').textContent())==='6'&&(await page.locator('[data-metric="leads"] .value').textContent())==='9'&&(await page.locator('[data-metric="leads"] .hint').textContent()).includes('קליטה שוטפת: 8 · היסטוריה: 1'),'Institution filtering updates both totals and preserves current/historical breakdown when an older summary omits the live field');
 await check(await page.locator('#assignment-alert,#open-unmapped').count()===0,'A scope without unassigned leads omits the assignment notice and action');
 await check((await page.locator('#capture-retry-title').textContent())==='7 פניות ממתינות לקליטה'&&(await page.locator('#capture-pending-count').textContent())==='7'&&(await page.locator('#capture-retry-alert').textContent()).includes('כל התקופות והמוסדות ואינו מושפע מהסינון')&&(await page.locator('#capture-pending-count').locator('..').textContent()).includes('כל המערכת'),'Queue notice stays explicitly global when both reporting period and institution change');
 capturePending=0;await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(await page.locator('#capture-retry-alert').count()===0&&(await page.locator('#capture-pending-count').textContent())==='0','An empty queue leaves an explicit zero count without an unnecessary alert');
 capturePending=undefined;await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(await page.locator('#capture-retry-alert,#capture-pending-count').count()===0,'Older summaries without queue information do not invent a zero or reuse scoped delivery counts');
 capturePending=hostile;await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(await page.locator('#capture-retry-alert,#capture-pending-count').count()===0&&await page.locator('#screen img').count()===0&&await page.evaluate(()=>!window.__limuXss),'Malformed queue values cannot inject markup or produce a false count');
 capturePending=7;await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(lastDeliveryQuery.get('institution')==='2'&&lastDeliveryQuery.get('date_from')==='2026-09-01'&&lastDeliveryQuery.get('date_to')==='2026-09-30'&&!lastDeliveryQuery.get('month')&&!lastDeliveryQuery.get('year')&&await page.locator('.recent-leads tbody tr').count()===1&&(await page.locator('.recent-leads tbody tr').textContent()).includes('מוסד עתידי'),'Dashboard recent leads request and render the active date range and institution');
 await page.locator('#recent-leads-all').click();await page.getByRole('heading',{name:'מרכז לידים',exact:true}).waitFor();
 await check(lastDeliveryQuery.get('institution')==='2'&&lastDeliveryQuery.get('date_from')==='2026-09-01'&&lastDeliveryQuery.get('date_to')==='2026-09-30'&&await page.locator('#filter-institution').inputValue()==='2'&&await page.locator('#filter-from').inputValue()==='2026-09-01'&&await page.locator('#filter-to').inputValue()==='2026-09-30','Recent leads show-all action preserves the selected period and institution');
 await page.setViewportSize({width:390,height:844});
 await check(await page.getByRole('link',{name:'יציאה',exact:true}).isVisible(),'Mobile portal retains a usable logout control');
 await page.setViewportSize({width:1440,height:1000});
 await page.locator('[data-view="deliveries"]').click();await page.getByRole('heading',{name:'מרכז לידים',exact:true}).waitFor();await check(await page.locator('[data-confirm]').count()===0&&(await page.locator('#screen').textContent()).includes('קליטה לא הושלמה'),'Incomplete legacy capture has no manual lead approval action');await page.locator('[data-detail="91"]').click();
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('dialog img').count()===0,'Lead fields, duplicate references and internal notes escape hostile HTML');await page.keyboard.press('Escape');
 await check(await page.getByRole('columnheader',{name:'מצב חיוב',exact:true}).count()===1&&await page.locator('#filter-billing-status').inputValue()==='','Lead center separates manual billing status from duplicate and delivery status');
 await page.locator('[data-detail="93"]').click();await check(await page.locator('#toggle-billing-exclusion').count()===0,'Incomplete lead capture has no billing-exclusion action');await page.keyboard.press('Escape');
 const originalDuplicate=lead.duplicate_of,originalState=lead.state;
 await page.locator('[data-detail="91"]').click();await page.getByRole('button',{name:'החרגה מחיוב',exact:true}).click();
 await check(await page.getByRole('heading',{name:'החרגה מחיוב',exact:true}).isVisible()&&await page.locator('dialog img').count()===0&&await page.evaluate(()=>!window.__limuXss),'Billing exclusion asks for confirmation and escapes contact and institution text');
 mode='exclusion-draft';await page.getByRole('button',{name:'אישור החרגה מחיוב',exact:true}).click();await page.locator('tbody .badge.excluded').waitFor();await page.waitForFunction(()=>!document.getElementById('modal').open&&document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(Object.keys(lastExclusionPayload).sort().join(',')==='excluded,id'&&lastExclusionPayload.excluded===true&&lead.billing_excluded===true&&(await page.locator('#notification').textContent()).includes('יש להכין מחדש')&&lead.duplicate_of===originalDuplicate&&lead.state===originalState&&await page.locator('tbody tr').count()===2,'Exclusion sends a boolean, preserves duplicate and delivery data, and explains draft refresh');
 const excludedRow=page.locator('tbody tr').filter({has:page.locator('[data-detail="91"]')});
 await check(await excludedRow.locator('.badge.excluded').count()===1&&(await excludedRow.textContent()).includes('כפול — לא לחיוב'),'Manual exclusion and duplicate indication remain visible together');
 await page.locator('[data-detail="91"]').click();await page.getByRole('button',{name:'ביטול החרגה',exact:true}).click();mode='exclusion-error';await page.getByRole('button',{name:'אישור ביטול החרגה',exact:true}).click();await page.locator('#billing-exclusion-form .form-error').filter({hasText:'כבר אושרה'}).waitFor();
 await check(await page.locator('#modal').isVisible()&&await page.getByRole('button',{name:'אישור ביטול החרגה',exact:true}).isEnabled()&&lead.billing_excluded===true,'Approved-period rejection leaves the confirmation open and preserves exclusion');await page.keyboard.press('Escape');mode='normal';
 await page.locator('#filter-billing-status').selectOption('excluded');await page.locator('#filter-period').selectOption('year');await page.locator('#filter-year').fill('2026');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(lastDeliveryQuery.get('billing_status')==='excluded'&&lastDeliveryQuery.get('year')==='2026'&&await page.locator('tbody tr').count()===1&&await page.locator('tbody .badge.excluded').count()===1,'Excluded-lead filtering combines with the reporting period');
 await page.evaluate(()=>{window.print=()=>{};});await page.locator('#print-leads').click();await page.locator('#print-snapshot').waitFor({state:'attached'});
 await check(lastReportQuery.get('billing_status')==='excluded'&&lastReportQuery.get('year')==='2026'&&await page.locator('#print-snapshot tbody tr').count()===1&&await page.locator('#print-snapshot .badge.excluded').count()===1&&(await page.locator('#print-snapshot').textContent()).includes('מצב חיוב: החרגה מחיוב')&&await page.locator('#print-snapshot img').count()===0,'Lead PDF preserves the exclusion filter and visibly identifies excluded records');await page.evaluate(()=>window.dispatchEvent(new Event('afterprint')));
 const excludedDownloadStarted=page.waitForEvent('download');await page.locator('#export').click();await excludedDownloadStarted;
 await check(lastExportQuery.get('billing_status')==='excluded'&&lastExportQuery.get('year')==='2026','Lead Excel export preserves manual exclusion and period filters');
 await page.locator('[data-view="bills"]').click();await page.getByRole('heading',{name:'חיובים ותשלומים',exact:true}).waitFor();
 await check(!lastBillQuery.has('billing_status')&&await page.locator('#filter-billing-status').count()===0,'Financial lists do not receive the lead-only exclusion filter');
 await page.locator('[data-view="reports"]').click();await page.getByRole('heading',{name:'דוחות וסיכומים',exact:true}).waitFor();await page.locator('#print-report').click();await page.locator('#print-snapshot').waitFor({state:'attached'});
 await check(!lastReportQuery.has('billing_status')&&lastReportQuery.get('target')==='bills','Financial PDF reports do not inherit the lead exclusion filter');await page.evaluate(()=>window.dispatchEvent(new Event('afterprint')));
 await page.locator('[data-view="dashboard"]').click();await page.getByRole('heading',{name:'סקירה כללית',exact:true}).waitFor();
 await check(!lastSummaryQuery.has('billing_status')&&!lastDeliveryQuery.has('billing_status')&&(await page.locator('[data-metric="received"] .value').textContent())==='4622'&&(await page.locator('[data-metric="leads"] .value').textContent())==='4008'&&await page.locator('.recent-leads .badge.excluded').count()===1,'Exclusion remains visible in recent leads without changing source or recipient counters');
 memberView=true;await page.reload();await page.getByRole('heading',{name:'סקירה כללית',exact:true}).waitFor();
 await check(await page.locator('#capture-retry-alert,#capture-pending-count').count()===0&&(await page.locator('.attention-list').textContent()).includes('קליטה לא הושלמה'),'Institution view hides global queue information even if a response incorrectly includes a positive count');
 // The HTML navigation still belongs to the real manager cookie; the mocked bootstrap must reject that stale entry point.
 await page.locator('[data-view="institutions"]').click();await page.getByRole('heading',{name:'לא ניתן לטעון את הנתונים',exact:true}).waitFor();
 await check(await page.locator('#institution-bulk-bar,#bulk-rates,.institution-check,[data-rate]').count()===0&&(await page.locator('#load-error').textContent()).includes('אין הרשאה לניהול מוסדות ותעריפים'),'A read-only bootstrap rejects stale manager navigation before rendering bulk controls or institution tariffs');
 await page.locator('[data-view="deliveries"]').click();await page.getByRole('heading',{name:'מרכז לידים',exact:true}).waitFor();await page.locator('[data-detail="91"]').click();
 await check(await page.locator('#modal .badge.excluded').count()===1&&await page.locator('#toggle-billing-exclusion,#treatment-form,#billing-exclusion-form').count()===0,'Institution view shows exclusion as read-only and offers no modification action');await page.keyboard.press('Escape');memberView=false;
 lead.bill=92;await page.reload();await page.getByRole('heading',{name:'סקירה כללית',exact:true}).waitFor();await page.locator('[data-view="deliveries"]').click();await page.getByRole('heading',{name:'מרכז לידים',exact:true}).waitFor();await page.locator('[data-detail="91"]').click();
 await check(await page.locator('#toggle-billing-exclusion').isDisabled(),'A delivery bound to an approved bill has a disabled exclusion action');await page.keyboard.press('Escape');lead.bill=0;
 await page.locator('[data-view="deliveries"]').click();await page.getByRole('heading',{name:'מרכז לידים',exact:true}).waitFor();await page.locator('[data-detail="91"]').click();await page.locator('#toggle-billing-exclusion').click();await page.getByRole('button',{name:'אישור ביטול החרגה',exact:true}).click();await page.locator('tbody .badge.excluded').waitFor({state:'detached'});await page.waitForFunction(()=>!document.getElementById('modal').open&&document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(lastExclusionPayload.excluded===false&&lead.billing_excluded===false&&lead.duplicate_of===originalDuplicate&&!((await page.locator('#notification').textContent()).includes('להכין מחדש'))&&await page.locator('tbody .badge.excluded').count()===0,'Restoring a lead clears only its manual flag and does not report an unnecessary draft refresh');
 await page.locator('#filter-period').selectOption('year');await page.locator('#filter-year').fill('2026');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await page.evaluate(()=>{window.print=()=>{};});await page.locator('#print-leads').click();await page.locator('#print-snapshot').waitFor({state:'attached'});
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('#print-snapshot img').count()===0&&(await page.locator('#print-snapshot').textContent()).includes('דוח לידים')&&await page.locator('#print-snapshot tbody tr').count()===2,'Lead PDF snapshot includes all matching records and escapes hostile contact data');await check(lastReportQuery.get('year')==='2026'&&!lastReportQuery.get('month')&&(await page.locator('#print-snapshot').textContent()).includes('שנת 2026'),'PDF lead report preserves the selected full year');await page.evaluate(()=>window.dispatchEvent(new Event('afterprint')));
 mode='deferred-report';await page.locator('#filter-institution').selectOption('1');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await page.locator('#print-leads').click();await reportStartedPromise;assert.ok(releaseReport,'Deferred report request started');
 await page.locator('#filter-period').selectOption('range');await page.locator('#filter-from').fill('2026-09-01');await page.locator('#filter-to').fill('2026-09-30');await page.locator('#filter-institution').selectOption('2');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 releaseReport();await page.locator('#print-snapshot').waitFor({state:'attached'});
 const delayedReportText=await page.locator('#print-snapshot').textContent();
 await check(lastReportQuery.get('year')==='2026'&&lastReportQuery.get('institution')==='1'&&delayedReportText.includes('שנת 2026')&&delayedReportText.includes('מוסד הדגמה')&&!delayedReportText.includes('2026-09-01 – 2026-09-30')&&!delayedReportText.includes('מוסד עתידי'),'A delayed PDF report keeps the requested period and institution after the screen filters change');
 await page.evaluate(()=>window.dispatchEvent(new Event('afterprint')));mode='normal';await page.locator('#filter-institution').selectOption('');
 await page.locator('#filter-period').selectOption('range');await page.locator('#filter-from').fill('2026-09-01');await page.locator('#filter-to').fill('2026-09-30');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await page.locator('#print-leads').click();await page.locator('#print-snapshot').waitFor({state:'attached'});
 await check(lastReportQuery.get('date_from')==='2026-09-01'&&lastReportQuery.get('date_to')==='2026-09-30'&&!lastReportQuery.get('month')&&!lastReportQuery.get('year')&&(await page.locator('#print-snapshot').textContent()).includes('2026-09-01 – 2026-09-30'),'PDF lead report preserves both date-range boundaries without a month or year filter');await page.evaluate(()=>window.dispatchEvent(new Event('afterprint')));
 const downloadStarted=page.waitForEvent('download');await page.locator('#export').click();const filteredDownload=await downloadStarted;
 await check(lastExportQuery.get('date_from')==='2026-09-01'&&lastExportQuery.get('date_to')==='2026-09-30'&&!lastExportQuery.get('month')&&!lastExportQuery.get('year')&&filteredDownload.suggestedFilename()==='filtered-fixture.xlsx','Excel download preserves both date-range boundaries without a month or year filter');
 await page.locator('[data-view="bills"]').click();await page.getByRole('heading',{name:'חיובים ותשלומים',exact:true}).waitFor();await page.locator('[data-bill]').click();
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('dialog img').count()===0,'Billing counters and line references escape hostile HTML');await page.keyboard.press('Escape');
 await page.locator('[data-view="audit"]').click();await page.getByRole('heading',{name:'יומן פעילות',exact:true}).waitFor();
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('#screen img').count()===0,'Audit event, actor and target escape hostile HTML');
 await check((await page.locator('#screen').textContent()).includes('פנייה הוחרגה מחיוב')&&(await page.locator('#screen').textContent()).includes('החרגה מחיוב בוטלה'),'Audit table labels exclusion and restoration as separate recorded actions');
 await page.locator('[data-view="reports"]').click();await page.getByRole('heading',{name:'דוחות וסיכומים',exact:true}).waitFor();await page.evaluate(()=>{window.print=()=>{};});await page.locator('#print-report').click();await page.locator('#print-snapshot').waitFor({state:'attached'});
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('#print-snapshot img').count()===0,'Print snapshot escapes hostile institution names and data');await page.evaluate(()=>window.dispatchEvent(new Event('afterprint')));
 await page.locator('[data-view="institutions"]').click();await page.getByRole('heading',{name:'מוסדות ותעריפים',exact:true}).waitFor();
 const active=page.locator('article').filter({has:page.locator('[data-rate="1"]')}),future=page.locator('article').filter({has:page.locator('[data-rate="2"]')});
 await check(await page.locator('.institution-check').count()===3&&await page.locator('#bulk-rates').isDisabled()&&(await page.locator('#institution-selected-count').textContent()).endsWith('0'),'Bulk tariff controls start with an explicit empty selection and a disabled update button');
 await page.locator('.institution-check[value="1"]').check();
 const partiallySelected=await page.locator('#institutions-select-all').evaluate(input=>input.indeterminate);
 await page.locator('#institutions-select-all').check();const selectedAll=await page.locator('.institution-check:checked').count();await page.locator('#institutions-select-all').uncheck();
 await check(partiallySelected&&selectedAll===3&&await page.locator('.institution-check:checked').count()===0&&await page.locator('#bulk-rates').isDisabled(),'Institution selection supports individual, select-all, and clear-all states');
 await page.locator('.institution-check[value="1"]').check();await page.locator('#institution-search').fill('מוסד עתידי');
 const hiddenSelectionCleared=await page.locator('#bulk-rates').isDisabled();await page.locator('#institutions-select-all').check();await page.locator('#institution-search').fill('');
 await check(hiddenSelectionCleared&&await page.locator('.institution-check:checked').count()===1&&await page.locator('.institution-check[value="2"]').isChecked()&&await page.locator('.institution-check[value="1"]').isChecked()===false,'Filtering removes hidden selections and select-all targets only currently displayed institutions');
 await page.locator('#institutions-select-all').uncheck();
 await check((await active.textContent()).includes('תעריף פעיל')&&(await active.textContent()).includes('תעריף עתידי מ־2026-11-01')&&(await future.textContent()).includes('תעריף עתידי בלבד'),'Current and scheduled future tariffs are shown separately');
 await active.locator('[data-rate]').click();await check(await page.locator('#rate-price').inputValue()==='10','Tariff edit defaults to the rate effective today');
 await check(await page.locator('#modal input[name="vat_percent"],#modal #vat').count()===0&&/18\s*%/.test(await page.locator('#modal').textContent()),'Fixed VAT is displayed without an institution-level VAT input');await page.keyboard.press('Escape');
 await future.locator('[data-rate]').click();await check(await page.locator('#rate-price').inputValue()==='','A future-only tariff does not prefill an active rate');await page.keyboard.press('Escape');
 await page.locator('[data-policy="2"]').click();
 await check(await page.locator('#institution-duplicate-mode').inputValue()==='custom'&&await page.locator('#institution-duplicate-days').inputValue()==='45','Institution duplicate dialog reads its own configured period');await page.keyboard.press('Escape');
 await page.locator('[data-policy="3"]').click();
 await check(await page.locator('#institution-duplicate-mode').inputValue()==='all_time'&&!(await page.locator('#institution-duplicate-days').isVisible())&&await page.getByRole('button',{name:'שמירת תקופת כפילות',exact:true}).isEnabled(),'An institution without a policy or tariff defaults to all history and can edit its own policy');
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('dialog img').count()===0&&(await page.locator('#modal-title').textContent()).includes(hostile),'Institution policy dialog escapes hostile institution names');
 await page.locator('#institution-duplicate-mode').selectOption('custom');await page.locator('#institution-duplicate-days').fill('0');
 const writesBeforeInvalid=policyWrites;await page.getByRole('button',{name:'שמירת תקופת כפילות',exact:true}).click();
 await check(policyWrites===writesBeforeInvalid&&await page.locator('#institution-duplicate-days').evaluate(input=>!input.validity.valid),'Invalid custom day count is rejected before a policy request is sent');
 await page.locator('#institution-duplicate-mode').selectOption('all_time');
 await check(!(await page.locator('#institution-duplicate-days').isVisible())&&!(await page.locator('#institution-duplicate-days').isEnabled()),'All-history selection disables irrelevant custom-day validation');
 await page.getByRole('button',{name:'שמירת תקופת כפילות',exact:true}).click();await page.waitForFunction(()=>!document.getElementById('modal').open&&document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(lastPolicyPayload.institution===3&&lastPolicyPayload.duplicate_mode==='all_time'&&!Object.hasOwn(lastPolicyPayload,'duplicate_days'),'All-history policy saves independently of tariffs and has no arbitrary day limit');
 await page.locator('[data-policy="1"]').click();await page.locator('#institution-duplicate-mode').selectOption('custom');await page.locator('#institution-duplicate-days').fill('37');await page.getByRole('button',{name:'שמירת תקופת כפילות',exact:true}).click();await page.waitForFunction(()=>!document.getElementById('modal').open&&document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(lastPolicyPayload.institution===1&&lastPolicyPayload.duplicate_mode==='custom'&&lastPolicyPayload.duplicate_days===37,'Institution custom policy sends the selected institution and bounded numeric days');
 await page.locator('[data-policy="2"]').click();await check(await page.locator('#institution-duplicate-mode').inputValue()==='custom'&&await page.locator('#institution-duplicate-days').inputValue()==='45','Saving one institution leaves another institution policy unchanged');await page.keyboard.press('Escape');
 await page.locator('[data-policy="1"]').click();await check(await page.locator('#institution-duplicate-mode').inputValue()==='custom'&&await page.locator('#institution-duplicate-days').inputValue()==='37','A saved institution policy is shown again after bootstrap refresh');
 mode='policy-error';await page.locator('#institution-duplicate-mode').selectOption('days_90');await page.getByRole('button',{name:'שמירת תקופת כפילות',exact:true}).click();await page.locator('#modal .form-error').filter({hasText:'לא ניתן לשמור'}).waitFor();
 await check(await page.locator('#modal').isVisible()&&await page.locator('#institution-duplicate-mode').inputValue()==='days_90','Failed policy save keeps the dialog and selected period available for correction');await page.keyboard.press('Escape');mode='normal';
 const bulkOriginalCredit=bootstrap.institutions[1].agreement.credit_days,bulkOriginalRates=JSON.stringify(bootstrap.institutions[1].agreement.rates),bulkOriginalPolicy=JSON.stringify(bootstrap.institutions[1].duplicate_policy);
 await page.locator('.institution-check[value="2"]').check();await page.locator('.institution-check[value="3"]').check();await page.locator('#bulk-rates').click();
 await check(await page.locator('#bulk-rate-price').inputValue()===''&&await page.locator('#bulk-rate-from').inputValue()===bootstrap.today&&await page.locator('#bulk-rate-form [name="credit_days"],#bulk-rate-form [name="duplicate_mode"],#bulk-rate-form [name="vat_percent"]').count()===0&&await page.locator('dialog img').count()===0&&(await page.locator('#modal').textContent()).includes(hostile),'Bulk tariff editor lists escaped institutions and offers only common price and effective date with fixed VAT');
 const bulkWritesBefore=bulkRateWrites;await page.locator('#bulk-rate-price').fill('-1');await page.getByRole('button',{name:'המשך לאישור העדכון',exact:true}).click();
 await check(bulkRateWrites===bulkWritesBefore&&await page.locator('#bulk-rate-price').evaluate(input=>!input.validity.valid)&&await page.locator('#bulk-rate-form').isVisible(),'Invalid bulk prices are blocked before confirmation or a write request');
 await page.locator('#bulk-rate-price').fill('12.75');await page.locator('#bulk-rate-from').fill('');await page.getByRole('button',{name:'המשך לאישור העדכון',exact:true}).click();
 await check(bulkRateWrites===bulkWritesBefore&&await page.locator('#bulk-rate-from').evaluate(input=>!input.validity.valid),'A missing bulk effective date cannot reach the write API');
 await page.locator('#bulk-rate-from').fill('2026-12-01');await page.getByRole('button',{name:'המשך לאישור העדכון',exact:true}).click();
 await check(await page.locator('#bulk-rate-confirm-form').isVisible()&&(await page.locator('#modal').textContent()).includes('12.75')&&(await page.locator('#modal').textContent()).includes('2026-12-01')&&(await page.locator('#modal').textContent()).includes('מוסד עתידי')&&(await page.locator('#modal').textContent()).includes(hostile)&&await page.locator('dialog img').count()===0&&await page.evaluate(()=>!window.__limuXss)&&bulkRateWrites===bulkWritesBefore,'Bulk confirmation reviews the selected names, common price, and date without sending a write');
 await page.locator('#edit-bulk-rates').click();await check(await page.locator('#bulk-rate-price').inputValue()==='12.75'&&await page.locator('#bulk-rate-from').inputValue()==='2026-12-01','Returning from bulk confirmation preserves the reviewed values and institution set');
 await page.getByRole('button',{name:'המשך לאישור העדכון',exact:true}).click();mode='bulk-error';await page.getByRole('button',{name:'אישור עדכון התעריפים',exact:true}).click();await page.locator('#bulk-rate-confirm-form .form-error').filter({hasText:'חיוב מאושר'}).waitFor();
 await check(await page.locator('#bulk-rate-confirm-form').isVisible()&&JSON.stringify(bootstrap.institutions[1].agreement.rates)===bulkOriginalRates&&await page.locator('.institution-check:checked').count()===2&&!(await page.locator('#notification').textContent()).includes('עדכון התעריפים הושלם'),'A closed-period conflict preserves all displayed tariffs, confirmation, and selection without claiming success');
 mode='bulk-malformed';await page.getByRole('button',{name:'אישור עדכון התעריפים',exact:true}).click();await page.locator('#bulk-rate-confirm-form .form-error').filter({hasText:'לא ניתן לאמת'}).waitFor();
 await check(await page.locator('#bulk-rate-confirm-form').isVisible()&&await page.locator('.institution-check:checked').count()===2&&!(await page.locator('#notification').textContent()).includes('עדכון התעריפים הושלם'),'Malformed bulk responses do not trigger undefined-length errors or false saved notifications');
 mode='bulk-deferred';await page.getByRole('button',{name:'אישור עדכון התעריפים',exact:true}).click();await bulkRatesStartedPromise;
 await check(await page.locator('#bulk-rate-confirm-form button[type="submit"]').isDisabled()&&(await page.locator('#bulk-rate-confirm-form button[type="submit"]').textContent()).includes('מעדכן')&&await page.locator('#edit-bulk-rates').isDisabled()&&await page.locator('#bulk-rates').isDisabled()&&await page.locator('.institution-check').evaluateAll(inputs=>inputs.every(input=>input.disabled)),'An in-flight bulk update disables repeated writes, editing, and institution selection while showing progress');
 releaseBulkRates();await page.waitForFunction(()=>!document.getElementById('modal').open&&document.getElementById('screen').getAttribute('aria-busy')==='false'&&document.querySelectorAll('.institution-check:checked').length===0&&document.getElementById('notification').textContent.includes('עודכנו: 2'));
 await check(JSON.stringify(lastBulkRatePayload.institutions)==='[2,3]'&&lastBulkRatePayload.price==='12.75'&&lastBulkRatePayload.from==='2026-12-01'&&await page.locator('.institution-check:checked').count()===0&&await page.locator('#bulk-rates').isDisabled()&&(await page.locator('#notification').textContent()).includes('עודכנו: 2'),'Confirmed bulk save sends the exact selected IDs and decimal price once and clears selection after refreshing institutions');
 await check(bootstrap.institutions[1].agreement.credit_days===bulkOriginalCredit&&JSON.stringify(bootstrap.institutions[1].duplicate_policy)===bulkOriginalPolicy&&JSON.stringify(bootstrap.institutions[1].agreement.rates.filter(rate=>rate.from!=='2026-12-01'))===bulkOriginalRates&&bootstrap.institutions[1].agreement.rates.at(-1).price===1275,'Bulk refresh retains individual credit terms, duplicate policy, and prior tariff history');
 mode='bulk-unchanged';await page.locator('.institution-check[value="2"]').check();await page.locator('.institution-check[value="3"]').check();await page.locator('#bulk-rates').click();await page.locator('#bulk-rate-price').fill('12.75');await page.locator('#bulk-rate-from').fill('2026-12-01');await page.getByRole('button',{name:'המשך לאישור העדכון',exact:true}).click();await page.getByRole('button',{name:'אישור עדכון התעריפים',exact:true}).click();await page.waitForFunction(()=>!document.getElementById('modal').open&&document.getElementById('screen').getAttribute('aria-busy')==='false'&&document.querySelectorAll('.institution-check:checked').length===0&&document.getElementById('notification').textContent.includes('לא נדרשו שינויים'));
 await check((await page.locator('#notification').textContent()).includes('לא נדרשו שינויים')&&await page.locator('.institution-check:checked').count()===0,'An unchanged tariff replay is reported clearly and clears its completed selection');mode='normal';
 mode='save-refresh-error';await active.locator('[data-rate]').click();await page.getByRole('button',{name:'שמירת תעריף',exact:true}).click();await page.waitForFunction(()=>document.getElementById('notification').textContent.includes('עדכון התצוגה נכשל'));
 await check((await page.locator('#notification').textContent()).startsWith('הנתונים נשמרו'),'Successful save followed by refresh failure is reported as committed');
 mode='normal';refreshFailure=false;await page.reload();await page.getByRole('heading',{name:'סקירה כללית'}).waitFor();await page.locator('[data-view="institutions"]').click();
 mode='deferred-save';await page.locator('[data-rate="1"]').click();await page.getByRole('button',{name:'שמירת תעריף',exact:true}).click();
 await writeStartedPromise;assert.ok(releaseWrite,'Mock write started');await page.keyboard.press('Escape');await page.locator('[data-rate="2"]').click();releaseWrite();await page.waitForFunction(()=>document.getElementById('notification').textContent==='הנתונים נשמרו');
 await check(await page.locator('#modal').isVisible()&&(await page.locator('#modal-title').textContent()).includes('מוסד עתידי'),'Completion of an older save does not close a newer dialog');await page.keyboard.press('Escape');
 mode='expired';await page.locator('[data-view="deliveries"]').click();await page.getByRole('heading',{name:'לא ניתן לטעון את הנתונים'}).waitFor();
 await check(await page.locator('#retry').textContent()==='רענון והתחברות מחדש'&&(await page.locator('#load-error').textContent()).includes('ההתחברות פגה'),'Expired authentication explains recovery instead of retrying a stale nonce');
 mode='normal';await page.locator('[data-view="settings"]').click();await page.getByRole('heading',{name:'הגדרות והרשאות',exact:true}).waitFor();
 await check(await page.locator('#settings-form').isVisible()&&await page.locator('#load-error').count()===0,'Current settings render when the bootstrap omits the retired forms property');
 await check(await page.getByRole('button',{name:'הוספת טופס'}).count()===0&&!/Elementor|חיבור טפסי/.test(await page.locator('#screen').textContent()),'Native capture requires no Elementor mapping in settings');
 await check(await page.locator('#import,#import-progress').count()===0&&await page.getByRole('button',{name:'ייבוא היסטוריה',exact:true}).count()===0,'Settings omit the removed manual import interface');
 await check(await page.locator('#settings-form [name="duplicate_mode"],#settings-form [name="duplicate_days"]').count()===0,'Global settings refer to institution policies without offering a conflicting global duplicate selector');
 await page.getByRole('button',{name:'שמירת הגדרות',exact:true}).click();await page.waitForFunction(()=>document.getElementById('notification').textContent==='הנתונים נשמרו'&&document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(lastSettingsPayload&&Object.keys(lastSettingsPayload).sort().join(',')==='automatic,start_date'&&lastSettingsPayload.automatic===false&&lastSettingsPayload.start_date==='','Global settings save only billing activation and approval preferences');
 await page.evaluate(()=>{localStorage.setItem('limu-crm-display-v1','1');localStorage.setItem('limu-crm-privacy','malformed');localStorage.setItem('limu-crm-privacy-seen','malformed');});await page.reload();await page.getByRole('heading',{name:'סקירה כללית',exact:true}).waitFor();
 await check(await page.locator('#privacy-notice').isVisible(),'Malformed privacy acknowledgment and old display settings leave a usable privacy notice');await page.locator('#privacy-ack').click();await check(!(await page.locator('#privacy-notice').isVisible()),'Privacy acknowledgment dismisses its notice');
 await page.addInitScript(()=>{Object.defineProperty(Storage.prototype,'getItem',{configurable:true,value(){throw new DOMException('Storage disabled','SecurityError');}});Object.defineProperty(Storage.prototype,'setItem',{configurable:true,value(){throw new DOMException('Storage disabled','SecurityError');}});});
 await page.reload();await page.getByRole('heading',{name:'סקירה כללית',exact:true}).waitFor();await page.locator('#privacy-ack').click();
 await check(!(await page.locator('#privacy-notice').isVisible())&&errors.length===0,'Blocked browser storage does not prevent privacy dismissal or CRM loading');
 console.log(checks.length+' nonmutating frontend regression checks passed.');
 }finally{if(releaseWrite)releaseWrite();if(releaseReport)releaseReport();if(releaseBulkRates)releaseBulkRates();await browser.close();}
})().catch(error=>{console.error(error.message);process.exit(1);});
