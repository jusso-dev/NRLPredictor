<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'NRL Try Predictor' }}</title>

    {{-- Apply theme before paint to avoid flash. Reads localStorage, falls back
         to the OS preference. --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('theme');
                if (!t) t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                document.documentElement.classList.toggle('dark', t === 'dark');
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen">
    @php($navLinks = [
        'dashboard'   => 'Dashboard',
        'value-picks' => 'Value Picks',
        'multi-bet'   => 'Multi Builder',
        'leaderboard' => 'Leaderboard',
        'accuracy'    => 'Accuracy',
        'calibration' => 'Calibration',
        'learning'    => 'Self-Tuning',
        'chat'        => 'Chat',
        'methodology' => 'How It Works',
        'jobs'        => 'Jobs',
        'logs'        => 'Logs',
    ])

    <header class="sticky top-0 z-40 bg-navy-900 text-white border-b border-white/5">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 md:px-6">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                <span class="grid h-9 w-9 place-items-center rounded-sm bg-gold-500 font-display text-lg font-bold leading-none text-navy-900 md:h-10 md:w-10 md:text-xl">N</span>
                <div class="leading-tight">
                    <div class="h-display text-base tracking-wide text-white md:text-lg">NRL Try Predictor</div>
                    <div class="hidden text-[10px] font-semibold uppercase tracking-[0.2em] text-white/60 sm:block">Signal-driven try analytics</div>
                </div>
            </a>

            <nav class="hidden items-center gap-0 text-sm md:flex">
                @foreach ($navLinks as $name => $label)
                    @php($active = request()->routeIs($name))
                    <a href="{{ route($name) }}"
                       class="relative px-4 py-5 text-xs font-semibold uppercase tracking-[0.14em] transition
                              {{ $active ? 'text-white' : 'text-white/70 hover:text-white' }}">
                        {{ $label }}
                        @if ($active)
                            <span class="absolute inset-x-2 bottom-0 h-[3px] bg-gold-500"></span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <div class="flex items-center gap-2">
                <button type="button" onclick="window._toggleTheme && window._toggleTheme()"
                        class="rounded-md border border-white/10 bg-white/5 p-2 text-white/80 transition hover:bg-white/10 hover:text-white"
                        aria-label="Toggle theme">
                    <svg class="hidden h-4 w-4 dark:block" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M10 2a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0v-1.5A.75.75 0 0110 2zm0 13.5a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0v-1.5a.75.75 0 01.75-.75zM4.22 4.22a.75.75 0 011.06 0l1.06 1.06a.75.75 0 11-1.06 1.06L4.22 5.28a.75.75 0 010-1.06zm9.44 9.44a.75.75 0 011.06 0l1.06 1.06a.75.75 0 01-1.06 1.06l-1.06-1.06a.75.75 0 010-1.06zM2 10a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5A.75.75 0 012 10zm13.5 0a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5a.75.75 0 01-.75-.75zM5.28 15.78a.75.75 0 010-1.06l1.06-1.06a.75.75 0 111.06 1.06l-1.06 1.06a.75.75 0 01-1.06 0zm9.44-9.44a.75.75 0 010-1.06l1.06-1.06a.75.75 0 011.06 1.06l-1.06 1.06a.75.75 0 01-1.06 0zM10 6a4 4 0 100 8 4 4 0 000-8z"/>
                    </svg>
                    <svg class="h-4 w-4 dark:hidden" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.455 2.004a.75.75 0 01.26.77 7 7 0 009.958 7.967.75.75 0 011.067.853A8.5 8.5 0 116.647 1.2a.75.75 0 01.808.804z" clip-rule="evenodd"/>
                    </svg>
                </button>

                <button type="button"
                        onclick="this.nextElementSibling.classList.toggle('hidden')"
                        class="rounded-md border border-white/10 bg-white/5 p-2 text-white md:hidden"
                        aria-label="Open menu">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M3 5.75A.75.75 0 013.75 5h12.5a.75.75 0 010 1.5H3.75A.75.75 0 013 5.75zm0 4.25a.75.75 0 01.75-.75h12.5a.75.75 0 010 1.5H3.75A.75.75 0 013 10zm.75 3.5a.75.75 0 000 1.5h12.5a.75.75 0 000-1.5H3.75z" clip-rule="evenodd"/>
                    </svg>
                </button>
                <div class="absolute left-0 right-0 top-full hidden border-t border-white/5 bg-navy-900 md:hidden" id="mobile-nav">
                    @foreach ($navLinks as $name => $label)
                        @php($active = request()->routeIs($name))
                        <a href="{{ route($name) }}"
                           class="block border-l-[3px] px-5 py-3 text-xs font-semibold uppercase tracking-[0.14em] transition
                                  {{ $active ? 'border-gold-500 bg-white/5 text-white' : 'border-transparent text-white/70 hover:bg-white/5 hover:text-white' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </header>

    <script>
        window._toggleTheme = function () {
            var isDark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
        };
        // Close mobile nav when a link is clicked (defer so Livewire nav still works).
        document.addEventListener('click', function (e) {
            var nav = document.getElementById('mobile-nav');
            if (!nav || nav.classList.contains('hidden')) return;
            if (e.target.closest('#mobile-nav a')) nav.classList.add('hidden');
        });
    </script>

    <main class="mx-auto max-w-7xl px-4 py-6 md:px-6 md:py-8">
        {{ $slot }}
    </main>

    <footer class="mt-16 border-t border-ink-600 bg-ink-900">
        <div class="mx-auto max-w-7xl px-4 py-6 text-xs text-bone-400 md:px-6 space-y-3">
            <p>Data scraped from public sources. Predictions are model-driven, not betting advice.
            Unofficial — not affiliated with the NRL.</p>
            <div class="border-t border-ink-700 pt-3 text-center text-bone-500">
                <p>For informational use only. Gambling involves real financial risk.</p>
                <p>If you need support, call <strong class="text-bone-400">1800 858 858</strong> or visit
                <a href="https://www.betstop.gov.au" class="text-gold-500 hover:underline" target="_blank" rel="noopener">www.betstop.gov.au</a></p>
            </div>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
