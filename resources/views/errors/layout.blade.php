{{--
    The frame every error page shares.

    Deliberately standalone rather than extending the app or guest layout: an
    error page has to render when the thing that broke is the layout, the
    session, or the database the layout reads its branding from. Anything it
    depends on is another way for the error page itself to fail, and a 500 while
    rendering the 500 page is what produces a blank white screen.

    So: no components, no queries, no Vite manifest. One inline stylesheet.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · KN Softic</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.25rem;
            background: #f8fafc;
            color: #0f172a;
            font: 400 15px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .card {
            width: 100%;
            max-width: 30rem;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 2.25rem 2rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 8px 24px rgba(15, 23, 42, .06);
            text-align: center;
        }
        .mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem; height: 2.5rem;
            border-radius: 10px;
            background: #1d4ed8;
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            letter-spacing: .02em;
            margin-bottom: 1.25rem;
        }
        .code { font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #94a3b8; margin: 0 0 .5rem; }
        h1 { font-size: 1.375rem; font-weight: 600; margin: 0 0 .625rem; letter-spacing: -.01em; }
        p { margin: 0 0 1.5rem; color: #475569; }
        .actions { display: flex; gap: .625rem; justify-content: center; flex-wrap: wrap; }
        a.btn {
            display: inline-flex; align-items: center;
            padding: .5rem 1rem; border-radius: 8px;
            font-size: 14px; font-weight: 500; text-decoration: none;
        }
        a.primary { background: #1d4ed8; color: #fff; }
        a.primary:hover { background: #1e40af; }
        a.ghost { color: #475569; border: 1px solid #e2e8f0; }
        a.ghost:hover { background: #f1f5f9; color: #0f172a; }
        .ref { margin-top: 1.5rem; font-size: 12px; color: #94a3b8; }
        .ref code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    </style>
</head>
<body>
    <main class="card">
        <div class="mark">KN</div>

        <p class="code">Error {{ $code }}</p>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>

        <div class="actions">
            <a class="btn primary" href="{{ url('/') }}">Go to the dashboard</a>
            @if ($showBack ?? true)
                <a class="btn ghost" href="javascript:history.back()">Go back</a>
            @endif
        </div>

        @isset($reference)
            {{-- Given so somebody reporting this can be helped without having to
                 describe what they were doing. --}}
            <p class="ref">Reference <code>{{ $reference }}</code></p>
        @endisset
    </main>
</body>
</html>
