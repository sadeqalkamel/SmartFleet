<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SmartFleet | مراقبة تنفيذ الرحلات</title>
<style>
*{box-sizing:border-box}body{background:#091321;color:#eaf2ff;font:15px/1.8 Tahoma,Arial,sans-serif;margin:0}main{max-width:1180px;margin:30px auto;padding:0 18px}a{color:#7dd3fc}.panel{background:#13233a;border:1px solid #324560;border-radius:14px;padding:22px;margin:16px 0}.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.actions{display:flex;gap:12px;flex-wrap:wrap}.actions a{background:#1d4ed8;color:#fff;padding:8px 13px;border-radius:8px;text-decoration:none}.muted{color:#b7c8df}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}.route{background:#0a1727;border:1px solid #35465a;border-radius:12px;padding:16px}.bar{background:#243247;border-radius:20px;overflow:hidden;height:8px;margin:11px 0}.fill{background:#22c55e;height:100%;transition:width .2s}ul{padding-right:18px}li{margin:4px 0;overflow-wrap:anywhere}.badge{display:inline-block;background:#30425b;border-radius:6px;padding:0 8px}.error{color:#ffabab}button{background:#0369a1;border:0;color:#fff;border-radius:9px;padding:9px 18px;cursor:pointer;font:inherit}
</style>
</head><body><main>
<div class="top"><div><a href="{{ url('/admin') }}">← لوحة الإدارة</a><h1>مراقبة تنفيذ الرحلات</h1><p class="muted">حالة الرحلات ونسبة الطلبات المسلّمة. تحديث تلقائي كل 15 ثانية.</p></div><div class="actions"><a href="{{ url('/admin/multi-dispatch') }}">توزيع الطلبات</a><a href="{{ url('/admin/optimization-workbench') }}">ترتيب المسارات</a><button id="refresh" type="button">تحديث الآن</button></div></div>
<div id="message" role="status" class="panel muted">جارٍ تحميل الرحلات...</div><div id="routes" class="grid"></div>
</main><script>
(()=>{
 const container=document.getElementById('routes'),message=document.getElementById('message');
 const names={planned:'مخططة',in_progress:'قيد التنفيذ',completed:'مكتملة'};
 const el=(tag,txt,cls)=>{const x=document.createElement(tag);x.textContent=txt;if(cls)x.className=cls;return x};
 let busy=false;
 async function refresh(){if(busy)return;busy=true;try{
 const res=await fetch(@json(route('admin.route-operations.data')),{credentials:'same-origin',headers:{'Accept':'application/json'}});
 if(!res.ok)throw Error('تعذر تحميل بيانات الرحلات');
 const data=await res.json();container.replaceChildren();
 for(const r of data.routes){
 const card=el('article','','route');card.appendChild(el('h3',`#${r.id} — ${r.number}`));
 card.appendChild(el('div',`الحالة: ${names[r.status]||r.status}`,'badge'));
 card.appendChild(el('p',`السائق: ${r.driver} | المركبة: ${r.vehicle_id}`));
 card.appendChild(el('p',`التاريخ: ${r.date} | تم التسليم: ${r.delivered_stops}/${r.total_stops}`));
 const bar=el('div','','bar'),fill=el('div','','fill');fill.style.width=(r.total_stops?(r.delivered_stops/r.total_stops*100):0)+'%';bar.appendChild(fill);card.appendChild(bar);
 const list=el('ul','');for(const s of r.stops)list.appendChild(el('li',`${s.sequence}. ${s.order_number} — ${s.status==='completed'?'تم التسليم':'بانتظار التسليم'}`));card.appendChild(list);container.appendChild(card);
 }
 message.textContent=`عدد الرحلات المعروضة: ${data.routes.length} — آخر تحديث ${new Date().toLocaleTimeString('ar')}`;message.className='panel muted';
 }catch(e){message.textContent=e.message;message.className='panel error'}finally{busy=false}}
 document.getElementById('refresh').onclick=refresh;
 refresh();setInterval(()=>{if(!document.hidden)refresh()},15000);
})();
</script></body></html>
