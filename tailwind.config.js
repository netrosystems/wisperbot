import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Space Grotesk', ...defaultTheme.fontFamily.sans],
                // Editorial serif for marketing landing display headings.
                display: ['Fraunces', 'Georgia', ...defaultTheme.fontFamily.serif],
            },
            // WisperBot palette — brand (orange #FF762E), accent (amber
            // #FFBF00 / light-yellow #FFF78D), secondary (warm charcoal).
            // Source of truth: ./.branding
            colors: {
                surface: {
                    DEFAULT: '#f7faec',
                    subtle: '#eef4dd',
                },
                secondary: {
                    50: '#eef2ec',
                    100: '#d3ddce',
                    200: '#b3c4ab',
                    300: '#8da583',
                    400: '#6c8760',
                    500: '#4f6b43',
                    600: '#3d5534',
                    700: '#32462b',
                    800: '#283f24',
                    900: '#20321d',
                    950: '#121d10',
                },
                brand: {
                    50: '#fff5ed',
                    100: '#ffe8d4',
                    200: '#ffcda8',
                    300: '#ffab70',
                    400: '#ff8a45',
                    500: '#ff762e',
                    600: '#f05a12',
                    700: '#c74310',
                    800: '#9e3615',
                    900: '#7f2f14',
                    950: '#451507',
                },
                // Accent (amber #FFBF00 at 500, light-yellow #FFF78D at 200)
                accent: {
                    50: '#fffdeb',
                    100: '#fffbc4',
                    200: '#fff78d',
                    300: '#ffe24a',
                    400: '#ffcf1f',
                    500: '#ffbf00',
                    600: '#e29400',
                    700: '#bb6c02',
                    800: '#985308',
                    900: '#7c450b',
                    950: '#482400',
                },
                // Danger / destructive (coral-red)
                coral: {
                    50: '#fff3f1',
                    100: '#ffe4df',
                    200: '#ffcabf',
                    300: '#ffa593',
                    400: '#fb7355',
                    500: '#f04e2e',
                    600: '#d8331a',
                    700: '#b32512',
                    800: '#931f13',
                    900: '#7a1e16',
                    950: '#420a07',
                },
                neutral: {
                    50: '#fafafa',
                    100: '#f4f4f5',
                    200: '#e4e4e7',
                    300: '#d4d4d8',
                    400: '#a1a1aa',
                    500: '#71717a',
                    600: '#52525b',
                    700: '#3f3f46',
                    800: '#27272a',
                    900: '#18181b',
                    950: '#0a0a0b',
                },
            },
            // Soft borders
            borderWidth: {
                soft: '1px',
            },
            borderColor: {
                DEFAULT: 'rgb(228 228 231 / 0.8)',
                soft: 'rgb(228 228 231 / 0.6)',
                muted: 'rgb(228 228 231 / 0.4)',
            },
            borderRadius: {
                soft: '0.5rem',
                'soft-lg': '0.75rem',
                'soft-xl': '1rem',
            },
            // Subtle shadows
            boxShadow: {
                soft: '0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 2px -1px rgb(0 0 0 / 0.04)',
                'soft-md': '0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05)',
                'soft-lg': '0 10px 15px -3px rgb(0 0 0 / 0.06), 0 4px 6px -4px rgb(0 0 0 / 0.06)',
                'soft-xl': '0 20px 25px -5px rgb(0 0 0 / 0.06), 0 8px 10px -6px rgb(0 0 0 / 0.06)',
                inner: 'inset 0 1px 2px 0 rgb(0 0 0 / 0.04)',
            },
            // Spacing scale (align with design)
            spacing: {
                '4.5': '1.125rem',
                '13': '3.25rem',
                '15': '3.75rem',
                '18': '4.5rem',
                '22': '5.5rem',
                '30': '7.5rem',
            },
            transitionDuration: {
                150: '150ms',
                250: '250ms',
            },
            transitionTimingFunction: {
                smooth: 'cubic-bezier(0.4, 0, 0.2, 1)',
            },
            // ── Landing-page animation system (WisperBot) ──
            keyframes: {
                'fade-up': {
                    '0%': { opacity: '0', transform: 'translateY(28px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'fade-in': {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                'scale-in': {
                    '0%': { opacity: '0', transform: 'scale(0.94)' },
                    '100%': { opacity: '1', transform: 'scale(1)' },
                },
                float: {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%': { transform: 'translateY(-18px)' },
                },
                'float-slow': {
                    '0%, 100%': { transform: 'translate(0, 0)' },
                    '50%': { transform: 'translate(0, -26px)' },
                },
                marquee: {
                    '0%': { transform: 'translateX(0)' },
                    '100%': { transform: 'translateX(-50%)' },
                },
                shimmer: {
                    '0%': { backgroundPosition: '-200% 0' },
                    '100%': { backgroundPosition: '200% 0' },
                },
                'gradient-pan': {
                    '0%, 100%': { backgroundPosition: '0% 50%' },
                    '50%': { backgroundPosition: '100% 50%' },
                },
                'pulse-ring': {
                    '0%': { transform: 'scale(0.8)', opacity: '0.5' },
                    '100%': { transform: 'scale(2.2)', opacity: '0' },
                },
                'spin-slow': {
                    '0%': { transform: 'rotate(0deg)' },
                    '100%': { transform: 'rotate(360deg)' },
                },
            },
            animation: {
                'fade-up': 'fade-up 0.7s cubic-bezier(0.16, 1, 0.3, 1) both',
                'fade-in': 'fade-in 0.8s ease-out both',
                'scale-in': 'scale-in 0.6s cubic-bezier(0.16, 1, 0.3, 1) both',
                float: 'float 6s ease-in-out infinite',
                'float-slow': 'float-slow 9s ease-in-out infinite',
                marquee: 'marquee 32s linear infinite',
                shimmer: 'shimmer 2.5s linear infinite',
                'gradient-pan': 'gradient-pan 8s ease infinite',
                'pulse-ring': 'pulse-ring 2.4s cubic-bezier(0.4, 0, 0.2, 1) infinite',
                'spin-slow': 'spin-slow 24s linear infinite',
            },
        },
    },

    plugins: [forms],
};
