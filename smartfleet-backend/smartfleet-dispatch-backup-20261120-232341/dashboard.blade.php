<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <meta name="csrf-token"
          content="{{ csrf_token() }}">

    <title>SmartFleet | لوحة الإدارة</title>

    @vite(['resources/js/app.js'])

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #0b1220;
            color: #f1f5f9;
            font-family: Tahoma, Arial, sans-serif;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 32px;
            background: #111c30;
            border-bottom: 1px solid #334155;
        }

        .brand {
            font-size: 25px;
            font-weight: bold;
            color: #38bdf8;
        }

        .container {
            max-width: 1150px;
            margin: 40px auto;
            padding: 0 20px;
        }

        h1 { font-size: 26px; }

        .subtitle { color: #94a3b8; }

        .cards {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .card {
            background: #172236;
            padding: 24px;
            border: 1px solid #334155;
            border-radius: 14px;
        }

        .card h3 {
            margin: 0 0 18px;
            color: #94a3b8;
            font-size: 15px;
        }

        .value {
            font-size: 23px;
            font-weight: bold;
            direction: ltr;
            text-align: right;
        }

        .success { color: #4ade80; }
        .warning { color: #facc15; }
        .error { color: #f87171; }

        .activity {
            margin-top: 28px;
            background: #172236;
            border-radius: 14px;
            border: 1px solid #334155;
            padding: 24px;
        }

        #events {
            margin-top: 20px;
            display: grid;
            gap: 12px;
        }

        .event {
            background: #0f172a;
            border-radius: 8px;
            padding: 14px;
            border-right: 3px solid #38bdf8;
            overflow-wrap: anywhere;
        }

        button {
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 18px;
            cursor: pointer;
        }

        @media (max-width: 600px) {
            header { padding: 16px; }
            .brand { font-size: 20px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>

<body>

<header>
    <div class="brand">SmartFleet</div>

    <form method="POST"
          action="{{ route('admin.logout') }}">
        @csrf
        <button type="submit">تسجيل الخروج</button>
    </form>
</header>

<main class="container">

    <h1>لوحة إدارة الأسطول</h1>

    <p class="subtitle">
        مرحبًا، {{ auth()->user()->name }}
        — مراقبة تحديثات السائقين لحظيًا
    </p>
    <div style="margin:22px 0;padding:20px;background:#0f172a;color:white;border-radius:14px">
    <h2 style="margin-top:0">🚚 تحسين مسارات التوصيل</h2>
    <p style="color:#cbd5e1">
        تحسين المسارات باستخدام OSRM + Google OR-Tools
    </p>

    <button id="optimizeRouteBtn" type="button"
        style="background:#0284c7;color:white;border:0;border-radius:10px;padding:12px 24px;cursor:pointer;font-weight:bold">
        تحسين المسارات الآن
    </button>

    <div id="optimizationResult"
        style="margin-top:18px;white-space:pre-wrap;line-height:2">
    </div>
</div>

<script>
(() => {
    const button = document.getElementById('optimizeRouteBtn');
    const output = document.getElementById('optimizationResult');

    button.addEventListener('click', async () => {
        button.disabled = true;
        button.textContent = 'جاري تحسين المسارات...';
        output.textContent = 'جاري حساب مسافات وأزمنة القيادة...';

        try {
            const response = await fetch(
                '{{ route("admin.optimization.preview") }}',
                {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'فشل تحسين المسارات');
            }

            const result = data.optimization;

            const lines = [
                '✅ اكتمل تحسين المسارات',
                '',
                `رقم الرحلة: ${data.route_id}`,
                `المسافة: ${(result.total_distance_meters / 1000).toFixed(2)} كم`,
                `زمن القيادة: ${(result.total_duration_seconds / 60).toFixed(1)} دقيقة`,
                ''
            ];

            result.routes.forEach((route, index) => {
                lines.push(
                    `🚚 المركبة ${index + 1} — رقم ${route.vehicle_id}`,
                    `ترتيب الطلبات: ${route.order_ids.join(' ← ') || 'لا توجد طلبات'}`,
                    `الحمولة: ${route.load_kg} كجم`,
                    `المسافة: ${(route.distance_meters / 1000).toFixed(2)} كم`,
                    `الزمن: ${(route.duration_seconds / 60).toFixed(1)} دقيقة`,
                    ''
                );
            });

            lines.push('🔒 هذه معاينة فقط، لم تتغير بيانات الرحلة.');

            output.textContent = lines.join('\n');

        } catch (error) {
            output.textContent = '❌ ' + error.message;
        } finally {
            button.disabled = false;
            button.textContent = 'تحسين المسارات الآن';
        }
    });
})();
</script>

    <div class="cards">

        <div class="card">
            <h3>حالة الاتصال</h3>
            <div id="connection-status"
                 class="value warning">
                جاري الاتصال...
            </div>
        </div>

        <div class="card">
            <h3>عدد التحديثات المستلمة</h3>
            <div id="updates-count" class="value">
                0
            </div>
        </div>

        <div class="card">
            <h3>آخر سائق أرسل موقعه</h3>
            <div id="last-driver" class="value">
                —
            </div>
        </div>

        <div class="card">
            <h3>آخر إحداثيات</h3>
            <div id="last-coordinates" class="value">
                —
            </div>
        </div>

    </div>

<section class="activity">

    <h2>خريطة تتبع السائقين المباشرة</h2>

    <p class="subtitle">
        عدد السائقين الظاهرين:
        <strong id="tracked-drivers-count">0</strong>
    </p>

    <div
        id="fleet-map"
        style="
            width: 100%;
            height: 460px;
            border-radius: 12px;
            overflow: hidden;
        "
    ></div>

</section>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const status = document.getElementById(
        'connection-status'
    );

    const countElement = document.getElementById(
        'updates-count'
    );

    const lastDriver = document.getElementById(
        'last-driver'
    );

    const coordinates = document.getElementById(
        'last-coordinates'
    );

    const events = document.getElementById('events');

    let count = 0;

    if (!window.Echo) {
        status.textContent = 'Echo غير جاهز';
        status.className = 'value error';
        return;
    }

    const pusher = window.Echo.connector.pusher;

    pusher.connection.bind('connected', function () {
        status.textContent = 'متصل بالخادم';
        status.className = 'value warning';
    });

    pusher.connection.bind('disconnected', function () {
        status.textContent = 'انقطع الاتصال';
        status.className = 'value error';
    });

    pusher.connection.bind('error', function () {
        status.textContent = 'خطأ في الاتصال';
        status.className = 'value error';
    });

    const channel = window.Echo.private('fleet.admin');

    const rawChannel = pusher.channel('private-fleet.admin');

    if (rawChannel) {
        rawChannel.bind('pusher:subscription_succeeded', function () {
            status.textContent = 'متصل ومصرح';
            status.className = 'value success';
        });

        rawChannel.bind('pusher:subscription_error', function () {
            status.textContent = 'فشلت صلاحية القناة';
            status.className = 'value error';
        });
    }

    channel.listen('.driver.location.updated', function (data) {

    console.log('GPS RECEIVED:', data);

    // Send GPS data to map immediately
    window.dispatchEvent(
        new CustomEvent('smartfleet:location-updated', {
            detail: data
        })
    );

    // Update dashboard cards
    count++;
    countElement.textContent = count;

    lastDriver.textContent =
        'Driver #' + data.driver_id;

    coordinates.textContent =
        data.latitude + ', ' + data.longitude;

    // Update activity history safely
    const activityList = document.getElementById('events');

    if (activityList) {

        document.getElementById('empty-message')?.remove();

        const item = document.createElement('div');
        item.className = 'event';

        const time = new Date().toLocaleTimeString('ar');

        item.textContent =
            'السائق #' + data.driver_id +
            ' | الموقع: ' +
            data.latitude + ', ' + data.longitude +
            ' | الاستلام: ' + time;

        activityList.prepend(item);

        while (activityList.children.length > 15) {
            activityList.lastElementChild.remove();
        }

    } else {
        console.warn('Activity container #events not found');
    }

});

});
</script>

<!-- SMARTFLEET_WORKBENCH_LINK_V1 -->
<a href="/admin/optimization-workbench" style="position:fixed;bottom:20px;left:20px;z-index:99999;background:#15803d;color:white;padding:12px 18px;border-radius:10px;text-decoration:none;box-shadow:0 4px 15px #0005;font-weight:bold"> اعتماد افضل مسار</a>
</body>
</html>