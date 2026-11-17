/* Shared billing UI regressions; all business writes and iCount endpoints are mocked. */
const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
(async()=>{
 const base=process.env.LIMU_TEST_URL||'http://127.0.0.1:8090';assert.equal(new URL(base).hostname,'127.0.0.1');
 const credentials=JSON.parse(fs.readFileSync(process.env.LIMU_TEST_LOGIN,'utf8'));
 const browser=await chromium.launch({headless:true,executablePath:process.env.LIMU_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox']});
 const page=await browser.newPage({locale:'he-IL',viewport:{width:1440,height:1100}});
 const errors=[];page.on('pageerror',error=>errors.push(error.message));let count=0;
 const check=(ok,label)=>{assert.ok(ok,label);++count;console.log('PASS '+label);};
 const bootstrap={manager:true,name:'Synthetic manager',today:'2026-11-18',month:'2026-11',settings:{start_date:'2020-01-01',automatic:false,audit_retention_days:0},icount:{configured:true,verified:true,automatic:false,bank_accounts:[]},institutions:[{id:1,name:'מוסד א',icount_client:{id:700},agreement:{credit_days:30,rates:[{from:'2020-01-01',price:1000}]}},{id:2,name:'מוסד ב',agreement:{credit_days:14,rates:[{from:'2020-01-01',price:2000}]}}]};
 let groups=[],createPayload,closePayload,paymentPayload,approvePayload,issuePayload,mode='normal',createCount=0;
 const bill={id:100,institution:1,billing_group:'group-fixture',group_name:'גבייה משותפת',billing_members:[1,2],month:'2026-10',state:'approved',subtotal:3000,vat:540,total:3540,paid:1001,due:'2026-12-02',issued:'2026-11-18',credit_days:14,duplicates:0,historical:0,documents:[],shares:{1:{name:'מוסד א',count:1,total:1180,paid:334},2:{name:'מוסד ב',count:1,total:2360,paid:667}},lines:[{delivery:11,institution:1,institution_name:'מוסד א',price:1000},{delivery:12,institution:2,institution_name:'מוסד ב',price:2000}]};
 const json=(route,data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
 async function axe(label){if(!process.env.LIMU_AXE_PATH)return;await page.evaluate(source=>{if(window.axe)return;const script=document.createElement('script'),nonce=document.querySelector('script[nonce]');if(nonce)script.nonce=nonce.nonce;script.textContent=source;document.head.append(script);script.remove();},fs.readFileSync(process.env.LIMU_AXE_PATH,'utf8'));const violations=await page.evaluate(async()=> (await window.axe.run(document.body,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}})).violations);check(!violations.length,label+': '+JSON.stringify(violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>n.target)}))));}
 async function ready(){await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');}
 try{
 await page.route('**/limu-crm/v1/**',async route=>{
  const url=new URL(route.request().url()),endpoint=url.pathname.split('/').at(-1);
  if(endpoint==='bootstrap')return json(route,bootstrap);
  if(endpoint==='summary')return json(route,{leads:0,received_leads:0,institution_leads:0,approved:3540,paid:1001,overdue:0,draft:0,by_institution:{},capture_pending:0});
  if(endpoint==='billing-groups'){
   if(route.request().method()==='GET')return json(route,{items:groups});
   const payload=route.request().postDataJSON();
   if(mode==='error')return json(route,{message:'מוסד כבר משויך לקבוצה'},409);
   if(payload.until||payload.cancelled){closePayload=payload;Object.assign(groups.find(g=>g.id===payload.id),payload);return json(route,groups[0]);}
   createPayload=payload;++createCount;const group={...payload,until:'',cancelled:false};groups.push(group);return json(route,group);
  }
  if(endpoint==='issue'){issuePayload=route.request().postDataJSON();return json(route,{state:'issued',doctype:'invoice',docnum:9001});}
  if(endpoint==='approve'){approvePayload=route.request().postDataJSON();mode='normal';return json(route,{results:[bill]});}
  if(endpoint==='bills'){
   if(url.searchParams.get('institution')==='2')return json(route,{items:[{...bill,state:mode==='draft'?'draft':'approved',institution:2,total:2360,paid:mode==='draft'?0:667,scoped_group:true,group_name:'חלק מסונן בחיוב משותף',shares:{2:bill.shares[2]},lines:[bill.lines[1]]}],total:1,pages:1});
   return json(route,{items:[{...bill,state:mode==='draft'?'draft':'approved',paid:mode==='draft'?0:bill.paid}],total:1,pages:1});
  }
  if(endpoint==='bill'&&url.pathname.includes('/icount/'))return json(route,{bill,payments:[]});
  if(endpoint==='payment'){paymentPayload=route.request().postDataJSON();return json(route,{saved:true});}
  return json(route,{items:[],total:0,pages:1});
 });
 await page.goto(base+'/crm/');
 if(await page.locator('#user_login').isVisible()){
  if(await page.locator('#privacy-ack').isVisible())await page.locator('#privacy-ack').click();
  await page.locator('#user_login').fill(credentials.user);await page.locator('#user_pass').fill(credentials.password);await page.getByRole('button',{name:'כניסה למערכת',exact:true}).click();
 }
 await page.getByRole('heading',{name:'סקירה כללית',exact:true}).waitFor();
 await page.locator('[data-view="groups"]').focus();await page.keyboard.press('Enter');await page.getByRole('heading',{name:'קבוצות גבייה',exact:true}).waitFor();
 check(await page.getByText('טרם הוגדרו קבוצות גבייה',{exact:true}).isVisible(),'Manager reaches empty billing-group screen by keyboard');await axe('Group screen accessibility');
 await page.locator('#new-billing-group').click();
 await page.locator('#group-name').fill('גבייה משותפת');await page.locator('#group-members').selectOption(['1','2']);await page.locator('#group-payer').selectOption('1');await page.locator('#group-credit').fill('14');
 await axe('Group creation form accessibility');
 await page.locator('#billing-group-form button[type=submit]').click();await page.getByRole('heading',{name:'גבייה משותפת',exact:true}).waitFor();
 check(createCount===1&&createPayload.members.join(',')==='1,2'&&createPayload.payer===1&&createPayload.credit_days===14&&createPayload.from==='2026-11','Group form sends explicit members, payer, month, credit days and retry-stable UUID');
 check(await page.getByText('גורם משלם ולקוח iCount: מוסד א',{exact:true}).isVisible(),'Saved group identifies the chosen payer');
 if(process.env.LIMU_SCREENSHOT_DIR){fs.mkdirSync(process.env.LIMU_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.LIMU_SCREENSHOT_DIR,'shared-billing-desktop.png'),fullPage:true});}
 await page.setViewportSize({width:390,height:844});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Group cards fit mobile viewport');
 if(process.env.LIMU_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.LIMU_SCREENSHOT_DIR,'shared-billing-mobile.png'),fullPage:true});
 await page.setViewportSize({width:1440,height:1100});
 await page.locator('[data-close-group]').click();await page.locator('#group-until').fill('2026-12');await page.locator('#close-group-form button[type=submit]').click();await ready();
 check(closePayload.id===createPayload.id&&closePayload.until==='2026-12','Group can schedule an explicit inclusive ending month');
 mode='error';await page.locator('#new-billing-group').click();await page.locator('#group-name').fill('שגיאה צפויה');await page.locator('#group-members').selectOption(['1','2']);await page.locator('#billing-group-form button[type=submit]').click();await page.getByText('מוסד כבר משויך לקבוצה',{exact:true}).waitFor();
 check(await page.locator('#billing-group-form').isVisible()&&!await page.locator('#billing-group-form button[type=submit]').isDisabled(),'Rejected group save preserves form and permits correction');await page.keyboard.press('Escape');mode='normal';
 await page.locator('[data-view="bills"]').click();await page.getByRole('heading',{name:'חיובים ותשלומים',exact:true}).waitFor();await page.locator('[data-bill="100"]').click();await page.getByRole('heading',{name:'חיוב גבייה משותפת',exact:true}).waitFor();
 check(await page.getByRole('region',{name:'חלוקת החיוב בין המוסדות'}).isVisible()&&await page.locator('.detail-list').count()===1,'Shared bill details show each institution share and one bill total');await axe('Shared bill modal accessibility');
 await page.locator('[data-fiscal-issue=invoice]').click();await page.locator('#fiscal-issue-form button[type=submit]').click();await ready();check(issuePayload.bill===100&&issuePayload.shared_billing===true,'Invoice UI explicitly acknowledges a whole shared bill');
 await page.locator('#filter-institution').selectOption('2');await page.getByRole('button',{name:'הצגת נתונים',exact:true}).click();await ready();await page.locator('[data-payment="100"]').click();await page.locator('#payment-form').waitFor();
 check(await page.locator('#amount').inputValue()==='25.39'&&(await page.locator('#modal-content').textContent()).includes('התשלום נרשם עבור הקבוצה כולה'),'Payment from an institution-filtered report loads and labels the whole group balance');
 await page.locator('#amount').fill('10.01');await page.locator('#payment-form button[type=submit]').click();await page.locator('#payment-form').waitFor({state:'detached'});await ready();
 check(paymentPayload.bill===100&&paymentPayload.amount==='10.01'&&paymentPayload.request_key&&paymentPayload.shared_billing===true&&!('allocations' in paymentPayload),'Payment UI sends one group payment; allocation is authoritative on server');
 mode='draft';await page.locator('#filter-institution').selectOption('2');await page.getByRole('button',{name:'הצגת נתונים',exact:true}).click();await ready();
 await page.getByText('לאישור הקבוצה יש לבחור כל המוסדות במסנן.',{exact:true}).waitFor();
 check(await page.locator('.bill-check').count()===0&&(await page.locator('#screen').textContent()).includes('לאישור הקבוצה יש לבחור כל המוסדות'),'A partial institution view cannot approve a larger group amount');
 await page.locator('#filter-institution').selectOption('');await page.getByRole('button',{name:'הצגת נתונים',exact:true}).click();await ready();await page.locator('.bill-check').check();await page.locator('#approve').click();
 check((await page.locator('#modal-content').textContent()).includes('האישור כולל חיובים משותפים'),'Approval confirmation identifies combined charges');
 await page.locator('#approve-form button[type=submit]').click();await ready();check(approvePayload.ids[0]===100&&approvePayload.shared_billing===true,'Whole-group approval posts explicit group-aware acknowledgement');
 groups[0].name='<img src=x onerror="window.__groupXss=true">';await page.locator('[data-view="groups"]').click();await page.getByRole('heading',{name:'קבוצות גבייה',exact:true}).waitFor();
 check(await page.locator('#screen img').count()===0&&!await page.evaluate(()=>window.__groupXss),'Group names remain escaped text');
 check(!errors.length,'No browser runtime errors: '+errors.join('; '));console.log(`${count} shared billing frontend checks passed.`);
 }finally{await browser.close();}
})().catch(error=>{console.error(error.message);process.exit(1);});
