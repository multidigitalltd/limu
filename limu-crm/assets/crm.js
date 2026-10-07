/* Vanilla JS portal. All dynamic text is escaped; authority resides in the REST server. */
(() => {
'use strict';
const config = window.LimuCRM;
const screen = document.getElementById('screen');
if (!config || !screen) return;
const modal = document.getElementById('modal');
let bootstrap, view = 'dashboard', page = 1, items = [], filter = {month: '', year: '', date_from: '', date_to: '', institution: '', state: '', search: ''}, lastFocus;
let generation = 0, modalSession = 0;
const titles = {dashboard:'סקירה כללית',deliveries:'מרכז לידים',bills:'חיובים ותשלומים',reports:'דוחות',institutions:'מוסדות ותעריפים',settings:'הגדרות והרשאות',audit:'יומן פעילות'};
const e = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money = n => new Intl.NumberFormat('he-IL',{style:'currency',currency:'ILS'}).format((n || 0)/100);
const name = id => bootstrap.institutions.find(i => i.id === Number(id))?.name || 'ללא שיוך';
const stateName = s => ({sent:'נשלח למוסד',pending:'קליטה לא הושלמה',historical:'היסטורי · לדוחות בלבד',unmapped:'דורש שיוך',draft:'ממתין לאישור',approved:'מאושר'}[s] || s);
const badge = (text, cls = '') => `<span class="badge ${e(cls)}">${e(text)}</span>`;
const params = () => new URLSearchParams({...filter,page:String(page)}).toString();
function appendReload(target) { const button=document.createElement('button');button.type='button';button.className='button';button.textContent='רענון והתחברות מחדש';button.addEventListener('click',()=>window.location.reload());target.append(' ',button); }
function notice(message, error = false, authExpired = false) { const n = document.getElementById('notification'); n.textContent = message; n.classList.toggle('error',error);if(authExpired)appendReload(n); }
const currentRate = agreement => agreement?.rates.filter(rate=>rate.from<=bootstrap.today).at(-1);
const nextRate = agreement => agreement?.rates.find(rate=>rate.from>bootstrap.today);
async function api(route, input) {
 const response = await fetch(config.api + route, {credentials:'same-origin', headers:{'X-WP-Nonce':config.nonce,...(input ? {'Content-Type':'application/json'} : {})}, ...(input ? {method:'POST',body:JSON.stringify(input)} : {})});
 const body = await response.json();
 if (!response.ok) { const authExpired=response.status===401 || body.code==='rest_cookie_invalid_nonce';const error=new Error(authExpired?'ההתחברות פגה או שהבקשה אינה עדכנית. רעננו את העמוד כדי להתחבר מחדש.':body.message || 'הפעולה נכשלה');error.authExpired=authExpired;throw error; } return body;
}
function heading(title, description, actions = '') { return `<div class="page-heading"><div><h1>${e(title)}</h1><p class="muted">${e(description)}</p></div><div class="actions">${actions}</div></div>`; }
const icons={leads:'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 3a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/>',bill:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5"/>',paid:'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h3"/>',balance:'<path d="M3 7h18M5 7v13h14V7M8 7V4h8v3M9 12h6M12 12v5"/>'};
const icon=key=>`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[key]||icons.leads}</svg>`;
function metric(label,value,hint,key,emphasis=false){return `<article class="metric ${emphasis?'metric-emphasis':''}" data-metric="${e(key)}"><div class="metric-head"><span class="label">${e(label)}</span><span class="metric-icon">${icon(key)}</span></div><strong class="value">${value}</strong><span class="hint">${e(hint)}</span></article>`;}
function navigate(next,selected={}){view=next;page=1;filter={month:'',year:'',date_from:'',date_to:'',institution:'',state:'',search:'',...selected};document.querySelectorAll('[data-view]').forEach(x=>{if(x.dataset.view===next)x.setAttribute('aria-current','page');else x.removeAttribute('aria-current');});const ready=load();document.getElementById('main').focus();return ready;}
function institutionOptions(selected = '', all = true) { return (all ? '<option value="">כל המוסדות</option>' : '') + bootstrap.institutions.map(i => `<option value="${e(i.id)}" ${String(i.id) === String(selected) ? 'selected' : ''}>${e(i.name)}</option>`).join(''); }
const periodLabel=()=>filter.date_from&&filter.date_to?`${filter.date_from} – ${filter.date_to}`:filter.year?`שנת ${filter.year}`:filter.month||'כל התקופות';
const scopeLabel=()=>`${periodLabel()}${filter.institution?' · '+name(filter.institution):''}`;
const metricLabel=label=>`${label} · ${scopeLabel()}`;
const billingPeriodNote=()=>filter.date_from?'<p class="muted period-note">פניות מסוננות לפי התאריכים המדויקים. סכומי החיובים כוללים את חודשי השירות המלאים שחופפים לטווח, והתשלומים הם מתוך חיובים אלה.</p>':'';
function filters(extra = '') {
 const period=filter.date_from?'range':filter.year?'year':filter.month?'month':'all';
 return `<form id="filters" class="filters"><div><label for="filter-period">תקופה</label><select id="filter-period" name="period"><option value="all" ${period==='all'?'selected':''}>כל התקופות</option><option value="year" ${period==='year'?'selected':''}>שנה שלמה</option><option value="month" ${period==='month'?'selected':''}>חודש מסוים</option><option value="range" ${period==='range'?'selected':''}>טווח תאריכים</option></select></div><div class="filter-period-field" id="month-field" ${period!=='month'?'hidden':''}><label for="filter-month">חודש</label><input id="filter-month" name="month" type="month" required value="${e(filter.month||bootstrap.month)}"></div><div class="filter-period-field" id="year-field" ${period!=='year'?'hidden':''}><label for="filter-year">שנה</label><input id="filter-year" name="year" type="number" required min="1900" max="9999" step="1" value="${e(filter.year||bootstrap.today.slice(0,4))}"></div><div class="filter-period-field" id="from-field" ${period!=='range'?'hidden':''}><label for="filter-from">מתאריך</label><input id="filter-from" name="date_from" type="date" required value="${e(filter.date_from||bootstrap.month+'-01')}"></div><div class="filter-period-field" id="to-field" ${period!=='range'?'hidden':''}><label for="filter-to">עד תאריך</label><input id="filter-to" name="date_to" type="date" required value="${e(filter.date_to||bootstrap.today)}"></div><div><label for="filter-institution">מוסד</label><select id="filter-institution" name="institution">${institutionOptions(filter.institution)}</select></div>${extra}<button class="button" type="submit">הצגת נתונים</button><button class="button" type="button" id="reset-filters">איפוס</button></form>`;
}
function bindFilters() {
 const period=document.getElementById('filter-period');
 const syncPeriod=()=>{for(const [kind,field,id] of [['month','month-field','filter-month'],['year','year-field','filter-year'],['range','from-field','filter-from'],['range','to-field','filter-to']]){document.getElementById(field).hidden=period.value!==kind;document.getElementById(id).disabled=period.value!==kind;}};
 const from=document.getElementById('filter-from'),to=document.getElementById('filter-to');
 const syncRange=()=>{to.min=from.value;to.setCustomValidity(to.value&&from.value&&to.value<from.value?'תאריך הסיום צריך להיות שווה לתאריך ההתחלה או מאוחר ממנו':'');};
 from?.addEventListener('input',syncRange);to?.addEventListener('input',syncRange);
 period?.addEventListener('change',syncPeriod);if(period){syncPeriod();syncRange();}
 document.getElementById('filters')?.addEventListener('submit', event => {event.preventDefault();const data=Object.fromEntries(new FormData(event.target));const {period,...selected}=data;filter={...filter,...selected,month:period==='month'?data.month:'',year:period==='year'?data.year:'',date_from:period==='range'?data.date_from:'',date_to:period==='range'?data.date_to:''};page=1;load();});
 document.getElementById('reset-filters')?.addEventListener('click',()=>{filter={month:'',year:'',date_from:'',date_to:'',institution:'',state:'',search:''};page=1;load();});
}
function pagination(total, pages) { return `<div class="pagination"><span class="muted">${e(total)} רשומות · עמוד ${page} מתוך ${Math.max(1,pages)}</span><button class="button" id="prev-page" ${page<=1?'disabled':''}>הקודם</button><button class="button" id="next-page" ${page>=pages?'disabled':''}>הבא</button></div>`; }
function bindPages() { document.getElementById('prev-page')?.addEventListener('click',()=>{page--;load();});document.getElementById('next-page')?.addEventListener('click',()=>{page++;load();}); }
function empty(text) { return `<div class="empty"><strong>${e(text)}</strong><p class="muted">נתונים יופיעו כאן לאחר קליטה או יבוא. אפשר לשנות את הסינון.</p></div>`; }
function openModal(content) { modalSession++;if(!modal.open)lastFocus=document.activeElement;document.getElementById('modal-content').innerHTML=content;modal.showModal(); }
modal.addEventListener('close',()=>lastFocus?.focus());document.getElementById('close-modal').addEventListener('click',()=>modal.close());
async function submit(form, action,success='הנתונים נשמרו') {
 const button=form.querySelector('button[type="submit"]'),session=modalSession,inModal=modal.contains(form);button.disabled=true;
 try {
  try {await action(Object.fromEntries(new FormData(form)));}
  catch(err) {const box=form.isConnected&&(!inModal||session===modalSession)?form.querySelector('.form-error'):null;if(box){box.textContent=err.message;if(err.authExpired)appendReload(box);}else notice(err.message,true,err.authExpired);return;}
  if(inModal&&session===modalSession&&modal.open)modal.close();notice(success);
  try {await refreshBootstrap();await load();}catch(err){notice(success+'. עדכון התצוגה נכשל: '+err.message,true,err.authExpired);}
 }finally {button.disabled=false;}
}

const formError = '<p class="form-error" role="alert"></p>';
async function refreshBootstrap() { bootstrap = await api('bootstrap'); }
const icountInfo=()=>bootstrap.icount||{configured:false,verified:false,company:null,bank_accounts:[],automatic:false};
const documentNames={deal:'דרישת תשלום',invoice:'חשבונית מס',invrec:'חשבונית מס / קבלה',receipt:'קבלה'};
const paymentNames={transfer:'העברה בנקאית',card:'כרטיס אשראי',check:'המחאה',cash:'מזומן',other:'אחר'};
const fiscalDocuments=record=>Array.isArray(record?.documents)?record.documents:[];
const fiscalWaiting=record=>fiscalDocuments(record).some(d=>d.state==='sending'||d.state==='unknown');
const fiscalIssued=(record,type)=>fiscalDocuments(record).some(d=>d.state==='issued'&&d.doctype===type);
function fiscalBadge(record) {
 if(fiscalWaiting(record))return badge('iCount · דורש בירור','orange');
 if(fiscalIssued(record,'invoice')||fiscalIssued(record,'invrec'))return badge('מסמך מס הופק','green');
 if(fiscalIssued(record,'deal'))return badge('דרישת תשלום הופקה','green');
 return '';
}
function documentLink(document) {
 let href='';
 try {const parsed=new URL(document.url);if(parsed.protocol==='https:'&&(!parsed.port||parsed.port==='443')&&!parsed.username&&!parsed.password&&/(^|\.)icount\.co\.il$/i.test(parsed.hostname))href=parsed.href;}catch(_){}
 const label=`${documentNames[document.doctype]||'מסמך'}${document.docnum?' · '+document.docnum:''}`;
 return href?`<a class="button" href="${e(href)}" target="_blank" rel="noopener noreferrer">${e(label)}</a>`:e(label);
}
function documentsMarkup(record) {
 return fiscalDocuments(record).map(d=>`<div class="callout">${d.state==='issued'?documentLink(d):badge(d.state==='sending'?'הפקה בטיפול':'תוצאת ההפקה דורשת בדיקה','orange')}${d.state==='issued'?`<p class="muted">הופק ב־${e(d.issued||'—')} · ${money(d.total)}</p>`:`<p>יש לבדוק את המסמך ב־iCount לפני כל ניסיון נוסף. המערכת חוסמת הפקה חוזרת.</p>${bootstrap.manager?`<button class="button" data-fiscal-reconcile="${e(d.target||record.id)}" data-doctype="${e(d.doctype)}">בירור מצב ב־iCount</button>`:''}`}</div>`).join('');
}
function fiscalNotice() {
 if(!bootstrap.manager)return '<p class="muted">מסמכים חשבונאיים שהופקו מוצגים בפירוט החיוב.</p>';
 const c=icountInfo();
 return `<div class="callout">${c.configured?(c.verified?`iCount מחובר${c.company?.name?' · '+e(c.company.name):''}. מסמכים מופקים מפירוט חיוב מאושר; תשלום נרשם תחילה במערכת.`:'מפתח iCount הוגדר. יש לאמת את החיבור במסך ההגדרות לפני הפקת מסמכים.'):'כדי להפיק מסמכים יש להגדיר מפתח iCount בשרת ולאמת את החיבור במסך ההגדרות.'}</div>`;
}
async function fiscalSubmit(form,route,payload) {
 const button=form.querySelector('button[type="submit"]'),session=modalSession;
 button.disabled=true;
 try {
  const result=await api(route,payload);
  if(session===modalSession&&modal.open)modal.close();
  const message=result.state==='issued'?`${documentNames[result.doctype]||'המסמך'} הופק${result.docnum?' · '+result.docnum:''}`:'מצב המסמך עודכן';
  notice(message);
  try {await refreshBootstrap();await load();}catch(err){notice(message+'. עדכון התצוגה נכשל: '+err.message,true,err.authExpired);}
 }catch(err){
  const box=form.isConnected&&session===modalSession?form.querySelector('.form-error'):null;
  if(box){box.textContent=err.message;if(err.authExpired)appendReload(box);}
  notice(err.message,true,err.authExpired);
  try {await refreshBootstrap();await load();}catch(_){}
  if(form.isConnected&&session===modalSession){const note=document.createElement('p');note.className='muted';note.textContent='יש לפתוח מחדש את פירוט החיוב כדי לראות את מצב המסמכים המעודכן.';form.append(note);}
 }
}
async function dashboard(token) {
 const d=await api('summary?'+params());if(token!==generation)return;
 const bars=Object.entries(d.by_institution).sort((a,b)=>b[1]-a[1]).slice(0,6),max=Math.max(1,...bars.map(x=>x[1]));
 const missing=bootstrap.manager?bootstrap.institutions.filter(i=>!currentRate(i.agreement)):[];
 const collected=d.approved>0?Math.max(0,Math.min(100,Math.round(d.paid/d.approved*100))):0;
 const received=d.received_leads??d.leads??0,institutionLeads=d.institution_leads??d.leads??0,historical=d.institution_historical??d.historical??0;
 screen.innerHTML=heading('סקירה כללית',scopeLabel(),`<button class="button" id="open-leads">צפייה בלידים</button><button class="button primary" id="open-bills">ניהול חיובים</button>`)+filters()+`${missing.length?`<section class="rate-alert"><div><strong>${e(missing.length)} מוסדות ללא תעריף פעיל</strong><p>יש להשלים תעריף כדי להכין עבורם חיובים.</p></div><button class="button" id="missing-rates">השלמת תעריפים</button></section>`:''}${billingPeriodNote()}<div class="metrics">${metric(metricLabel(bootstrap.manager?'לידים שהאתר קיבל':'פניות מקור'),e(received),bootstrap.manager?'כל פנייה נספרת פעם אחת':'כל פנייה למוסדות שלך נספרת פעם אחת','received')}${metric(metricLabel('פניות למוסדות'),e(institutionLeads),`לכל מוסד בנפרד · ${historical} היסטוריות ללא חיוב`,'leads')}${metric(metricLabel('חיובים מאושרים'),money(d.approved),'כולל מע״מ · בתקופה שנבחרה','bill')}${metric(metricLabel('תשלומים שנרשמו'),money(d.paid),'מתוך חיובי התקופה','paid')}${metric(metricLabel('יתרה לגבייה'),money(d.approved-d.paid),`${money(d.overdue)} לאחר מועד הפירעון`,'balance',true)}</div><div class="grid-two dashboard-grid"><section class="card"><div class="section-title"><div><h2>פניות לפי מוסד</h2><p class="section-note">המוסדות עם מספר הפניות הגבוה ביותר בתקופה</p></div><span class="period-chip">${e(periodLabel())}</span></div>${bars.length?bars.map(([id,count],index)=>`<div class="bar-row"><span class="bar-name"><span class="bar-rank" aria-hidden="true">${index+1}</span>${e(name(id))}</span><div class="bar-track" aria-hidden="true"><div class="bar-fill" style="width:${Math.round(count/max*100)}%"></div></div><strong>${e(count)}</strong></div>`).join(''):empty('אין פניות בתקופה שנבחרה')}<div class="kpi-footer"><span>כל פנייה מוצגת בנפרד לכל מוסד מקבל</span></div></section><section class="card"><div class="section-title"><div><h2>מעקב גבייה</h2><p class="section-note">תשלומים מתוך החיובים שאושרו בתקופה</p></div></div><div class="collection-summary"><strong>${collected}%</strong><span class="muted">מהסכום נגבה</span></div><div class="collection-track" role="progressbar" aria-label="שיעור גבייה" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${collected}"><span style="width:${collected}%"></span></div><div class="collection-amounts"><span>שולם <strong>${money(d.paid)}</strong></span><span>מתוך <strong>${money(d.approved)}</strong></span></div><h3 class="attention-heading">לתשומת לבך</h3><dl class="attention-list"><div class="attention-item"><dt>כפולים · ללא חיוב</dt><dd>${e(d.duplicates)}</dd></div><div class="attention-item"><dt>פניות ללא שיוך</dt><dd>${e(d.unmapped)}</dd></div><div class="attention-item"><dt>קליטה לא הושלמה</dt><dd>${e(d.pending)}</dd></div><div class="attention-item"><dt>חיובים שממתינים לאישור</dt><dd>${money(d.draft)}</dd></div></dl></section></div>`;
 bindFilters();
 document.getElementById('open-leads').addEventListener('click',()=>navigate('deliveries',{month:filter.month,year:filter.year,date_from:filter.date_from,date_to:filter.date_to,institution:filter.institution}));
 document.getElementById('open-bills').addEventListener('click',()=>navigate('bills',{month:filter.month,year:filter.year,date_from:filter.date_from,date_to:filter.date_to,institution:filter.institution}));
 document.getElementById('missing-rates')?.addEventListener('click',async()=>{await navigate('institutions');if(view!=='institutions')return;const status=document.getElementById('institution-status');if(status){status.value='missing';renderInstitutions();}});
}
function deliveryTable(rows) { return `<div class="table-wrap"><table><caption class="sr-only">פניות למוסדות</caption><thead><tr><th scope="col">פונה</th><th scope="col">מוסד</th><th scope="col">תאריך</th><th scope="col">טלפון</th><th scope="col">כפילות</th><th scope="col">מצב פנייה</th><th scope="col" class="no-print">פעולות</th></tr></thead><tbody>${rows.map(d=>`<tr><td class="contact-cell"><button class="row-link contact-name" data-detail="${e(d.id)}">${e(d.name||'ללא שם')}</button><span class="contact-meta" dir="ltr">${e(d.email)}</span></td><td>${e(name(d.institution))}</td><td>${e(d.date)}</td><td dir="ltr">${e(d.phone)}</td><td>${d.duplicate_of?badge('כפול — לא לחיוב','orange'):badge('ללא כפילות','green')}</td><td>${badge(stateName(d.state),d.state==='pending'?'orange':'')}</td><td class="no-print">${bootstrap.manager&&d.state==='unmapped'?`<button class="button" data-remap="${e(d.id)}">שיוך למוסד</button>`:'—'}</td></tr>`).join('')}</tbody></table></div>`; }
function bindDeliveryDetails() {
 screen.querySelectorAll('[data-detail]').forEach(b=>b.addEventListener('click',()=>{
 const d=items.find(x=>x.id===Number(b.dataset.detail));
 const treatment={new:'חדש',working:'בטיפול',closed:'נסגר',irrelevant:'לא רלוונטי'};
 openModal(`<h2 id="modal-title">כרטיס פנייה</h2><p class="muted">${e(name(d.institution))}</p><dl class="detail-list"><dt>שם</dt><dd>${e(d.name)}</dd><dt>טלפון</dt><dd dir="ltr">${e(d.phone)}</dd><dt>אימייל</dt><dd dir="ltr">${e(d.email)}</dd><dt>מועד</dt><dd>${e(d.date)}</dd><dt>טופס / תחום</dt><dd>${e(d.form)}</dd><dt>מצב פנייה</dt><dd>${e(stateName(d.state))}</dd><dt>מצב טיפול</dt><dd>${e(treatment[d.treatment]||'חדש')}</dd><dt>כפילות</dt><dd>${d.duplicate_of?'כפול — לא לחיוב':'ללא כפילות'}${bootstrap.manager&&d.duplicate_of?` · רשומה ${e(d.duplicate_of)}`:''}</dd></dl>${bootstrap.manager?`<form id="treatment-form"><div class="form-grid"><div class="wide"><label for="treatment">מצב טיפול</label><select id="treatment" name="treatment">${Object.entries(treatment).map(([key,label])=>`<option value="${key}" ${key===(d.treatment||'new')?'selected':''}>${e(label)}</option>`).join('')}</select></div><div class="wide"><label for="note">הערה פנימית חדשה (לא מוצגת למוסד)</label><textarea id="note" name="note" maxlength="2000"></textarea></div></div>${formError}<button class="button primary" type="submit">שמירת טיפול</button></form>${d.notes?.length?`<h3>הערות פנימיות</h3>${d.notes.map(n=>`<p class="muted">${e(n.at)}</p><p>${e(n.text)}</p>`).join('')}`:''}`:''}`);
 document.getElementById('treatment-form')?.addEventListener('submit',ev=>{ev.preventDefault();submit(ev.target,data=>api('treatment',{id:d.id,...data}));});
 }));
 screen.querySelectorAll('[data-remap]').forEach(b=>b.addEventListener('click',()=>{const id=Number(b.dataset.remap),lead=items.find(d=>d.id===id);openModal(`<h2 id="modal-title">שיוך פנייה למוסד</h2><p>${lead.origin_live?'שם המוסד לא זוהה אוטומטית. יש לתקן את השיוך לפי הפנייה המקורית; כללי הכפילות והחיוב יחולו בהתאם.':'יש לבחור את המוסדות שאליהם משויכת הפנייה ההיסטורית. השיוך מיועד לדוחות בלבד ולא יוצר חיוב.'}</p><form id="remap-form"><label for="remap-institutions">מוסדות (בחירה מרובה)</label><select id="remap-institutions" name="institutions" multiple size="6" required>${institutionOptions('',false)}</select>${formError}<button type="submit" class="button primary">אישור שיוך</button></form>`);document.getElementById('remap-form').addEventListener('submit',ev=>{ev.preventDefault();const institutions=[...ev.target.elements.institutions.selectedOptions].map(o=>Number(o.value));submit(ev.target,()=>api('remap',{id,institutions}));});}));
}
async function deliveries(token) {
 const result=await api('deliveries?'+params());if(token!==generation)return;items=result.items;
 screen.innerHTML=heading('מרכז לידים','פניות האתר והיסטוריית הלידים, עם פרטי קשר וחיווי כפילות',`<button class="button" id="export">ייצוא Excel</button><button class="button" id="print-leads">הדפסה / שמירה כ־PDF</button>`)+filters(`<div><label for="search">חיפוש לפי שם</label><input id="search" name="search" value="${e(filter.search)}"></div><div><label for="filter-state">מצב פנייה</label><select id="filter-state" name="state"><option value="">כל המצבים</option>${['sent','pending','historical','unmapped'].map(s=>`<option value="${s}" ${filter.state===s?'selected':''}>${e(stateName(s))}</option>`).join('')}</select></div>`)+(items.length?deliveryTable(items):empty('אין פניות להצגה'))+pagination(result.total,result.pages);
 bindFilters();bindPages();bindDeliveryDetails();document.getElementById('export').addEventListener('click',()=>download('deliveries'));document.getElementById('print-leads').addEventListener('click',()=>printReport('deliveries'));
}
function billTable(rows, selectable) { return `<div class="table-wrap"><table><caption class="sr-only">חיובים חודשיים</caption><thead><tr>${selectable?'<th scope="col" class="no-print"><input id="select-all" type="checkbox" aria-label="בחירת כל החיובים הממתינים בעמוד"></th>':''}<th scope="col">מוסד</th><th scope="col">תקופה</th><th scope="col">לידים לחיוב</th><th scope="col">סכום כולל מע״מ</th><th scope="col">שולם</th><th scope="col">יתרה</th><th scope="col">פירעון</th><th scope="col">מצב</th><th scope="col" class="no-print">פעולות</th></tr></thead><tbody>${rows.map(b=>`<tr>${selectable?`<td class="no-print">${b.state==='draft'?`<input class="bill-check" type="checkbox" value="${e(b.id)}" aria-label="בחירת חיוב ${e(name(b.institution))} ${e(b.month)}">`:''}</td>`:''}<td>${e(name(b.institution))}</td><td>${e(b.month)}</td><td>${b.lines?b.lines.length:e(b.count||'—')}</td><td class="amount">${money(b.total)}</td><td class="amount">${money(b.paid)}</td><td class="amount">${money(b.total-b.paid)}</td><td>${e(b.due||'טרם אושר')}</td><td>${badge(stateName(b.state),b.state==='approved'?'green':'orange')}${fiscalDocuments(b).length?'<br>'+fiscalBadge(b):''}</td><td class="no-print"><button class="row-link" data-bill="${e(b.id)}">פירוט</button>${bootstrap.manager&&b.state==='approved'&&b.paid<b.total?`<button class="button" data-payment="${e(b.id)}">רישום תשלום</button>`:''}</td></tr>`).join('')}</tbody></table></div>`; }
async function bills(token) {
 const result=await api('bills?'+params());if(token!==generation)return;items=result.items;
 screen.innerHTML=heading('חיובים ותשלומים','סיכום חודשי, אישור מנהל ורישום גבייה',bootstrap.manager?'<button class="button" id="prepare">הכנת חודש לחיוב</button><button class="button primary" id="approve">אישור נבחרים</button>':'')+fiscalNotice()+filters()+billingPeriodNote()+(items.length?billTable(items,bootstrap.manager):empty('אין חיובים להצגה'))+pagination(result.total,result.pages);
 bindFilters();bindPages();bindBillDetails();
 document.getElementById('select-all')?.addEventListener('change',event=>screen.querySelectorAll('.bill-check').forEach(c=>c.checked=event.target.checked));
 document.getElementById('prepare')?.addEventListener('click',prepareModal);
 document.getElementById('approve')?.addEventListener('click',()=>{const selected=[...screen.querySelectorAll('.bill-check:checked')].map(c=>Number(c.value));if(!selected.length){notice('יש לבחור חיובים לאישור',true);return;}const total=items.filter(i=>selected.includes(i.id)).reduce((sum,i)=>sum+i.total,0);openModal(`<h2 id="modal-title">אישור ${selected.length} חיובים</h2><p>סכום כולל: <strong>${money(total)}</strong></p><p class="muted">לאחר האישור הסכומים והלידים בחיוב יינעלו. הסכום מחושב שוב בשרת לפני אישור.</p><form id="approve-form">${formError}<button type="submit" class="button primary">אישור החיובים</button></form>`);document.getElementById('approve-form').addEventListener('submit',ev=>{ev.preventDefault();submit(ev.target,async()=>{let failures=[];for(let i=0;i<selected.length;i+=20){const r=await api('approve',{ids:selected.slice(i,i+20)});failures.push(...r.results.filter(x=>x.error));}if(failures.length)throw new Error(failures.map(x=>`${x.id}: ${x.error}`).join(' · '));});});});
}
function bindBillDetails() {
 screen.querySelectorAll('[data-bill]').forEach(button=>button.addEventListener('click',()=>billModal(items.find(i=>i.id===Number(button.dataset.bill)))));
 screen.querySelectorAll('[data-payment]').forEach(button=>button.addEventListener('click',()=>paymentModal(items.find(i=>i.id===Number(button.dataset.payment)))));
}
function billDetailsMarkup(b,payments) {
 const c=icountInfo(),client=bootstrap.institutions.find(i=>i.id===Number(b.institution))?.icount_client;
 const ready=bootstrap.manager&&c.configured&&c.verified&&client&&b.state==='approved'&&payments!==null;
 const invoiced=fiscalIssued(b,'invoice')||fiscalIssued(b,'invrec')||(payments||[]).some(p=>fiscalIssued(p,'invrec'));
 const uncertain=fiscalWaiting(b)||(payments||[]).some(fiscalWaiting);
 const documentActions=ready?`<div class="actions"><button class="button" data-fiscal-issue="deal" ${fiscalIssued(b,'deal')||uncertain?'disabled':''}>הפקת דרישת תשלום</button><button class="button" data-fiscal-issue="invoice" ${invoiced||uncertain?'disabled':''}>הפקת חשבונית מס</button></div>`:'';
 const paymentList=payments===null?'<p class="muted" role="status">טוען תשלומים ומסמכים…</p>':payments.length?payments.map(p=>{
  const hasReceipt=fiscalIssued(p,'receipt')||fiscalIssued(p,'invrec'),full=p.amount===b.total&&payments.length===1;
  const permitted=ready&&!hasReceipt&&!uncertain&&(fiscalIssued(b,'invoice')||full);
  const type=fiscalIssued(b,'invoice')?'receipt':'invrec';
  return `<section class="card"><h3>${money(p.amount)} · ${e(paymentNames[p.method]||p.method)}</h3><p class="muted">${e(p.date)}${p.reference?' · '+e(p.reference):''}</p>${documentsMarkup(p)}${bootstrap.manager&&!hasReceipt?`<button class="button" data-fiscal-payment="${e(p.id)}" ${permitted?'':'disabled'}>הפקת ${e(documentNames[type])}</button>${ready&&!full&&!fiscalIssued(b,'invoice')?'<p class="muted">לתשלום חלקי יש להפיק תחילה חשבונית מס על החיוב המלא, ואז קבלה על התשלום.</p>':''}`:''}</section>`;
 }).join(''):'<p class="muted">טרם נרשמו תשלומים.</p>';
 const billDocuments=bootstrap.manager?{...b,documents:fiscalDocuments(b).filter(d=>Number(d.target||b.id)===Number(b.id))}:b;
 return `<h2 id="modal-title">חיוב ${e(name(b.institution))}</h2><p>${e(b.month)} · ${e(stateName(b.state))}</p><dl class="detail-list"><dt>לפני מע״מ</dt><dd>${money(b.subtotal)}</dd><dt>מע״מ</dt><dd>${money(b.vat)}</dd><dt>סך הכל</dt><dd>${money(b.total)}</dd><dt>שולם</dt><dd>${money(b.paid)}</dd><dt>יתרה</dt><dd>${money(b.total-b.paid)}</dd><dt>${fiscalIssued(b,'deal')?'תאריך הפקת דרישה':'תאריך אישור'}</dt><dd>${e(b.issued||'טרם אושר')}</dd><dt>מועד פירעון</dt><dd>${e(b.due||'טרם אושר')}</dd><dt>כפולים</dt><dd>${e(b.duplicates)}</dd><dt>היסטוריים</dt><dd>${e(b.historical)} · לא לחיוב</dd></dl>${b.lines?`<h3>לידים בחיוב</h3><p class="muted">${b.lines.map(l=>`#${e(l.delivery)} · ${money(l.price)} לפני מע״מ`).join('<br>')}</p>`:''}<h3>מסמכי iCount</h3>${documentsMarkup(billDocuments)||'<p class="muted">טרם הופק מסמך לחיוב.</p>'}${documentActions}${bootstrap.manager&&b.state==='approved'&&!client?'<p class="muted">יש לשייך למוסד לקוח iCount במסך המוסדות לפני הפקת מסמך.</p>':''}${bootstrap.manager?`<h3>תשלומים שנרשמו</h3>${paymentList}`:''}`;
}
async function billModal(b) {
 openModal(billDetailsMarkup(b,bootstrap.manager&&bootstrap.icount?null:[]));
 if(!bootstrap.manager||!bootstrap.icount)return;
 const session=modalSession;
 try {
  const result=await api('icount/bill',{bill:b.id});
  if(session!==modalSession||!modal.open)return;
  if(!result.bill||!Array.isArray(result.payments))throw new Error('לא ניתן לטעון את פירוט התשלומים.');
  document.getElementById('modal-content').innerHTML=billDetailsMarkup(result.bill,result.payments);
  bindFiscalDocuments(result.bill,result.payments);
  const title=document.getElementById('modal-title');title.setAttribute('tabindex','-1');title.focus();
 }catch(err){if(session===modalSession&&modal.open){const message=document.createElement('p');message.className='form-error';message.setAttribute('role','alert');message.textContent=err.message;document.getElementById('modal-content').append(message);}}
}
function bindFiscalDocuments(b,payments) {
 document.querySelectorAll('[data-fiscal-issue]').forEach(button=>button.addEventListener('click',()=>issueModal(b,button.dataset.fiscalIssue)));
 document.querySelectorAll('[data-fiscal-payment]').forEach(button=>button.addEventListener('click',()=>fiscalPaymentModal(b,payments.find(p=>p.id===Number(button.dataset.fiscalPayment)))));
 document.querySelectorAll('[data-fiscal-reconcile]').forEach(button=>button.addEventListener('click',()=>reconcileModal(Number(button.dataset.fiscalReconcile),button.dataset.doctype)));
}
function issueModal(b,doctype) {
 openModal(`<h2 id="modal-title">הפקת ${e(documentNames[doctype])}</h2><p>${e(name(b.institution))} · ${e(b.month)}</p><p>סכום כולל מע״מ 18%: <strong>${money(b.total)}</strong></p><p class="muted">המסמך יופק ב־iCount עבור הלקוח המשויך למוסד. שליחה באימייל וב־SMS כבויה.</p><form id="fiscal-issue-form">${formError}<button class="button primary" type="submit">אישור והפקת ${e(documentNames[doctype])}</button></form>`);
 document.getElementById('fiscal-issue-form').addEventListener('submit',ev=>{ev.preventDefault();fiscalSubmit(ev.target,'icount/issue',{bill:b.id,doctype});});
}
function reconcileModal(target,doctype) {
 openModal(`<h2 id="modal-title">בירור מצב ${e(documentNames[doctype]||'מסמך')}</h2><p>המערכת תברר את תוצאת הבקשה המקורית ב־iCount לפי אותם פרטי חיוב.</p><form id="fiscal-reconcile-form">${formError}<button class="button primary" type="submit">בירור מצב ב־iCount</button></form>`);
 document.getElementById('fiscal-reconcile-form').addEventListener('submit',ev=>{ev.preventDefault();fiscalSubmit(ev.target,'icount/reconcile',{target,doctype});});
}
function fiscalPaymentModal(b,payment) {
 const c=icountInfo(),method=payment.method,type=fiscalIssued(b,'invoice')?'receipt':'invrec';
 const supported=['transfer','cash','check'].includes(method),accounts=c.bank_accounts||[];
 const fields=method==='transfer'?`<div class="wide"><label for="fiscal-account">חשבון הבנק שאליו התקבל התשלום</label><select id="fiscal-account" name="account" required><option value="">בחירת חשבון מ־iCount</option>${accounts.map(a=>`<option value="${e(a.id)}">${e(a.title)}</option>`).join('')}</select></div>`:method==='check'?`${[['bank','מספר בנק'],['branch','מספר סניף'],['account','מספר חשבון'],['number','מספר המחאה']].map(([field,label])=>`<div><label for="check-${field}">${label}</label><input id="check-${field}" name="${field}" inputmode="numeric" required maxlength="10" pattern="[1-9][0-9]{0,9}" title="מספר של עד 10 ספרות, ללא אפס מוביל"></div>`).join('')}<div><label for="check-date">תאריך פירעון ההמחאה</label><input id="check-date" name="check_date" type="date" value="${e(payment.date)}" required></div>`:'';
 openModal(`<h2 id="modal-title">הפקת ${e(documentNames[type])}</h2><p>${e(name(b.institution))} · תשלום ${money(payment.amount)}</p><p class="muted">${e(payment.date)} · ${e(paymentNames[method]||method)}</p>${supported?`<form id="fiscal-payment-form"><div class="form-grid">${fields}</div>${method==='transfer'&&!accounts.length?'<p class="callout">לא נמצאו חשבונות בנק ב־iCount. יש להגדיר חשבון במערכת ולבדוק שוב את החיבור.</p>':''}<p class="muted">המסמך יופק ב־iCount על התשלום שכבר נרשם. שליחה באימייל וב־SMS כבויה.</p>${formError}<button class="button primary" type="submit" ${method==='transfer'&&!accounts.length?'disabled':''}>אישור והפקת ${e(documentNames[type])}</button></form>`:'<p class="callout">הפקת מסמך לתשלום באמצעי זה אינה זמינה עדיין. התשלום נשאר רשום במערכת.</p>'}`);
 document.getElementById('fiscal-payment-form')?.addEventListener('submit',ev=>{ev.preventDefault();const data=Object.fromEntries(new FormData(ev.target));fiscalSubmit(ev.target,'icount/payment',{payment:payment.id,...data});});
}
function paymentModal(bill) {
 const key=crypto.randomUUID();
 openModal(`<h2 id="modal-title">רישום תשלום</h2><p>${e(name(bill.institution))} · יתרה ${money(bill.total-bill.paid)}</p><form id="payment-form"><div class="form-grid"><div><label for="amount">סכום שהתקבל (₪)</label><input id="amount" name="amount" type="number" min="0.01" step="0.01" max="${(bill.total-bill.paid)/100}" value="${(bill.total-bill.paid)/100}" required></div><div><label for="payment-date">תאריך תשלום</label><input id="payment-date" name="date" type="date" max="${e(bootstrap.today)}" value="${e(bootstrap.today)}" required></div><div><label for="method">אמצעי תשלום</label><select id="method" name="method"><option value="transfer">העברה בנקאית</option><option value="card">כרטיס אשראי</option><option value="check">המחאה</option><option value="cash">מזומן</option><option value="other">אחר</option></select></div><div><label for="reference">אסמכתה (ללא פרטי כרטיס)</label><input id="reference" name="reference" maxlength="200"></div></div><p class="callout">התשלום יירשם במערכת. לאחר הרישום אפשר להפיק חשבונית מס / קבלה או קבלה דרך פירוט החיוב.</p>${formError}<button class="button primary" type="submit">אישור רישום תשלום</button></form>`);
 document.getElementById('payment-form').addEventListener('submit',ev=>{ev.preventDefault();submit(ev.target,data=>api('payment',{bill:bill.id,request_key:key,...data}));});
}
function prepareModal() {
 const lastMonth=new Date(bootstrap.today+'T12:00:00');lastMonth.setDate(1);lastMonth.setMonth(lastMonth.getMonth()-1);const month=`${lastMonth.getFullYear()}-${String(lastMonth.getMonth()+1).padStart(2,'0')}`;
 openModal(`<h2 id="modal-title">הכנת חיוב חודשי</h2><p>מכין טיוטות לבדיקה, ללא הפקת מסמכים.</p><form id="prepare-form"><div class="form-grid"><div><label for="bill-month">חודש שירות</label><input id="bill-month" name="month" type="month" value="${month}" required></div><div><label for="bill-inst">מוסד</label><select id="bill-inst" name="institution">${institutionOptions()}</select></div></div>${formError}<button class="button primary" type="submit">הכנת סיכומים</button></form>`);
 document.getElementById('prepare-form').addEventListener('submit',ev=>{ev.preventDefault();submit(ev.target,async data=>{const ids=data.institution?[Number(data.institution)]:bootstrap.institutions.filter(i=>i.agreement).map(i=>i.id);if(!ids.length)throw new Error('יש להגדיר תעריף למוסד לפני הכנת חיוב');let failures=[];for(let i=0;i<ids.length;i+=20){const r=await api('prepare',{month:data.month,institutions:ids.slice(i,i+20)});failures.push(...r.results.filter(x=>x.error));}if(failures.length)throw new Error(failures.map(x=>`${name(x.id)}: ${x.error}`).join(' · '));});});
}
function renderInstitutions() {
 const search=document.getElementById('institution-search').value.trim().toLocaleLowerCase('he-IL'),status=document.getElementById('institution-status').value;
 const matches=bootstrap.institutions.filter(i=>(!search||i.name.toLocaleLowerCase('he-IL').includes(search))&&(status==='all'||(status==='missing'?!currentRate(i.agreement):!!currentRate(i.agreement))));
 document.getElementById('institution-count').textContent=`${matches.length} מוסדות`;
 const grid=document.getElementById('institution-list');
 grid.innerHTML=matches.length?matches.map(i=>{const rate=currentRate(i.agreement),future=nextRate(i.agreement);return `<article class="card"><div class="institution-head"><span class="institution-mark" aria-hidden="true">${e(i.name.slice(0,1))}</span>${badge(rate?'תעריף פעיל':future?'תעריף עתידי בלבד':'חסר תעריף',rate?'green':'orange')}</div><h2>${e(i.name)}</h2><div class="rate-value">${rate?money(rate.price):'—'}</div><p class="rate-caption">${rate?'לליד, לפני מע״מ 18%':'אין תעריף בתוקף היום'}</p><p class="muted">${rate?`בתוקף מ־${e(rate.from)} · ${e(i.agreement.credit_days)} ימי אשראי`:'יש להגדיר תעריף לפני חיוב'}</p>${future?`<p class="muted">תעריף עתידי מ־${e(future.from)}: ${money(future.price)} לפני מע״מ</p>`:''}<button class="button" data-rate="${e(i.id)}">ניהול תעריף</button>${bootstrap.manager?`<p class="muted">iCount: ${i.icount_client?`${e(i.icount_client.name)} · לקוח ${e(i.icount_client.id)}`:'טרם שויך לקוח'}</p><button class="button" data-icount-client="${e(i.id)}" ${icountInfo().configured&&icountInfo().verified?'':'disabled'}>שיוך לקוח iCount</button>`:''}</article>`;}).join(''):empty('אין מוסדות התואמים לסינון');
 grid.querySelectorAll('[data-rate]').forEach(button=>button.addEventListener('click',()=>rateModal(bootstrap.institutions.find(i=>i.id===Number(button.dataset.rate)))));
 grid.querySelectorAll('[data-icount-client]').forEach(button=>button.addEventListener('click',()=>icountClientModal(bootstrap.institutions.find(i=>i.id===Number(button.dataset.icountClient)))));
}
async function institutions(token) {
 if(token!==generation)return;
 screen.innerHTML=heading('מוסדות ותעריפים','תעריף לליד ותנאי תשלום לכל מוסד · מע״מ אחיד 18%')+`<div class="filters"><div><label for="institution-search">חיפוש מוסד</label><input id="institution-search" type="search" placeholder="שם המוסד"></div><div><label for="institution-status">מצב תעריף</label><select id="institution-status"><option value="all">כל המוסדות</option><option value="missing">ללא תעריף פעיל</option><option value="active">עם תעריף פעיל</option></select></div><span class="muted" id="institution-count" role="status"></span></div><div class="institution-grid" id="institution-list"></div>`;
 document.getElementById('institution-search').addEventListener('input',renderInstitutions);
 document.getElementById('institution-status').addEventListener('change',renderInstitutions);
 renderInstitutions();
}
function rateModal(inst) {
 const rate=currentRate(inst.agreement);
 openModal(`<h2 id="modal-title">תעריף · ${e(inst.name)}</h2><p class="muted">${rate?`התעריף הפעיל היום: ${money(rate.price)} לפני מע״מ`:'אין תעריף בתוקף היום'}</p><form id="rate-form"><div class="form-grid"><div><label for="rate-price">מחיר לליד לפני מע״מ (₪)</label><input id="rate-price" name="price" type="number" min="0" step="0.01" value="${rate?rate.price/100:''}" required></div><div><label for="rate-from">תחולת תעריף</label><input id="rate-from" name="from" type="date" value="${e(bootstrap.today)}" required></div><div class="wide"><label for="credit">ימי אשראי מתאריך הדרישה</label><input id="credit" name="credit_days" type="number" min="0" max="365" step="1" value="${inst.agreement?.credit_days??30}" required></div></div><p class="muted">מע״מ בשיעור 18% מתווסף אוטומטית לכל חיוב.</p>${formError}<button class="button primary" type="submit">שמירת תעריף</button></form>${inst.agreement?`<h3>היסטוריית תעריפים</h3><p class="muted">${inst.agreement.rates.map(r=>`${e(r.from)}: ${money(r.price)} לפני מע״מ · ${r.from>bootstrap.today?'עתידי':r.from===rate?.from?'פעיל היום':'היסטורי'}`).join('<br>')}</p>`:''}`);
 document.getElementById('rate-form').addEventListener('submit',ev=>{ev.preventDefault();submit(ev.target,data=>api('agreement',{institution:inst.id,...data}));});
}
function icountClientModal(inst,initial=inst.icount_client?.id||'') {
 const client=inst.icount_client;
 openModal(`<h2 id="modal-title">שיוך לקוח iCount · ${e(inst.name)}</h2>${client?`<p>לקוח משויך: <strong>${e(client.name)}</strong> · ${e(client.id)}</p>`:''}<p class="muted">יש לבחור לקוח קיים ב־iCount. מספר הלקוח שונה מקוד המוסד באתר; השם ופרטי העסק יוצגו לאישור לפני השיוך.</p><form id="icount-client-form"><div class="form-grid"><div class="wide"><label for="icount-client-id">מספר לקוח iCount</label><input id="icount-client-id" name="client_id" type="number" min="1" max="9999999999" step="1" value="${e(initial)}" required></div></div>${formError}<button class="button primary" type="submit">בדיקת פרטי לקוח</button></form>`);
 document.getElementById('icount-client-form').addEventListener('submit',async ev=>{
  ev.preventDefault();const form=ev.target,button=form.querySelector('button[type="submit"]'),session=modalSession,id=Number(form.elements.client_id.value);button.disabled=true;
  try {
   const result=await api('icount/client-preview',{client_id:id});
   if(session!==modalSession||!modal.open)return;
   if(!result||Number(result.id)!==id||!result.name)throw new Error('לא ניתן לאמת את פרטי הלקוח.');
   icountClientConfirm(inst,result);
  }catch(err){if(form.isConnected&&session===modalSession){const box=form.querySelector('.form-error');box.textContent=err.message;if(err.authExpired)appendReload(box);}}
  finally {button.disabled=false;}
 });
}
function icountClientConfirm(inst,client) {
 openModal(`<h2 id="modal-title">אישור שיוך לקוח</h2><p>המסמכים של <strong>${e(inst.name)}</strong> יופקו עבור הלקוח הבא:</p><dl class="detail-list"><dt>שם לקוח iCount</dt><dd>${e(client.name)}</dd><dt>מספר לקוח</dt><dd>${e(client.id)}</dd><dt>מספר עסק / ח״פ</dt><dd>${e(client.vat_id||'לא הוגדר ב־iCount')}</dd></dl><p class="muted">יש לוודא שזה הלקוח שמקבל את החיובים עבור המוסד.</p><form id="icount-client-confirm-form">${formError}<div class="actions"><button class="button primary" type="submit">אישור ושיוך הלקוח למוסד</button><button class="button" id="icount-client-back" type="button">בחירת לקוח אחר</button></div></form>`);
 document.getElementById('icount-client-confirm-form').addEventListener('submit',ev=>{ev.preventDefault();submit(ev.target,()=>api('icount/client',{institution:inst.id,client_id:Number(client.id)}),'לקוח iCount אומת ושויך למוסד');});
 document.getElementById('icount-client-back').addEventListener('click',()=>icountClientModal(inst,client.id));
}
function icountSettingsMarkup() {
 const c=icountInfo();
 return `<section class="card settings-card"><h2>iCount</h2>${badge(c.verified?'החיבור אומת':c.configured?'מפתח הוגדר · ממתין לאימות':'מפתח טרם הוגדר',c.verified?'green':'orange')}${c.company?`<p><strong>${e(c.company.name)}</strong>${c.company.vat_id?` · ${e(c.company.vat_id)}`:''}</p>`:''}<form id="icount-check-form">${formError}<button class="button" type="submit" ${c.configured?'':'disabled'}>בדיקת חיבור iCount</button></form><form id="icount-automatic-form"><label class="check-label"><input type="checkbox" name="enabled" ${c.automatic?'checked':''} ${c.verified?'':'disabled'}><span>הפקת דרישות תשלום אוטומטית לאחר אישור חיוב</span></label><p class="muted">חל על חיובים שיאושרו מכאן ואילך, למוסדות ששויכו ללקוח iCount. חשבוניות וקבלות מופקות בפעולה מפורשת.</p>${formError}<button class="button" type="submit" ${c.verified?'':'disabled'}>שמירת הפקה אוטומטית</button></form><p class="muted">שליחת מסמכים באימייל וב־SMS כבויה.</p></section>`;
}
async function settingsView(token) {
 if(token!==generation)return;
 const s=bootstrap.settings;
 screen.innerHTML=heading('הגדרות והרשאות','כללי חיוב, מניעת כפילות וגישה למוסדות')+`<div class="lead-source-status"><span class="status-dot" aria-hidden="true"></span><div><strong>קליטת לידים אוטומטית</strong><p>פניות חדשות באתר מסתנכרנות למערכת.</p></div></div><div class="settings-layout"><section class="card settings-card"><h2>כללי חיוב</h2><p class="section-note">חיוב חודשי עבור החודש הקודם · מע״מ אחיד 18%</p><form id="settings-form"><div class="form-grid"><div class="wide"><label for="duplicate-mode">תקופת מניעת כפילות</label><select id="duplicate-mode" name="duplicate_mode">${[['calendar','שנה קלנדרית — ינואר עד דצמבר'],['days_30','30 ימים מהפנייה המקורית'],['days_90','90 ימים מהפנייה המקורית'],['days_180','180 ימים מהפנייה המקורית'],['rolling','12 חודשים מהפנייה המקורית'],['months_24','24 חודשים מהפנייה המקורית'],['custom','מספר ימים לבחירה']].map(([value,label])=>`<option value="${value}" ${s.duplicate_mode===value?'selected':''}>${label}</option>`).join('')}</select></div><div class="wide" id="duplicate-days-field" hidden><label for="duplicate-days">מספר ימים להשוואה</label><input id="duplicate-days" name="duplicate_days" type="number" min="1" max="3650" step="1" value="${e(s.duplicate_days??30)}" required></div><div class="wide"><label for="start-date">תאריך תחילת חיובים</label><input id="start-date" name="start_date" type="date" value="${e(s.start_date)}"><p class="muted">פניות היסטוריות נשארות לדוחות בלבד.</p></div><div class="wide"><label class="check-label"><input type="checkbox" name="automatic" ${s.automatic?'checked':''}><span>אישור חיובים אוטומטי</span></label><p class="muted">כשהאפשרות כבויה, מנהל מאשר כל חיוב או קבוצת חיובים.</p></div></div><div class="callout">טלפון או אימייל זהים אצל אותו מוסד מספיקים לסימון כפילות. ליד כפול נשמר במערכת ואינו מחויב.</div><p class="muted">שינוי תקופת הכפילות חל על פניות עתידיות. חיובים מאושרים נשמרים.</p>${formError}<button class="button primary" type="submit">שמירת הגדרות</button></form></section><div>${icountSettingsMarkup()}<section class="card settings-card"><h2>גישה למוסדות</h2><p class="section-note">חשבון קיים באתר יכול לצפות רק בנתוני המוסדות ששויכו אליו.</p><form id="member-form"><div class="form-grid"><div class="wide"><label for="member-email">אימייל של חשבון קיים</label><input id="member-email" name="email" type="email" required></div><div class="wide"><label for="member-institutions">מוסדות מורשים (בחירה מרובה)</label><select id="member-institutions" name="institutions" multiple size="4">${institutionOptions('',false)}</select><p class="muted">בחירה ריקה מסירה את הגישה למערכת.</p></div></div>${formError}<button class="button" type="submit">עדכון גישה</button></form></section></div></div>`;
 const duplicateMode=document.getElementById('duplicate-mode'),duplicateDays=document.getElementById('duplicate-days');
 const syncDuplicateDays=()=>{const custom=duplicateMode.value==='custom';document.getElementById('duplicate-days-field').hidden=!custom;duplicateDays.disabled=!custom;};
 duplicateMode.addEventListener('change',syncDuplicateDays);syncDuplicateDays();
 document.getElementById('settings-form').addEventListener('submit',ev=>{ev.preventDefault();submit(ev.target,data=>api('settings',{duplicate_mode:data.duplicate_mode,...(data.duplicate_mode==='custom'?{duplicate_days:Number(data.duplicate_days)}:{}),start_date:data.start_date,automatic:data.automatic==='on'}));});
 document.getElementById('member-form').addEventListener('submit',ev=>{ev.preventDefault();const ids=[...ev.target.elements.institutions.selectedOptions].map(o=>Number(o.value));submit(ev.target,data=>api('member',{email:data.email,institutions:ids}));});
 document.getElementById('icount-check-form').addEventListener('submit',ev=>{ev.preventDefault();submit(ev.target,()=>api('icount/check',{}),'החיבור ל־iCount אומת');});
 document.getElementById('icount-automatic-form').addEventListener('submit',ev=>{ev.preventDefault();submit(ev.target,data=>api('icount/automatic',{enabled:data.enabled==='on'}),'הגדרת הפקת הדרישות האוטומטית נשמרה');});

}
async function download(target) {
 try {notice('מכין קובץ Excel…');const result=await api('export?'+new URLSearchParams({...filter,target}));const bytes=Uint8Array.from(atob(result.file),c=>c.charCodeAt(0));const url=URL.createObjectURL(new Blob([bytes],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));const a=document.createElement('a');a.href=url;a.download=result.name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);notice(`יוצאו ${result.count} רשומות`);}catch(err){notice(err.message,true,err.authExpired);}
}
async function printReport(target = 'bills') {
 try {
 const r=await api('report?'+new URLSearchParams({...filter,target}));
 document.getElementById('print-snapshot')?.remove();
 const section=document.createElement('section');section.id='print-snapshot';
 section.innerHTML=`<h1>Limu CRM · דוח ${target==='deliveries'?'לידים':'חיובים'}</h1><p>${e(periodLabel())} · ${filter.institution?e(name(filter.institution)):'כל המוסדות המורשים'} · ${e(r.total)} רשומות</p><p>דוח פנימי — אינו מסמך חשבונאי</p>`+(target==='deliveries'?deliveryTable(r.items):billingPeriodNote()+billTable(r.items,false));
 document.body.append(section);document.body.classList.add('printing-report');window.print();
 }catch(err){notice(err.message,true,err.authExpired);}
}
window.addEventListener('afterprint',()=>{document.body.classList.remove('printing-report');document.getElementById('print-snapshot')?.remove();});
async function reports(token) {
 const result=await api('bills?'+params());if(token!==generation)return;items=result.items;
 screen.innerHTML=heading('דוחות וסיכומים','דוח חיובים לתקופה, ייצוא Excel ותצוגה להדפסה','<button class="button" id="export-bills">ייצוא Excel</button><button class="button" id="print-report">הדפסה / שמירה כ־PDF</button>')+filters()+billingPeriodNote()+'<p class="muted print-only">Limu CRM · דוח חיובים פנימי · אינו מסמך חשבונאי</p>'+(items.length?billTable(items,false):empty('אין חיובים לתקופה'))+pagination(result.total,result.pages)+'<p class="muted">Excel כולל את כל הרשומות בסינון (עד 5,000). הדפסה ושמירה כ־PDF כוללות את כל הרשומות בסינון (עד 5,000).</p>';
 bindFilters();bindPages();bindBillDetails();document.getElementById('export-bills').addEventListener('click',()=>download('bills'));document.getElementById('print-report').addEventListener('click',()=>printReport('bills'));
}
const events={historical_remapped:'פנייה היסטורית שויכה',automatic_error:'חריג בחיוב אוטומטי',treatment_updated:'טיפול בליד עודכן',bill_approved:'חיוב אושר',payment_recorded:'תשלום נרשם',agreement_changed:'תעריף עודכן',settings_changed:'הגדרות עודכנו',member_access_changed:'הרשאות מוסד עודכנו',history_batch:'יבוא היסטוריה',native_capture_error:'כשל בקליטת ליד',native_capture_exception:'ליד דורש שיוך',dispatch_error:'כשל בסנכרון פנייה',export:'דוח יוצא',icount_client_linked:'לקוח iCount שויך',icount_automatic_changed:'הפקת דרישות אוטומטית עודכנה',icount_automatic_error:'דרישת תשלום אוטומטית דורשת בדיקה',icount_sending:'מסמך נשלח להפקה ב־iCount',icount_issued:'מסמך iCount הופק',icount_unknown:'תוצאת הפקת iCount דורשת בדיקה'};
async function auditView(token) {
 const r=await api('audit?page='+page);if(token!==generation)return;
 screen.innerHTML=heading('יומן פעילות','מי שינה תעריף, אישר חיוב או רשם תשלום — ומתי')+(r.items.length?`<div class="table-wrap"><table><caption class="sr-only">יומן ביקורת</caption><thead><tr><th scope="col">מועד</th><th scope="col">פעולה</th><th scope="col">משתמש</th><th scope="col">רשומה</th></tr></thead><tbody>${r.items.map(x=>`<tr><td>${e(x.at)}</td><td>${e(events[x.event]||x.event)}</td><td>${e(x.actor||'תהליך מתוזמן')}</td><td>${e(x.target||'—')}</td></tr>`).join('')}</tbody></table></div>`:empty('אין פעולות להצגה'))+pagination(r.total,r.pages);bindPages();
}
async function load() {
 const token=++generation;screen.setAttribute('aria-busy','true');document.getElementById('breadcrumb').textContent=titles[view];
 try {await ({dashboard,deliveries,bills,reports,institutions,settings:settingsView,audit:auditView}[view])(token);}catch(err){if(token===generation){screen.innerHTML='<section class="card"><h1>לא ניתן לטעון את הנתונים</h1><p id="load-error"></p><button class="button" id="retry">ניסיון חוזר</button></section>';document.getElementById('load-error').textContent=err.message;const retry=document.getElementById('retry');retry.textContent=err.authExpired?'רענון והתחברות מחדש':'ניסיון חוזר';retry.addEventListener('click',err.authExpired?()=>window.location.reload():load);notice(err.message,true,err.authExpired);}}finally{if(token===generation)screen.setAttribute('aria-busy','false');}
}
document.getElementById('navigation').addEventListener('click',ev=>{const b=ev.target.closest('[data-view]');if(!b)return;navigate(b.dataset.view);});
refreshBootstrap().then(load).catch(err=>{screen.setAttribute('aria-busy','false');screen.textContent=err.message;notice(err.message,true,err.authExpired);});
})();
