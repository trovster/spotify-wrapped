<!doctype html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0c0c0e">
    <meta name="color-scheme" content="dark">
    <title>@yield('title', 'Spotify Wrapped')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css?v={{ filemtime(public_path('css/app.css')) }}">
    @stack('head')
</head>
<body class="@yield('body-class')">
    @unless(View::hasSection('bare'))
        <header class="site-header">
            <div class="container site-header__inner"><x-app-nav /></div>
        </header>
    @endunless
    @yield('content')
    <script src="/js/wrap-delete.js?v={{ filemtime(public_path('js/wrap-delete.js')) }}" defer></script>
</body>
</html>
