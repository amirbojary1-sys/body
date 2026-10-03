'use strict';
let authMode='login',authWorking=false;
function guestHasData(){return Boolean(state.profile||state.weights.length||state.sessions.length||Object.keys(state.water).length||Object.values(state.chat).some(m=>m.length));}
function openAccount(mode='login'){
 if(account.user){showAccount();return;}
 authMode=mode;
 const register=mode==='register';
 openModal(register?'مسیرت را همراه خودت نگه دار.':'خوش برگشتی.','حساب شخصی با ذخیره‌سازی روی سرور همین برنامه.',`<div class="segmented auth-tabs"><button data-action="auth-mode" data-mode="login" class="${!register?'active':''}">ورود</button><button data-action="auth-mode" data-mode="register" class="${register?'active':''}">ساخت حساب</button></div><form id="authForm"><div class="form-grid">${register?'<div class="field full"><label for="authName">نام نمایشی</label><input id="authName" name="name" autocomplete="name" minlength="2" maxlength="50" required placeholder="نام تو"></div>':''}<div class="field full"><label for="authEmail">ایمیل</label><input id="authEmail" name="email" type="email" autocomplete="email" maxlength="254" required dir="ltr" placeholder="you@example.com"></div><div class="field full"><label for="authPassword">رمز عبور</label><input id="authPassword" name="password" type="password" autocomplete="${register?'new-password':'current-password'}" minlength="${register?'10':'1'}" maxlength="72" required dir="ltr" placeholder="${register?'حداقل ۱۰ نویسه':'رمز حساب تو'}">${register?'<small>حداکثر ۷۲ بایت؛ برای حروف فارسی طول رمز کوتاه‌تر است. ایمیل در این نسخه تأیید نمی‌شود؛ نشانی را درست وارد کن.</small>':''}</div></div>${register?`<label class="checkbox-label" style="margin-top:18px"><input name="consent" type="checkbox" required><span>می‌دانم اطلاعات حساب و داده‌های ورزشی در دیتابیس همین سرور ذخیره می‌شوند. اتصال به سرویس مدل زبانی، رضایت جداگانه دارد.</span></label>${guestHasData()?'<label class="checkbox-label" style="margin-top:12px"><input name="carry" type="checkbox"><span>اطلاعات فعلی مهمان را هم به حساب جدیدم منتقل کن. بدون این انتخاب، حساب با داده‌های خالی شروع می‌شود.</span></label>':''}`:'<div class="notice neutral section-gap">'+icon('shield')+'اطلاعات حساب قبلی از سرور بارگیری می‌شود؛ اطلاعات مهمان روی این دستگاه جدا می‌ماند.</div>'}<div id="authError" class="field-error" role="alert"></div></form><p class="micro" style="line-height:2;margin-top:18px">اگر کوکی در پیش‌نمایش مرورگر محدود شده، <a class="text-accent" href="./" target="_blank" rel="noopener">برنامه را در تب مستقل باز کن</a>. حساب، جایگزین پشتیبان‌گیری نیست.</p>`,`${actionButton('close-modal','ادامه به‌عنوان مهمان','','','btn btn-ghost')}<button class="btn btn-primary" type="submit" form="authForm" id="authSubmit">${icon(register?'plus':'arrow')}${register?'ساخت حساب':'ورود به حساب'}</button>`,'narrow');
 ui.modal='auth';
}
function showAccount(){
 const u=account.user;if(!u){openAccount();return;}
 const statusNames={saved:'آخرین تغییرات ذخیره شده‌اند.',saving:'ذخیره اطلاعات در حال انجام است…',pending:'تغییراتی در انتظار ذخیره هستند.',error:'آخرین ذخیره‌سازی ناموفق بود.',conflict:'دو نسخه متفاوت از اطلاعات وجود دارد.',expired:'نشست یا حساب فعال تغییر کرده است.'};
 const issue=['error','conflict','expired'].includes(account.status);
 let conflict='';
 if(account.status==='conflict')conflict=`<div class="notice section-gap">${icon('info')}نسخه سرور و این صفحه متفاوت‌اند. هیچ نسخه‌ای خودکار روی دیگری نوشته نشده است. می‌توانی اول از نسخه فعلی پشتیبان بگیری.</div><div class="settings-data section-gap">${actionButton('export-backup','پشتیبان نسخه من','download','','btn small')}${actionButton('account-pull','بارگیری نسخه سرور','download','','btn small')}${actionButton('account-overwrite','جایگزینی با نسخه من','upload','','btn btn-soft small')}</div>`;
 if(account.status==='expired')conflict=`<div class="notice section-gap">${icon('info')}برای حفظ تغییرات ثبت‌نشده، ابتدا پشتیبان بگیر. سپس صفحه را تازه کن، وارد حساب شو و در صورت نیاز پشتیبان را بازیابی کن.</div><div class="settings-data section-gap">${actionButton('export-backup','پشتیبان داده‌ها','download','','btn small')}${actionButton('account-reload','تازه‌سازی صفحه','reset','','btn small')}</div>`;
 openModal('فضای شخصی '+esc(u.name),'حساب و داده‌ها، روی سرور همین برنامه.',`<div class="account-identity"><span class="account-big-avatar">${esc(u.name.slice(0,1))}</span><div><h3>${esc(u.name)}</h3><p dir="ltr">${esc(u.email)}</p></div><span class="badge green">PHP + SQLite</span></div><div class="notice ${issue?'':'neutral'}" style="margin-top:21px">${icon(issue?'info':'shield')}<div><strong>${statusNames[account.status]||statusNames.pending}</strong><p class="micro">${account.updatedAt?'آخرین ذخیره: '+friendlyDate(new Date(account.updatedAt))+' · '+new Date(account.updatedAt).toLocaleTimeString('fa-IR',{hour:'2-digit',minute:'2-digit'}):'هنوز تغییر ورزشی‌ای روی سرور ذخیره نشده.'}</p>${account.error?`<p class="micro" style="margin-top:6px">${esc(account.error)}</p>`:''}</div></div>${conflict}<div class="settings-data section-gap">${actionButton('account-sync','همگام‌سازی الان','reset','','btn small')}${actionButton('export-backup','پشتیبان JSON','download','','btn small')}${actionButton('settings','تنظیمات داده‌ها','settings','','btn small')}</div><p class="small-text text-muted" style="margin-top:20px;line-height:2.2">اطلاعات حساب روی دستگاه مشترک در localStorage ذخیره نمی‌شوند. تغییرات معمولاً چند لحظه بعد به SQLite می‌رسند؛ همیشه نشانگر ذخیره را بررسی کن. فایل پشتیبان شامل داده‌های شخصی توست.</p><div class="notice neutral section-gap">${icon('shield')}این نسخه، بازیابی ایمیلی رمز یا تأیید ایمیل ندارد. رمز و پشتیبان را امن نگه دار. برای انتشار عمومی، HTTPS و سیاست نگهداری داده لازم است.</div><details class="account-danger section-gap"><summary>حذف دائمی حساب و داده‌ها</summary><p class="micro" style="margin:12px 0">حساب، مشخصات و سوابق ذخیره‌شده آن حذف می‌شوند. این عملیات قابل برگشت نیست. نسخه‌های پشتیبان خارجی جداگانه‌اند.</p><form id="deleteAccountForm"><div class="field"><label for="deletePassword">رمز عبور برای تأیید هویت</label><input id="deletePassword" name="password" type="password" autocomplete="current-password" maxlength="72" required></div><button class="btn btn-danger small" type="submit" style="margin-top:12px">${icon('trash')}درخواست حذف حساب</button></form></details><div id="accountError" class="field-error" role="alert"></div>`,`${actionButton('close-modal','بستن','','','btn btn-ghost')}${actionButton('account-logout','خروج از حساب','arrow','','btn')}`);ui.modal='account';
}
function resetRuntime(){chatEpoch++;pendingController?.abort();ui.busy=false;ui.busyRole=null;ui.aiMode='local';ui.draft=null;ui.calc=null;timer.running=false;timer.visible=false;renderMiniTimer();phpCalculation=null;}
async function applyAuthResult(result,carry=null){
 clearTimeout(account.timer);resetRuntime();
 account={user:result.user,csrf:result.csrf,revision:result.revision||0,updatedAt:result.updatedAt,pending:false,saving:false,status:'saved',change:0,promise:null,timer:null,error:''};
 state=result.state?cleanState(result.state):freshState();
 if(carry){state=cleanState(carry);queueServerSave();}
 closeModal();refresh();updateAccountChrome();
 if(carry){const saved=await flushServerSave();toast(saved?'حساب ساخته شد و اطلاعات انتخاب‌شده انتقال یافت.':'حساب ساخته شد، اما انتقال داده کامل نشده؛ نشانگر ذخیره را بررسی کن.',saved?'success':'warning',6500);}else toast('به حساب شخصی‌ات خوش آمدی.');
}
async function submitAuth(form){
 if(authWorking||!form.reportValidity())return;
 const fields=new FormData(form),register=authMode==='register',password=fields.get('password');
 if(new TextEncoder().encode(password).length>72){$('#authError').textContent='رمز بیش از ۷۲ بایت است؛ رمز کوتاه‌تری انتخاب کن.';return;}
 const carry=register&&fields.has('carry')?JSON.parse(JSON.stringify(state)):null;
 authWorking=true;const button=$('#authSubmit');button.disabled=true;button.textContent='در حال بررسی…';$('#authError').textContent='';
 try{const result=await phpRequest(register?'register':'login',{method:'POST',data:{name:fields.get('name')||'',email:fields.get('email'),password,consent:fields.has('consent')}});await applyAuthResult(result,carry);}
 catch(error){if($('#authError'))$('#authError').textContent=error.message;else toast(error.message,'warning',7000);}
 finally{authWorking=false;if(document.contains(button)){button.disabled=false;button.innerHTML=icon('arrow')+(register?'ساخت حساب':'ورود به حساب');}}
}
async function leaveAccount(deleted=false,password=''){
 if(!deleted&&account.pending){const saved=await flushServerSave();if(!saved){toast('پیش از خروج، تغییرات ذخیره‌نشده را همگام یا پشتیبان‌گیری کن.','warning',6500);showAccount();return;}}
 const result=await phpRequest(deleted?'account':'logout',{method:deleted?'DELETE':'POST',data:deleted?{password}:{logout:true}});
 clearTimeout(account.timer);resetRuntime();account.user=null;account.csrf=result.csrf;account.pending=false;account.saving=false;account.status='saved';
 state=loadLocalData();PHP_BOOT.state=null;PHP_BOOT.user=null;PHP_BOOT.calculation=null;closeModal();updateAccountChrome();
 // A fresh, no-store document removes server-bootstrapped private data from the DOM too.
 location.reload();
}
async function handlePhpAction(btn){
 const d=btn.dataset,a=d.action;
 const mine=['auth','auth-mode','account-sync','account-logout','account-pull','account-overwrite','account-reload','reset-data'];
 if(!mine.includes(a)||(a==='reset-data'&&!account.user))return false;
 try{
  if(a==='auth')openAccount();
  if(a==='auth-mode')openAccount(d.mode==='register'?'register':'login');
  if(a==='account-sync'){
   if(account.status==='error')account.status='pending';
   const ok=await flushServerSave();showAccount();if(ok)toast('اطلاعات حساب با سرور همگام است.');
  }
  if(a==='reset-data')askConfirm('داده‌های ورزشی حساب پاک شود؟','برنامه، اندازه‌گیری‌ها، غذاها و گفتگوهای حساب حذف می‌شوند. خود حساب و اطلاعات مهمان جداگانه باقی می‌مانند.',async()=>{try{if(account.pending&&!await flushServerSave()){showAccount();return;}const empty=freshState();const result=await phpRequest('state',{method:'PUT',data:{state:empty,revision:account.revision}});resetRuntime();state=empty;account.revision=result.revision;account.updatedAt=result.updatedAt;account.pending=false;account.status='saved';account.error='';closeModal();go('overview');toast('داده‌های ورزشی حساب از سرور پاک شد.');}catch(error){toast(error.message,'warning',7000);}},'پاک‌کردن داده‌های حساب',true);
  if(a==='account-logout')await leaveAccount();
  if(a==='account-reload')askConfirm('صفحه تازه شود؟','تغییرات ذخیره‌نشده این صفحه از بین می‌روند. اگر لازم است ابتدا پشتیبان بگیر.',()=>{account.pending=false;state.activeSession=null;location.reload();},'تازه‌سازی');
  if(a==='account-pull')askConfirm('نسخه سرور بارگیری شود؟','تغییرات ثبت‌نشده همین صفحه جایگزین می‌شوند؛ پشتیبان‌گیری از نسخه فعلی توصیه می‌شود.',async()=>{try{const r=await phpRequest('state');resetRuntime();state=r.state?cleanState(r.state):freshState();account.revision=r.revision;account.updatedAt=r.updatedAt;account.pending=false;account.error='';account.status='saved';closeModal();refresh();toast('نسخه سرور بارگیری شد.');}catch(e){toast(e.message,'warning',6500);}},'بارگیری نسخه سرور');
  if(a==='account-overwrite')askConfirm('نسخه همین صفحه روی سرور ذخیره شود؟','نسخه فعلی سرور جایگزین می‌شود. این انتخاب را فقط وقتی انجام بده که نسخه همین صفحه را می‌خواهی.',async()=>{try{const r=await phpRequest('state');account.revision=r.revision;account.status='pending';account.pending=true;const ok=await flushServerSave();showAccount();if(ok)toast('نسخه انتخابی تو ذخیره شد.');}catch(e){toast(e.message,'warning',6500);}},'جایگزینی نسخه سرور');
 }catch(error){toast(error.message,'warning',7000);const out=$('#accountError');if(out)out.textContent=error.message;}
 return true;
}
document.addEventListener('submit',e=>{
 if(e.target.id==='authForm'){e.preventDefault();submitAuth(e.target);}
 if(e.target.id==='deleteAccountForm'){
  e.preventDefault();if(!e.target.reportValidity())return;
  const password=new FormData(e.target).get('password');
  askConfirm('حساب و همه اطلاعات آن حذف شود؟','حساب و داده‌های ذخیره‌شده روی سرور به‌طور برگشت‌ناپذیر حذف می‌شوند؛ پشتیبان‌های دانلودشده جداگانه باقی می‌مانند.',async()=>{try{await leaveAccount(true,password);}catch(error){$('#accountError').textContent=error.message;}},'حذف دائمی حساب',true);
 }
});
window.addEventListener('beforeunload',e=>{if(account.user&&(account.pending||account.saving)){flushServerSave();e.preventDefault();e.returnValue='';}});
window.addEventListener('online',()=>{if(account.user&&account.status==='error'){account.status='pending';flushServerSave();}});
window.addEventListener('pageshow',e=>{if(e.persisted)location.reload();});
updateAccountChrome();
