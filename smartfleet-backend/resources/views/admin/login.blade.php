<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>SmartFleet - تسجيل الدخول</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0b1220;
            color: #f8fafc;
            font-family: Tahoma, Arial, sans-serif;
            padding: 20px;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: #172236;
            border: 1px solid #334155;
            border-radius: 18px;
            padding: 32px;
        }

        h1 {
            color: #38bdf8;
            text-align: center;
            margin-bottom: 8px;
        }

        p {
            text-align: center;
            color: #94a3b8;
            margin-bottom: 30px;
        }

        label {
            display: block;
            margin-bottom: 8px;
        }

        input {
            width: 100%;
            padding: 13px;
            margin-bottom: 20px;
            border: 1px solid #475569;
            border-radius: 8px;
            background: #0f172a;
            color: white;
            direction: ltr;
        }

        button {
            width: 100%;
            padding: 14px;
            background: #0284c7;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #0369a1;
        }

        .error {
            color: #fca5a5;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <h1>SmartFleet</h1>
    <p>تسجيل الدخول إلى لوحة الإدارة</p>

    @if ($errors->any())
        <div class="error">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.login.submit') }}">
        @csrf

        <label>البريد الإلكتروني</label>
        <input
            type="email"
            name="email"
            value="{{ old('email') }}"
            autocomplete="username"
            required
        >

        <label>كلمة المرور</label>
        <input
            type="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <button type="submit">
            تسجيل الدخول
        </button>
    </form>
</div>

</body>
</html>