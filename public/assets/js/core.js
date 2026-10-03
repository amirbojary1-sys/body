const $=(s,r=document)=>r.querySelector(s);
const $$=(s,r=document)=>[...r.querySelectorAll(s)];
const icon=(n,c='')=>`<svg class="icon ${c}" aria-hidden="true"><use href="#i-${n}"/></svg>`;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fa=(v,dp=0)=>Number(v).toLocaleString('fa-IR',{maximumFractionDigits:dp});
const clamp=(n,a,b)=>Math.min(b,Math.max(a,n));
const num=(x,a,b,d)=>Number.isFinite(Number(x))&&x!==null&&x!==''?clamp(Number(x),a,b):d;
const text=(x,max=100)=>typeof x==='string'?x.slice(0,max):'';
const uid=()=>Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,8);
const dateKey=(d=new Date())=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
const today=()=>dateKey();
const parseDate=s=>new Date(s+'T12:00:00');
const validDate=s=>typeof s==='string'&&/^\d{4}-\d{2}-\d{2}$/.test(s)&&!Number.isNaN(parseDate(s).getTime())&&dateKey(parseDate(s))===s&&s<='2100-12-31'&&s>='2000-01-01';
const friendlyDate=(d=new Date(),full=false)=>new Date(d).toLocaleDateString('fa-IR',full?{weekday:'long',day:'numeric',month:'long'}:{day:'numeric',month:'short'});
const timeText=s=>`${fa(Math.floor(Math.max(0,s)/60)).padStart(2,'۰')}:${fa(Math.max(0,Math.floor(s))%60).padStart(2,'۰')}`;
const plainDigits=s=>String(s).replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d));
const norm=s=>String(s).replace(/[يى]/g,'ی').replace(/ك/g,'ک').replace(/[\u200c\u200f]/g,' ').replace(/\s+/g,' ').toLowerCase().trim();
const STORAGE_KEY='fitbot.php.guest.v1';
let storageAvailable=true,storageWarned=false;
const freshState=()=>({version:2,profile:null,water:{},weights:[],sessions:[],favorites:[],meals:{},mealChoices:[0,0,0,0],diet:'regular',events:[],seenEventsAt:0,chat:{coordinator:[],trainer:[],nutrition:[],recovery:[]},settings:{theme:'dark',reduced:false,waterGoal:8},activeSession:null});
function sanitizeProfile(p){
 if(!p||typeof p!=='object')return null;
 return {name:text(p.name,32).trim(),gender:p.gender==='female'?'female':'male',age:num(p.age,18,85,25),height:num(p.height,120,230,175),weight:num(p.weight,35,250,75),activity:[1.2,1.375,1.55,1.725,1.9].includes(Number(p.activity))?Number(p.activity):1.55,goal:Object.hasOwn(GOALS,p.goal)?p.goal:'maintain',days:[2,3,4,5].includes(Number(p.days))?Number(p.days):3,equipment:Object.hasOwn(EQUIPMENT,p.equipment)?p.equipment:'home',level:p.level==='intermediate'?'intermediate':'beginner',duration:[20,35,50,60].includes(Number(p.duration))?Number(p.duration):35};
}
function sanitizeAction(a){
 if(!a||typeof a!=='object')return null;
 const allowed=['planner','calculator','nutrition','progress','recovery','log_water','start_timer','update_goal'];
 if(!allowed.includes(a.type))return null;
 const args={};
 if(a.type==='log_water')args.glasses=Math.round(num(a.args?.glasses,1,2,1));
 if(a.type==='start_timer')args.seconds=Math.round(num(a.args?.seconds,30,180,60));
 if(a.type==='update_goal'){if(!Object.hasOwn(GOALS,a.args?.goal))return null;args.goal=a.args.goal;}
 return {id:text(a.id,80)||uid(),type:a.type,args,done:a.done===true};
}
function cleanState(raw){
 const s=freshState();if(!raw||raw.version!==2)throw new Error('نسخه فایل پشتیبان معتبر نیست.');
 s.profile=sanitizeProfile(raw.profile);
 const daily=(obj,fn)=>{const out={};if(obj&&typeof obj==='object'&&!Array.isArray(obj))Object.keys(obj).filter(validDate).sort().slice(-365).forEach(k=>{out[k]=fn(obj[k]);});return out;};
 s.water=daily(raw.water,v=>Math.round(num(v,0,20,0)));
 s.weights=(Array.isArray(raw.weights)?raw.weights:[]).filter(w=>w&&validDate(w.date)&&w.date<=today()&&Number.isFinite(w.value)&&w.value>=35&&w.value<=250).slice(-1000).map(w=>({date:w.date,value:Math.round(w.value*10)/10,note:text(w.note,120)}));
 s.weights=[...new Map(s.weights.map(w=>[w.date,w])).values()].sort((a,b)=>a.date.localeCompare(b.date));
 s.sessions=(Array.isArray(raw.sessions)?raw.sessions:[]).filter(v=>v&&Number.isFinite(v.endedAt)&&v.endedAt>0).slice(-1000).map(v=>({id:text(v.id,80)||uid(),name:text(v.name,80),startedAt:num(v.startedAt,0,Date.now()+86400000,Date.now()),endedAt:num(v.endedAt,0,Date.now()+86400000,Date.now()),duration:Math.round(num(v.duration,0,21600,0)),count:Math.round(num(v.count,1,10,4)),dayIndex:Math.round(num(v.dayIndex,0,6,0))}));
 s.favorites=(Array.isArray(raw.favorites)?raw.favorites:[]).filter(id=>Object.hasOwn(EX,id)).slice(0,EXERCISES.length);
 s.meals=daily(raw.meals,rows=>(Array.isArray(rows)?rows:[]).filter(m=>m&&Number.isInteger(m.slot)&&m.slot>=0&&m.slot<4).slice(0,4).map(m=>({slot:m.slot,id:text(m.id,40),name:text(m.name,80),kcal:num(m.kcal,0,4000,0),p:num(m.p,0,300,0),c:num(m.c,0,600,0),f:num(m.f,0,200,0),ingredients:(Array.isArray(m.ingredients)?m.ingredients:[]).filter(v=>Array.isArray(v)&&typeof v[0]==='string'&&Number.isFinite(v[1])).slice(0,12).map(v=>[text(v[0],70),num(v[1],0,3000,0)])})));
 s.mealChoices=Array.from({length:4},(_,i)=>raw.mealChoices?.[i]===1?1:0);s.diet=raw.diet==='vegetarian'?'vegetarian':'regular';
 s.events=(Array.isArray(raw.events)?raw.events:[]).filter(e=>e&&typeof e.message==='string').slice(-40).map(e=>({message:text(e.message,200),at:num(e.at,0,Date.now()+86400000,Date.now()),type:['check','drop','dumbbell','scale','settings','spark','leaf'].includes(e.type)?e.type:'check'}));
 s.seenEventsAt=num(raw.seenEventsAt,0,Date.now()+86400000,0);
 Object.keys(ROLES).forEach(role=>{s.chat[role]=(Array.isArray(raw.chat?.[role])?raw.chat[role]:[]).filter(m=>m&&['user','assistant'].includes(m.role)&&typeof m.content==='string').slice(-40).map(m=>({id:text(m.id,80)||uid(),role:m.role,content:text(m.content,6000),at:num(m.at,0,Date.now()+86400000,Date.now()),mode:m.mode==='cloud'?'cloud':'local',actions:(Array.isArray(m.actions)?m.actions:[]).slice(0,3).map(sanitizeAction).filter(Boolean)}));});
 s.settings={theme:raw.settings?.theme==='light'?'light':'dark',reduced:raw.settings?.reduced===true,waterGoal:Math.round(num(raw.settings?.waterGoal,4,16,8))};
 const a=raw.activeSession;
 if(a&&Array.isArray(a.items)&&a.items.length&&Number.isFinite(a.startedAt)&&Date.now()-a.startedAt<86400000){
  const items=a.items.filter(it=>Object.hasOwn(EX,it?.id)).slice(0,8).map(it=>({id:it.id,sets:Math.round(num(it.sets,1,4,2)),reps:text(it.reps,40)||'۸–۱۲'}));
  if(items.length)s.activeSession={id:text(a.id,80)||uid(),name:text(a.name,80),dayIndex:Math.round(num(a.dayIndex,0,6,0)),items,startedAt:a.startedAt,pausedMs:num(a.pausedMs,0,86400000,0),pausedAt:Number.isFinite(a.pausedAt)?a.pausedAt:Date.now(),checked:items.map((_,i)=>a.checked?.[i]===true)};
 }
 return s;
}
function loadLocalData(){try{const raw=localStorage.getItem(STORAGE_KEY);localStorage.setItem('fitbot.test','1');localStorage.removeItem('fitbot.test');return raw?cleanState(JSON.parse(raw)):freshState();}catch(e){storageAvailable=false;return freshState();}}
function loadData(){if(account.user){try{return PHP_BOOT.state?cleanState(PHP_BOOT.state):freshState();}catch{return freshState();}}return loadLocalData();}
let state=loadData();
let ui={view:'overview',selectedDay:0,role:'coordinator',filter:'all',equipment:'all',search:'',onlyFavorites:false,range:30,modal:null,wizardStep:0,draft:null,calc:null,commandIndex:0,commandQuery:'',aiMode:'local',busy:false,api:{available:false,checked:false},seenDay:today()};
let timer={total:60,remaining:60,end:0,running:false,visible:false,finished:false};
let particleCleanup=()=>{};
let pendingController=null;
const getProfile=()=>state.profile||DEFAULT_PROFILE;
const reduced=()=>state.settings.reduced||matchMedia('(prefers-reduced-motion: reduce)').matches;
function save(silent=false){if(account.user){queueServerSave();updateChrome();return;}try{localStorage.setItem(STORAGE_KEY,JSON.stringify(state));storageAvailable=true;}catch(e){storageAvailable=false;if(!silent&&!storageWarned){storageWarned=true;toast('ذخیره دائمی در این پیش‌نمایش محدود است؛ فایل را در مرورگر عادی باز کن یا خروجی پشتیبان بگیر.','warning',7500);}}updateChrome();}
function addEvent(message,type='check'){state.events.push({message,type,at:Date.now()});state.events=state.events.slice(-40);save();}
function updateChrome(){
 const el=$('#storageLabel');if(el)el.textContent=storageAvailable?'داده‌ها، فقط روی دستگاه تو':'حالت موقت · ذخیره‌سازی محدود';
 $('#profileAvatar').textContent=state.profile?.name?.slice(0,1)||'ش';
 $('#notificationDot').hidden=!state.events.some(e=>e.at>state.seenEventsAt);
 document.documentElement.dataset.theme=state.settings.theme;document.documentElement.dataset.reduced=String(state.settings.reduced);updateAccountChrome();
}
function estimate(p=getProfile()){
 if(phpCalculation&&phpCalculation.key===phpProfileKey(p))return {...phpCalculation.estimates};
 const bmr=10*p.weight+6.25*p.height-5*p.age+(p.gender==='male'?5:-161);
 const tdee=bmr*p.activity,bmi=p.weight/((p.height/100)**2);
 const effectiveGoal=bmi<18.5&&p.goal==='lose'?'maintain':p.goal;
 const base=tdee*(effectiveGoal==='lose'?.85:effectiveGoal==='gain'?1.08:1);
 const floor=p.gender==='male'?1500:1200;
 const target=Math.round(Math.max(base,floor)/10)*10;
 const pG=Math.round(Math.min(p.weight*(effectiveGoal==='gain'?1.8:1.6),target*.32/4));
 const fG=Math.round(target*.27/9),cG=Math.max(0,Math.round((target-pG*4-fG*9)/4));
 return {bmr:Math.round(bmr),tdee:Math.round(tdee),target,protein:pG,carbs:cG,fat:fG,bmi,effectiveGoal,floored:base<floor,lowBMI:bmi<18.5};
}
function makePlan(p=getProfile()){
 if(phpCalculation&&phpCalculation.key===phpProfileKey(p))return structuredClone(phpCalculation.plan);
 const beginner=p.level==='beginner';
 const push=beginner?'incline':'pushup';
 let full,upper,lower;
 if(p.equipment==='home'){
  full=[['squat',push,'bird','bridge','deadbug','calf'],['lunge',push,'bird','calf','plank','bridge'],['squat','bridge',push,'deadbug','bird','crunch']];upper=[push,'bird','deadbug','plank','crunch'];lower=['squat','bridge','lunge','calf','deadbug'];
 }else if(p.equipment==='dumbbell'){
  full=[['squat','dbbench','dbrow','bridge','curl','deadbug'],['lunge','dbbench','dbrow','calf','lateral','plank'],['squat','dbrow','dbbench',beginner?'bridge':'rdl','curl','crunch']];upper=['dbbench','dbrow','lateral','curl','plank','deadbug'];lower=['squat',beginner?'bridge':'rdl','lunge','calf','deadbug'];
 }else{
  full=[['legpress',beginner?'dbbench':'bench','latpull','bridge','curl','plank'],[beginner?'squat':'bbsquat','fly','latpull','calf','pushdown','deadbug'],['legpress','dbbench',beginner?'dbrow':'bbrow','lateral','curl','crunch']];upper=[beginner?'dbbench':'bench','latpull','lateral','curl','pushdown','plank'];lower=['legpress',beginner?'squat':'bbsquat','bridge','calf','deadbug'];
 }
 const schedules={2:[0,3],3:[0,2,4],4:[0,1,3,4],5:[0,1,3,4,5]};
 const count=p.duration===20?3:p.duration===35?4:p.duration===50?5:6;
 const sets=p.duration===20?2:beginner?(p.duration>=50?3:2):3;
 return schedules[p.days].map((dayIndex,i)=>{
  const recovery=p.days===5&&i===4;
  const name=recovery?'تحرک و میان‌تنه':p.days<=3?`فول‌بادی ${['A','B','C'][i]}`:`${i%2===0?'بالاتنه':'پایین‌تنه'} ${i<2?'A':'B'}`;
  const pool=recovery?['bird','deadbug','bridge','calf']:p.days<=3?full[i]:i%2===0?upper:lower;
  return {dayIndex,name,duration:recovery?20:p.duration,recovery,items:pool.slice(0,count).map(id=>({id,sets:recovery?2:sets,reps:EX[id].reps||(p.goal==='gain'?'۸–۱۲':'۱۰–۱۲')}))};
 });
}
function weeklySessions(){const d=new Date();d.setHours(0,0,0,0);d.setDate(d.getDate()-((d.getDay()+1)%7));return state.sessions.filter(s=>s.endedAt>=+d&&s.endedAt<+d+7*86400000);}
function dailyWater(){return state.water[today()]||0;}
function lastWeight(){return [...state.weights].filter(w=>w.date<=today()).sort((a,b)=>b.date.localeCompare(a.date))[0]||null;}
function mealsConsumed(){return (state.meals[today()]||[]).reduce((a,m)=>({kcal:a.kcal+m.kcal,p:a.p+m.p,c:a.c+m.c,f:a.f+m.f}),{kcal:0,p:0,c:0,f:0});}
function mealFor(slot){
 const raw=MEALS[state.diet][slot][state.mealChoices[slot]],base=raw.p*4+raw.c*4+raw.f*9;
 const f=estimate().target*MEAL_SHARES[slot]/base;
 const p=Math.round(raw.p*f),c=Math.round(raw.c*f),fat=Math.round(raw.f*f);
 return {...raw,slot,p,c,f:fat,kcal:p*4+c*4+fat*9,ingredients:raw.ingredients.map(([name,g])=>[name,Math.max(5,Math.round(g*f/5)*5)])};
}
function toast(message,type='success',duration=3900){
 const el=document.createElement('div');el.className='toast '+(type==='warning'?'warning':'');
 el.innerHTML=`${icon(type==='warning'?'info':'check')}<span>${esc(message)}</span><button class="icon-btn" aria-label="بستن پیام">${icon('close')}</button>`;
 el.querySelector('button').onclick=()=>el.remove();$('#toasts').append(el);
 if($('#toasts').children.length>3)$('#toasts').firstElementChild.remove();setTimeout(()=>el.remove(),duration);
}
function openModal(title,sub,body,footer='',cls=''){
 const d=$('#appDialog');d.className='app-dialog '+cls;
 $('#modalContent').innerHTML=`<div class="modal-head"><div><h2 id="modalTitle">${title}</h2>${sub?`<p>${sub}</p>`:''}</div><button class="icon-btn" data-action="close-modal" aria-label="بستن پنجره">${icon('close')}</button></div><div class="modal-body">${body}</div>${footer?`<div class="modal-foot">${footer}</div>`:''}`;
 if(!d.open)d.showModal();d.scrollTop=0;
 requestAnimationFrame(()=>{const input=d.querySelector('[autofocus]');if(input)input.focus();});
}
function closeModal(){const d=$('#appDialog');if(d.open)d.close();ui.modal=null;}
function askConfirm(title,description,onConfirm,button='تأیید',danger=false){
 const d=$('#confirmDialog');
 $('#confirmContent').innerHTML=`<div class="confirm-body">${icon(danger?'info':'shield')}<h2 id="confirmTitle">${esc(title)}</h2><p>${esc(description)}</p><div class="confirm-actions"><button class="btn ${danger?'btn-danger':'btn-primary'}" id="confirmYes">${esc(button)}</button><button class="btn" id="confirmNo" autofocus>انصراف</button></div></div>`;
 $('#confirmYes').onclick=()=>{d.close();onConfirm();};$('#confirmNo').onclick=()=>d.close();if(!d.open)d.showModal();$('#confirmNo').focus();
}
function go(view){if(!VIEW_NAMES[view])return;if(ui.view===view){render();closeMobile();}else location.hash=view;}
function refresh(){const y=window.scrollY;render();window.scrollTo({top:y,behavior:'instant'});}
function download(name,content,type='text/plain;charset=utf-8'){
 const blob=new Blob([content],{type}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),10000);
}
function actionButton(action,label,iconName='',extra='',style='btn'){return `<button class="${style}" data-action="${action}" ${extra}>${iconName?icon(iconName):''}${label}</button>`;}
function agentArt(){return `<div class="agent-art" aria-hidden="true"><div class="orbit-ring"></div><div class="orbit-ring second"></div><div class="orbit-ring third"></div><div class="orbit-track"></div><div class="agent-core">${icon('spark')}</div><span class="satellite s1">${icon('leaf')}</span><span class="satellite s2">${icon('moon')}</span><span class="satellite s3">${icon('dumbbell')}</span></div>`;}
function movement(art){
 const bodies={
 squat:'<circle class="head-shape" cx="95" cy="24" r="9"/><path class="body-line" d="m93 35-4 31 22 18-17 26m-5-44-24 18 12 26M91 43l-22 7-17-11"/><path class="accent-line" d="m89 66 22 18-17 26m-5-44-24 18 12 26"/>',
 lunge:'<circle class="head-shape" cx="90" cy="23" r="9"/><path class="body-line" d="m90 34 1 30-28 16 1 29m27-45 25 33 24-1M91 40l-22 16m22-14 23 13"/><path class="accent-line" d="m91 64-28 16 1 29m27-45 25 33 24-1"/>',
 pushup:'<circle class="head-shape" cx="137" cy="51" r="8"/><path class="body-line" d="m127 58-34 11-32 21-14 8m70-37 9 27 14 19M90 72l-23 26H45"/><path class="accent-line" d="m117 61 9 27 14 19"/>',
 plank:'<circle class="head-shape" cx="136" cy="63" r="8"/><path class="body-line" d="m126 70-45 8-36 27h-8m78-31 9 27h23"/><path class="accent-line" d="m81 78 45-8m-11 4 9 27h23"/>',
 bridge:'<circle class="head-shape" cx="46" cy="96" r="8"/><path class="body-line" d="m55 100 47-30 29 18-10 20h17m-81-8h27"/><path class="accent-line" d="m69 91 33-21 29 18-10 20"/>',
 bird:'<circle class="head-shape" cx="141" cy="57" r="8"/><path class="body-line" d="m130 64-43 8-29-11-22 2m85 3 7 41m-40-35-3 33h-15"/><path class="accent-line" d="m118 67 29-17 15-7M88 73 58 61l-22 2"/>',
 core:'<circle class="head-shape" cx="141" cy="97" r="8"/><path class="body-line" d="m130 103-40-3-22-26 9-22m13 48-35-14-15 6m80 11-14-42"/><path class="accent-line" d="m90 100-22-26 9-22m43 51-14-42"/>',
 bench:'<path class="floor-line" d="M46 86h112m-92 0v24m78-24v24"/><circle class="head-shape" cx="133" cy="74" r="8"/><path class="body-line" d="m123 80-38-2-26 15 4 18m25-33 14 15-9 17"/><path class="accent-line" d="m114 79 0-29 15-15m-18 43-20-25-3-18"/><path class="weight-shape" d="M76 29h24v8H76Zm39 0h27v8h-27Z"/>',
 row:'<circle class="head-shape" cx="132" cy="44" r="8"/><path class="body-line" d="m123 51-35 25 4 30m-4-30-28 28M114 57l-1 31-17 6"/><path class="accent-line" d="m114 57-1 31-17 6"/><path class="weight-shape" d="M85 91h21v8H85Z"/>',
 curl:'<circle class="head-shape" cx="100" cy="22" r="9"/><path class="body-line" d="M100 34v36l-16 38m16-38 16 38"/><g class="move-arms"><path class="accent-line" d="m97 43-21 22-12-10m39-12 20 22 13-10"/><path class="weight-shape" d="M53 48h23v8H53Zm71 0h23v8h-23Z"/></g>',
 press:'<circle class="head-shape" cx="100" cy="34" r="9"/><path class="body-line" d="M100 46v30l-17 33m17-33 18 33"/><path class="accent-line" d="m97 51-22-9V24m28 27 22-9V24"/><path class="weight-shape" d="M63 16h24v8H63Zm50 0h24v8h-24Z"/>',
 pull:'<path class="weight-shape" d="M62 14h76v5H62Z"/><circle class="head-shape" cx="100" cy="43" r="9"/><path class="body-line" d="M100 55v29l-14 26m14-26 14 26"/><path class="accent-line" d="m96 58-24-17V20m32 38 24-17V20"/>',
 standing:'<circle class="head-shape" cx="100" cy="22" r="9"/><path class="body-line" d="M100 34v35m-3-26L80 63m23-20 17 20"/><path class="accent-line" d="m100 69-13 36 8 4m5-40 13 36-8 4"/>'
 };
 return `<svg class="movement-svg" viewBox="0 0 200 125" aria-hidden="true"><path class="floor-line" d="M30 112h140"/><g class="move-body">${bodies[art]||bodies.standing}</g></svg>`;
}
function chart(range=ui.range,id='main',large=false){
 const end=parseDate(today());const start=new Date(end);start.setDate(start.getDate()-range+1);
 const data=state.weights.filter(w=>parseDate(w.date)>=start&&parseDate(w.date)<=end).sort((a,b)=>a.date.localeCompare(b.date));
 const w=600,h=large?245:190,left=35,right=575,top=20,bottom=h-30;
 let grid='';for(let j=0;j<4;j++){let y=top+(bottom-top)*j/3;grid+=`<line class="chart-grid-line" x1="${left}" y1="${y}" x2="${right}" y2="${y}"/>`;}
 if(!data.length)return `<div class="chart-wrap"><svg class="weight-chart" viewBox="0 0 ${w} ${h}" role="img" aria-label="هنوز وزنی برای این بازه ثبت نشده">${grid}<path class="chart-placeholder" d="M35 145C100 145 110 118 175 130S280 87 340 100S450 60 575 64"/></svg><div class="chart-empty-caption"><span>اولین قدم، شناختن نقطه شروعه.</span>${actionButton('weight','اولین وزنم را ثبت می‌کنم','plus','','btn btn-soft small')}</div></div>`;
 const values=data.map(d=>d.value),min=Math.floor(Math.min(...values)-1),max=Math.ceil(Math.max(...values)+1),timeSpan=Math.max(1,+end-+start);
 const pts=data.map(d=>({x:left+(+parseDate(d.date)-+start)/timeSpan*(right-left),y:bottom-(d.value-min)/(max-min)*(bottom-top),d}));
 const path=pts.map((p,i)=>`${i?'L':'M'}${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' '),area=`${path} L${pts.at(-1).x},${bottom} L${pts[0].x},${bottom} Z`;
 const ys=[min,(min+max)/2,max].map(v=>`<text class="chart-axis" x="29" y="${bottom-(v-min)/(max-min)*(bottom-top)+3}" text-anchor="end">${fa(v,1)}</text>`).join('');
 return `<div class="chart-wrap"><svg class="weight-chart" viewBox="0 0 ${w} ${h}" role="img" aria-label="نمودار وزن، ${fa(data.length)} ثبت در ${fa(range)} روز"><defs><linearGradient id="area-${id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#ff7846" stop-opacity=".2"/><stop offset="100%" stop-color="#ff7846" stop-opacity="0"/></linearGradient></defs>${grid}${ys}${pts.length>1?`<path d="${area}" fill="url(#area-${id})"/><path class="chart-path" d="${path}"/>`:''}${pts.map(p=>`<circle class="chart-point" cx="${p.x}" cy="${p.y}" r="3.5"><title>${esc(friendlyDate(parseDate(p.d.date)))}: ${fa(p.d.value,1)} کیلوگرم</title></circle>`).join('')}<text x="${left}" y="${h-8}" class="chart-axis" text-anchor="start">${friendlyDate(start)}</text><text x="${right}" y="${h-8}" class="chart-axis" text-anchor="end">امروز</text></svg></div>`;
}
function donutMarkup(){const c=mealsConsumed(),t=estimate(),pc=clamp(c.kcal/t.target,0,1),circ=326.73;return `<div class="donut"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="track" cx="60" cy="60" r="52"/><circle class="progress" cx="60" cy="60" r="52" stroke-dasharray="${circ}" stroke-dashoffset="${circ*(1-pc)}"/></svg><strong>${fa(c.kcal)}</strong><small>از ${fa(t.target)} کیلوکالری</small></div><div class="macro-list">${[['پروتئین',c.p,t.protein,'var(--accent)'],['کربوهیدرات',c.c,t.carbs,'var(--blue)'],['چربی',c.f,t.fat,'var(--green)']].map(([l,v,total,color])=>`<div><div class="macro-row"><span class="macro-dot" style="background:${color}"></span>${l}<b>${fa(v)} / ${fa(total)} g</b></div><div class="macro-track"><div class="macro-fill" style="width:${clamp(v/total*100,0,100)}%;background:${color}"></div></div></div>`).join('')}</div>`;}
function activeSessionBanner(){return state.activeSession?`<div class="active-session-banner">${icon('play')}<div><strong>یک جلسه نیمه‌تمام داری</strong><p>${esc(state.activeSession.name)} · پیشرفتت نگه داشته شده</p></div>${actionButton('continue-session','ادامه جلسه','arrow','','btn btn-soft small')}</div>`:'';}
function safetyNote(){return `<div class="safety-note">${icon('shield')}<span>این ابزار برای راهنمایی عمومی بزرگسالان است، نه تشخیص یا نسخه پزشکی. در بارداری، آسیب‌دیدگی، بیماری یا محدودیت غذایی، برنامه را با متخصص هماهنگ کن. در صورت درد، تمرین را متوقف کن.</span></div>`;}
