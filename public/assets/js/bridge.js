'use strict';
// PHP boot data is JSON-encoded with HEX escaping by public/index.php.
const PHP_BOOT = window.FITBOT_BOOT || {};
let account = {user:PHP_BOOT.user||null,csrf:PHP_BOOT.csrf||'',revision:PHP_BOOT.revision||0,updatedAt:PHP_BOOT.updatedAt||null,pending:false,saving:false,status:'saved',change:0,promise:null,timer:null,error:''};
function phpProfileKey(p){return JSON.stringify(['gender','age','height','weight','activity','goal','days','equipment','level','duration'].map(k=>p[k]));}
let phpCalculation=PHP_BOOT.calculation?{...PHP_BOOT.calculation,key:phpProfileKey(PHP_BOOT.calculation.profile)}:null;
function apiUrl(action){const url=new URL(PHP_BOOT.apiUrl||'api.php',location.href);url.searchParams.set('action',action);return url.pathname+url.search;}
function apiHeaders(extra={}){return {'Accept':'application/json','Content-Type':'application/json','X-CSRF-Token':account.csrf,'X-Fitbot-Account':account.user?String(account.user.id):'guest',...extra};}
async function phpRequest(action,{method='GET',data,timeout=15000,signal,keepalive=false}={}){
 const controller=signal?null:new AbortController();const timerId=controller?setTimeout(()=>controller.abort(),timeout):null;
 try{
  const res=await fetch(apiUrl(action),{method,credentials:'same-origin',headers:apiHeaders(),body:data===undefined?undefined:JSON.stringify(data),cache:'no-store',signal:signal||controller?.signal,keepalive});
  let body;try{body=await res.json();}catch{throw new Error('پاسخ PHP قابل خواندن نیست؛ تنظیمات سرور و مسیر public را بررسی کن.');}
  if(!res.ok){const error=new Error(body.error||'عملیات سرور انجام نشد.');error.code=body.code;error.status=res.status;error.details=body;throw error;}
  return body;
 }catch(error){if(error.name==='AbortError'){const e=new Error('مهلت اتصال تمام شد؛ وضعیت شبکه را بررسی کن و دوباره تلاش کن.');e.code='timeout';throw e;}throw error;}
 finally{if(timerId)clearTimeout(timerId);}
}
async function getPhpCalculation(profile){
 const result=await phpRequest('calculate',{method:'POST',data:{profile}});
 if(result.engine!=='php'||!result.estimates||!Array.isArray(result.plan))throw new Error('نتیجه محاسبه PHP معتبر نیست.');
 phpCalculation={...result,key:phpProfileKey(result.profile)};
 return result;
}
function queueServerSave(){
 if(!account.user)return;
 account.pending=true;account.change++;
 if(account.status!=='conflict'&&account.status!=='expired')account.status='pending';
 clearTimeout(account.timer);
 if(!['conflict','expired'].includes(account.status))account.timer=setTimeout(()=>flushServerSave(),450);
 updateAccountChrome();
}
async function flushServerSave(){
 clearTimeout(account.timer);
 if(!account.user||!account.pending)return true;
 if(['conflict','expired'].includes(account.status))return false;
 if(account.saving){await account.promise;return account.pending&&!['error','conflict','expired'].includes(account.status)?flushServerSave():!account.pending;}
 const owner=account.user.id,generation=account.change,revision=account.revision,snapshot=JSON.parse(JSON.stringify(state));
 account.saving=true;account.status='saving';updateAccountChrome();
 account.promise=(async()=>{
  try{
   const result=await phpRequest('state',{method:'PUT',data:{state:snapshot,revision},keepalive:JSON.stringify(snapshot).length<24000});
   if(account.user?.id!==owner)return false;
   account.revision=result.revision;account.updatedAt=result.updatedAt;account.pending=account.change!==generation;account.status=account.pending?'pending':'saved';account.error='';return true;
  }catch(error){
   if(account.user?.id!==owner)return false;
   account.status=error.code==='state_conflict'?'conflict':(error.status===401||error.status===419||error.code==='account_changed')?'expired':'error';account.error=error.message;
   toast(account.status==='conflict'?'نسخه دیگری در حساب ذخیره شده؛ از حساب کاربری، نسخه موردنظر را انتخاب کن.':`ذخیره روی سرور انجام نشد: ${error.message}`,'warning',7500);
   return false;
  }finally{if(account.user?.id===owner){account.saving=false;account.promise=null;updateAccountChrome();}}
 })();
 const saved=await account.promise;
 if(saved&&account.pending&&account.status==='pending')return flushServerSave();
 return saved;
}
function updateAccountChrome(){
 const label=document.querySelector('#accountButtonLabel'),status=document.querySelector('#storageLabel'),badge=document.querySelector('#serverSyncBadge');
 if(label)label.textContent=account.user?'حساب '+account.user.name:'ورود / ساخت حساب';
 const names={saved:'ذخیره‌شده در حساب',saving:'در حال ذخیره…',pending:'در انتظار ذخیره',error:'ذخیره نشده · تلاش دوباره',conflict:'انتخاب نسخه لازم است',expired:'نشست نیاز به بررسی دارد'};
 if(account.user){
  if(status)status.textContent=names[account.status]||names.pending;
  if(badge){badge.hidden=false;badge.dataset.status=account.status;badge.innerHTML=icon(account.status==='saved'?'shield':account.status==='saving'?'reset':'info')+`<span>${names[account.status]||names.pending}</span>`;}
 }else{
  if(status)status.textContent=typeof storageAvailable!=='undefined'&&!storageAvailable?'مهمان · حافظه موقت':'مهمان · ذخیره روی دستگاه';
  if(badge){badge.hidden=false;badge.dataset.status='guest';badge.innerHTML=icon('user')+'<span>ورود به حساب</span>';}
 }
 const avatar=document.querySelector('#profileAvatar');if(avatar&&account.user)avatar.textContent=account.user.name.slice(0,1);
}
