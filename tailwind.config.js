import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/Livewire/**/*.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['"Barlow"', ...defaultTheme.fontFamily.sans],
                display: ['"Barlow Condensed"', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                // Paper = alternate surface shade (subtle bg on cards, chips).
                // Both paper and ink flip with .dark via CSS variables.
                paper: {
                    50:  'rgb(var(--color-paper-50)  / <alpha-value>)',
                    100: 'rgb(var(--color-paper-100) / <alpha-value>)',
                    200: 'rgb(var(--color-paper-200) / <alpha-value>)',
                    300: 'rgb(var(--color-paper-300) / <alpha-value>)',
                    400: 'rgb(var(--color-paper-400) / <alpha-value>)',
                },
                // Ink = primary surface (page bg, card bg, hover, divider, border).
                ink: {
                    950: 'rgb(var(--color-ink-950) / <alpha-value>)',
                    900: 'rgb(var(--color-ink-900) / <alpha-value>)',
                    800: 'rgb(var(--color-ink-800) / <alpha-value>)',
                    700: 'rgb(var(--color-ink-700) / <alpha-value>)',
                    600: 'rgb(var(--color-ink-600) / <alpha-value>)',
                },
                // Bone = text/foreground scale.
                bone: {
                    50:  'rgb(var(--color-bone-50)  / <alpha-value>)',
                    100: 'rgb(var(--color-bone-100) / <alpha-value>)',
                    200: 'rgb(var(--color-bone-200) / <alpha-value>)',
                    400: 'rgb(var(--color-bone-400) / <alpha-value>)',
                    500: 'rgb(var(--color-bone-500) / <alpha-value>)',
                },
                // Navy = the NRL black used on masthead + primary buttons. Stays
                // the same in both modes (nrl.com header is black-on-all-themes).
                navy: {
                    500: '#262626',
                    600: '#1A1A1A',
                    700: '#141414',
                    800: '#0A0A0A',
                    900: '#000000',
                },
                // NRL bright green. Same in both modes; pops on light and dark.
                gold: {
                    300: '#D6F7E1',
                    400: '#1FD46B',
                    500: '#00B852',
                    600: '#008F3E',
                    700: '#006B2D',
                },
                signal: {
                    red:    '#D21F2E',
                    orange: '#E8843C',
                    yellow: '#E6B41F',
                    green:  '#2F8F4F',
                    blue:   '#1F5BB8',
                    purple: '#6F3FB0',
                },
            },
            boxShadow: {
                card: '0 1px 0 rgba(0,0,0,0.03) inset, 0 8px 24px -12px rgba(0,0,0,0.12)',
                glow: '0 0 0 1px rgba(0,184,82,0.35), 0 12px 28px -12px rgba(0,184,82,0.45)',
            },
        },
    },
    plugins: [],
};
