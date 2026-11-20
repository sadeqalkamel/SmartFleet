<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>SmartFleet | التوزيع متعدد المركبات</title>
<style>
*{box-sizing:border-box}body{background:#091321;color:#e8f1ff;font:16px/1.8 Tahoma,Arial,sans-serif;margin:0}
main{max-width:1020px;margin:35px auto;padding:0 18px}.panel{background:#122138;border:1px solid #263952;padding:23px;border-radius:15px;margin-bottom:18px}
input,button{font:inherit;border-radius:8px;padding:10px 13px}input{background:#081522;color:#fff;border:1px solid #526682;width:100%;direction:ltr;text-align:left}
button{border:0;background:#0284c7;color:white;cursor:pointer}button:disabled{opacity:.45;cursor:not-allowed}button.approve{background:#15803d}a{color:#7dd3fc}
.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}.card{background:#0a192b;border:1px solid #33445c;padding:17px;border-radius:11px;margin:12px 0}
.metrics{display:flex;gap:15px;flex-wrap:wrap}.metric{background:#0a192b;padding:14px 20px;border-radius:9px}small,.muted{color:#adc0d3}.warn{background:#342716;border-right:4px solid #f59e0b;padding:12px;border-radius:8px}.error{color:#ffb2b2}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
</style>
</head><body><main>
<a href="{{ url('/admin') }}">← العودة للوحة الإدارة</a>
<section class="panel"><h1>التوزيع الذكي على عدة مركبات</h1>
<p class="muted">إنشاء رحلات جديدة للطلبات المعلقة غير المسندة باستخدام OSRM + OR-Tools.</p>
<div class="warn">معاينة أولًا، ثم اعتماد يدوي. يستخدم النظام السائقين والمركبات المتاحة، ويربطهما تلقائيًا بترتيب المعرفات. تأكد من صحة الموقع والتاريخ قبل الاعتماد. لا يشمل زمن الخدمة أو مواعيد التسليم بعد. الحد التجريبي 15 طلبًا.</div>
<div class="fields">
<label>تاريخ الرحلة<input id="date" type="date" value="{{ now()->toDateString() }}"></label>
<label>خط عرض المستودع<input id="lat" type="number" step="any" value="15.5250"></label>
<label>خط طول المستودع<input id="lng" type="number" step="any" value="44.3250"></label>
</div><div class="actions"><button id="preview">حساب توزيع المركبات</button></div></section>
<section id="resultPanel" class="panel" hidden><h2>نتيجة التوزيع</h2><div id="summary" class="metrics"></div><div id="routes"></div>
<div class="actions"><button id="apply" class="approve" disabled>اعتماد وإنشاء الرحلات</button></div></section>
<section id="message" class="panel" role="status" aria-live="polite">اضغط حساب التوزيع لبدء المعاينة.</section>
</main><script>
(() => {
 const csrf=document.querySelector('meta[name="csrf-token"]').content;
 const button=document.getElementById('preview'),apply=document.getElementById('apply');
 const panel=document.getElementById('resultPanel'),message=document.getElementById('message');
 const routes=document.getElementById('routes'),summary=document.getElementById('summary');
 const fields=['date','lat','lng'].map(id=>document.getElementById(id));
 let current=null, busy=false;
 function status(txt,bad=false){message.textContent=txt;message.className=bad?'panel error':'panel'}
 function locked(value){busy=value;button.disabled=value;apply.disabled=value||!current;fields.forEach(x=>x.disabled=value)}
 async function post(url,data){
  const res=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(data)});
  let body;try{body=await res.json()}catch{throw Error('استجابة غير صالحة من الخادم')}
  if(!res.ok){const errors=body.errors?Object.values(body.errors).flat().join(' | '):null;throw Error(errors||body.message||'فشل الطلب')}
  return body;
 }
 const node=(tag,text,cls)=>{const el=document.createElement(tag);el.textContent=text;if(cls)el.className=cls;return el};
 fields.forEach(x=>x.addEventListener('input',()=>{current=null;apply.disabled=true;panel.hidden=true}));
 button.onclick=async()=>{
  if(busy)return;current=null;panel.hidden=true;locked(true);status('يجري الاستعلام عن الطرق وتحسين التوزيع...');
  try{
   const data=await post(@json(route('admin.multi-dispatch.preview')),{route_date:fields[0].value,latitude:Number(fields[1].value),longitude:Number(fields[2].value)});
   current=data;summary.replaceChildren();routes.replaceChildren();
   for(const value of [`عدد الطلبات: ${data.orders_count}`,`المسافة: ${(data.total_distance_meters/1000).toFixed(2)} كم`,`زمن القيادة: ${(data.total_duration_seconds/60).toFixed(1)} دقيقة`])summary.appendChild(node('div',value,'metric'));
   data.dispatch.forEach((item,index)=>{
    const el=node('div','', 'card');
    el.appendChild(node('h3',`الرحلة ${index+1} | السائق ${item.driver_id} | المركبة ${item.vehicle_id}`));
    el.appendChild(node('p',`الطلبات بالترتيب: ${item.order_ids.join(' ← ')}`));
    el.appendChild(node('p',`الحمولة: ${item.load_kg} كجم | المسافة: ${(item.distance_meters/1000).toFixed(2)} كم | الزمن: ${(item.duration_seconds/60).toFixed(1)} دقيقة`));
    routes.appendChild(el);
   });
   panel.hidden=false;status('تمت المعاينة فقط. راجع السائقين والمركبات والطلبات قبل الحفظ.');
  }catch(e){status(e.message,true)}finally{locked(false)}
 };
 apply.onclick=async()=>{
  if(!current||busy)return;
  if(!confirm('هل تعتمد التوزيع وتُنشئ رحلات جديدة في قاعدة البيانات؟'))return;
  locked(true);status('جارٍ التحقق النهائي وحفظ الرحلات...');
  try{
   const result=await post(@json(route('admin.multi-dispatch.apply')),{token:current.token});
   current=null;apply.disabled=true;
   status(`تم إنشاء ${result.routes.length} رحلة بنجاح. معرفات الرحلات: ${result.routes.map(x=>x.route_id).join('، ')}. حدّث الخريطة لرؤيتها.`);
   panel.hidden=true;
  }catch(e){current=null;panel.hidden=true;status(e.message+' — أعد المعاينة.',true)}finally{locked(false)}
 };
})();
</script></body></html>
