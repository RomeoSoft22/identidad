<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('titulo', 'Identidad') · Juarez Campos</title>
    <style>
        :root {
            --bg: #f5f7f8; --surface: #fff; --fg: #15222c; --muted: #5a6a75; --line: #d3dce1;
            --accent: #0b6b72; --accent-fg: #fff; --danger: #b0252e; --danger-soft: #fae8e9;
            color-scheme: light;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f171c; --surface: #16222a; --fg: #e2eaee; --muted: #94a5af; --line: #2b3b45;
                --accent: #52c2c8; --accent-fg: #0f171c; --danger: #f07a80; --danger-soft: #3b1d21;
                color-scheme: dark;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--fg);
               font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; padding: 24px 16px; }
        .card { width: 100%; max-width: 400px; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; padding: 32px 28px; }
        .marca { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; }
        .marca span { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 8px; background: var(--accent); color: var(--accent-fg); font-weight: 700; font-size: 14px; }
        .marca strong { font-size: 15px; }
        .marca small { display: block; color: var(--muted); font-size: 12.5px; }
        h1 { font-size: 21px; margin: 0 0 6px; }
        p { margin: 0 0 16px; color: var(--muted); }
        label { display: block; font-weight: 600; font-size: 13.5px; margin: 14px 0 6px; }
        input { width: 100%; padding: 10px 12px; font: inherit; color: var(--fg); background: var(--bg); border: 1px solid var(--line); border-radius: 6px; }
        input:focus-visible, button:focus-visible, a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        button, .boton { display: block; width: 100%; margin-top: 22px; padding: 11px; font: 600 15px/1 inherit; text-align: center; text-decoration: none;
                 color: var(--accent-fg); background: var(--accent); border: 0; border-radius: 6px; cursor: pointer; }
        .error { background: var(--danger-soft); color: var(--danger); border-radius: 6px; padding: 10px 12px; font-size: 14px; margin: 14px 0 0; }
        .pie { margin-top: 22px; font-size: 12.5px; color: var(--muted); text-align: center; }
        a { color: var(--accent); }
    </style>
</head>
<body>
<main class="card">
    <div class="marca"><span>JC</span><div><strong>Identidad</strong><small>Juarez Campos</small></div></div>
    @yield('contenido')
</main>
</body>
</html>
