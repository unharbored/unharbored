<!doctype html>
<html lang="en" @if ($page['props']['theme'] ?? null) data-theme="{{ $page['props']['theme'] }}" @endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500&family=Space+Mono:wght@400;700&display=swap">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
@vite(['resources/css/app.css', 'resources/js/app.js'])
@inertiaHead
</head>
<body>
@inertia
<script src="/js/dialogs.js?v={{ @filemtime(public_path('js/dialogs.js')) }}"></script>
</body>
</html>
