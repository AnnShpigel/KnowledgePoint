<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Агрегатор знаний</title>

    {{-- Google Fonts — Manrope (основной), Merriweather (заголовки), JetBrains Mono (код) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Merriweather:ital,wght@0,400;0,700;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Manrope', sans-serif;
            background: #F8FAFC;
        }
    </style>
</head>

<body>
    <div id="app">
        <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:1rem;background:#F8FAFC;">
            <div style="width:40px;height:40px;border:3px solid #E2E8F0;border-top-color:#4F46E5;border-radius:50%;animation:spin .8s linear infinite;"></div>
            <p style="font-family:'Manrope',sans-serif;color:#64748B;font-size:.9375rem;">Загрузка…</p>
            <style>
                @keyframes spin {
                    to {
                        transform: rotate(360deg)
                    }
                }
            </style>
        </div>
    </div>
</body>

</html>