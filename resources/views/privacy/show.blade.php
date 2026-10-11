@php
    $fr = app()->getLocale() === 'fr';
    $title = $fr ? 'Politique de confidentialité' : 'Privacy Policy';
@endphp
<!DOCTYPE html>
<html lang="{{ $fr ? 'fr' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }} · Fitness Tracker</title>
    <meta name="description" content="{{ $fr ? 'Comment Fitness Tracker collecte, utilise et protège vos données.' : 'How Fitness Tracker collects, uses and protects your data.' }}">
    <link rel="icon" href="{{ asset('images/app-icon.png') }}">
    {{-- The app's own palette (AppTheme in the Flutter app: Material 3 from a
         teal seed, a white page, white cards with a thin border, no shadows),
         so opening this from Settings feels like the same app. --}}
    <style>
        :root {
            --page: #ffffff;
            --card: #ffffff;
            --border: #dde4e3;
            --text: #161d1c;
            --muted: #3f4947;
            --primary: #006a60;
            --primary-soft: #e3f4f1;
            --on-primary-soft: #00423b;
            --chip: #eff3f3;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --page: #0e1514;
                --card: #1a2120;
                --border: rgba(63, 73, 71, 0.6);
                --text: #dde4e2;
                --muted: #bec9c6;
                --primary: #82d5c8;
                --primary-soft: #1f3a36;
                --on-primary-soft: #a1f2e4;
                --chip: #242b2a;
            }
        }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0;
            background: var(--page);
            color: var(--text);
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
        header { display: flex; align-items: center; gap: 14px; margin-bottom: 8px; }
        header img {
            width: 56px; height: 56px; border-radius: 14px;
            border: 1px solid var(--border); background: #fff; flex: none;
        }
        header .name { font-size: 14px; color: var(--muted); }
        h1 { font-size: 28px; line-height: 1.2; margin: 2px 0 0; letter-spacing: -0.01em; }
        .meta {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 12px; margin: 16px 0 24px;
        }
        .updated { color: var(--muted); font-size: 14px; }
        .lang {
            display: inline-flex; padding: 3px; border-radius: 999px;
            border: 1px solid var(--border); background: var(--card);
        }
        .lang a {
            padding: 6px 14px; border-radius: 999px; font-size: 14px; font-weight: 600;
            color: var(--muted); text-decoration: none;
        }
        .lang a[aria-current="page"] { background: var(--primary-soft); color: var(--on-primary-soft); }
        .card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 16px; padding: 20px; margin-bottom: 16px;
        }
        .card.highlight { background: var(--primary-soft); border-color: transparent; }
        .card.highlight, .card.highlight p, .card.highlight li { color: var(--on-primary-soft); }
        h2 {
            display: flex; align-items: center; gap: 12px;
            font-size: 19px; line-height: 1.3; margin: 0 0 12px;
        }
        h2 .num {
            flex: none; width: 30px; height: 30px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--primary-soft); color: var(--on-primary-soft);
            font-size: 14px; font-weight: 700;
        }
        h3 { font-size: 16px; margin: 18px 0 6px; }
        p, li { color: var(--text); }
        p { margin: 0 0 10px; }
        p:last-child, ul:last-child { margin-bottom: 0; }
        ul { margin: 0 0 10px; padding-left: 20px; }
        li { margin-bottom: 6px; }
        a { color: var(--primary); }
        .muted { color: var(--muted); }
        .glance { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
        .glance li { display: flex; gap: 10px; margin: 0; }
        .glance li::before { content: "✓"; font-weight: 700; flex: none; }
        .toc { columns: 2; column-gap: 24px; padding-left: 20px; margin: 0; }
        .toc li { break-inside: avoid; }
        .toc a { text-decoration: none; }
        .toc a:hover { text-decoration: underline; }
        .badges { display: flex; flex-wrap: wrap; gap: 8px; margin: 4px 0 12px; }
        .badge {
            display: inline-block; padding: 4px 12px; border-radius: 999px;
            background: var(--chip); border: 1px solid var(--border);
            font-size: 13px; font-weight: 600; color: var(--muted);
        }
        .table { width: 100%; border-collapse: collapse; font-size: 15px; }
        .table th, .table td { text-align: left; vertical-align: top; padding: 10px 0; border-top: 1px solid var(--border); }
        .table th { width: 34%; padding-right: 16px; font-weight: 600; }
        .table tr:first-child th, .table tr:first-child td { border-top: 0; }
        footer { text-align: center; color: var(--muted); font-size: 14px; margin-top: 32px; }
        section { scroll-margin-top: 16px; }
        @media (max-width: 560px) {
            h1 { font-size: 24px; }
            .toc { columns: 1; }
            .table th, .table td { display: block; width: 100%; padding: 0; }
            .table th { padding-top: 12px; }
            .table td { padding-bottom: 12px; }
        }
    </style>
</head>
<body>
<main class="wrap">
    <header>
        <img src="{{ asset('images/app-icon.png') }}" alt="" width="56" height="56">
        <div>
            <div class="name">Fitness Tracker</div>
            <h1>{{ $title }}</h1>
        </div>
    </header>

    <div class="meta">
        <span class="updated">{{ $fr ? 'Dernière mise à jour : 11 octobre 2026' : 'Last updated: October 11, 2026' }}</span>
        <nav class="lang" aria-label="{{ $fr ? 'Langue' : 'Language' }}">
            <a href="{{ url('/privacy?lang=en') }}" hreflang="en" @if (! $fr) aria-current="page" @endif>English</a>
            <a href="{{ url('/privacy?lang=fr') }}" hreflang="fr" @if ($fr) aria-current="page" @endif>Français</a>
        </nav>
    </div>

    @include($fr ? 'privacy.fr' : 'privacy.en', ['contactEmail' => $contactEmail])

    <footer>© {{ date('Y') }} Fitness Tracker</footer>
</main>
</body>
</html>
