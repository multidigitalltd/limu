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
  {id:1,name:'מוסד הדגמה',agreement:{credit_days:30,rates:[{from:'2026-01-01',price:1000,vat_bp:1800},{from:'2026-11-01',price:2500,vat_bp:1800}]}},
  {id:2,name:'מוסד עתידי',agreement:{credit_days:15,rates:[{from:'2026-11-15',price:3000,vat_bp:1800}]}},
  {id:3,name:hostile,agreement:null}
 ]};
 const lead={id:91,name:hostile,institution:3,date:'2026-09-15 12:00:00',phone:hostile,email:hostile,form:hostile,duplicate_of:hostile,state:'historical',notes:[{at:'2026-09-15',text:hostile}],treatment:'new'};
 const pendingLead={...lead,id:93,name:'קליטה שלא הושלמה',state:'pending'};
 const bill={id:92,institution:3,month:'2026-09',state:'approved',total:1180,subtotal:1000,vat:180,paid:0,due:'2026-11-06',issued:'2026-10-07',duplicates:hostile,historical:hostile,lines:[{delivery:hostile,price:1000}]};
 let mode='normal',refreshFailure=false,pendingWrite=null,releaseWrite,writeStarted,lastSummaryQuery,lastReportQuery,lastExportQuery;
 const writeStartedPromise=new Promise(resolve=>writeStarted=resolve);
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
   assert.equal(endpoint,'agreement','Only mock tariff save is allowed');
   if(mode==='deferred-save'){pendingWrite=new Promise(resolve=>releaseWrite=resolve);writeStarted();await pendingWrite;}
   if(mode==='save-refresh-error')refreshFailure=true;
   return json(route,{saved:true});
  }
  if(mode==='expired')return json(route,{code:'rest_cookie_invalid_nonce',message:'Cookie check failed'},403);
  if(endpoint==='bootstrap')return refreshFailure?json(route,{message:'כשל רשת מדומה'},503):json(route,bootstrap);
  if(endpoint==='summary'){lastSummaryQuery=url.searchParams;const scoped=url.searchParams.get('institution')==='2';return json(route,{leads:hostile,received_leads:scoped?6:4622,institution_leads:scoped?9:4008,institution_historical:scoped?1:hostile,confirmed:1,approved:1180,paid:0,overdue:0,draft:0,duplicates:0,pending:0,unmapped:3,historical:1,by_institution:{3:1}});}
  if(endpoint==='deliveries')return json(route,{items:[lead,pendingLead],total:2,pages:1});
  if(endpoint==='report'&&url.searchParams.get('target')==='deliveries'){lastReportQuery=url.searchParams;return json(route,{items:[lead,pendingLead],total:2,pages:1});}
  if(endpoint==='bills'||endpoint==='report'){if(endpoint==='report')lastReportQuery=url.searchParams;return json(route,{items:[bill],total:1,pages:1});}
  if(endpoint==='export'){lastExportQuery=url.searchParams;return json(route,{file:Buffer.from('Filtered export response fixture').toString('base64'),name:'filtered-fixture.xlsx',count:2});}
  if(endpoint==='audit')return json(route,{items:[{at:'2026-10-07',event:hostile,actor:hostile,target:hostile}],total:1,pages:1});
  throw new Error('Unexpected fixture endpoint '+endpoint);
 });
 await page.goto(base+'/crm/');
 if(await page.locator('#user_login').count()){
  if(await page.locator('#privacy-ack').isVisible())await page.locator('#privacy-ack').click();
  await page.locator('#user_login').fill(credentials.user);await page.locator('#user_pass').fill(credentials.password);await page.getByRole('button',{name:'כניסה למערכת'}).click();
 }
 await page.getByRole('heading',{name:'סקירה כללית'}).waitFor();
 await check(!(await page.locator('body').textContent()).includes('מרחב ניהול מאובטח'),'Portal omits the removed topbar wording');
 await check((await page.locator('[data-metric="received"] .value').textContent())==='4622'&&(await page.locator('[data-metric="leads"] .value').textContent())==='4008'&&(await page.locator('[data-metric="received"] .hint').textContent()).includes('פעם אחת')&&(await page.locator('[data-metric="leads"] .hint').textContent()).includes('בנפרד'),'Dashboard distinguishes site submissions from institution recipient deliveries with an explanation of each count');
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('#screen img').count()===0&&(await page.locator('[data-metric="leads"] .hint').textContent()).includes(hostile),'Dashboard historical counters and institution names escape hostile HTML');
 await check(await page.locator('#access-tools,#reading-guide,a[href*="m-d.co.il"]').count()===0&&await page.getByRole('link',{name:'הצהרת נגישות',exact:true}).count()===0,'Portal omits the removed toolbar, accessibility statement and credit');
 await page.locator('#missing-rates').click();await page.getByRole('heading',{name:'מוסדות ותעריפים',exact:true}).waitFor();
 await check(await page.locator('#institution-status').inputValue()==='missing'&&await page.locator('[data-rate="1"]').count()===0&&await page.locator('[data-rate="2"]').count()===1&&await page.locator('[data-rate="3"]').count()===1,'Missing-rate alert opens only institutions without a currently active tariff');
 await page.locator('#institution-search').fill('מוסד עתידי');await check(await page.locator('[data-rate="2"]').count()===1&&await page.locator('[data-rate="3"]').count()===0,'Institution search refines the missing-rate list');await page.locator('[data-view="dashboard"]').click();await page.getByRole('heading',{name:'סקירה כללית',exact:true}).waitFor();
 await page.locator('#filter-period').selectOption('year');await page.locator('#filter-year').fill('2026');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(lastSummaryQuery.get('year')==='2026'&&!lastSummaryQuery.get('month')&&(await page.locator('.period-chip').textContent()).includes('2026'),'Whole-year summary requests contain a year without a monthly constraint');
 await check(await page.locator('.metrics .label').evaluateAll(labels=>labels.length===5&&labels.every(label=>label.textContent.includes('שנת 2026'))),'Every dashboard metric title identifies the selected full year');
 await page.locator('#filter-period').selectOption('range');await page.locator('#filter-from').fill('2026-09-01');await page.locator('#filter-to').fill('2026-09-30');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(lastSummaryQuery.get('date_from')==='2026-09-01'&&lastSummaryQuery.get('date_to')==='2026-09-30'&&!lastSummaryQuery.get('month')&&!lastSummaryQuery.get('year')&&(await page.locator('.period-chip').textContent()).includes('2026-09-01 – 2026-09-30'),'Dashboard date-range request and label omit monthly and yearly constraints');
 await page.locator('#filter-institution').selectOption('2');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await check(lastSummaryQuery.get('institution')==='2'&&await page.locator('.metrics .label').evaluateAll(labels=>labels.length===5&&labels.every(label=>label.textContent.includes('2026-09-01 – 2026-09-30')&&label.textContent.includes('מוסד עתידי'))),'Every dashboard metric title identifies both the selected date range and institution');
 await check((await page.locator('[data-metric="received"] .value').textContent())==='6'&&(await page.locator('[data-metric="leads"] .value').textContent())==='9','Institution filtering updates both original-submission and recipient-delivery totals');
 await page.setViewportSize({width:390,height:844});
 await check(await page.getByRole('link',{name:'יציאה',exact:true}).isVisible(),'Mobile portal retains a usable logout control');
 await page.setViewportSize({width:1440,height:1000});
 await page.locator('[data-view="deliveries"]').click();await page.getByRole('heading',{name:'מרכז לידים',exact:true}).waitFor();await check(await page.locator('[data-confirm]').count()===0&&(await page.locator('#screen').textContent()).includes('קליטה לא הושלמה'),'Incomplete legacy capture has no manual lead approval action');await page.locator('[data-detail="91"]').click();
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('dialog img').count()===0,'Lead fields, duplicate references and internal notes escape hostile HTML');await page.keyboard.press('Escape');
 await page.locator('#filter-period').selectOption('year');await page.locator('#filter-year').fill('2026');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await page.evaluate(()=>{window.print=()=>{};});await page.locator('#print-leads').click();await page.locator('#print-snapshot').waitFor({state:'attached'});
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('#print-snapshot img').count()===0&&(await page.locator('#print-snapshot').textContent()).includes('דוח לידים')&&await page.locator('#print-snapshot tbody tr').count()===2,'Lead PDF snapshot includes all matching records and escapes hostile contact data');await check(lastReportQuery.get('year')==='2026'&&!lastReportQuery.get('month')&&(await page.locator('#print-snapshot').textContent()).includes('שנת 2026'),'PDF lead report preserves the selected full year');await page.evaluate(()=>window.dispatchEvent(new Event('afterprint')));
 await page.locator('#filter-period').selectOption('range');await page.locator('#filter-from').fill('2026-09-01');await page.locator('#filter-to').fill('2026-09-30');await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await page.locator('#print-leads').click();await page.locator('#print-snapshot').waitFor({state:'attached'});
 await check(lastReportQuery.get('date_from')==='2026-09-01'&&lastReportQuery.get('date_to')==='2026-09-30'&&!lastReportQuery.get('month')&&!lastReportQuery.get('year')&&(await page.locator('#print-snapshot').textContent()).includes('2026-09-01 – 2026-09-30'),'PDF lead report preserves both date-range boundaries without a month or year filter');await page.evaluate(()=>window.dispatchEvent(new Event('afterprint')));
 const downloadStarted=page.waitForEvent('download');await page.locator('#export').click();const filteredDownload=await downloadStarted;
 await check(lastExportQuery.get('date_from')==='2026-09-01'&&lastExportQuery.get('date_to')==='2026-09-30'&&!lastExportQuery.get('month')&&!lastExportQuery.get('year')&&filteredDownload.suggestedFilename()==='filtered-fixture.xlsx','Excel download preserves both date-range boundaries without a month or year filter');
 await page.locator('[data-view="bills"]').click();await page.getByRole('heading',{name:'חיובים ותשלומים',exact:true}).waitFor();await page.locator('[data-bill]').click();
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('dialog img').count()===0,'Billing counters and line references escape hostile HTML');await page.keyboard.press('Escape');
 await page.locator('[data-view="audit"]').click();await page.getByRole('heading',{name:'יומן פעילות',exact:true}).waitFor();
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('#screen img').count()===0,'Audit event, actor and target escape hostile HTML');
 await page.locator('[data-view="reports"]').click();await page.getByRole('heading',{name:'דוחות וסיכומים',exact:true}).waitFor();await page.evaluate(()=>{window.print=()=>{};});await page.locator('#print-report').click();await page.locator('#print-snapshot').waitFor({state:'attached'});
 await check(await page.evaluate(()=>!window.__limuXss)&&await page.locator('#print-snapshot img').count()===0,'Print snapshot escapes hostile institution names and data');await page.evaluate(()=>window.dispatchEvent(new Event('afterprint')));
 await page.locator('[data-view="institutions"]').click();await page.getByRole('heading',{name:'מוסדות ותעריפים',exact:true}).waitFor();
 const active=page.locator('article').filter({has:page.locator('[data-rate="1"]')}),future=page.locator('article').filter({has:page.locator('[data-rate="2"]')});
 await check((await active.textContent()).includes('תעריף פעיל')&&(await active.textContent()).includes('תעריף עתידי מ־2026-11-01')&&(await future.textContent()).includes('תעריף עתידי בלבד'),'Current and scheduled future tariffs are shown separately');
 await active.locator('[data-rate]').click();await check(await page.locator('#rate-price').inputValue()==='10','Tariff edit defaults to the rate effective today');
 await check(await page.locator('#modal input[name="vat_percent"],#modal #vat').count()===0&&/18\s*%/.test(await page.locator('#modal').textContent()),'Fixed VAT is displayed without an institution-level VAT input');await page.keyboard.press('Escape');
 await future.locator('[data-rate]').click();await check(await page.locator('#rate-price').inputValue()==='','A future-only tariff does not prefill an active rate');await page.keyboard.press('Escape');
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
 await page.evaluate(()=>{localStorage.setItem('limu-crm-display-v1','1');localStorage.setItem('limu-crm-privacy','malformed');localStorage.setItem('limu-crm-privacy-seen','malformed');});await page.reload();await page.getByRole('heading',{name:'סקירה כללית',exact:true}).waitFor();
 await check(await page.locator('#privacy-notice').isVisible(),'Malformed privacy acknowledgment and old display settings leave a usable privacy notice');await page.locator('#privacy-ack').click();await check(!(await page.locator('#privacy-notice').isVisible()),'Privacy acknowledgment dismisses its notice');
 await page.addInitScript(()=>{Object.defineProperty(Storage.prototype,'getItem',{configurable:true,value(){throw new DOMException('Storage disabled','SecurityError');}});Object.defineProperty(Storage.prototype,'setItem',{configurable:true,value(){throw new DOMException('Storage disabled','SecurityError');}});});
 await page.reload();await page.getByRole('heading',{name:'סקירה כללית',exact:true}).waitFor();await page.locator('#privacy-ack').click();
 await check(!(await page.locator('#privacy-notice').isVisible())&&errors.length===0,'Blocked browser storage does not prevent privacy dismissal or CRM loading');
 console.log(checks.length+' nonmutating frontend regression checks passed.');
 }finally{if(releaseWrite)releaseWrite();await browser.close();}
})().catch(error=>{console.error(error.message);process.exit(1);});
