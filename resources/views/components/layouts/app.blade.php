<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'سامانه مناقصات سیترا' }}</title>
    @vite(['resources/css/app.css'])
    @livewireStyles
</head>
<body class="bg-gray-50 text-gray-900 antialiased" style="font-family: Tahoma, Vazirmatn, sans-serif;">
    {{ $slot }}
    @livewireScripts
</body>
</html>
