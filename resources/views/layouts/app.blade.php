<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Simpul Warehouse Selection')</title>
    <style>
        /* ── Reset & base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue:   #2563eb;
            --blue-d: #1d4ed8;
            --green:  #16a34a;
            --orange: #d97706;
            --red:    #dc2626;
            --gray-50:#f9fafb;
            --gray-100:#f3f4f6;
            --gray-200:#e5e7eb;
            --gray-400:#9ca3af;
            --gray-600:#4b5563;
            --gray-800:#1f2937;
            --radius: .5rem;
            --shadow: 0 1px 3px rgba(0,0,0,.12), 0 1px 2px rgba(0,0,0,.06);
        }

        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 1rem;
            line-height: 1.6;
            background: var(--gray-50);
            color: var(--gray-800);
        }

        /* ── Layout ── */
        header {
            background: var(--blue);
            color: #fff;
            padding: 1.25rem 1.5rem;
        }
        header h1 { font-size: 1.35rem; font-weight: 700; }
        header p  { font-size: .875rem; opacity: .85; margin-top: .15rem; }

        main {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        footer {
            text-align: center;
            color: var(--gray-400);
            font-size: .8rem;
            padding: 2rem 1rem;
        }

        /* ── Cards ── */
        .card {
            background: #fff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card-title {
            font-size: 1.05rem;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: .5rem;
            border-bottom: 1px solid var(--gray-200);
        }

        /* ── Form ── */
        .order-row {
            display: grid;
            grid-template-columns: 1fr 120px auto;
            gap: .5rem;
            align-items: center;
            margin-bottom: .5rem;
        }
        select, input[type="number"] {
            width: 100%;
            padding: .5rem .75rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: .95rem;
            background: #fff;
        }
        select:focus, input[type="number"]:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37,99,235,.15);
        }

        label { font-size: .875rem; font-weight: 500; color: var(--gray-600); }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .5rem 1rem;
            border-radius: var(--radius);
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: background .15s;
        }
        .btn-primary { background: var(--blue); color: #fff; }
        .btn-primary:hover { background: var(--blue-d); }
        .btn-primary:disabled { opacity: .55; cursor: not-allowed; }
        .btn-secondary { background: var(--gray-100); color: var(--gray-800); border: 1px solid var(--gray-200); }
        .btn-secondary:hover { background: var(--gray-200); }
        .btn-danger-sm {
            background: none;
            border: none;
            color: var(--red);
            cursor: pointer;
            font-size: 1.1rem;
            line-height: 1;
            padding: .25rem;
        }
        .btn-danger-sm:hover { opacity: .7; }

        /* ── Status badges ── */
        .badge {
            display: inline-block;
            padding: .2rem .6rem;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .03em;
            text-transform: uppercase;
        }
        .badge-complete      { background: #dcfce7; color: #15803d; }
        .badge-partial       { background: #fef3c7; color: #b45309; }
        .badge-unfulfillable { background: #fee2e2; color: #b91c1c; }

        /* ── Result panels ── */
        .result-header {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin-bottom: 1rem;
        }
        .result-summary { color: var(--gray-600); }

        .allocation-block {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            margin-bottom: .75rem;
            overflow: hidden;
        }
        .allocation-header {
            background: var(--gray-100);
            padding: .6rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
        }
        .allocation-header .dist { font-size: .8rem; color: var(--gray-600); font-weight: 400; }
        .allocation-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .875rem;
        }
        .allocation-table th {
            background: var(--gray-50);
            text-align: left;
            padding: .4rem .75rem;
            font-weight: 600;
            color: var(--gray-600);
            font-size: .8rem;
            border-bottom: 1px solid var(--gray-200);
        }
        .allocation-table td {
            padding: .4rem .75rem;
            border-bottom: 1px solid var(--gray-100);
        }
        .allocation-table tr:last-child td { border-bottom: none; }

        .shortage-list { list-style: none; }
        .shortage-list li {
            display: flex;
            justify-content: space-between;
            padding: .4rem 0;
            border-bottom: 1px solid var(--gray-100);
            font-size: .875rem;
        }
        .shortage-list li:last-child { border-bottom: none; }
        .shortage-qty { color: var(--red); font-weight: 600; }

        .explanation-list { list-style: none; }
        .explanation-list li {
            padding: .35rem 0;
            border-bottom: 1px solid var(--gray-100);
            font-size: .875rem;
            color: var(--gray-600);
        }
        .explanation-list li:last-child { border-bottom: none; }
        .explanation-list li::before { content: "•  "; color: var(--blue); }

        /* ── Alerts ── */
        .alert {
            padding: .75rem 1rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            font-size: .9rem;
        }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert-info  { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

        /* ── Loading spinner ── */
        .spinner {
            display: inline-block;
            width: 1rem; height: 1rem;
            border: 2px solid rgba(255,255,255,.5);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Metric row ── */
        .metrics {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .metric {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: .5rem 1rem;
            font-size: .85rem;
        }
        .metric span { font-weight: 700; font-size: 1rem; }

        /* ── Responsive ── */
        @media (max-width: 600px) {
            .order-row { grid-template-columns: 1fr 90px auto; }
            .allocation-header { flex-direction: column; align-items: flex-start; gap: .2rem; }
        }
    </style>
</head>
<body>
    <header>
        <h1>🏥 Simpul Warehouse Selection</h1>
        <p>Medical &amp; pharmaceutical distribution — warehouse recommendation tool</p>
    </header>

    <main>
        @yield('content')
    </main>

    <footer>
        Simpul Tech Backend Skill Challenge · Laravel {{ app()->version() }}
    </footer>
</body>
</html>
