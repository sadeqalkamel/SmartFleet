<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SmartFleet | اعتماد ترتيب الرحلات</title>
    <style>
        * { box-sizing:border-box } body { margin:0; background:#0b1220; color:#e2e8f0; font-family:Tahoma,Arial,sans-serif; line-height:1.7 }
        main {max-width:940px;margin:30px auto;padding:0 18px} .panel {background:#142136;border:1px solid #26364e;border-radius:16px;padding:24px;margin:18px 0}
        h1 {margin:0 0 10px} h2 {font-size:18px} p,.muted {color:#adbed1}
        select,button,a.button {font:inherit;border-radius:9px;padding:12px 16px} select {background:#0d192b;color:white;border:1px solid #51607a;max-width:100%;width:100%}
        button,a.button {border:0;background:#0284c7;color:white;cursor:pointer;text-decoration:none;display:inline-block} button:disabled {opacity:.55;cursor:not-allowed}
        button.apply {background:#15803d} .warning {border-right:4px solid #f59e0b;padding:10px 14px;background:#292318} .error {color:#fca5a5}
        .stats {display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin:14px 0}
        .stat {padding:12px;background:#0d192b;border-radius:9px} .stat strong {display:block;font-size:23px;color:white}
        .orders {direction:ltr;text-align:left;overflow-wrap:anywhere;background:#0d192b;padding:12px;border-radius:9px}
        .actions {display:flex;flex-wrap:wrap;gap:12px;margin-top:18px}
    </style>
</head>
<body>
<main>
    <a href="{{ url('/admin') }}" style="color:#7dd3fc">العودة إلى لوحة الإدارة</a>
    <div class="panel">
        <h1>تحسين واعتماد ترتيب التوصيل</h1>
        <p>محرك OSRM + OR-Tools | اعتماد ترتيب الطلبات داخل رحلة واحدة فقط</p>
        <div class="warning">هذه الحزمة لا تنقل الطلبات بين المركبات، ولا تعدّل الرحلات التي بدأت بالفعل. يجب أن تكون جميع نقاط الرحلة في حالة انتظار.</div>
        <h2>اختر الرحلة المخططة</h2>
        <select id="route_id">
            @forelse($routes as $route)
                <option value="{{ $route->id }}">{{ $route->route_number }} — المركبة {{ $route->vehicle_id }} — {{ $route->stops_count }} طلب</option>
            @empty
                <option value="">لا توجد رحلات مخططة</option>
            @endforelse
        </select>
        <div class="actions"><button id="preview" @if($routes->isEmpty()) disabled @endif>حساب أفضل ترتيب</button></div>
    </div>
    <div class="panel" id="resultPanel" hidden>
        <h2>نتيجة التحسين</h2>
        <div id="result"></div>
        <div class="actions"><button id="apply" class="apply" disabled>اعتماد الترتيب وحفظه</button></div>
    </div>
    <div class="panel" id="message" role="status" aria-live="polite">اختر رحلة واضغط «حساب أفضل ترتيب».</div>
</main>
<script>
(() => {
    const routeSelect = document.getElementById('route_id');
    const previewBtn = document.getElementById('preview');
    const applyBtn = document.getElementById('apply');
    const result = document.getElementById('result');
    const panel = document.getElementById('resultPanel');
    const message = document.getElementById('message');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let current = null;
    let busy = false;
    const readable = (value) => String(value ?? '—');

    function status(value, error = false) {
        message.textContent = value;
        message.className = error ? 'panel error' : 'panel';
    }
    function setBusy(state) {
        busy = state;
        previewBtn.disabled = state || !routeSelect.value;
        applyBtn.disabled = state || !current;
    }
    async function post(url, payload) {
        const response = await fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN':csrf },
            body: JSON.stringify(payload)
        });
        const body = await response.json();
        if (!response.ok) {
            const errors = body.errors ? Object.values(body.errors).flat().join(' | ') : null;
            throw new Error(errors || body.message || 'تعذر تنفيذ العملية');
        }
        return body;
    }
    routeSelect.addEventListener('change', () => {
        current = null; panel.hidden = true; applyBtn.disabled = true;
        status('اختر «حساب أفضل ترتيب» لعرض نتيجة هذه الرحلة.');
    });
    previewBtn.addEventListener('click', async () => {
        if (busy) return;
        current = null; panel.hidden = true; setBusy(true);
        status('جاري حساب المسارات عبر شبكة الطرق...');
        try {
            const data = await post(@json(route('admin.optimization-workbench.preview')), { route_id: Number(routeSelect.value) });
            const optimization = data.result;
            const summary = optimization.routes[0];
            const div = document.createElement('div');
            div.innerHTML = '<div class="stats"><div class="stat">مسافة القيادة<strong id="distance"></strong></div><div class="stat">زمن القيادة<strong id="duration"></strong></div><div class="stat">حمولة المركبة<strong id="load"></strong></div></div><p>ترتيب الطلبات الأصلي:</p><div class="orders" id="original"></div><p>الترتيب المقترح:</p><div class="orders" id="optimized"></div>';
            div.querySelector('#distance').textContent = (optimization.total_distance_meters/1000).toFixed(2)+' كم';
            div.querySelector('#duration').textContent = (optimization.total_duration_seconds/60).toFixed(1)+' دقيقة';
            div.querySelector('#load').textContent = readable(summary.load_kg)+' كجم';
            div.querySelector('#original').textContent = data.original_order_ids.join(' → ');
            div.querySelector('#optimized').textContent = data.optimized_order_ids.join(' → ');
            result.replaceChildren(div);
            panel.hidden = false; current = data;
            status('المعاينة جاهزة. لن يتم الحفظ إلا بعد الضغط على زر الاعتماد.');
        } catch (e) { status(e.message, true); }
        finally { setBusy(false); }
    });
    applyBtn.addEventListener('click', async () => {
        if (busy || !current) return;
        if (!confirm('اعتماد ترتيب الطلبات الجديد في قاعدة البيانات؟')) return;
        setBusy(true); status('جاري إعادة التحقق من التحسين وحفظ الترتيب...');
        try {
            const data = await post(@json(route('admin.optimization-workbench.apply')), {
                route_id: current.route_id,
                snapshot: current.snapshot,
                optimized_order_ids: current.optimized_order_ids,
            });
            status('تم حفظ ترتيب الطلبات للرحلة رقم '+data.route_id+' بنجاح. حدّث الخريطة لمشاهدة الترتيب.');
            current = null; applyBtn.disabled = true; panel.hidden = true;
        } catch (e) { status(e.message, true); }
        finally { setBusy(false); }
    });
})();
</script>
</body>
</html>
