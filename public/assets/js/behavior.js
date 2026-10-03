function addWater(delta=1,notify=true){
 const old=dailyWater(),value=clamp(old+delta,0,20);if(old===value){if(notify)toast(delta>0?'بیش از ۲۰ لیوان در روز در این ثبت‌گر پشتیبانی نمی‌شود. این سقف، توصیه مصرف نیست.':'چیزی برای کم‌کردن ثبت نشده.','warning');return false;}
 state.water[today()]=value;save();if(notify)toast(delta>0?`${fa(delta)} لیوان آب ثبت شد.`:'ثبت آب اصلاح شد.');
 if(ui.view==='overview'||ui.view==='nutrition')refresh();return true;
}
function toggleFavorite(id){if(!EX[id])return;const was=state.favorites.includes(id);state.favorites=was?state.favorites.filter(x=>x!==id):[...state.favorites,id];save();updateExerciseResults();if($('#detailFavorite'))$('#detailFavorite').innerHTML=icon('heart')+(was?'ذخیره حرکت':'حذف از علاقه‌مندی');toast(was?'از علاقه‌مندی‌ها برداشته شد.':'حرکت به علاقه‌مندی‌ها اضافه شد.');}
function logMeal(slot){
 const meals=state.meals[today()]||[],exists=meals.some(m=>m.slot===slot);
 if(exists){state.meals[today()]=meals.filter(m=>m.slot!==slot);save();toast('ثبت این وعده برداشته شد.');}else{const m=mealFor(slot);state.meals[today()]=[...meals,{slot,id:m.id,name:m.name,p:m.p,c:m.c,f:m.f,kcal:m.kcal,ingredients:m.ingredients.map(v=>[...v])}];addEvent(`${MEAL_NAMES[slot]} ثبت شد: ${m.name}`,'leaf');toast('وعده در مجموع امروز ثبت شد.');}
 refresh();
}
function startTimer(seconds){const n=Math.round(clamp(Number(seconds)||60,15,600));timer={total:n,remaining:n,end:Date.now()+n*1000,running:true,visible:true,finished:false};renderMiniTimer();}
function renderMiniTimer(){
 const el=$('#miniTimer');el.hidden=!timer.visible;el.classList.toggle('finished',timer.finished);
 if(timer.visible)el.innerHTML=`${icon(timer.finished?'check':'clock')}<div><small>${timer.finished?'استراحت تمام شد':'تایمر استراحت'}</small><strong data-timer-text>${timeText(timer.remaining)}</strong></div><div class="mini-timer-actions"><button class="icon-btn" data-action="pause-timer" aria-label="${timer.running?'مکث تایمر':'ادامه تایمر'}">${icon(timer.running?'pause':'play')}</button><button class="icon-btn" data-action="reset-timer" aria-label="شروع دوباره تایمر">${icon('reset')}</button><button class="icon-btn" data-action="close-timer" aria-label="بستن تایمر">${icon('close')}</button></div>`;
 if($('#sessionRest'))$('#sessionRest').innerHTML=sessionTimerMarkup();
}
function pauseTimer(){if(!timer.visible)return;if(timer.finished){startTimer(timer.total);return;}if(timer.running){timer.remaining=Math.max(0,Math.ceil((timer.end-Date.now())/1000));timer.running=false;}else{timer.end=Date.now()+timer.remaining*1000;timer.running=true;}renderMiniTimer();}
function checkExercise(index){const a=state.activeSession;if(!a||index<0||index>=a.items.length)return;a.checked[index]=!a.checked[index];save();const row=$(`[data-row="${index}"]`);if(row){row.classList.toggle('done',a.checked[index]);row.querySelector('button').setAttribute('aria-pressed',String(a.checked[index]));}const done=a.checked.filter(Boolean).length;if($('#sessionDoneText'))$('#sessionDoneText').textContent=`${fa(done)} از ${fa(a.items.length)} حرکت تکمیل شده`;if($('#sessionProgress'))$('#sessionProgress').style.width=done/a.items.length*100+'%';if($('#completeSession'))$('#completeSession').disabled=done!==a.items.length;}
function pauseSession(){const a=state.activeSession;if(!a)return;if(a.pausedAt){a.pausedMs+=(Date.now()-a.pausedAt);a.pausedAt=null;}else a.pausedAt=Date.now();save();const b=$('#sessionPause');if(b){b.innerHTML=icon(a.pausedAt?'play':'pause');b.setAttribute('aria-label',a.pausedAt?'ادامه زمان جلسه':'مکث زمان جلسه');}}
function completeSession(){const a=state.activeSession;if(!a||a.checked.some(v=>!v))return;state.sessions.push({id:a.id,name:a.name,dayIndex:a.dayIndex,startedAt:a.startedAt,endedAt:Date.now(),duration:Math.min(21600,sessionSeconds(a)),count:a.items.length});state.activeSession=null;timer.visible=false;timer.running=false;renderMiniTimer();addEvent(`جلسه ${a.name} تکمیل شد.`,'dumbbell');closeModal();refresh();celebrate();toast('جلسه ثبت شد. به استمرار خودت افتخار کن!', 'success',5000);}
function exportPlan(){const p=getProfile(),est=estimate(),plan=makePlan();const content=`فیت‌بات — برنامه تمرینی ${state.profile?'شخصی':'نمونه'}\nتاریخ خروجی: ${friendlyDate(new Date(),true)}\n${p.name?'نام: '+p.name+'\n':''}هدف: ${GOALS[est.effectiveGoal]}\nتجهیزات: ${EQUIPMENT[p.equipment]}\nسطح: ${p.level==='beginner'?'مبتدی':'متوسط'}\n\n${plan.map(day=>`${DAYS[day.dayIndex]} — ${day.name} — حدود ${fa(day.duration)} دقیقه\n۵ دقیقه گرم‌کردن سبک\n${day.items.map(it=>`• ${EX[it.id].name}: ${fa(it.sets)} ست × ${it.reps}\n  نکته: ${EX[it.id].tip}`).join('\n')}\nاستراحت نمونه بین ست‌ها: ۶۰ تا ۹۰ ثانیه؛ بسته به آمادگی بیشتر شود.\n۵ دقیقه سردکردن\n`).join('\n')}\nبرآورد تغذیه (نه نسخه رژیم):\nBMR: ${fa(est.bmr)} / TDEE: ${fa(est.tdee)}\nهدف انرژی: ${fa(est.target)} کیلوکالری\nپروتئین: ${fa(est.protein)} گرم / کربوهیدرات: ${fa(est.carbs)} گرم / چربی: ${fa(est.fat)} گرم\n\nاین برنامه با قواعد برنامه‌ساز ساخته شده و برای راهنمایی عمومی بزرگسالان است. در بارداری، آسیب یا بیماری، برنامه فردی را با متخصص هماهنگ کن. در صورت درد تمرین را متوقف کن.\n`;
 download('FitBot-Plan.txt','\ufeff'+content);toast('فایل متنی برنامه برای دانلود آماده شد.');
}
function exportCSV(){const safe=v=>'"'+String(v??'').replace(/^[=+\-@\t\r]/,"'$&").replace(/"/g,'""')+'"';const csv='\ufeff'+[['Date (Gregorian)','Weight (kg)','Note'],...state.weights.map(w=>[w.date,w.value,w.note])].map(row=>row.map(safe).join(',')).join('\r\n');download('FitBot-Weights.csv',csv,'text/csv;charset=utf-8');if(!state.weights.length)toast('فایل خالیِ عنوان‌دار آماده شد؛ هنوز وزنی ثبت نکرده‌ای.','warning');else toast('خروجی همه اندازه‌گیری‌ها آماده شد.');}
let chatEpoch=0;
function localReply(input,role){
 const q=norm(input),p=getProfile(),e=estimate(),sample=state.profile?'مشخصات ثبت‌شده تو':'مشخصات نمونه (پروفایل شخصی هنوز تکمیل نشده)';
 const action=(type,args={})=>({id:uid(),type,args,done:false});
 let content='',actions=[];
 if(/درد (قفسه|سینه)|غش|تنگی نفس شدید/.test(q))content='تمرین را همین حالا متوقف کن. درد قفسه سینه، غش یا تنگی نفس شدید می‌تواند نیاز به ارزیابی فوری داشته باشد؛ با اورژانس محل یا یک فرد حاضر تماس بگیر.\nمن نمی‌توانم علت این علائم را تشخیص بدهم یا ادامه تمرین را ایمن اعلام کنم.';
 else if(/درد|آسیب|مصدوم|باردار|دیابت|بیماری|دارو|کودک|زیر ۱۸|اختلال خوردن/.test(q))content='برای آسیب، درد، بیماری، بارداری یا سن زیر ۱۸ سال، این ابزار برنامه تخصصی نمی‌سازد. فعالیت دردناک را متوقف کن و برای انتخاب تمرین یا رژیم مناسب با پزشک یا متخصص واجد صلاحیت هماهنگ شو.\nدر حالت محلی فقط راهنمای عمومی دارم؛ نمی‌توانم تشخیص بدهم، دارو پیشنهاد کنم یا برنامه را از نظر پزشکی تأیید کنم.';
 else if(/آب|لیوان|هیدرات/.test(q)){
  const match=plainDigits(q).match(/\b([12])\b/),glasses=match?Number(match[1]):1;
  content=`امروز ${fa(dailyWater())} لیوان (${fa(dailyWater()*.25,2)} لیتر) ثبت کرده‌ای. هدف انتخابی تو ${fa(state.settings.waterGoal)} لیوان است؛ این هدف یک توصیه پزشکی همگانی نیست.\nهر لیوان در این برنامه ۲۵۰ میلی‌لیتر حساب می‌شود. اگر نوشیده‌ای، با تأیید دکمه زیر ${fa(glasses)} لیوان ثبت می‌کنم. در صورت محدودیت مصرف مایعات، دستور متخصصت اولویت دارد.`;actions=[action('log_water',{glasses})];
 }else if(/ریکاوری|خواب|استراحت|خسته|خستگی|کوفتگی/.test(q)){
  content='ریکاوری هم بخشی از برنامه است.\n\n۱. برای بیشتر بزرگسالان، حدود ۷ تا ۹ ساعت خواب با ساعت نسبتاً ثابت نقطه شروع مناسبی است.\n۲. بین جلسه‌های سنگین یک گروه عضلانی، فرصت بازیابی بده و روزهای سبک را جدی بگیر.\n۳. برای کوفتگی معمول، حرکت آرامِ بدون درد و استراحت ممکن است کمک کند؛ درد تیز یا ماندگار را عادی فرض نکن.\n۴. اگر فرم حرکت افت کرده، استراحت بین ست‌ها را بیشتر کن.\n\nاین راهنمای محلی است و آمادگی یا وضعیت پزشکی تو را اندازه‌گیری نمی‌کند.';actions=[action('start_timer',{seconds:60})];
 }else if(/غذا|بخور|پروتئین|کربو|ماکرو|تغذیه|وعده|گیاهی/.test(q)){
  content=`بر اساس ${sample}، هدف تخمینی انرژی روزانه ${fa(e.target)} کیلوکالری است.\nماکروهای هدف: ${fa(e.protein)} گرم پروتئین، ${fa(e.carbs)} گرم کربوهیدرات و ${fa(e.fat)} گرم چربی.\n\nاز پیشنهادهای آماده امروز:\n${[0,1,2,3].map(i=>{const m=mealFor(i);return `${MEAL_NAMES[i]}: ${m.name} — حدود ${fa(m.kcal)} کیلوکالری`;}).join('\n')}\n\nاین‌ها پیشنهادهای عمومی با سهم تخمینی‌اند، نه رژیم درمانی. ترکیبات، حساسیت غذایی و دستور متخصصت را بررسی کن.`;actions=[action('nutrition')];if(!state.profile)actions.unshift(action('planner'));
 }else if(/هدف/.test(q)&&/کاهش|چربی|عضله|حفظ/.test(q)){
  const goal=/کاهش|چربی/.test(q)?'lose':/عضله/.test(q)?'gain':'maintain';
  if(e.lowBMI&&goal==='lose')content='بر اساس قد و وزن فعلی، کاهش وزن توسط این ابزار پیشنهاد نمی‌شود. برای تعیین هدف مناسب با متخصص مشورت کن. می‌توانم برای حفظ آمادگی، برنامه عمومی آماده کنم.';
  else{content=`می‌توانم هدف ثبت‌شده را به «${GOALS[goal]}» تغییر بدهم. این کار برآورد انرژی و پیشنهادهای بعدی را به‌روزرسانی می‌کند؛ هنوز چیزی تغییر نکرده است.\nبرای تغییر، دکمه تأیید را بزن. برنامه، جایگزین ارزیابی حرفه‌ای نیست.`;actions=[action('update_goal',{goal})];}
 }else if(/کالری|انرژی|bmr|tdee|محاسبه/.test(q)){
  content=`برآورد برای ${sample}:\n\n• انرژی پایه (BMR): ${fa(e.bmr)} کیلوکالری\n• مصرف روزانه (TDEE): ${fa(e.tdee)} کیلوکالری\n• هدف ${GOALS[e.effectiveGoal]}: حدود ${fa(e.target)} کیلوکالری\n\nاز فرمول Mifflin–St Jeor و ضریب فعالیت انتخابی استفاده شده. این اعداد تخمینی‌اند؛ سن، دارو، بیماری، بارداری و تفاوت‌های فردی می‌توانند نیاز واقعی را تغییر دهند. مشخصات را در محاسبه‌گر بررسی کن.`;actions=[action('calculator')];
 }else if(/برنامه|تمرین|عضله|بدنسازی|باشگاه|دمبل/.test(q)){
  const plan=makePlan();content=`نقشه شروع بر اساس ${sample}:\n\n${plan.map(d=>`• ${DAYS[d.dayIndex]}: ${d.name}، حدود ${fa(d.duration)} دقیقه`).join('\n')}\nتجهیزات: ${EQUIPMENT[p.equipment]}\nسطح: ${p.level==='beginner'?'مبتدی':'متوسط'}\n\nبرنامه‌ساز محلی، تعداد حرکات و ست‌ها را با زمان و تجربه تو تنظیم می‌کند. با بار سبک، فرم قابل‌کنترل و چند تکرار ذخیره شروع کن.\nبرای تأیید یا تغییر مشخصاتت، برنامه‌ساز را باز کن.`;actions=[action('planner'),action('start_timer',{seconds:60})];
 }else if(/وزن|پیشرفت|اندازه|نمودار/.test(q)){
  const w=lastWeight();content=w?`آخرین وزنت در ${friendlyDate(parseDate(w.date))}، ${fa(w.value,1)} کیلوگرم ثبت شده. تا الان ${fa(state.sessions.length)} جلسه را تکمیل کرده‌ای.\n\nبه روند چند هفته نگاه کن، نه بالا و پایین شدن یک روز. شرایط اندازه‌گیری، آب بدن و وعده اخیر روی عدد اثر می‌گذارند. وزن به‌تنهایی معیار سلامت نیست.`:'هنوز اندازه‌گیری ثبت نشده. یک وزن اولیه در شرایط معمول ثبت کن تا نمودارت شروع شود.\nپیشرفت فقط وزن نیست؛ استمرار، انرژی و کیفیت حرکت هم مهم‌اند.';actions=[action('progress')];
 }else if(/^(سلام|درود|hi|hello)/.test(q)){
  content=`سلام${state.profile?.name?' '+state.profile.name:''}! من ${ROLES[role].name} در حالت محلی هستم.\nبا راهنماهای قانون‌محور به ساخت برنامه، برآورد کالری، ثبت آب و ریکاوری کمک می‌کنم. پیام‌ها در این حالت به سرویس مدل ارسال نمی‌شوند.\nمثلاً بپرس: «برای من برنامه بساز» یا «کالری مورد نیازم چقدره؟»`;actions=[action('planner')];
 }else{content='این درخواست را در راهنماهای محدودِ حالت محلی پیدا نکردم؛ نمی‌خواهم یک پاسخ نامرتبط یا ساختگی بدهم.\n\nدر این حالت می‌توانم در ساخت برنامه، محاسبه کالری و ماکروها، ثبت آب و وزن، و اصول عمومی ریکاوری کمک کنم.\nبرای سؤال آزاد و پاسخ مدل زبانی، از «اتصال هوش مصنوعی» استفاده کن؛ راه‌اندازی سرور و رضایت برای ارسال داده لازم است.';actions=[action('planner'),action('calculator')];}
 return {content,actions};
}
async function checkApi(){
 ui.api={available:false,checked:true};
 if(!['http:','https:'].includes(location.protocol)||location.origin==='null')return;
 const controller=new AbortController(),t=setTimeout(()=>controller.abort(),2200);
 try{const res=await fetch(apiUrl('status'),{signal:controller.signal,headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'});if(res.ok){const x=await res.json();if(x.service==='fitbot')ui.api={available:x.available===true,checked:true,model:text(x.model,80)};}}catch{}finally{clearTimeout(t);}
}
async function sendMessage(value){
 const input=text(value,1600).trim();if(!input||ui.busy)return;
 const role=ui.role,mode=ui.aiMode,epoch=chatEpoch;
 state.chat[role].push({id:uid(),role:'user',content:input,at:Date.now(),mode,actions:[]});state.chat[role]=state.chat[role].slice(-40);ui.busy=true;ui.busyRole=role;
 if($('#chatInput'))$('#chatInput').value='';save();updateChat();
 try{
  let answer;
  if(mode==='local'){await new Promise(r=>setTimeout(r,reduced()?0:260));answer=localReply(input,role);}
  else{
   const requestController=new AbortController();pendingController=requestController;const t=setTimeout(()=>requestController.abort(),50000);
   try{
    const res=await fetch(apiUrl('agent'),{method:'POST',credentials:'same-origin',headers:apiHeaders(),signal:requestController.signal,body:JSON.stringify({role,consent:true,profile:{...getProfile(),sample:!state.profile},context:{waterGlasses:dailyWater(),waterGoal:state.settings.waterGoal,completedThisWeek:weeklySessions().length,estimates:estimate()},messages:state.chat[role].slice(-12).map(m=>({role:m.role,content:m.content}))})});
    const data=await res.json();if(!res.ok)throw new Error(text(data.error,350)||'سرویس مدل پاسخ معتبر نداد.');
    if(typeof data.message!=='string')throw new Error('پاسخ سرویس قابل خواندن نیست.');
    answer={content:text(data.message,6000),actions:(Array.isArray(data.actions)?data.actions:[]).slice(0,3).map(a=>sanitizeAction({...a,id:uid(),done:false})).filter(Boolean)};
   }finally{clearTimeout(t);if(pendingController===requestController)pendingController=null;}
  }
  if(epoch===chatEpoch){state.chat[role].push({id:uid(),role:'assistant',content:answer.content,actions:answer.actions,at:Date.now(),mode});save();}
 }catch(err){if(epoch===chatEpoch){state.chat[role].push({id:uid(),role:'assistant',content:err.name==='AbortError'?'درخواست لغو شد یا مهلت اتصال تمام شد. داده‌ای در برنامه تغییر نکرده؛ می‌توانی دوباره تلاش کنی یا به حالت محلی برگردی.':`پاسخ از سرویس مدل دریافت نشد.\n${text(err.message,400)}\n\nحالت محلی همچنان در دسترس است؛ پاسخ آفلاین به جای پاسخ مدل جا زده نمی‌شود.`,actions:[],at:Date.now(),mode});save();}}
 finally{if(epoch===chatEpoch){ui.busy=false;ui.busyRole=null;updateChat();if(ui.view==='agents')$('#chatInput')?.focus();}}
}
function approveAgentAction(messageId,actionId){
 const m=state.chat[ui.role].find(m=>m.id===messageId),a=m?.actions?.find(a=>a.id===actionId);if(!a||a.done)return;
 const complete=()=>{a.done=true;save();updateChat();};
 if(a.type==='log_water'){if(addWater(a.args.glasses||1)){complete();addEvent(`${fa(a.args.glasses||1)} لیوان آب با تأیید پیشنهاد ایجنت ثبت شد.`,'drop');}return;}
 if(a.type==='update_goal'){
  if(!state.profile){ui.draft={...getProfile(),goal:a.args.goal};ui.wizardStep=0;showWizard();return;}
  if(a.args.goal==='lose'&&estimate().lowBMI){toast('برای وزن فعلی، هدف کاهش کالری توسط ابزار پیشنهاد نمی‌شود؛ با متخصص مشورت کن.','warning',6000);return;}
  askConfirm('تغییر هدف را تأیید می‌کنی؟',`هدف به «${GOALS[a.args.goal]}» تغییر می‌کند و برآورد انرژی و پیشنهادهای آینده به‌روز می‌شوند.`,()=>{state.profile.goal=a.args.goal;complete();addEvent('هدف با تأیید تو تغییر کرد: '+GOALS[a.args.goal],'spark');toast('هدف به‌روزرسانی شد.');refresh();},'تأیید تغییر هدف');return;
 }
 complete();
 if(a.type==='planner')openPlanner();else if(a.type==='calculator')openCalculator();else if(a.type==='nutrition')go('nutrition');else if(a.type==='progress')openWeight();else if(a.type==='recovery'){ui.role='recovery';go('agents');}else if(a.type==='start_timer')startTimer(a.args.seconds||60);
}
function switchRole(role){if(!ROLES[role])return;if(ui.busy){toast('برای تغییر ایجنت، پاسخ فعلی را دریافت یا درخواست را لغو کن.','warning');return;}ui.role=role;go('agents');}
function runCommand(id){closeModal();if(VIEW_NAMES[id])go(id);else handleAction({dataset:{action:id}});}
function openMobile(){
 $('#sidebar').classList.add('open');$('#navBackdrop').classList.add('visible');$('#mobileMenu').setAttribute('aria-expanded','true');$('#sidebar').setAttribute('role','dialog');$('#sidebar').setAttribute('aria-modal','true');$('.app-shell').inert=true;$('.bottom-nav').inert=true;document.body.style.overflow='hidden';$('#sidebar .nav-item').focus();
}
function closeMobile(){const open=$('#sidebar').classList.contains('open');$('#sidebar').classList.remove('open');$('#navBackdrop').classList.remove('visible');$('#mobileMenu').setAttribute('aria-expanded','false');$('#sidebar').removeAttribute('role');$('#sidebar').removeAttribute('aria-modal');$('.app-shell').inert=false;$('.bottom-nav').inert=false;document.body.style.overflow='';if(open)$('#mobileMenu').focus();}
async function handleAction(btn){
 if(typeof handlePhpAction==='function'&&await handlePhpAction(btn))return;
 const d=btn.dataset,a=d.action;
 switch(a){
 case 'planner':openPlanner();break;
 case 'calculator':openCalculator();break;
 case 'settings':openSettings();break;
 case 'notifications':openNotifications();break;
 case 'commands':openCommands();break;
 case 'close-modal':closeModal();break;
 case 'wizard-back':{const f=$('#plannerForm');if(f)ui.draft=readProfileForm(f,ui.draft);ui.wizardStep=Math.max(0,ui.wizardStep-1);showWizard();break;}
 case 'apply-calc':{
  const p=ui.calc?.profile;if(!p)return;
  if(estimate(p).lowBMI&&p.goal==='lose'){toast('برای این وزن، هدف کاهش کالری را اعمال نمی‌کنم. هدف دیگری انتخاب کن.','warning');return;}
  if(!state.profile){ui.draft={...p};ui.wizardStep=1;showWizard();}else{const old=state.profile.weight;state.profile={...p};if(old!==p.weight)putWeight({date:today(),value:p.weight,note:'به‌روزرسانی از محاسبه‌گر'});addEvent('برآورد انرژی و مشخصات پروفایل به‌روز شد.','settings');closeModal();refresh();toast('مشخصات و هدف انرژی به‌روز شد.');}break;
 }
 case 'weight':openWeight();break;
 case 'edit-weight':openWeight(d.date);break;
 case 'delete-weight':askConfirm('حذف این اندازه‌گیری؟','این عدد از دفتر اندازه‌گیری و نمودار حذف می‌شود. سایر ثبت‌ها باقی می‌مانند.',()=>{state.weights=state.weights.filter(w=>w.date!==d.date);if(state.profile&&lastWeight())state.profile.weight=lastWeight().value;save();refresh();toast('اندازه‌گیری حذف شد.');},'حذف اندازه‌گیری',true);break;
 case 'agent':case 'switch-agent':switchRole(d.role||'coordinator');break;
 case 'chart-range':ui.range=Number(d.range);refresh();break;
 case 'select-day':ui.selectedDay=Number(d.day);refresh();break;
 case 'start-session':startSession(Number(d.day));break;
 case 'continue-session':openSession();break;
 case 'check-exercise':checkExercise(Number(d.index));break;
 case 'session-tip':if(EX[d.id])toast(EX[d.id].tip,'warning',6500);break;
 case 'pause-session':pauseSession();break;
 case 'complete-session':completeSession();break;
 case 'abandon-session':askConfirm('جلسه را بدون ثبت کنار می‌گذاری؟','پیشرفت جلسه جاری حذف می‌شود؛ این جلسه در سابقه تکمیل‌شده‌ها قرار نمی‌گیرد.',()=>{state.activeSession=null;save();closeModal();refresh();},'کنار گذاشتن',true);break;
 case 'exercise':openExercise(d.id);break;
 case 'favorite':toggleFavorite(d.id);break;
 case 'favorite-filter':ui.onlyFavorites=!ui.onlyFavorites;refresh();break;
 case 'filter-group':ui.filter=GROUPS[d.filter]?d.filter:'all';refresh();break;
 case 'reset-filters':ui.filter='all';ui.equipment='all';ui.search='';ui.onlyFavorites=false;refresh();break;
 case 'toggle-demo':{const el=$('#movementDemo');if(!el)return;const playing=el.classList.toggle('demo-playing');btn.setAttribute('aria-pressed',String(playing));btn.setAttribute('aria-label',playing?'توقف انیمیشن نمادین':'پخش انیمیشن نمادین');btn.innerHTML=icon(playing?'pause':'play');if(reduced()&&playing)toast('کاهش حرکت فعال است؛ انیمیشن پخش نمی‌شود.','warning');break;}
 case 'water-add':addWater(1);break;
 case 'water-remove':addWater(-1);break;
 case 'log-meal':logMeal(Number(d.slot));break;
 case 'swap-meal':{const slot=Number(d.slot);if((state.meals[today()]||[]).some(m=>m.slot===slot))return;state.mealChoices[slot]=1-state.mealChoices[slot];save();refresh();break;}
 case 'diet':if(['regular','vegetarian'].includes(d.diet)){state.diet=d.diet;save();refresh();if((state.meals[today()]||[]).length)toast('پیشنهادهای آینده تغییر کرد؛ وعده‌های ثبت‌شده حفظ شدند.');}break;
 case 'rest-60':startTimer(60);break;
 case 'start-timer':startTimer(Number(d.seconds));break;
 case 'pause-timer':pauseTimer();break;
 case 'reset-timer':startTimer(timer.total);break;
 case 'close-timer':timer.visible=false;timer.running=false;renderMiniTimer();break;
 case 'export-plan':exportPlan();break;
 case 'export-csv':exportCSV();break;
 case 'export-backup':download(`FitBot-Backup-${today()}.json`,JSON.stringify(state,null,2),'application/json');toast('پشتیبان حاوی داده‌های شخصی است؛ آن را امن نگه دار.','success',5000);break;
 case 'import-backup':$('#importFile').click();break;
 case 'reset-data':askConfirm('همه داده‌های محلی پاک شود؟','پروفایل، برنامه، اندازه‌گیری‌ها، غذاها و گفتگوها حذف می‌شوند و قابل بازگشت نیستند، مگر پشتیبان داشته باشی.',()=>{chatEpoch++;ui.busy=false;ui.busyRole=null;pendingController?.abort();state=freshState();timer.visible=false;timer.running=false;renderMiniTimer();save();closeModal();ui.aiMode='local';ui.selectedDay=0;go('overview');toast('همه داده‌های محلی پاک شد.');},'پاک‌کردن همه داده‌ها',true);break;
 case 'toggle-theme':state.settings.theme=state.settings.theme==='dark'?'light':'dark';save();btn.setAttribute('aria-checked',String(state.settings.theme==='light'));refresh();break;
 case 'toggle-motion':state.settings.reduced=!state.settings.reduced;save();btn.setAttribute('aria-checked',String(state.settings.reduced));refresh();break;
 case 'connect':openConnect();break;
 case 'recheck-api':btn.disabled=true;await checkApi();openConnect();break;
 case 'use-local':if(ui.busy){toast('ابتدا درخواست فعلی را لغو یا تمام کن.','warning');return;}ui.aiMode='local';closeModal();if(ui.view==='agents')refresh();toast('حالت محلی فعال است؛ داده‌ای به سرویس مدل ارسال نمی‌شود.');break;
 case 'use-cloud':if(ui.busy)return;if(!ui.api.available)return;if(!account.user){openAccount();return;}if(!$('#cloudConsent')?.checked){toast('برای فعال‌سازی، رضایت ارسال مشخصات و پیام را تأیید کن.','warning');return;}ui.aiMode='cloud';closeModal();if(ui.view==='agents')refresh();toast('حالت مدل زبانی فعال شد؛ ارسال داده فقط با ارسال پیام انجام می‌شود.','success',6000);break;
 case 'suggestion':if(SUGGESTIONS[Number(d.index)])await sendMessage(SUGGESTIONS[Number(d.index)][0]);break;
 case 'approve-agent-action':approveAgentAction(d.message,d.id);break;
 case 'cancel-request':chatEpoch++;ui.busy=false;ui.busyRole=null;pendingController?.abort();ui.busy=false;updateChat();toast('درخواست لغو شد؛ داده‌ای تغییر نکرد.');break;
 case 'clear-chat':if(!state.chat[ui.role].length){toast('این گفتگو هنوز خالی است.');break;}askConfirm('پاک‌کردن گفتگوی این ایجنت؟','فقط پیام‌های این گفتگو از مرورگر پاک می‌شوند؛ داده‌های تمرین و تغذیه باقی می‌مانند.',()=>{chatEpoch++;ui.busy=false;ui.busyRole=null;pendingController?.abort();state.chat[ui.role]=[];save();updateChat();},'پاک کردن گفتگو',true);break;
 case 'command-go':runCommand(d.id);break;
 }
}
async function handleForm(e){
 const f=e.target;if(!['plannerForm','calculatorForm','weightForm','chatForm'].includes(f.id))return;e.preventDefault();
 if(f.id==='plannerForm'){await submitPlanner(f);return;}
 if(f.id==='calculatorForm'){
  if(!f.reportValidity())return;
  const p=readProfileForm(f,ui.calc?.profile||getProfile()),button=f.querySelector('button[type=submit]'),requestId=uid();ui.calc.requestId=requestId;button.disabled=true;button.textContent='محاسبه با PHP…';
  try{const result=await getPhpCalculation(p);if($('#appDialog').open&&ui.modal==='calculator'&&ui.calc?.requestId===requestId)openCalculator(result.profile,result.estimates);}
  catch(error){if($('#calcError'))$('#calcError').textContent=error.message;}
  finally{if(document.contains(button)){button.disabled=false;button.innerHTML=icon('calculator')+'محاسبه برآورد';}}
  return;
 }
 if(f.id==='chatForm'){sendMessage($('#chatInput').value);return;}
 if(f.id==='weightForm'){
  if(!f.reportValidity())return;const fields=new FormData(f),w={date:fields.get('date'),value:Number(fields.get('weight')),note:text(fields.get('note'),120).trim()};
  if(!validDate(w.date)||w.date>today()||!Number.isFinite(w.value)||w.value<35||w.value>250){$('#weightError').textContent='وزن بین ۳۵ تا ۲۵۰ و تاریخ معتبر (نه آینده) وارد کن.';return;}
  const commit=()=>{putWeight(w);addEvent(`وزن ${fa(w.value,1)} کیلوگرم ثبت شد.`,'scale');closeModal();refresh();toast('اندازه‌گیری در مسیرت ثبت شد.');};
  if(state.weights.some(r=>r.date===w.date)&&ui.editWeight!==w.date)askConfirm('این روز قبلاً اندازه‌گیری شده','ثبت جدید جایگزین وزن همان روز می‌شود؛ یک روز دو بار در نمودار تکرار نمی‌شود.',commit,'جایگزینی ثبت');else commit();
 }
}
async function importBackup(file){
 if(!file)return;if(file.size>2*1024*1024){toast('حجم پشتیبان باید کمتر از ۲ مگابایت باشد.','warning');return;}
 try{const parsed=JSON.parse(await file.text());const restored=cleanState(parsed);askConfirm('داده‌های پشتیبان بازیابی شوند؟','داده‌های فعلی جایگزین می‌شوند. فایل باید متعلق به خودت باشد؛ بهتر است پیش از ادامه، پشتیبان فعلی را دریافت کنی.',()=>{chatEpoch++;ui.busy=false;ui.busyRole=null;pendingController?.abort();state=restored;timer.visible=false;timer.running=false;renderMiniTimer();save();closeModal();ui.aiMode='local';refresh();toast('پشتیبان بازیابی شد.');},'بازیابی و جایگزینی');}catch{toast('فایل پشتیبان معتبرِ فیت‌بات نسخه ۲ نیست؛ داده‌های فعلی تغییر نکردند.','warning',6000);}
}
function initHeroAnimation(){
 const hero=$('#hero'),canvas=$('#heroCanvas');if(!hero||!canvas||reduced())return;
 const ctx=canvas.getContext('2d');if(!ctx)return;let alive=true,visible=true,raf=0,last=0;
 const rect=hero.getBoundingClientRect(),width=rect.width,height=rect.height,dpr=Math.min(devicePixelRatio||1,2);canvas.width=width*dpr;canvas.height=height*dpr;ctx.scale(dpr,dpr);
 const points=Array.from({length:Math.min(24,Math.round(width/35))},()=>({x:Math.random()*width,y:Math.random()*height,r:Math.random()*1+.3,v:.15+Math.random()*.35,a:Math.random()*.3+.1}));
 function tick(now){if(!alive||!visible||document.hidden){raf=0;return;}const dt=Math.min(2,(now-last)/16.67||1);last=now;ctx.clearRect(0,0,width,height);points.forEach(p=>{p.y-=p.v*dt;p.x+=.07*dt;if(p.y<0){p.y=height;p.x=Math.random()*width;}ctx.beginPath();ctx.arc(p.x,p.y,p.r,0,Math.PI*2);ctx.fillStyle=`rgba(255,150,91,${p.a})`;ctx.fill();});raf=requestAnimationFrame(tick);}
 function resume(){if(alive&&visible&&!document.hidden&&!raf)raf=requestAnimationFrame(tick);}
 const observer=new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;resume();},{threshold:.05});observer.observe(hero);
 const pointer=e=>{if(e.pointerType==='touch')return;const r=hero.getBoundingClientRect();hero.style.setProperty('--px',((e.clientX-r.left)/r.width-.5)*7+'px');hero.style.setProperty('--py',((e.clientY-r.top)/r.height-.5)*5+'px');};
 const leave=()=>{hero.style.setProperty('--px','0px');hero.style.setProperty('--py','0px');};hero.addEventListener('pointermove',pointer);hero.addEventListener('pointerleave',leave);document.addEventListener('visibilitychange',resume);resume();
 particleCleanup=()=>{alive=false;if(raf)cancelAnimationFrame(raf);observer.disconnect();hero.removeEventListener('pointermove',pointer);hero.removeEventListener('pointerleave',leave);document.removeEventListener('visibilitychange',resume);};
}
function celebrate(count=70){
 if(reduced())return;const c=$('#celebration'),ctx=c.getContext('2d');if(!ctx)return;const w=innerWidth,h=innerHeight;c.width=w;c.height=h;c.style.display='block';
 const start=performance.now(),points=Array.from({length:count},()=>({x:w*.5,y:h*.5,vx:(Math.random()-.5)*13,vy:-3-Math.random()*10,r:3+Math.random()*4,rot:Math.random()*6,color:['#ff7846','#a0cba6','#b5a2ee','#f7d7c7'][Math.floor(Math.random()*4)]}));
 let last=start;function draw(t){const dt=Math.min(2,(t-last)/16.67);last=t;ctx.clearRect(0,0,w,h);points.forEach(p=>{p.vy+=.15*dt;p.x+=p.vx*dt;p.y+=p.vy*dt;p.rot+=.04*dt;ctx.save();ctx.translate(p.x,p.y);ctx.rotate(p.rot);ctx.globalAlpha=clamp(1-(t-start)/1800,0,1);ctx.fillStyle=p.color;ctx.fillRect(-p.r/2,-p.r/2,p.r,p.r*1.6);ctx.restore();});if(t-start<1800)requestAnimationFrame(draw);else{ctx.clearRect(0,0,w,h);c.style.display='none';}}requestAnimationFrame(draw);
}
function onHash(){const key=location.hash.slice(1);ui.view=VIEW_NAMES[key]?key:'overview';render();closeMobile();window.scrollTo({top:0,behavior:'instant'});}
// A single delegated interaction layer keeps dynamically rendered controls functional.
document.addEventListener('click',e=>{const b=e.target.closest('[data-action]');if(b&&!b.disabled){e.preventDefault();if($('#sidebar').contains(b)&&innerWidth<=760)closeMobile();Promise.resolve(handleAction(b)).catch(err=>{console.error('FitBot action failed',err);toast('این عملیات کامل نشد. دوباره تلاش کن.','warning');});}});
document.addEventListener('submit',handleForm);
document.addEventListener('input',e=>{
 if(e.target.id==='exerciseSearch'){ui.search=e.target.value;updateExerciseResults();}
 if(e.target.id==='commandInput'){ui.commandQuery=e.target.value;ui.commandIndex=0;$('#commandResults').innerHTML=commandResults();}
 if(e.target.id==='chatInput'){e.target.style.height='44px';e.target.style.height=Math.min(120,e.target.scrollHeight)+'px';}
});
document.addEventListener('change',e=>{
 if(e.target.id==='equipmentFilter'){ui.equipment=e.target.value;updateExerciseResults();}
 if(e.target.id==='waterGoal'){state.settings.waterGoal=Math.round(num(e.target.value,4,16,8));save();refresh();}
 if(e.target.id==='importFile'){const file=e.target.files?.[0];e.target.value='';importBackup(file);}
});
document.addEventListener('keydown',e=>{
 if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();if($('#confirmDialog').open)return;openCommands();return;}
 if(e.key==='Escape'&&$('#sidebar').classList.contains('open')){closeMobile();return;}
 if($('#sidebar').classList.contains('open')&&e.key==='Tab'){const f=$$('a,button',$('#sidebar')).filter(el=>el.offsetParent!==null),first=f[0],last=f.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}
 if(ui.modal==='commands'&&$('#appDialog').open&&['ArrowDown','ArrowUp','Enter'].includes(e.key)){
  const cmds=filteredCommands();if(!cmds.length)return;e.preventDefault();if(e.key==='Enter'){runCommand(cmds[ui.commandIndex][0]);return;}ui.commandIndex=(ui.commandIndex+(e.key==='ArrowDown'?1:-1)+cmds.length)%cmds.length;$('#commandResults').innerHTML=commandResults();$('#commandResults .selected')?.scrollIntoView({block:'nearest'});return;
 }
 if(e.target.id==='chatInput'&&e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();sendMessage(e.target.value);}
});
$('#mobileMenu').onclick=()=>$('#sidebar').classList.contains('open')?closeMobile():openMobile();$('#navBackdrop').onclick=closeMobile;
$('#appDialog').addEventListener('close',()=>{if(!$('#appDialog').open)ui.modal=null;});
for(const d of [$('#appDialog'),$('#confirmDialog')])d.addEventListener('click',e=>{if(e.target===d){const r=d.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)d.close();}});
window.addEventListener('hashchange',onHash);
window.addEventListener('resize',()=>{if(innerWidth>760&&$('#sidebar').classList.contains('open'))closeMobile();});
let scrollTick=false;window.addEventListener('scroll',()=>{if(scrollTick)return;scrollTick=true;requestAnimationFrame(()=>{const total=document.documentElement.scrollHeight-innerHeight;$('#scrollMeter').style.width=(total>0?window.scrollY/total*100:0)+'%';scrollTick=false;});},{passive:true});
window.addEventListener('beforeunload',()=>{const a=state.activeSession;if(a&&!a.pausedAt){a.pausedAt=Date.now();save(true);}else if(!account.user)save(true);});
setInterval(()=>{
 if(timer.running){const next=Math.max(0,Math.ceil((timer.end-Date.now())/1000));if(next!==timer.remaining){timer.remaining=next;$$('[data-timer-text]').forEach(el=>el.textContent=timeText(next));}if(next===0){timer.running=false;timer.finished=true;renderMiniTimer();toast('زمان استراحت تمام شد؛ وقتی آماده‌ای ادامه بده.');}}
 if($('#sessionClock'))$('#sessionClock').textContent=timeText(sessionSeconds());
 if(today()!==ui.seenDay){ui.seenDay=today();if(ui.view==='overview'||ui.view==='nutrition')refresh();}
},250);
$$('.sidebar .nav-item').forEach(el=>el.setAttribute('aria-label',el.querySelector('span')?.textContent||el.textContent));
ui.selectedDay=0;onHash();checkApi();
