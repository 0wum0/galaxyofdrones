<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="UTF-8" />
        <title>@yield('title')</title>
        @yield('head')
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @stack('stylesheets')
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}" />
    </head>
    <body>
        @yield('body')
        {{-- Fallback: visible logout/reload links when JS fails to mount Vue.
             Hidden by Vue's v-cloak once the app is alive, visible otherwise. --}}
        @auth
        <noscript>
            <div style="position:fixed;bottom:0;left:0;right:0;background:#1a1a2e;color:#fff;padding:10px;text-align:center;z-index:99999;font-family:sans-serif;">
                JavaScript is required. <a href="{{ route('logout') }}" style="color:#4fc3f7;">Logout</a>
            </div>
        </noscript>
        <div id="js-fallback-bar" style="display:none;position:fixed;bottom:0;left:0;right:0;background:#1a1a2e;color:#fff;padding:10px;text-align:center;z-index:99999;font-family:sans-serif;">
            App failed to load. <a href="/" style="color:#4fc3f7;">Reload</a> |
            <a href="{{ route('logout') }}" style="color:#4fc3f7;">Logout</a>
        </div>
        <script>
            // Show fallback bar if Vue hasn't mounted within 5 seconds.
            (function() {
                var timer = setTimeout(function() {
                    var appEl = document.getElementById('app');
                    // Vue sets __vue__ on the mount element when it mounts.
                    if (appEl && !appEl.__vue__) {
                        var bar = document.getElementById('js-fallback-bar');
                        if (bar) bar.style.display = 'block';
                    }
                }, 5000);
                // Cancel the timer once Vue mounts (event emitted by Vue lifecycle).
                window.__cancelFallbackBar = function() { clearTimeout(timer); };
            })();
        </script>
        @endauth
        @stack('javascripts')
    </body>
</html>
