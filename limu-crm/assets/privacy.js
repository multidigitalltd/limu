/* Stores only notice acknowledgement, never identity or authentication. */
(() => {
'use strict';
const notice=document.getElementById('privacy-notice'),button=document.getElementById('privacy-ack');
if(!notice||!button)return;
let acknowledged=false;
try{acknowledged=localStorage.getItem('limu-crm-privacy')==='ack'||localStorage.getItem('limu-crm-privacy-seen')==='1';}catch{}
notice.hidden=acknowledged;
button.addEventListener('click',()=>{notice.hidden=true;try{localStorage.setItem('limu-crm-privacy','ack');}catch{}});
})();
