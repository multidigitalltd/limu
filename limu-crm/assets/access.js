/* Stores only display preferences and notice acknowledgement, never identity or authentication. */
(() => {
'use strict';
const body=document.body, panel=document.getElementById('access-tools');if(!panel)return;
const fields=[...panel.querySelectorAll('[data-access]')],size=document.getElementById('access-size');
const key='limu-crm-display-v1';let prefs={};
try{prefs=JSON.parse(localStorage.getItem(key)||'{}');if(!prefs||typeof prefs!=='object'||Array.isArray(prefs))prefs={};}catch{prefs={};}
function apply(){fields.forEach(field=>{const enabled=prefs[field.dataset.access]===true;field.checked=enabled;body.classList.toggle('access-'+field.dataset.access,enabled);});const scale=[100,110,120,130,140,150].includes(Number(prefs.size))?Number(prefs.size):100;size.value=scale;body.style.setProperty('--text-scale',String(scale/100));document.getElementById('reading-guide').hidden=prefs.guide!==true;}
function save(){try{localStorage.setItem(key,JSON.stringify(prefs));}catch{}apply();}
fields.forEach(field=>field.addEventListener('change',()=>{prefs[field.dataset.access]=field.checked;save();}));size.addEventListener('input',()=>{prefs.size=Number(size.value);save();});
document.getElementById('access-reset').addEventListener('click',()=>{prefs={};save();});
document.addEventListener('pointermove',event=>{if(prefs.guide===true){document.getElementById('reading-guide').style.top=event.clientY+'px';}},{passive:true});
const privacy=document.getElementById('privacy-notice');let acknowledged=false;try{acknowledged=localStorage.getItem('limu-crm-privacy-seen')==='1';}catch{}
privacy.hidden=acknowledged;document.getElementById('privacy-ack').addEventListener('click',()=>{privacy.hidden=true;try{localStorage.setItem('limu-crm-privacy-seen','1');}catch{}});apply();
})();
