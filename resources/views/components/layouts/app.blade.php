{{--
    Layout for the pages that live OUTSIDE the Filament panel.

    Right now that means just /register, which cannot be a Filament page
    because the visitor is not authenticated yet. Filament renders its own
    <html> shell for everything else, so this file is not involved there.

    lang="fa" + dir="rtl" are hard-coded: this app is Persian-only and
    RTL-only by requirement — there is no language switcher to react to.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'سامانه مناقصات سیترا' }}</title>

    {{--
        Vazirmatn @font-face rules. This is the same static stylesheet the
        Filament panel loads (see AppPanelProvider), so both halves of the
        app use one identical font definition served from our own public/
        folder — no Google/Bunny/CDN request anywhere.

        The <link rel="preload"> above it starts the .woff2 download in
        parallel with the CSS instead of after it, which avoids a visible
        flash of the fallback font.
    --}}
    <link rel="preload" href="{{ asset('fonts/vazirmatn/Vazirmatn-Variable.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/vazirmatn.css') }}">

    {{-- Tailwind build for these standalone pages (see resources/css/app.css). --}}
    @vite(['resources/css/app.css'])
    @livewireStyles
</head>
{{--
    No inline font-family here on purpose: resources/css/app.css sets
    Tailwind's `--font-sans` to Vazirmatn, and `antialiased` (a Tailwind
    utility) applies that sans stack. Keeping it in one place means the
    standalone pages and the panel can never drift apart.
--}}
<body class="bg-gray-50 font-sans text-gray-900 antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
