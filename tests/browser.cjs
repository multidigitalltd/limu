const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
(async()=>{
 const credentials=JSON.parse(fs.readFileSync(process.env.LIMU_TEST_LOGIN,'utf8'));
 const axe=fs.readFileSync(process.env.LIMU_AXE_PATH,'utf8');
 const browser=await chromium.launch({headless:true,executablePath:process.env.LIMU_CHROMIUM || '/usr/bin/chromium',args:['--no-sandbox']});
 const base=process.env.LIMU_TEST_URL||'http://127.0.0.1:8090';
 if(new URL(base).hostname!=='127.0.0.1')throw new Error('Browser test requires local disposable fixtures');
 const page=await browser.newPage({locale:'he-IL',viewport:{width:1440,height:1000}});
 const errors=[];page.on('pageerror',error=>errors.push(error.message));
 const checks=[];
 async function check(condition,label){if(!condition)throw new Error(label);checks.push(label);console.log('PASS',label);}
 async function accessibility(label){await page.addScriptTag({content:axe});const r=await page.evaluate(()=>axe.run(document.body,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}}));if(r.violations.length)throw new Error(label+': '+JSON.stringify(r.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>n.target)}))));checks.push('Accessibility '+label);console.log('PASS Accessibility',label);}
 try {
 await page.goto(base+'/crm/');await check(await page.getByRole('heading',{name:'ברוכים הבאים'}).isVisible(),'Dedicated CRM login loads');await accessibility('login');
 if(await page.getByRole('button',{name:'הבנתי',exact:true}).isVisible())await page.getByRole('button',{name:'הבנתי',exact:true}).click();
 await page.getByLabel('שם משתמש או כתובת אימייל').fill(credentials.user);await page.getByLabel('סיסמה',{exact:true}).fill(credentials.password);await page.getByRole('button',{name:'כניסה למערכת'}).click();
 await page.getByRole('heading',{name:'התמונה המלאה, במקום אחד'}).waitFor();await accessibility('dashboard');
 await page.locator('#filter-month').fill(new Date(new Date().getFullYear(),new Date().getMonth()-1,1).toLocaleDateString('sv-SE').slice(0,7));await page.getByRole('button',{name:'הצגת נתונים'}).click();await page.waitForFunction(()=>document.getElementById('screen').getAttribute('aria-busy')==='false');
 await page.screenshot({path:process.env.LIMU_SCREENSHOT_DIR+'/dashboard.png',fullPage:true});
 await page.getByRole('button',{name:'מרכז לידים',exact:false}).click();await page.getByRole('heading',{name:'מרכז לידים',exact:true}).waitFor();await page.locator('tbody tr').first().waitFor();
 await check((await page.locator('tbody tr').count())>=4,'Lead list shows captured and historical rows');await accessibility('leads');
 await page.locator('[data-detail]').first().click();await page.getByRole('dialog').waitFor();await accessibility('lead dialog');await page.keyboard.press('Escape');await check(!(await page.getByRole('dialog').isVisible()),'Dialog supports Escape');
 await page.getByRole('button',{name:'חיובים ותשלומים',exact:false}).click();await page.getByRole('heading',{name:'חיובים ותשלומים',exact:true}).waitFor();await accessibility('bills');
 await page.getByRole('button',{name:'מוסדות ותעריפים',exact:false}).click();await page.getByRole('heading',{name:'מוסדות ותעריפים',exact:true}).waitFor();await accessibility('institutions');
 await page.locator('[data-rate]').first().click();await page.getByRole('dialog').waitFor();await accessibility('tariff dialog');await page.keyboard.press('Escape');
 await page.getByRole('button',{name:'הגדרות והרשאות',exact:false}).click();await page.getByRole('heading',{name:'הגדרות והרשאות',exact:true}).waitFor();await accessibility('settings');
 await page.getByRole('button',{name:'הוספת טופס'}).click();await page.getByRole('dialog').waitFor();await accessibility('form mapping');await page.keyboard.press('Escape');
 await page.setViewportSize({width:390,height:844});await page.getByRole('button',{name:'סקירה כללית',exact:false}).click();await page.getByRole('heading',{name:'התמונה המלאה, במקום אחד'}).waitFor();await check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Mobile page has no horizontal overflow');await accessibility('mobile');await page.screenshot({path:process.env.LIMU_SCREENSHOT_DIR+'/mobile.png',fullPage:true});
 await page.getByText('נגישות ותצוגה',{exact:true}).click();await page.getByLabel('ניגודיות גבוהה',{exact:true}).check();await accessibility('high contrast settings');await page.getByRole('button',{name:'איפוס העדפות'}).click();await page.getByText('נגישות ותצוגה',{exact:true}).click();
 await page.setViewportSize({width:1440,height:1000});await page.getByRole('button',{name:'דוחות',exact:false}).click();await page.getByRole('heading',{name:'דוחות וסיכומים',exact:true}).waitFor();await page.evaluate(()=>{window.print=()=>{};});await page.getByRole('button',{name:'הדפסה / שמירה כ־PDF'}).click();await page.waitForSelector('#print-snapshot',{state:'attached'});await check(await page.locator('#print-snapshot tbody tr').count()===2,'Print report includes all matching bills');await page.emulateMedia({media:'print'});await page.pdf({path:process.env.LIMU_SCREENSHOT_DIR+'/demo-billing.pdf',preferCSSPageSize:true,printBackground:true});await check(fs.statSync(process.env.LIMU_SCREENSHOT_DIR+'/demo-billing.pdf').size>1000,'Hebrew PDF report renders');await page.emulateMedia({media:'screen'});await page.evaluate(()=>window.dispatchEvent(new Event('afterprint')));
 await check(errors.length===0,'No browser JavaScript errors');
 if(process.env.LIMU_MEMBER_LOGIN){
  const member=JSON.parse(fs.readFileSync(process.env.LIMU_MEMBER_LOGIN,'utf8'));const p=await browser.newPage();await p.goto(base+'/crm/');await p.getByLabel('שם משתמש או כתובת אימייל').fill(member.user);await p.getByLabel('סיסמה',{exact:true}).fill(member.password);await p.getByRole('button',{name:'כניסה למערכת'}).click();await p.getByRole('heading',{name:'התמונה המלאה, במקום אחד'}).waitFor();
  await check(await p.locator('[data-view="institutions"]').count()===0,'Institution login hides manager navigation');
  const protectedResult=await p.evaluate(async()=>{const r=await fetch(window.LimuCRM.api+'settings',{method:'POST',headers:{'X-WP-Nonce':window.LimuCRM.nonce,'Content-Type':'application/json'},body:JSON.stringify({duplicate_mode:'rolling',start_date:''})});return r.status;});await check(protectedResult===403,'Real cookie-authenticated institution cannot change settings');await p.close();
 }

 console.log(`${checks.length} browser and accessibility checks passed.`);
 }finally{await browser.close();}
})().catch(error=>{console.error(error.message);process.exit(1)});
